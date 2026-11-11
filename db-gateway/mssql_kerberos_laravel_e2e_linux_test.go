package main

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"
)

// Exercises the actual hidden Artisan command, anonymous FD 3 and trusted Unix
// worker together. The AD/SQL job separately verifies these bytes at the adapter.
func TestKerberosLaravelFD3Bootstrap(t *testing.T) {
	root, err := filepath.Abs("../control-plane")
	if err != nil { t.Fatal("control-plane path unavailable") }
	if _, err := os.Stat(filepath.Join(root, "vendor/autoload.php")); err != nil {
		t.Skip("install Laravel dependencies for real FD3 E2E")
	}
	php, err := exec.LookPath("php")
	if err != nil { t.Skip("PHP required for real FD3 E2E") }
	dir := privateIPCTestDir(t)
	configPath, keytabPath := filepath.Join(dir, "krb5.conf"), filepath.Join(dir, "gateway.keytab")
	write := func(path string, data []byte) {
		t.Helper()
		if os.WriteFile(path, data, 0600) != nil { t.Fatal("private fixture file unavailable") }
	}
	write(configPath, []byte("[libdefaults]\n default_realm = SAG.TEST\n"))
	write(keytabPath, []byte("opaque-private-keytab-fixture"))
	dbPath := filepath.Join(dir, "control.sqlite")
	write(dbPath, nil)
	profiles, _ := json.Marshal(map[string]any{"isolated-ad": map[string]string{
		"target": "sql.sag.test:1433", "spn": "MSSQLSvc/sql.sag.test:1433", "username": "gateway", "realm": "SAG.TEST",
		"config_file": configPath, "keytab_file": keytabPath,
	}})
	profilesPath := filepath.Join(dir, "profiles.json")
	write(profilesPath, profiles)
	for name, value := range map[string]string{
		"APP_ENV": "testing", "APP_DEBUG": "false", "BCRYPT_ROUNDS": "4",
		"APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
		"SAG_CE_CREDENTIAL_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
		"DB_CONNECTION": "sqlite", "DB_DATABASE": dbPath, "CACHE_STORE": "array", "SESSION_DRIVER": "array", "LOG_CHANNEL": "stderr",
		"SAG_MSSQL_KERBEROS_ENABLED": "true", "SAG_MSSQL_KERBEROS_PROFILES_FILE": profilesPath,
	} { t.Setenv(name, value) }
	fixture := func(action string) struct { Resource int64; Consumed bool } {
		t.Helper()
		ticks, err := pgIPCPeerProcessStartTicks(int32(os.Getpid()))
		if err != nil { t.Fatal("test process identity unavailable") }
		input, _ := json.Marshal(map[string]any{"action": action, "pid": os.Getpid(), "ticks": ticks})
		ctx, cancel := context.WithTimeout(context.Background(), 35*time.Second)
		defer cancel()
		cmd := exec.CommandContext(ctx, php, filepath.Join(root, "tests/Fixtures/gateway-kerberos-e2e.php"))
		cmd.Stdin = bytes.NewReader(input)
		body, err := cmd.Output()
		var result struct { Resource int64; Consumed bool }
		if err != nil || json.Unmarshal(body, &result) != nil { t.Fatal("private Laravel fixture failed") }
		return result
	}
	setup := fixture("setup")
	ctx, cancel := context.WithCancel(context.Background())
	socket := filepath.Join(dir, "worker.sock")
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()),
			pgIPCLaravelAuthorizer(php, filepath.Join(root, "artisan")),
			pgIPCLaravelCredentials(php, filepath.Join(root, "artisan")))
	}()
	defer func() {
		cancel()
		if err := <-done; err != nil { t.Error("trusted worker shutdown failed") }
	}()
	for i := 0; i < 100; i++ {
		c, err := net.Dial("unix", socket)
		if err == nil { c.Close(); break }
		time.Sleep(10 * time.Millisecond)
	}
	session := "SAG-KERBEROS-IPC-E2E"
	if _, err := pgIPCTakeCredentials(ctx, socket, session, setup.Resource, uint32(os.Getuid())); err == nil {
		t.Fatal("Windows bootstrap fell back to the stale SQL Vault slot")
	}
	if fixture("state").Consumed { t.Fatal("wrong typed grant consumed bootstrap") }
	material, err := pgIPCTakeKerberos(ctx, socket, session, setup.Resource, uint32(os.Getuid()))
	if err != nil || material.ProfileID != "isolated-ad" { t.Fatal("real Laravel FD3 Kerberos handoff failed") }
	keytab, err := base64.StdEncoding.DecodeString(material.KeytabB64)
	if err != nil || string(keytab) != "opaque-private-keytab-fixture" { t.Fatal("FD3 material mismatch") }
	if !fixture("state").Consumed { t.Fatal("bootstrap nonce not persisted") }
	if _, err := pgIPCTakeKerberos(ctx, socket, session, setup.Resource, uint32(os.Getuid())); err == nil {
		t.Fatal("Kerberos bootstrap replay accepted")
	}
	gate := pgIPCBoundSessionCheck(socket, session, setup.Resource, uint32(os.Getuid()))
	if gate(ctx) { t.Fatal("starting session authorized SQL traffic") }
	fixture("activate")
	if !gate(ctx) { t.Fatal("activated Kerberos process denied") }
	fixture("change_auth")
	if gate(ctx) { t.Fatal("changing backend auth kept the Windows process authorized") }
	fixture("revoke")
	if gate(ctx) { t.Fatal("revoked Windows process authorized") }
	t.Log("Real Laravel -> FD3 -> Unix IPC: typed one-time keytab grant and live invalidation passed")
}
