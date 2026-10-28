package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	mysqlclient "github.com/go-sql-driver/mysql"
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

func myTestQuery(conn net.Conn, sql string) ([][]string, error) {
	conn.SetDeadline(time.Now().Add(10 * time.Second))
	seq := byte(0)
	if myWrite(conn, &seq, append([]byte{3}, []byte(sql)...)) != nil {
		return nil, errMyProtocol
	}
	var out bytes.Buffer
	if myRelayResult(conn, &out) != nil {
		return nil, errMyProtocol
	}
	seq = 1
	p, e := myRead(&out, &seq)
	if e != nil || p[0] == 0xff {
		return nil, errMyProtocol
	}
	if p[0] == 0 {
		return nil, nil
	}
	n, _, e := myLength(p)
	if e != nil {
		return nil, e
	}
	for i := uint64(0); i < n; i++ {
		if _, e = myRead(&out, &seq); e != nil {
			return nil, e
		}
	}
	if _, e = myRead(&out, &seq); e != nil {
		return nil, e
	}
	var rows [][]string
	for {
		p, e = myRead(&out, &seq)
		if e != nil {
			return nil, e
		}
		if p[0] == 0xfe && len(p) < 9 {
			return rows, nil
		}
		var row []string
		for i := uint64(0); i < n; i++ {
			k, m, e := myLength(p)
			if e != nil || k > uint64(len(p)-m) {
				return nil, errMyProtocol
			}
			row = append(row, string(p[m:m+int(k)]))
			p = p[m+int(k):]
		}
		rows = append(rows, row)
	}
}
func TestMySQLMariaDBVaultSQL(t *testing.T) {
	for _, engine := range []string{"mysql", "mariadb"} {
		t.Run(engine, func(t *testing.T) {
			target := os.Getenv("SAG_E2E_" + strings.ToUpper(engine) + "_TARGET")
			if target == "" {
				t.Skip("set isolated MySQL/MariaDB endpoint")
			}
			for _, scenario := range []string{"revoke", "expire", "deactivate", "replace_ticks", "worker_lost", "unsupported_command", "bad_secret", "startup_revoke"} {
				t.Run(scenario, func(t *testing.T) { myVaultScenario(t, engine, target, scenario) })
			}
		})
	}
}
func myVaultScenario(t *testing.T, engine, target, scenario string) {
	binary := os.Getenv("SAG_E2E_MYSQL_BROKER_BINARY")
	if binary == "" {
		t.Fatal("native MySQL lab binary required")
	}
	caPath := os.Getenv("SAG_E2E_MYSQL_CA")
	roots := x509.NewCertPool()
	ca, e := os.ReadFile(caPath)
	if e != nil || !roots.AppendCertsFromPEM(ca) {
		t.Fatal("test backend CA unavailable")
	}
	backendTLS := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}
	var admin net.Conn
	for i := 0; i < 120; i++ {
		admin, e = myBackendConnect(target, backendTLS, "root", "isolated_mysql_admin_only", "sag_mysql_e2e")
		if e == nil {
			break
		}
		time.Sleep(500 * time.Millisecond)
	}
	if e != nil {
		t.Fatal("isolated verified-TLS database unavailable")
	}
	defer admin.Close()
	query := func(conn net.Conn, sql string) [][]string {
		t.Helper()
		rows, e := myTestQuery(conn, sql)
		if e != nil {
			t.Fatal("native SQL failed")
		}
		return rows
	}
	secret := make([]byte, 32)
	rand.Read(secret)
	password := hex.EncodeToString(secret)
	query(admin, "CREATE USER 'sag_e2e_backend'@'%' IDENTIFIED BY '"+password+"' REQUIRE SSL")
	defer query(admin, "DROP USER 'sag_e2e_backend'@'%'")
	query(admin, "CREATE TABLE guard_probe(value INTEGER)")
	defer query(admin, "DROP TABLE guard_probe")
	query(admin, "GRANT SELECT,INSERT ON sag_mysql_e2e.* TO 'sag_e2e_backend'@'%'")
	plugin := query(admin, "SELECT plugin FROM mysql.user WHERE User='sag_e2e_backend'")
	expected := "caching_sha2_password"
	if engine == "mariadb" {
		expected = "mysql_native_password"
	}
	if len(plugin) != 1 || plugin[0][0] != expected {
		t.Fatal("test database did not exercise expected native auth plugin")
	}
	if conn, e := myBackendConnect(target, backendTLS, "sag_e2e_backend", "wrong-password", "sag_mysql_e2e"); e == nil {
		conn.Close()
		t.Fatal("wrong backend password accepted")
	}
	// An unrelated frontend CA must never authenticate the backend server.
	cert := testBrokerCertificate(t)
	wrongRoots := x509.NewCertPool()
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	wrongRoots.AddCert(parsed)
	if conn, e := myBackendConnect(target, &tls.Config{RootCAs: wrongRoots}, "sag_e2e_backend", password, "sag_mysql_e2e"); e == nil {
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
	env := append(os.Environ(), "APP_ENV=testing", "APP_DEBUG=false", "BCRYPT_ROUNDS=4", "APP_KEY=base64:"+base64.StdEncoding.EncodeToString(secret), "SAG_CE_CREDENTIAL_KEY=base64:"+base64.StdEncoding.EncodeToString(secret), "DB_CONNECTION=sqlite", "DB_DATABASE="+dbPath, "CACHE_STORE=array", "SESSION_DRIVER=array", "LOG_CHANNEL=stderr", "SAG_INTERNAL_TOKEN=mysql-internal-only")
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
			t.Fatal("Laravel native MySQL fixture failed")
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
	if json.Unmarshal(fixture(map[string]any{"action": "setup", "password": vaultPassword, "backend_username": "sag_e2e_backend", "managed": true, "engine": engine, "database": "sag_mysql_e2e", "tls_mode": "verify-full", "target_host": host, "target_port": portNumber}), &setup) != nil {
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
			rows := query(admin, "SELECT count(*) FROM information_schema.processlist WHERE USER='sag_e2e_backend'")
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
	if conn, e := myBackendConnect(frontAddress, frontendTLS, setup.Temporary.Username, "wrong-temporary-password", "sag_mysql_e2e"); e == nil {
		conn.Close()
		t.Fatal("wrong temporary password accepted")
	}
	raw, e := net.Dial("tcp", frontAddress)
	if e != nil {
		t.Fatal(e)
	}
	seq := byte(0)
	myRead(raw, &seq)
	myWrite(raw, &seq, make([]byte, 32))
	raw.SetReadDeadline(time.Now().Add(time.Second))
	var one [1]byte
	if _, e = raw.Read(one[:]); e == nil {
		t.Fatal("plaintext frontend accepted")
	}
	raw.Close()
	// Independent, widely used client proves frontend wire compatibility.
	tlsName := "sag-e2e-" + hex.EncodeToString(secret[:8])
	if mysqlclient.RegisterTLSConfig(tlsName, frontendTLS) != nil {
		t.Fatal("client TLS registration failed")
	}
	defer mysqlclient.DeregisterTLSConfig(tlsName)
	clientConfig := mysqlclient.NewConfig()
	clientConfig.Net = "tcp"
	clientConfig.Addr = frontAddress
	clientConfig.User = setup.Temporary.Username
	clientConfig.Passwd = setup.Temporary.Password
	clientConfig.DBName = "sag_mysql_e2e"
	clientConfig.TLSConfig = tlsName
	clientConfig.AllowCleartextPasswords = true
	clientConfig.InterpolateParams = true
	clientConfig.Timeout = 5 * time.Second
	clientConfig.ReadTimeout = 10 * time.Second
	independent, e := sql.Open("mysql", clientConfig.FormatDSN())
	if e != nil {
		t.Fatal("independent client open failed")
	}
	var oneValue int
	e = independent.QueryRow("SELECT 1").Scan(&oneValue)
	if e == nil {
		var nullValue sql.NullString
		var word string
		var number int
		if independent.QueryRow("SELECT NULL, 'π', 42").Scan(&nullValue, &word, &number) != nil || nullValue.Valid || word != "π" || number != 42 {
			independent.Close()
			t.Fatal("independent text/NULL result mismatch")
		}
		result, insertErr := independent.Exec("INSERT INTO guard_probe VALUES (7)")
		if insertErr != nil {
			independent.Close()
			t.Fatal("independent COM_QUERY write failed")
		}
		affected, _ := result.RowsAffected()
		if affected != 1 {
			independent.Close()
			t.Fatal("incorrect affected row count")
		}
		if rows := query(admin, "SELECT count(*) FROM guard_probe"); len(rows) != 1 || rows[0][0] != "1" {
			independent.Close()
			t.Fatal("allowed write did not reach real database")
		}
		query(admin, "DELETE FROM guard_probe")
	}
	independent.Close()
	if e != nil || oneValue != 1 {
		t.Fatal("independent MySQL client SELECT 1 failed")
	}
	front, e := myBackendConnect(frontAddress, frontendTLS, setup.Temporary.Username, setup.Temporary.Password, "sag_mysql_e2e")
	if e != nil {
		t.Fatal("temporary MySQL frontend login failed")
	}
	defer front.Close()
	rows := query(front, "SELECT 1")
	if len(rows) != 1 || len(rows[0]) != 1 || rows[0][0] != "1" {
		t.Fatal("SELECT 1 mismatch")
	}
	t.Log(engine + " " + expected + ": Vault -> native broker -> verified TLS SQL SELECT 1 = 1")
	if scenario == "unsupported_command" {
		seq := byte(0)
		myWrite(front, &seq, append([]byte{0x16}, []byte("SELECT 1")...))
	} else if scenario == "worker_lost" {
		stopWorker()
		if !checkBrokerEngineSessionActive(context.Background(), nil, "http://"+controlAddress, "mysql-internal-only", "SAG-IPC-E2E", engine) {
			t.Fatal("worker-loss test accidentally revoked HTTP")
		}
	} else {
		fixture(map[string]any{"action": scenario})
	}
	if scenario == "expire" || scenario == "deactivate" || scenario == "replace_ticks" {
		seq := byte(0)
		myWrite(front, &seq, append([]byte{3}, []byte("INSERT INTO guard_probe VALUES (1)")...))
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
		rows = query(admin, "SELECT count(*) FROM information_schema.processlist WHERE USER='sag_e2e_backend'")
		if len(rows) == 1 && rows[0][0] == "0" {
			t.Log("both native SQL endpoints closed: " + scenario)
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("backend session remained open")
}
