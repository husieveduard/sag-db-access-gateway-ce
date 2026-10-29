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
	"encoding/pem"
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
	if os.Getenv("SAG_E2E_REQUIRE_WORKER_PID1") == "1" && os.Getpid() != 1 {
		t.Fatal("PID-namespace regression must run its real worker as PID 1")
	}
	for _, scenario := range []string{"revoke", "expire", "deactivate", "replace_pid", "replace_ticks", "worker_lost", "managed_start", "managed_extended", "managed_extended_revoke", "managed_extended_worker_lost", "managed_extended_closed", "managed_extended_copy", "managed_idle_revoke", "managed_idle_worker_lost", "managed_pending_revoke", "managed_bad_secret", "managed_revoke_start"} {
		t.Run(scenario, func(t *testing.T) { postgresVaultSQLScenario(t, scenario) })
	}
}

func postgresVaultSQLScenario(t *testing.T, scenario string) {
	managed := strings.HasPrefix(scenario, "managed_")
	target := os.Getenv("SAG_E2E_POSTGRES")
	if target == "" {
		t.Skip("set SAG_E2E_POSTGRES for isolated PostgreSQL E2E")
	}
	managedBinary := os.Getenv("SAG_E2E_BROKER_BINARY")
	if managed && managedBinary == "" {
		t.Skip("set SAG_E2E_BROKER_BINARY for real managed launcher E2E")
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
	query(admin, "CREATE TABLE ipc_guard_probe(value integer)")
	defer query(admin, "DROP TABLE ipc_guard_probe")
	query(admin, "GRANT INSERT ON ipc_guard_probe TO ipc_e2e_backend")

	if invalid, e := pgBrokerBackendConnectWithMode(target, "disable", nil, "ipc_e2e_backend", "invalid-fixture-password", "sag_e2e"); e == nil {
		invalid.Close()
		t.Fatal("isolated PostgreSQL must require password authentication")
	}

	env := append(os.Environ(), "APP_ENV=testing", "APP_DEBUG=false", "BCRYPT_ROUNDS=4", "APP_KEY=base64:"+base64.StdEncoding.EncodeToString(random),
		"SAG_CE_CREDENTIAL_KEY=base64:"+base64.StdEncoding.EncodeToString(random), "DB_CONNECTION=sqlite", "DB_DATABASE="+filepath.Join(dir, "control.sqlite"),
		"CACHE_STORE=array", "SESSION_DRIVER=array", "LOG_CHANNEL=stderr", "SAG_INTERNAL_TOKEN=e2e-internal-only")
	if err = os.WriteFile(filepath.Join(dir, "control.sqlite"), nil, 0600); err != nil {
		t.Fatal(err)
	}
	fixture := func(input map[string]any) []byte {
		t.Helper()
		data, _ := json.Marshal(input)
		fixtureCtx, fixtureCancel := context.WithTimeout(context.Background(), 35*time.Second)
		defer fixtureCancel()
		cmd := exec.CommandContext(fixtureCtx, php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
		cmd.WaitDelay = 2 * time.Second
		cmd.Env = env
		cmd.Stdin = bytes.NewReader(data)
		out, e := cmd.Output()
		if e != nil {
			t.Fatal("Laravel fixture failed")
		}
		return out
	}
	var ticks string
	// Managed cases bind the separately launched broker, not this test worker.
	if !managed {
		ticks, err = pgIPCPeerProcessStartTicks(int32(os.Getpid()))
		if err != nil {
			t.Fatal(err)
		}
	}
	var setup struct {
		Resource  int64 `json:"resource"`
		Temporary struct {
			Username string `json:"username"`
			Password string `json:"password"`
		} `json:"temporary"`
	}
	vaultPassword := password
	if scenario == "managed_bad_secret" {
		vaultPassword = "incorrect-vault-fixture"
	}
	targetHost, targetPort, _ := net.SplitHostPort(target)
	targetPortNumber, _ := strconv.Atoi(targetPort)
	if json.Unmarshal(fixture(map[string]any{"action": "setup", "password": vaultPassword, "pid": os.Getpid(), "ticks": ticks, "managed": managed, "target_host": targetHost, "target_port": targetPortNumber}), &setup) != nil {
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
	client := &http.Client{Timeout: 5 * time.Second}
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
	provider := pgIPCLaravelCredentials(php, filepath.Join(root, "artisan"))
	if scenario == "managed_revoke_start" {
		original := provider
		provider = func(ctx context.Context, session string, resource int64) (pgIPCCredential, error) {
			credential, e := original(ctx, session, resource)
			if e == nil {
				stopCtx, stopCancel := context.WithTimeout(ctx, 2*time.Second)
				defer stopCancel()
				data, _ := json.Marshal(map[string]any{"action": "stop_requested"})
				command := exec.CommandContext(stopCtx, php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
				command.Env = env
				command.Stdin = bytes.NewReader(data)
				if stopErr := command.Run(); stopErr != nil {
					return pgIPCCredential{}, stopErr
				}
			}
			return credential, e
		}
	}
	workerCtx, stopWorker := context.WithCancel(ctx)
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(workerCtx, socket, uint32(os.Getuid()), pgIPCLaravelAuthorizer(php, filepath.Join(root, "artisan")), provider)
	}()
	defer func() {
		stopWorker()
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
	var credentials PgBrokerBackendCredential
	if !managed {
		if _, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource+1, uint32(os.Getuid())); e == nil {
			t.Fatal("wrong resource accepted")
		}
		foreign := exec.Command(os.Args[0], "-test.run=^TestIPCForeignBootstrapProcess$")
		foreign.Env = append(os.Environ(), "SAG_E2E_FOREIGN_SOCKET="+socket, "SAG_E2E_FOREIGN_RESOURCE="+strconv.FormatInt(setup.Resource, 10))
		if foreign.Run() != nil {
			t.Fatal("foreign process binding check failed")
		}
		credentials, err = pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))
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
		foreign = exec.Command(os.Args[0], "-test.run=^TestIPCForeignBootstrapProcess$")
		foreign.Env = append(os.Environ(), "SAG_E2E_FOREIGN_SOCKET="+socket, "SAG_E2E_FOREIGN_RESOURCE="+strconv.FormatInt(setup.Resource, 10), "SAG_E2E_FOREIGN_ACTIVE=1")
		if foreign.Run() != nil {
			t.Fatal("foreign process passed live authorization")
		}

	}

	cert := testBrokerCertificate(t)
	pool := x509.NewCertPool()
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	pool.AddCert(parsed)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	brokerAddress := listener.Addr().String()
	var brokerDone chan error
	var launcher map[string]any
	if managed {
		listener.Close()
		certPath, keyPath := filepath.Join(dir, "server.crt"), filepath.Join(dir, "server.key")
		keyDER, e := x509.MarshalPKCS8PrivateKey(cert.PrivateKey)
		if e != nil {
			t.Fatal(e)
		}
		if os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Certificate[0]}), 0600) != nil || os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), 0600) != nil {
			t.Fatal("TLS fixture failed")
		}
		_, port, _ := net.SplitHostPort(brokerAddress)
		portNumber, _ := strconv.Atoi(port)
		launcher = map[string]any{"binary": managedBinary, "socket": socket, "cert": certPath, "key": keyPath, "worker_uid": os.Getuid(), "log_dir": filepath.Join(dir, "logs"), "control": "http://" + address, "port": portNumber}
		defer func() {
			logs, _ := filepath.Glob(filepath.Join(dir, "logs", "managed-lab", "*.log"))
			for _, path := range logs {
				body, _ := os.ReadFile(path)
				for _, secret := range []string{password, vaultPassword, "e2e-internal-only"} {
					if bytes.Contains(body, []byte(secret)) {
						t.Error("managed log disclosed secret")
					}
				}
			}
		}()
		defer fixture(map[string]any{"action": "terminate", "launcher": launcher})
		var result struct {
			Error     string `json:"error"`
			Reason    string `json:"reason"`
			Failed    bool   `json:"failed"`
			Status    string `json:"status"`
			PID       int    `json:"gateway_pid"`
			FailedPID int    `json:"pid"`
			Consumed  bool   `json:"bootstrap_consumed"`
		}
		if json.Unmarshal(fixture(map[string]any{"action": "launch", "launcher": launcher}), &result) != nil {
			t.Fatal("invalid launcher response")
		}
		if scenario == "managed_bad_secret" || scenario == "managed_revoke_start" {
			expected := "failed"
			if scenario == "managed_revoke_start" {
				expected = "terminated"
			}
			if !result.Failed || result.Status != expected || result.FailedPID < 2 || !result.Consumed {
				t.Fatalf("failed launch state mismatch: %+v", result)
			}
			if _, e := pgIPCPeerProcessStartTicks(int32(result.FailedPID)); e == nil {
				t.Fatal("failed broker process remained alive")
			}
			if conn, e := net.DialTimeout("tcp", brokerAddress, time.Second); e == nil {
				conn.Close()
				t.Fatal("failed broker kept listener")
			}
			rows := query(admin, "SELECT count(*)::text FROM pg_stat_activity WHERE usename='ipc_e2e_backend'")
			if len(rows) != 7 || rows[6] != '0' {
				t.Fatal("failed launch leaked backend connection")
			}
			t.Log("rejected launch left state " + expected + " and child stopped: " + scenario)
			return
		}
		if result.Failed || result.Status != "started" || result.PID < 2 || !result.Consumed {
			t.Fatalf("real managed Laravel launch failed: %+v", result)
		}
		processEnv, e := os.ReadFile("/proc/" + strconv.Itoa(result.PID) + "/environ")
		if e != nil {
			t.Fatal("broker environment unavailable")
		}
		for _, forbidden := range []string{"SAG_CE_CREDENTIAL_KEY=", "APP_KEY=", "DB_PASSWORD=", "SAG_INTERNAL_TOKEN="} {
			if bytes.Contains(processEnv, []byte(forbidden)) {
				t.Fatal("broker inherited privileged control-plane environment")
			}
		}
		descriptors, _ := os.ReadDir("/proc/" + strconv.Itoa(result.PID) + "/fd")
		for _, fd := range descriptors {
			link, _ := os.Readlink("/proc/" + strconv.Itoa(result.PID) + "/fd/" + fd.Name())
			if strings.Contains(link, "control.sqlite") {
				t.Fatal("broker inherited control-plane database descriptor")
			}
		}
		t.Log("Laravel launcher recorded process identity, bootstrapped Vault, authenticated backend and activated session")
		if strings.HasPrefix(scenario, "managed_idle_") || scenario == "managed_pending_revoke" {
			assertIdleManagedBrokerStops(t, result.PID, brokerAddress, scenario == "managed_pending_revoke", func() {
				if scenario == "managed_idle_worker_lost" {
					stopWorker()
				} else {
					fixture(map[string]any{"action": "revoke"})
				}
			})
			rows := query(admin, "SELECT count(*)::text FROM pg_stat_activity WHERE usename='ipc_e2e_backend'")
			if len(rows) != 7 || rows[6] != '0' {
				t.Fatal("idle invalidation retained a backend connection")
			}
			return
		}

		if strings.HasPrefix(scenario, "managed_extended") {
			independent := pgIndependentClient(t, brokerAddress, setup.Temporary.Username, setup.Temporary.Password, &tls.Config{RootCAs: pool, ServerName: "localhost", MinVersion: tls.VersionTLS12})
			defer independent.Close(context.Background())
			pgExerciseExtendedClient(t, independent)
			rows := query(admin, "SELECT count(*)::text FROM ipc_guard_probe")
			if len(rows) != 7 || rows[6] != '1' {
				t.Fatal("extended write did not reach real PostgreSQL")
			}
			query(admin, "DELETE FROM ipc_guard_probe")
			testCtx, stopTest := context.WithTimeout(context.Background(), 10*time.Second)
			defer stopTest()
			if _, e := independent.Prepare(testCtx, "guard_write", "INSERT INTO ipc_guard_probe VALUES ($1)"); e != nil {
				t.Fatal("guard prepared statement failed")
			}
			if scenario == "managed_extended" {
				independent.Close(testCtx)
			} else {
				switch scenario {
				case "managed_extended_revoke":
					fixture(map[string]any{"action": "revoke"})
				case "managed_extended_worker_lost":
					stopWorker()
					if !checkBrokerSessionActive(ctx, client, "http://"+address, "e2e-internal-only", "SAG-IPC-E2E") {
						t.Fatal("worker loss revoked HTTP gate")
					}
				case "managed_extended_closed":
					if independent.Deallocate(testCtx, "guard_write") != nil {
						t.Fatal("Close before reuse failed")
					}
				case "managed_extended_copy":
				}
				var executeErr error
				if scenario == "managed_extended_copy" {
					result := independent.PgConn().ExecParams(testCtx, "COPY (SELECT 1) TO STDOUT", nil, nil, nil, nil).Read()
					executeErr = result.Err
					if len(result.Rows) != 0 {
						t.Fatal("COPY data reached frontend")
					}
				} else {
					executeErr = independent.PgConn().ExecPrepared(testCtx, "guard_write", [][]byte{[]byte("99")}, []int16{0}, nil).Read().Err
				}
				if executeErr == nil {
					t.Fatal("revoked/closed/COPY extended operation was accepted")
				}
				if scenario == "managed_extended_revoke" || scenario == "managed_extended_worker_lost" {
					assertManagedBrokerExited(t, result.PID, brokerAddress)
				}
				rows := query(admin, "SELECT count(*)::text FROM ipc_guard_probe")
				if len(rows) != 7 || rows[6] != '0' {
					t.Fatal("denied extended write reached PostgreSQL")
				}
				stopped := false
				for i := 0; i < 50; i++ {
					rows = query(admin, "SELECT count(*)::text FROM pg_stat_activity WHERE usename='ipc_e2e_backend'")
					if len(rows) == 7 && rows[6] == '0' {
						stopped = true
						break
					}
					time.Sleep(20 * time.Millisecond)
				}
				if !stopped {
					t.Fatal("extended failure retained backend connection")
				}
				t.Log("extended invalidation/closed handle/COPY denied without backend session leak")

				return
			}
		}

	} else {
		brokerDone = make(chan error, 1)
		go func() {
			conn, e := listener.Accept()
			if e != nil {
				brokerDone <- e
				return
			}
			brokerDone <- pgBrokerServeAuthenticatedSession(ctx, conn, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, client, "http://"+address, "e2e-internal-only", "SAG-IPC-E2E", "sag_e2e", target, "disable", nil, func(context.Context) (PgBrokerBackendCredential, error) { return credentials, nil }, pgIPCBoundSessionCheck(socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid())))
		}()
	}
	front, err := net.Dial("tcp", brokerAddress)
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
			select {
			case brokerErr := <-brokerDone:
				t.Logf("broker startup result: %v", brokerErr)
			default:
			}
			t.Fatalf("frontend authentication failed: %v", e)
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
	if managed {
		var ended struct {
			Status  string `json:"status"`
			Gateway struct {
				Stopped bool `json:"stopped"`
			} `json:"gateway"`
		}
		if json.Unmarshal(fixture(map[string]any{"action": "terminate", "launcher": launcher}), &ended) != nil || ended.Status != "terminated" || !ended.Gateway.Stopped {
			t.Fatal("managed lifecycle termination failed")
		}
		if conn, e := net.DialTimeout("tcp", brokerAddress, time.Second); e == nil {
			conn.Close()
			t.Fatal("terminated broker still accepted connections")
		}
	} else if scenario == "worker_lost" {
		stopWorker()
	} else {
		fixture(map[string]any{"action": scenario})
	}
	// Worker loss must deny even while the independent HTTP gate still allows.
	if scenario == "worker_lost" && !checkBrokerSessionActive(ctx, client, "http://"+address, "e2e-internal-only", "SAG-IPC-E2E") {
		t.Fatal("HTTP gate unexpectedly denied healthy session")
	}
	// Give worker shutdown time to close the listener without cancelling broker ctx.
	if scenario == "worker_lost" {
		for i := 0; i < 100; i++ {
			if _, e := os.Stat(socket); os.IsNotExist(e) {
				break
			}
			time.Sleep(10 * time.Millisecond)
		}
	}
	if pgIPCBoundSessionCheck(socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid()))(ctx) {
		t.Fatal("invalidated local session allowed")
	}

	// A query queued after invalidation must not reach PostgreSQL.
	if scenario == "expire" || scenario == "deactivate" || scenario == "replace_ticks" {
		_ = pgBrokerFrame(secure, 'Q', append([]byte("INSERT INTO ipc_guard_probe VALUES (1)"), 0))
	}
	// The other cases send no new query, exercising the periodic idle guard.
	secure.SetReadDeadline(time.Now().Add(7 * time.Second))
	var b [1]byte
	if _, e := secure.Read(b[:]); e == nil {
		t.Fatal("revoked connection remained open")
	}
	if !managed {
		select {
		case <-brokerDone:
		case <-time.After(time.Second):
			t.Fatal("broker did not terminate revoked session")
		}
	}
	if _, e := pgIPCTakeCredentials(ctx, socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid())); e == nil {
		t.Fatal("invalidated session issued credentials")
	}
	writes := query(admin, "SELECT count(*)::text FROM ipc_guard_probe")
	if len(writes) != 7 || writes[6] != '0' {
		t.Fatal("post-invalidation SQL was forwarded")
	}

	// Server-side SQL connection must be gone as well, not merely the TLS client.
	for i := 0; i < 50; i++ {
		rows := query(admin, "SELECT count(*)::text FROM pg_stat_activity WHERE usename='ipc_e2e_backend'")
		if len(rows) == 7 && rows[6] == '0' {
			t.Log("both SQL endpoints closed after " + scenario)
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("backend connection remained open after invalidation")
}

func TestIPCForeignBootstrapProcess(t *testing.T) {
	socket := os.Getenv("SAG_E2E_FOREIGN_SOCKET")
	if socket == "" {
		t.Skip("subprocess helper")
	}
	resource, _ := strconv.ParseInt(os.Getenv("SAG_E2E_FOREIGN_RESOURCE"), 10, 64)
	if os.Getenv("SAG_E2E_FOREIGN_ACTIVE") == "1" {
		if pgIPCBoundSessionCheck(socket, "SAG-IPC-E2E", resource, uint32(os.Getuid()))(context.Background()) {
			t.Fatal("foreign process passed live gate")
		}
		return
	}

	if _, err := pgIPCTakeCredentials(context.Background(), socket, "SAG-IPC-E2E", resource, uint32(os.Getuid())); err == nil {
		t.Fatal("foreign process obtained credentials")
	}
}
