package main

import (
	"bytes"
	"context"

	"crypto/tls"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestMySQLHandshakePluginAndPacketBounds(t *testing.T) {
	seed := []byte("12345678901234567890")
	caps, got, plugin, e := myParseGreeting(myGreeting(seed))
	if e != nil || caps != myCapabilities || !bytes.Equal(got, seed) || plugin != "mysql_clear_password" {
		t.Fatal("greeting fields corrupted")
	}
	if _, e := myScramble("unsupported-plugin", "secret", seed); e == nil {
		t.Fatal("unsupported plugin accepted")
	}
	for _, frame := range [][]byte{{0xff, 0xff, 0xff, 0}, {1, 0, 0, 2, 0}} {
		seq := byte(0)
		if _, e := myRead(bytes.NewReader(frame), &seq); e == nil {
			t.Fatal("unbounded or wrong-sequence frame accepted")
		}
	}
	for _, plugin := range []string{"mysql_native_password", "caching_sha2_password"} {
		token, e := myScramble(plugin, "fixture-password", seed)
		if e != nil || len(token) < 20 || bytes.Contains(token, []byte("fixture-password")) {
			t.Fatal("invalid native challenge response")
		}
		t.Log(plugin + " challenge response validated")
	}
}
func TestMySQLNeverRelaysLocalInfileOrMultipleResults(t *testing.T) {
	for _, response := range [][]byte{{0xfb, 's', 'e', 'c', 'r', 'e', 't'}, {0, 0, 0, 8, 0, 0, 0}} {
		backend, server := net.Pipe()
		done := make(chan struct{})
		go func() { defer close(done); defer server.Close(); seq := byte(1); myWrite(server, &seq, response) }()
		var front bytes.Buffer
		if myRelayResult(backend, &front) == nil || front.Len() != 0 {
			t.Error("forbidden backend response reached frontend")
		}
		backend.Close()
		<-done
	}
}
func TestMySQLBrokerRequiresLocalAuthorization(t *testing.T) {
	front, server := net.Pipe()
	defer front.Close()
	if myServeSession(context.Background(), server, nil, nil, nil, "", "", "", "mysql", "database", "backend", nil, nil) == nil {
		t.Fatal("missing local gate accepted")
	}
}

func TestMySQLBackendNeverAcceptsUnverifiedTLS(t *testing.T) {
	for _, config := range []*tls.Config{nil, {InsecureSkipVerify: true}} {
		if conn, e := myBackendConnect("unused", config, "service", "fixture", "database"); e == nil {
			conn.Close()
			t.Fatal("unverified transport accepted")
		}
	}
}
func TestNativeSessionGuardRejectsOtherEngineFamilies(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		engine := "mariadb"
		if strings.HasPrefix(r.URL.Path, "/pg/") {
			engine = "postgresql"
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"ok":true,"allowed":true,"session_status":"started","db_engine":"` + engine + `"}`))
	}))
	defer server.Close()
	ctx := context.Background()
	if checkBrokerEngineSessionActive(ctx, server.Client(), server.URL+"/pg", "fixture", "session", "mysql") {
		t.Fatal("PostgreSQL session authorized MySQL")
	}
	if !checkBrokerEngineSessionActive(ctx, server.Client(), server.URL, "fixture", "session", "mysql") {
		t.Fatal("MariaDB protocol alias rejected")
	}
	if checkBrokerSessionActive(ctx, server.Client(), server.URL, "fixture", "session") {
		t.Fatal("MariaDB session authorized PostgreSQL")
	}
}
