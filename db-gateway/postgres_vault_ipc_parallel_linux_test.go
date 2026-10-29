package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

// Real Laravel/SQLite transactions and concurrent PHP credential subprocesses;
// no SQL server is needed for this atomic Vault grant test.
func TestLaravelVaultIPCParallelBootstrap(t *testing.T) {
	if os.Getenv("SAG_E2E_LARAVEL") != "1" {
		t.Skip("set SAG_E2E_LARAVEL for real local Laravel IPC integration")
	}
	php := os.Getenv("SAG_E2E_PHP")
	if php == "" {
		php = "php"
	}
	root, _ := filepath.Abs("../control-plane")
	dir := privateIPCTestDir(t)
	db := filepath.Join(dir, "control.sqlite")
	if os.WriteFile(db, nil, 0600) != nil {
		t.Fatal("SQLite fixture unavailable")
	}
	secret := make([]byte, 32)
	if _, e := rand.Read(secret); e != nil {
		t.Fatal("fixture entropy unavailable")
	}
	password := hex.EncodeToString(secret)
	for k, v := range map[string]string{"APP_ENV": "testing", "APP_DEBUG": "false", "BCRYPT_ROUNDS": "4", "APP_KEY": "base64:" + base64.StdEncoding.EncodeToString(secret), "SAG_CE_CREDENTIAL_KEY": "base64:" + base64.StdEncoding.EncodeToString(secret), "DB_CONNECTION": "sqlite", "DB_DATABASE": db, "CACHE_STORE": "array", "SESSION_DRIVER": "array", "LOG_CHANNEL": "stderr"} {
		t.Setenv(k, v)
	}
	fixture := func(input map[string]any) []byte {
		t.Helper()
		data, _ := json.Marshal(input)
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		cmd := exec.CommandContext(ctx, php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
		cmd.Stdin = bytes.NewReader(data)
		cmd.WaitDelay = 250 * time.Millisecond
		out, e := cmd.Output()
		if e != nil {
			t.Fatal("Laravel fixture failed")
		}
		if bytes.Contains(out, []byte(password)) {
			t.Fatal("fixture disclosed backend credential")
		}
		return out
	}
	ticks, e := pgIPCPeerProcessStartTicks(int32(os.Getpid()))
	if e != nil {
		t.Fatal("test requires a non-init bound gateway process")
	}
	var setup struct {
		Resource int64 `json:"resource"`
	}
	if json.Unmarshal(fixture(map[string]any{"action": "setup", "password": password, "pid": os.Getpid(), "ticks": ticks}), &setup) != nil || setup.Resource <= 0 {
		t.Fatal("invalid fixture response")
	}
	socket := filepath.Join(dir, "vault.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()), pgIPCLaravelAuthorizer(php, filepath.Join(root, "artisan")), pgIPCLaravelCredentials(php, filepath.Join(root, "artisan")))
	}()
	defer func() {
		cancel()
		if e := <-done; e != nil {
			t.Error("worker failed")
		}
	}()
	for i := 0; i < 200; i++ {
		if c, e := net.Dial("unix", socket); e == nil {
			c.Close()
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	for _, r := range []int64{setup.Resource + 1, 0} {
		if c, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", r, uint32(os.Getuid())); e == nil || c.Password != "" {
			t.Fatal("wrong resource obtained a credential")
		}
	}
	type result struct {
		credential BrokerBackendCredential
		err        error
	}
	results := make(chan result, 8)
	start := make(chan struct{})
	var attempts sync.WaitGroup
	for i := 0; i < 8; i++ {
		attempts.Add(1)
		go func() {
			defer attempts.Done()
			<-start
			c, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))
			results <- result{c, e}
		}()
	}
	close(start)
	attempts.Wait()
	close(results)
	grants := 0
	for r := range results {
		if r.err != nil {
			if r.credential.Password != "" {
				t.Fatal("denied request retained a credential")
			}
			continue
		}
		grants++
		if r.credential.Username != "ipc_e2e_backend" || r.credential.Password != password {
			t.Fatal("incorrect credential grant")
		}
	}
	if grants > 1 {
		t.Fatal("concurrent requests replayed one-time Vault bootstrap")
	}
	// A burst may fail closed under SQLite lock contention. A subsequent single
	// attempt can grant only if none of the burst requests committed a grant.
	c, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))
	if e == nil {
		grants++
		if c.Password != password {
			t.Fatal("incorrect subsequent credential")
		}
	}
	if grants != 1 {
		t.Fatal("one-time grant was lost or replayed")
	}
	if c, e = pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid())); e == nil || c.Password != "" {
		t.Fatal("consumed bootstrap replay succeeded")
	}
	fixture(map[string]any{"action": "activate"})
	if !pgIPCBoundSessionCheck(socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))(ctx) {
		t.Fatal("granted process lost live authorization")
	}
	fixture(map[string]any{"action": "revoke"})
	if pgIPCBoundSessionCheck(socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))(ctx) {
		t.Fatal("revoked process retained authorization")
	}
	t.Log("concurrent real Laravel grants: exactly one committed credential; replay and revoke denied")
}
