package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"encoding/binary"
	"net"
	"strconv"
	"testing"
	"time"

	mssqlclient "github.com/microsoft/go-mssqldb"
	"github.com/microsoft/go-mssqldb/msdsn"
)

func tdsTestClient(t *testing.T, address, user, password, database string, config *tls.Config) *sql.DB {
	t.Helper()
	host, port, _ := net.SplitHostPort(address)
	n, _ := strconv.Atoi(port)
	config = config.Clone()
	if config.ServerName == "" {
		config.ServerName = host
	}
	connector := mssqlclient.NewConnectorConfig(msdsn.Config{Host: host, Port: uint64(n), User: user, Password: password, Database: database, Encryption: msdsn.EncryptionRequired, TLSConfig: config, PacketSize: 4096, DisableRetry: true, DialTimeout: 3 * time.Second, ConnTimeout: 5 * time.Second, Protocols: []string{"tcp"}, NoTraceID: true})
	db := sql.OpenDB(connector)
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)
	return db
}
func tdsTestBatch(sql string) []byte {
	p := make([]byte, 22)
	binary.LittleEndian.PutUint32(p, 22)
	binary.LittleEndian.PutUint32(p[4:], 18)
	binary.LittleEndian.PutUint16(p[8:], 2)
	binary.LittleEndian.PutUint32(p[18:], 1)
	return append(p, tdsUTF16(sql)...)
}
func TestTDSFramingAndLoginBoundaries(t *testing.T) {
	if enc, e := tdsPreloginEncryption([]byte{1, 0, 11, 0, 1, 3, 0, 12, 0, 0, 0xff, 1}); e != nil || enc != 1 {
		t.Fatal("valid empty deprecated THREADID denied")
	}
	p, _ := tdsLogin("temporary", "secret", "lab")
	if u, s, e := tdsParseLogin(p, "lab"); e != nil || u != "temporary" || s != "secret" {
		t.Fatal("LOGIN7 mismatch")
	}
	for _, mutate := range []func([]byte){func(p []byte) { p[25] |= 0x80 }, func(p []byte) { p[27] |= 0x10 }, func(p []byte) { p[86+2] = 1 }, func(p []byte) { p[40] = 1 }, func(p []byte) { p[4] = 0 }} {
		q := append([]byte(nil), p...)
		mutate(q)
		if _, _, e := tdsParseLogin(q, "lab"); e == nil {
			t.Fatal("unsafe LOGIN7 accepted")
		}
	}
	if _, _, e := tdsParseLogin(p, "other"); e == nil {
		t.Fatal("wrong database accepted")
	}
	var wire bytes.Buffer
	payload := bytes.Repeat([]byte{'x'}, 10000)
	tdsWrite(&wire, 1, payload)
	got, e := tdsRead(&wire, 1, len(payload))
	if e != nil || !bytes.Equal(got, payload) {
		t.Fatal("fragment reassembly")
	}
	for _, h := range [][]byte{{1, 9, 0, 9, 0, 0, 1, 0, 1}, {1, 1, 0, 9, 0, 0, 2, 0, 1}, {1, 1, 0, 8, 0, 0, 1, 0}, {3, 1, 0, 9, 0, 0, 1, 0, 1}} {
		if _, e := tdsRead(bytes.NewReader(h), 1, 100); e == nil {
			t.Fatal("unsafe packet accepted")
		}
	}
	if !tdsAcceptLogin(tdsLoginAck()) || tdsAcceptLogin(append([]byte{0xaa}, tdsLoginAck()...)) {
		t.Fatal("authentication response accepted incorrectly")
	}
	if !tdsValidateBatch(tdsTestBatch("SELECT 1")) {
		t.Fatal("standard SQLBatch denied")
	}
	bad := tdsTestBatch("SELECT 1")
	bad[10] = 1
	if tdsValidateBatch(bad) {
		t.Fatal("nonzero transaction accepted")
	}
	for _, cfg := range []*tls.Config{nil, {InsecureSkipVerify: true}} {
		if c, e := tdsBackendConnect(context.Background(), "127.0.0.1:1", cfg, "user", "pass", "lab"); e == nil {
			c.Close()
			t.Fatal("unverified backend accepted")
		}
	}
}
func TestTDSIndependentClientFrontend(t *testing.T) {
	cert := testBrokerCertificate(t)
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	roots := x509.NewCertPool()
	roots.AddCert(parsed)
	listener, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer listener.Close()
	done := make(chan error, 1)
	go func() {
		raw, e := listener.Accept()
		if e != nil {
			done <- e
			return
		}
		defer raw.Close()
		ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
		defer cancel()
		front, e := tdsFrontend(ctx, raw, &tls.Config{Certificates: []tls.Certificate{cert}}, "lab", func(u, p string) bool { return u == "temporary" && p == "temporary-secret" })
		if e != nil {
			done <- e
			return
		}
		if e = tdsWrite(front, 4, tdsLoginAck()); e != nil {
			done <- e
			return
		}
		p, _, e := tdsReadMessage(front, 1, tdsMessageLimit, true)
		if e != nil || !tdsValidateBatch(p) {
			done <- errTDSProtocol
			return
		}
		// One INT column, one row and a final DONE. The real-server suite separately
		// proves that all SQL results originate from the isolated SQL Server.
		result := []byte{0x81, 1, 0, 0, 0, 0, 0, 0, 0, 0x38, 0, 0xd1, 1, 0, 0, 0, 0xfd, 0x10, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0}
		done <- tdsWrite(front, 4, result)
	}()
	db := tdsTestClient(t, listener.Addr().String(), "temporary", "temporary-secret", "lab", &tls.Config{RootCAs: roots, ServerName: "localhost"})
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()
	var n int
	if e = db.QueryRowContext(ctx, "SELECT 1").Scan(&n); e != nil {
		serverError := <-done
		t.Fatalf("independent frontend: %v; server: %v", e, serverError)
	}
	if n != 1 {
		t.Fatal("wrong SQL value")
	}
	if e = <-done; e != nil {
		t.Fatal("frontend protocol failed")
	}
}
func TestTDSFrontendDeniesPlaintextAndMissingLocalAuthorization(t *testing.T) {
	client, server := net.Pipe()
	go func() { tdsWrite(client, 18, tdsPrelogin(0)); client.Close() }()
	defer server.Close()
	if _, e := tdsFrontend(context.Background(), server, &tls.Config{Certificates: []tls.Certificate{testBrokerCertificate(t)}}, "lab", func(string, string) bool { return true }); e == nil {
		t.Fatal("plaintext allowed")
	}
	a, b := net.Pipe()
	defer a.Close()
	defer b.Close()
	if e := tdsServeSession(context.Background(), a, nil, nil, nil, "", "", "", "mssql", "lab", "", nil, nil); e == nil {
		t.Fatal("missing local authorization allowed")
	}
}
