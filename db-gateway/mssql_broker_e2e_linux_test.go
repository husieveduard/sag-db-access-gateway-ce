package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
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

func tdsTestQuery(conn any, text string) ([][]string, error) {
	if db, ok := conn.(*sql.DB); ok {
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		rows, e := db.QueryContext(ctx, text)
		if e != nil {
			return nil, errTDSProtocol
		}
		defer rows.Close()
		cols, e := rows.Columns()
		if e != nil {
			return nil, errTDSProtocol
		}
		var result [][]string
		for rows.Next() {
			values := make([]sql.NullString, len(cols))
			dest := make([]any, len(cols))
			for i := range values {
				dest[i] = &values[i]
			}
			if rows.Scan(dest...) != nil {
				return nil, errTDSProtocol
			}
			var row []string
			for _, v := range values {
				row = append(row, v.String)
			}
			result = append(result, row)
		}
		if rows.Err() != nil {
			return nil, errTDSProtocol
		}
		return result, nil
	}
	c := conn.(net.Conn)
	c.SetDeadline(time.Now().Add(10 * time.Second))
	if tdsWrite(c, 1, tdsTestBatch(text)) != nil {
		return nil, errTDSProtocol
	}
	p, e := tdsRead(c, 4, 64<<20)
	if e != nil {
		return nil, e
	}
	// Native probe is deliberately only SELECT 1; independent client validates
	// general typed rows and affected-row results from the real server.
	if text != "SELECT 1" || len(p) < 29 {
		return nil, errTDSProtocol
	}
	for i := 0; i+5 <= len(p); i++ {
		if p[i] == 0xd1 && binary.LittleEndian.Uint32(p[i+1:]) == 1 {
			return [][]string{{"1"}}, nil
		}
	}
	return nil, errTDSProtocol
}
func TestMSSQLVaultSQL(t *testing.T) {
	target := os.Getenv("SAG_E2E_MSSQL_TARGET")
	if target == "" {
		t.Skip("set isolated SQL Server endpoint")
	}
	for _, scenario := range []string{"revoke", "expire", "deactivate", "replace_pid", "replace_ticks", "worker_lost", "unsupported_command", "bad_secret", "startup_revoke"} {
		t.Run(scenario, func(t *testing.T) { tdsVaultScenario(t, "mssql", target, scenario) })
	}
}
func tdsVaultScenario(t *testing.T, engine, target, scenario string) {
	binary := os.Getenv("SAG_E2E_MSSQL_BROKER_BINARY")
	if binary == "" {
		t.Fatal("native MSSQL lab binary required")
	}
	caPath := os.Getenv("SAG_E2E_MSSQL_CA")
	roots := x509.NewCertPool()
	ca, e := os.ReadFile(caPath)
	if e != nil || !roots.AppendCertsFromPEM(ca) {
		t.Fatal("test backend CA unavailable")
	}
	backendTLS := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}
	admin := tdsTestClient(t, target, "sa", "Isolated_Mssql_Admin_Only_42!", "master", backendTLS)
	defer func() { admin.Close() }()
	ready := false
	for i := 0; i < 120; i++ {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		e := admin.PingContext(ctx)
		cancel()
		if e == nil {
			ready = true
			break
		}
		time.Sleep(500 * time.Millisecond)
	}
	if !ready {
		t.Fatal("isolated verified-TLS SQL Server unavailable")
	}
	// Dedicated database exists only inside this disposable Developer container.
	query := func(conn any, text string) [][]string {
		t.Helper()
		rows, e := tdsTestQuery(conn, text)
		if e != nil {
			t.Fatal("native SQL failed")
		}
		return rows
	}
	query(admin, "IF DB_ID('sag_mssql_e2e') IS NULL CREATE DATABASE sag_mssql_e2e")
	admin.Close()
	admin = tdsTestClient(t, target, "sa", "Isolated_Mssql_Admin_Only_42!", "sag_mssql_e2e", backendTLS)
	secret := make([]byte, 32)
	rand.Read(secret)
	password := "SaG_42!" + hex.EncodeToString(secret)
	query(admin, "CREATE LOGIN sag_e2e_backend WITH PASSWORD='"+password+"', CHECK_POLICY=OFF")
	defer query(admin, "DROP LOGIN sag_e2e_backend")
	query(admin, "CREATE USER sag_e2e_backend FOR LOGIN sag_e2e_backend")
	defer query(admin, "DROP USER sag_e2e_backend")
	query(admin, "CREATE TABLE guard_probe(value INTEGER)")
	defer query(admin, "DROP TABLE guard_probe")
	query(admin, "GRANT SELECT,INSERT ON guard_probe TO sag_e2e_backend")
	backendProbe, err := tdsBackendConnect(context.Background(), target, backendTLS, "sag_e2e_backend", password, "sag_mssql_e2e")
	if err != nil {
		t.Fatalf("native backend authentication failed: %v", err)
	}
	backendProbe.Close()
	if conn, e := tdsBackendConnect(context.Background(), target, backendTLS, "sag_e2e_backend", "wrong-password", "sag_mssql_e2e"); e == nil {
		conn.Close()
		t.Fatal("wrong backend password accepted")
	}
	// An unrelated frontend CA must never authenticate the backend server.
	cert := testBrokerCertificate(t)
	wrongRoots := x509.NewCertPool()
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	wrongRoots.AddCert(parsed)
	if conn, e := tdsBackendConnect(context.Background(), target, &tls.Config{RootCAs: wrongRoots}, "sag_e2e_backend", password, "sag_mssql_e2e"); e == nil {
		conn.Close()
		t.Fatal("untrusted backend TLS accepted")
	}
	root, _ := filepath.Abs("../control-plane")
	dir := t.TempDir()
	os.Chmod(dir, 0700)
	dbPath := filepath.Join(dir, "control.sqlite")
	os.WriteFile(dbPath, nil, 0600)
	php := os.Getenv("SAG_E2E_PHP")
	if php == "" {
		php = "php"
	}
	env := append(os.Environ(), "APP_ENV=testing", "APP_DEBUG=false", "BCRYPT_ROUNDS=4", "APP_KEY=base64:"+base64.StdEncoding.EncodeToString(secret), "SAG_CE_CREDENTIAL_KEY=base64:"+base64.StdEncoding.EncodeToString(secret), "DB_CONNECTION=sqlite", "DB_DATABASE="+dbPath, "CACHE_STORE=array", "SESSION_DRIVER=array", "LOG_CHANNEL=stderr", "SAG_INTERNAL_TOKEN=mssql-internal-only")
	fixture := func(input map[string]any) []byte {
		t.Helper()
		data, _ := json.Marshal(input)
		ctx, cancel := context.WithTimeout(context.Background(), 35*time.Second)
		defer cancel()
		cmd := exec.CommandContext(ctx, php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
		cmd.Env = env
		cmd.Stdin = bytes.NewReader(data)
		cmd.WaitDelay = 2 * time.Second
		out, e := cmd.Output()
		if e != nil {
			t.Fatal("Laravel native MSSQL fixture failed")
		}
		return out
	}
	host, port, _ := net.SplitHostPort(target)
	portNumber, _ := strconv.Atoi(port)
	vaultPassword := password
	if scenario == "bad_secret" {
		vaultPassword = "incorrect-vault-fixture"
	}
	var setup struct {
		Resource  int64 `json:"resource"`
		Temporary struct {
			Username string `json:"username"`
			Password string `json:"password"`
		} `json:"temporary"`
	}
	if json.Unmarshal(fixture(map[string]any{"action": "setup", "password": vaultPassword, "backend_username": "sag_e2e_backend", "managed": true, "engine": engine, "database": "sag_mssql_e2e", "tls_mode": "verify-full", "target_host": host, "target_port": portNumber}), &setup) != nil {
		t.Fatal("invalid fixture")
	}
	for _, entry := range env {
		parts := strings.SplitN(entry, "=", 2)
		switch parts[0] {
		case "APP_ENV", "APP_DEBUG", "APP_KEY", "SAG_CE_CREDENTIAL_KEY", "DB_CONNECTION", "DB_DATABASE", "CACHE_STORE", "SESSION_DRIVER", "LOG_CHANNEL", "SAG_INTERNAL_TOKEN":
			t.Setenv(parts[0], parts[1])
		}
	}
	probe, _ := net.Listen("tcp", "127.0.0.1:0")
	controlAddress := probe.Addr().String()
	probe.Close()
	httpCmd := exec.Command(php, "-S", controlAddress, "-t", filepath.Join(root, "public"), filepath.Join(root, "public/index.php"))
	httpCmd.Env = env
	var httpLog bytes.Buffer
	httpCmd.Stdout = &httpLog
	httpCmd.Stderr = &httpLog
	if httpCmd.Start() != nil {
		t.Fatal("control server failed")
	}
	defer func() {
		httpCmd.Process.Kill()
		httpCmd.Wait()
		if bytes.Contains(httpLog.Bytes(), []byte(password)) {
			t.Error("HTTP disclosed backend password")
		}
	}()
	for i := 0; i < 100; i++ {
		resp, e := http.Get("http://" + controlAddress + "/api/health")
		if e == nil {
			resp.Body.Close()
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	socket := filepath.Join(dir, "vault.sock")
	workerCtx, stopWorker := context.WithCancel(context.Background())
	provider := pgIPCLaravelCredentials(php, filepath.Join(root, "artisan"))
	if scenario == "startup_revoke" {
		original := provider
		provider = func(ctx context.Context, uid string, id int64) (pgIPCCredential, error) {
			c, e := original(ctx, uid, id)
			if e != nil {
				return c, e
			}
			data, _ := json.Marshal(map[string]any{"action": "stop_requested"})
			cmd := exec.CommandContext(ctx, php, filepath.Join(root, "tests/Fixtures/gateway-vault-e2e.php"))
			cmd.Env = env
			cmd.Stdin = bytes.NewReader(data)
			if e = cmd.Run(); e != nil {
				return pgIPCCredential{}, e
			}
			return c, nil
		}
	}
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(workerCtx, socket, uint32(os.Getuid()), pgIPCLaravelAuthorizer(php, filepath.Join(root, "artisan")), provider)
	}()
	defer func() {
		stopWorker()
		if e := <-done; e != nil {
			t.Error("worker shutdown failed")
		}
	}()
	for i := 0; i < 100; i++ {
		c, e := net.Dial("unix", socket)
		if e == nil {
			c.Close()
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	certPath, keyPath := filepath.Join(dir, "server.crt"), filepath.Join(dir, "server.key")
	keyDER, _ := x509.MarshalPKCS8PrivateKey(cert.PrivateKey)
	os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Certificate[0]}), 0600)
	os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), 0600)
	probe, _ = net.Listen("tcp", "127.0.0.1:0")
	frontAddress := probe.Addr().String()
	probe.Close()
	_, frontPort, _ := net.SplitHostPort(frontAddress)
	frontPortNumber, _ := strconv.Atoi(frontPort)
	launcher := map[string]any{"binary": binary, "socket": socket, "cert": certPath, "key": keyPath, "worker_uid": os.Getuid(), "log_dir": filepath.Join(dir, "logs"), "control": "http://" + controlAddress, "port": frontPortNumber, "backend_ca": caPath}
	defer func() {
		logs, _ := filepath.Glob(filepath.Join(dir, "logs", "managed-lab", "*.log"))
		for _, path := range logs {
			body, _ := os.ReadFile(path)
			if bytes.Contains(body, []byte(password)) || bytes.Contains(body, []byte(vaultPassword)) {
				t.Error("broker log disclosed backend password")
			}
		}
	}()
	defer fixture(map[string]any{"action": "terminate", "launcher": launcher})
	var launch struct {
		Failed    bool   `json:"failed"`
		Status    string `json:"status"`
		Reason    string `json:"reason"`
		Error     string `json:"error"`
		PID       int    `json:"gateway_pid"`
		FailedPID int    `json:"pid"`
		Consumed  bool   `json:"bootstrap_consumed"`
	}
	json.Unmarshal(fixture(map[string]any{"action": "launch", "launcher": launcher}), &launch)
	if scenario == "bad_secret" || scenario == "startup_revoke" {
		expected := "failed"
		if scenario == "startup_revoke" {
			expected = "terminated"
		}
		if !launch.Failed || launch.Status != expected || !launch.Consumed || launch.FailedPID < 2 {
			t.Fatalf("rejected launch state mismatch: %+v", launch)
		}
		if _, e := pgIPCPeerProcessStartTicks(int32(launch.FailedPID)); e == nil {
			t.Fatal("failed child remained alive")
		}
		if conn, e := net.DialTimeout("tcp", frontAddress, time.Second); e == nil {
			conn.Close()
			t.Fatal("rejected launch kept listener")
		}
		stopped := false
		for i := 0; i < 50; i++ {
			rows := query(admin, "SELECT count(*) FROM sys.dm_exec_sessions WHERE login_name='sag_e2e_backend'")
			if len(rows) == 1 && rows[0][0] == "0" {
				stopped = true
				break
			}
			time.Sleep(20 * time.Millisecond)
		}
		if !stopped {
			t.Fatal("rejected launch retained backend session")
		}
		t.Log("native launch rejected and child stopped: " + scenario)
		return
	}
	if launch.Failed || launch.Status != "started" || !launch.Consumed || launch.PID < 2 {
		t.Fatalf("native managed launch failed: %+v", launch)
	}
	processEnv, e := os.ReadFile("/proc/" + strconv.Itoa(launch.PID) + "/environ")
	if e != nil {
		t.Fatal("child env unavailable")
	}
	for _, key := range []string{"DB_PASSWORD=", "APP_KEY=", "SAG_CE_CREDENTIAL_KEY=", "SAG_INTERNAL_TOKEN="} {
		if bytes.Contains(processEnv, []byte(key)) {
			t.Fatal("privileged environment inherited")
		}
	}
	descriptors, _ := os.ReadDir("/proc/" + strconv.Itoa(launch.PID) + "/fd")
	for _, fd := range descriptors {
		link, _ := os.Readlink("/proc/" + strconv.Itoa(launch.PID) + "/fd/" + fd.Name())
		if strings.Contains(link, "control.sqlite") {
			t.Fatal("broker inherited control-plane database FD")
		}
	}
	args, _ := os.ReadFile("/proc/" + strconv.Itoa(launch.PID) + "/cmdline")
	if bytes.Contains(args, []byte(password)) {
		t.Fatal("backend password leaked in argv")
	}
	if _, e := pgIPCTakeCredentials(context.Background(), socket, "SAG-IPC-E2E", setup.Resource, uint32(os.Getuid())); e == nil {
		t.Fatal("unrelated process replayed bootstrap")
	}
	frontendTLS := &tls.Config{RootCAs: wrongRoots, ServerName: "localhost", MinVersion: tls.VersionTLS12}
	if conn, e := tdsBackendConnect(context.Background(), frontAddress, frontendTLS, setup.Temporary.Username, "wrong-temporary-password", "sag_mssql_e2e"); e == nil {
		conn.Close()
		t.Fatal("wrong temporary password accepted")
	}
	raw, e := net.Dial("tcp", frontAddress)
	if e != nil {
		t.Fatal(e)
	}
	tdsWrite(raw, 18, tdsPrelogin(0))
	raw.SetReadDeadline(time.Now().Add(time.Second))
	var one [1]byte
	if _, e = raw.Read(one[:]); e == nil {
		t.Fatal("plaintext frontend accepted")
	}
	raw.Close()
	independent := tdsTestClient(t, frontAddress, setup.Temporary.Username, setup.Temporary.Password, "sag_mssql_e2e", frontendTLS)
	var oneValue int
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	e = independent.QueryRowContext(ctx, "SELECT 1").Scan(&oneValue)
	if e == nil {
		var nullValue sql.NullString
		var word string
		var number int
		if independent.QueryRowContext(ctx, "SELECT CAST(NULL AS NVARCHAR(10)), N'π', 42").Scan(&nullValue, &word, &number) != nil || nullValue.Valid || word != "π" || number != 42 {
			cancel()
			independent.Close()
			t.Fatal("independent typed rows mismatch")
		}
		result, err := independent.ExecContext(ctx, "INSERT INTO guard_probe VALUES (7)")
		if err != nil {
			cancel()
			independent.Close()
			t.Fatal("independent SQLBatch write failed")
		}
		affected, _ := result.RowsAffected()
		if affected != 1 {
			cancel()
			independent.Close()
			t.Fatal("incorrect affected row count")
		}
		if rows := query(admin, "SELECT count(*) FROM guard_probe"); len(rows) != 1 || rows[0][0] != "1" {
			cancel()
			independent.Close()
			t.Fatal("allowed write did not reach real database")
		}
		query(admin, "DELETE FROM guard_probe")
	}
	cancel()
	independent.Close()
	if e != nil || oneValue != 1 {
		t.Fatal("independent Microsoft client SELECT 1 failed")
	}
	front, e := tdsBackendConnect(context.Background(), frontAddress, frontendTLS, setup.Temporary.Username, setup.Temporary.Password, "sag_mssql_e2e")
	if e != nil {
		t.Fatal("temporary MSSQL frontend login failed")
	}
	defer front.Close()
	rows := query(front, "SELECT 1")
	if len(rows) != 1 || len(rows[0]) != 1 || rows[0][0] != "1" {
		t.Fatal("SELECT 1 mismatch")
	}
	t.Log("MSSQL: Vault -> native TDS broker -> verified TLS SQL SELECT 1 = 1")
	if scenario == "unsupported_command" {
		tdsWrite(front, 3, []byte{0, 0, 0, 0})
	} else if scenario == "worker_lost" {
		stopWorker()
		if !checkBrokerEngineSessionActive(context.Background(), nil, "http://"+controlAddress, "mssql-internal-only", "SAG-IPC-E2E", engine) {
			t.Fatal("worker-loss test accidentally revoked HTTP")
		}
	} else {
		fixture(map[string]any{"action": scenario})
	}
	if scenario == "expire" || scenario == "deactivate" || scenario == "replace_ticks" || scenario == "replace_pid" {
		tdsWrite(front, 1, tdsTestBatch("INSERT INTO guard_probe VALUES (1)"))
	}
	front.SetReadDeadline(time.Now().Add(7 * time.Second))
	if _, e = front.Read(one[:]); e == nil {
		t.Fatal("invalidated connection remained open")
	}
	rows = query(admin, "SELECT count(*) FROM guard_probe")
	if len(rows) != 1 || rows[0][0] != "0" {
		t.Fatal("unauthorized SQL was forwarded")
	}
	for i := 0; i < 50; i++ {
		rows = query(admin, "SELECT count(*) FROM sys.dm_exec_sessions WHERE login_name='sag_e2e_backend'")
		if len(rows) == 1 && rows[0][0] == "0" {
			t.Log("both native SQL endpoints closed: " + scenario)
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("backend session remained open")
}
