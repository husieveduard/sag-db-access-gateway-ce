package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

// A backend connector can carry AD authority. Invalid frontend credentials and
// a revoke during their verification must never cause it to run.
func TestTDSBackendConnectorRequiresTemporaryLogin(t *testing.T) {
	for _, scenario := range []string{"wrong_password", "revoked_during_verify", "valid"} {
		t.Run(scenario, func(t *testing.T) {
			var verified atomic.Bool
			var connects atomic.Int32
			control := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				var input struct { Username, Password string }
				if json.NewDecoder(r.Body).Decode(&input) != nil { w.WriteHeader(http.StatusBadRequest); return }
				allowed := input.Username == "temporary" && input.Password == "temporary-secret"
				verified.Store(allowed)
				_ = json.NewEncoder(w).Encode(map[string]bool{"ok": true, "allowed": allowed})
			}))
			defer control.Close()
			cert := testBrokerCertificate(t)
			parsed, _ := x509.ParseCertificate(cert.Certificate[0])
			roots := x509.NewCertPool()
			roots.AddCert(parsed)
			listener, err := net.Listen("tcp", "127.0.0.1:0")
			if err != nil { t.Fatal(err) }
			defer listener.Close()
			ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			done := make(chan error, 1)
			go func() {
				raw, err := listener.Accept()
				if err != nil { done <- err; return }
				done <- tdsServeSessionWithBackend(ctx, raw, &tls.Config{Certificates: []tls.Certificate{cert}},
					control.Client(), control.URL, "internal-fixture", "session", "mssql", "lab",
					func(context.Context) (net.Conn, error) {
						connects.Add(1)
						return nil, errTDSProtocol // Stop after proving the authority boundary.
					}, func(context.Context) bool {
						return scenario != "revoked_during_verify" || !verified.Load()
					})
			}()
			password := "temporary-secret"
			if scenario == "wrong_password" { password = "incorrect-temporary" }
			conn, err := tdsBackendConnect(ctx, listener.Addr().String(),
				&tls.Config{RootCAs: roots, ServerName: "localhost"}, "temporary", password, "lab")
			if conn != nil { conn.Close() }
			if err == nil { t.Fatal("connector test unexpectedly completed a SQL login") }
			if err := <-done; err == nil { t.Fatal("unavailable backend accepted") }
			want := int32(0)
			if scenario == "valid" { want = 1 }
			if connects.Load() != want { t.Fatal("backend authority reached before valid live SAG login") }
		})
	}
}

func TestKerberosMemoryMaterialIsTargetBoundAndOwned(t *testing.T) {
	conf := []byte("[libdefaults]\n default_realm = SAG.TEST\n[realms]\n SAG.TEST = {\n  kdc = 127.0.0.1:1\n }\n")
	// Valid empty keytab framing is enough to construct a client. It cannot
	// obtain tickets; the separate real AD acceptance uses an exported AES key.
	data := []byte{5, 2, 0, 0, 0, 0}
	material := tdsKerberosMaterial{
		Kind: "kerberos_keytab", ProfileID: "isolated-ad", Username: "gateway", Realm: "SAG.TEST",
		SPN: "MSSQLSvc/sql.sag.test:1433", ConfigB64: base64.StdEncoding.EncodeToString(conf),
		KeytabB64: base64.StdEncoding.EncodeToString(data),
	}
	auth, err := tdsNewKerberosAuthMaterial("sql.sag.test:1433", material)
	if err != nil { t.Fatal("private in-memory Kerberos material denied") }
	owned := auth.(*tdsKerberosAuth).keytabData
	if !bytes.Equal(owned, data) { t.Fatal("keytab did not survive temporary input release") }
	auth.Free()
	if !bytes.Equal(owned, make([]byte, len(owned))) { t.Fatal("owned keytab bytes retained after client release") }
	for _, target := range []string{"other.sag.test:1433", "sql.sag.test:1444", "127.0.0.1:1433"} {
		if auth, err := tdsNewKerberosAuthMaterial(target, material); err == nil {
			auth.Free()
			t.Fatal("material accepted for a different target")
		}
	}
	material.KeytabB64 = base64.StdEncoding.EncodeToString([]byte("malformed-keytab-fixture"))
	if auth, err := tdsNewKerberosAuthMaterial("sql.sag.test:1433", material); err == nil {
		auth.Free()
		t.Fatal("malformed keytab accepted")
	}
	if connect, err := tdsBootstrapBackend(context.Background(), "/absent", "session", 1, 0,
		"mssql_kerberos_delegation", "sql.sag.test:1433", "lab", nil); err == nil || connect != nil {
		t.Fatal("unsupported authentication strategy accepted")
	}
}
