package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

// Requires an isolated PostgreSQL with admin password from test-only env,
// PHP with SQLite and installed Laravel dependencies. Never points at production.
func TestLaravelVaultIPCPostgresSQL(t *testing.T) {
	target := os.Getenv("SAG_E2E_POSTGRES")
	if target == "" {
		t.Skip("set SAG_E2E_POSTGRES for isolated PostgreSQL E2E")
	}
	php := os.Getenv("SAG_E2E_PHP")
	if php == "" {
		php = "php"
	}
	root, err := filepath.Abs("../control-plane")
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	if err = os.Chmod(dir, 0700); err != nil {
		t.Fatal(err)
	}
	random := make([]byte, 32)
	if _, err = rand.Read(random); err != nil {
		t.Fatal(err)
	}
	password := hex.EncodeToString(random)
	admin, err := pgBrokerBackendConnectWithMode(target, "disable", nil, "postgres", os.Getenv("SAG_E2E_ADMIN_PASSWORD"), "sag_e2e")
	if err != nil {
		t.Fatal("isolated postgres admin unavailable")
	}
	defer admin.Close()
	query := func(conn net.Conn, sql string) []byte {
		t.Helper()
		_ = conn.SetDeadline(time.Now().Add(8 * time.Second))
		if pgBrokerFrame(conn, 'Q', append([]byte(sql), 0)) != nil {
			t.Fatal("SQL write failed")
		}
		var result []byte
		for {
			kind, payload, _, e := readPgMessage(conn)
			if e != nil {
				t.Fatal("SQL response failed")
			}
			if kind == 'E' {
				t.Fatal("SQL execution failed")
			}
			if kind == 'D' {
				result = append(result, payload...)
			}
			if kind == 'Z' {
				return result
			}
		}
	}
	query(admin, "CREATE ROLE ipc_e2e_backend LOGIN PASSWORD '"+password+"'")
	defer query(admin, "DROP ROLE ipc_e2e_backend")
	if invalid, e := pgBrokerBackendConnectWithMode(target, "disable", nil, "ipc_e2e_backend", "invalid-fixture-password", "sag_e2e"); e == nil {
		invalid.Close()
		t.Fatal("isolated PostgreSQL must require password authentication")
	}

	env := append(os.Environ(), "APP_ENV=testing", "APP_DEBUG=false", "APP_KEY=base64:"+base64.StdEncoding.EncodeToString(random),
		"SAG_CE_CREDENTIAL_KEY=base64:"+base64.StdEncoding.EncodeToString(random), "DB_CONNECTION=sqlite", "DB_DATABASE="+filepath.Join(dir, "control.sqlite"),
		"CACHE_STORE=array", "SESSION_DRIVER=array", "LOG_CHANNEL=stderr", "SAG_INTERNAL_TOKEN=e2e-internal-only")
	if err = os.WriteFile(filepath.Join(dir, "control.sqlite"), nil, 0600); err != nil {
		t.Fatal(err)
	}
	fixture := func(input map[string]any) []byte {
		t.Helper()
		data, _ := json.Marshal(input)
		cmd := exec.Command(php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
		cmd.Env = env
		cmd.Stdin = bytes.NewReader(data)
		out, e := cmd.Output()
		if e != nil {
			t.Fatal("Laravel fixture failed")
		}
		return out
	}
	ticks, err := pgIPCPeerProcessStartTicks(int32(os.Getpid()))
	if err != nil {
		t.Fatal(err)
	}
	var setup struct {
		Resource  int64 `json:"resource"`
		Temporary struct {
			Username string `json:"username"`
			Password string `json:"password"`
		} `json:"temporary"`
	}
	if json.Unmarshal(fixture(map[string]any{"action": "setup", "password": password, "pid": os.Getpid(), "ticks": ticks}), &setup) != nil {
		t.Fatal("invalid fixture response")
	}
	// Launch the real HTTP control plane. Backend credentials never use HTTP.
	portListener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	address := portListener.Addr().String()
	portListener.Close()
	httpCmd := exec.Command(php, "-S", address, "-t", filepath.Join(root, "public"), filepath.Join(root, "public/index.php"))
	httpCmd.Env = env
	var httpOutput bytes.Buffer
	httpCmd.Stdout = &httpOutput
	httpCmd.Stderr = &httpOutput
	if httpCmd.Start() != nil {
		t.Fatal("HTTP control plane failed")
	}
	defer func() {
		httpCmd.Process.Kill()
		httpCmd.Wait()
		if strings.Contains(httpOutput.String(), password) {
			t.Error("backend password leaked to HTTP logs")
		}
	}()
	client := &http.Client{Timeout: 2 * time.Second}
	for i := 0; i < 100; i++ {
		resp, e := client.Get("http://" + address + "/api/health")
		if e == nil {
			resp.Body.Close()
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	// Real Unix server and real Laravel FD 3 credential command.
	socket := filepath.Join(dir, "vault.sock")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	// Environment belongs to this test process for inherited worker subprocesses.
	for _, key := range []string{"APP_ENV", "APP_DEBUG", "APP_KEY", "SAG_CE_CREDENTIAL_KEY", "DB_CONNECTION", "DB_DATABASE", "CACHE_STORE", "SESSION_DRIVER", "LOG_CHANNEL", "SAG_INTERNAL_TOKEN"} {
		for _, entry := range env {
			if strings.HasPrefix(entry, key+"=") {
				t.Setenv(key, strings.TrimPrefix(entry, key+"="))
			}
		}
	}
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()), pgIPCLaravelAuthorizer(php, filepath.Join(root, "artisan")), pgIPCLaravelCredentials(php, filepath.Join(root, "artisan")))
	}()
	defer func() {
		cancel()
		if e := <-done; e != nil {
			t.Error("IPC shutdown failed")
		}
	}()
	for i := 0; i < 100; i++ {
		if _, e := os.Stat(socket); e == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if _, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource+1, uint32(os.Getuid())); e == nil {
		t.Fatal("wrong resource accepted")
	}
	foreign := exec.Command(os.Args[0], "-test.run=^TestIPCForeignBootstrapProcess$")
	foreign.Env = append(os.Environ(), "SAG_E2E_FOREIGN_SOCKET="+socket, "SAG_E2E_FOREIGN_RESOURCE="+strconv.FormatInt(setup.Resource, 10))
	if foreign.Run() != nil {
		t.Fatal("foreign process binding check failed")
	}
	credentials, err := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))
	if err != nil {
		t.Fatal("real Vault IPC bootstrap failed")
	}
	if credentials.Password != password {
		t.Fatal("incorrect Vault credential")
	}
	if _, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid())); e == nil {
		t.Fatal("bootstrap replay accepted")
	}
	fixture(map[string]any{"action": "activate"})
	cert := testBrokerCertificate(t)
	pool := x509.NewCertPool()
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	pool.AddCert(parsed)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	brokerDone := make(chan error, 1)
	go func() {
		conn, e := listener.Accept()
		if e != nil {
			brokerDone <- e
			return
		}
		brokerDone <- pgBrokerServeAuthenticatedSession(ctx, conn, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, client, "http://"+address, "e2e-internal-only", "SAG-IPC-E2E", "sag_e2e", target, "disable", nil, func(context.Context) (PgBrokerBackendCredential, error) { return credentials, nil })
	}()
	front, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer front.Close()
	front.SetDeadline(time.Now().Add(10 * time.Second))
	ssl := make([]byte, 8)
	binary.BigEndian.PutUint32(ssl, 8)
	binary.BigEndian.PutUint32(ssl[4:], 80877103)
	front.Write(ssl)
	ack := make([]byte, 1)
	if _, err = io.ReadFull(front, ack); err != nil || ack[0] != 'S' {
		t.Fatal("TLS negotiation failed")
	}
	secure := tls.Client(front, &tls.Config{RootCAs: pool, ServerName: "localhost", MinVersion: tls.VersionTLS12})
	if secure.Handshake() != nil {
		t.Fatal("TLS verification failed")
	}
	secure.Write(pgTestStartup("user", setup.Temporary.Username, "database", "sag_e2e"))
	if kind, _, _, e := readPgMessage(secure); e != nil || kind != 'R' {
		t.Fatal("temporary auth challenge missing")
	}
	pgBrokerFrame(secure, 'p', append([]byte(setup.Temporary.Password), 0))
	for {
		kind, payload, _, e := readPgMessage(secure)
		if e != nil {
			t.Fatal("frontend authentication failed")
		}
		if bytes.Contains(payload, []byte(password)) {
			t.Fatal("backend secret leaked")
		}
		if kind == 'Z' {
			break
		}
	}
	result := query(secure, "SELECT 1")
	if len(result) != 7 || binary.BigEndian.Uint16(result[:2]) != 1 || binary.BigEndian.Uint32(result[2:6]) != 1 || result[6] != '1' {
		t.Fatal("SELECT 1 returned unexpected result")
	}
	t.Log("real Laravel Vault -> Unix IPC -> broker TLS -> PostgreSQL SCRAM: SELECT 1 = 1")
	fixture(map[string]any{"action": "revoke"})
	if pgIPCCheckSession(ctx, socket, "SAG-IPC-E2E", setup.Resource) {
		t.Fatal("revoked IPC session allowed")
	}
	secure.SetReadDeadline(time.Now().Add(7 * time.Second))
	var b [1]byte
	if _, e := secure.Read(b[:]); e == nil {
		t.Fatal("revoked connection remained open")
	}
	select {
	case <-brokerDone:
	case <-time.After(time.Second):
		t.Fatal("broker did not terminate revoked session")
	}
	t.Log("bootstrap replay, wrong resource, and live session revocation rejected")
}

func TestIPCForeignBootstrapProcess(t *testing.T) {
	socket := os.Getenv("SAG_E2E_FOREIGN_SOCKET")
	if socket == "" {
		t.Skip("subprocess helper")
	}
	resource, _ := strconv.ParseInt(os.Getenv("SAG_E2E_FOREIGN_RESOURCE"), 10, 64)
	if _, err := pgIPCTakeCredentials(context.Background(), socket, "SAG-IPC-E2E", resource, uint32(os.Getuid())); err == nil {
		t.Fatal("foreign process obtained credentials")
	}
}
