package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func fixtureKerberosMaterial(t *testing.T) json.RawMessage {
	t.Helper()
	body, err := json.Marshal(tdsKerberosMaterial{
		Kind: "kerberos_keytab", ProfileID: "service-a", Username: "gateway", Realm: "SAG.TEST",
		SPN: "MSSQLSvc/sql.sag.test:1433",
		ConfigB64: base64.StdEncoding.EncodeToString([]byte("trusted-config-fixture")),
		KeytabB64: base64.StdEncoding.EncodeToString([]byte("opaque-keytab-fixture")),
	})
	if err != nil {
		t.Fatal("material fixture failed")
	}
	return body
}

func TestKerberosIPCTypeIsolationAndTrustedWorker(t *testing.T) {
	socket := filepath.Join(privateIPCTestDir(t), "worker.sock")
	material := fixtureKerberosMaterial(t)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()),
			func(context.Context, string, int64) bool { return true },
			func(peerCtx context.Context, session string, resource int64) (pgIPCCredential, error) {
				if session == "sql" {
					return pgIPCCredential{Username: "sql_user", Password: "sql-fixture"}, nil
				}
				if session == "mixed" {
					return pgIPCCredential{Username: "sql_user", Password: "sql-fixture", Kerberos: material}, nil
				}
				if session == "valid" {
					peer, ok := peerCtx.Value(pgIPCPeerIdentityKey{}).(pgIPCPeerIdentity)
					kerberos, _ := peerCtx.Value(pgIPCKerberosBootstrapKey{}).(bool)
					if !ok || peer.PID != int32(os.Getpid()) || peer.StartTicks == "" || !kerberos {
						return pgIPCCredential{}, errIPCUnavailable
					}
				}
				return pgIPCCredential{Kerberos: material}, nil
			})
	}()
	defer func() { cancel(); if err := <-done; err != nil { t.Error("IPC shutdown failed") } }()
	for i := 0; i < 100; i++ {
		conn, err := net.Dial("unix", socket)
		if err == nil { conn.Close(); break }
		time.Sleep(5 * time.Millisecond)
	}
	got, err := pgIPCTakeKerberos(ctx, socket, "valid", 7, uint32(os.Getuid()))
	if err != nil || got.ProfileID != "service-a" {
		t.Fatal("valid process-bound Kerberos capability denied")
	}
	for _, session := range []string{"sql", "mixed"} {
		got, err := pgIPCTakeKerberos(ctx, socket, session, 7, uint32(os.Getuid()))
		if err == nil || got.KeytabB64 != "" {
			t.Fatal("Kerberos receiver accepted SQL or mixed credentials")
		}
	}
	for _, session := range []string{"kerberos", "mixed"} {
		got, err := pgIPCTakeCredentials(ctx, socket, session, 7, uint32(os.Getuid()))
		if err == nil || got.Password != "" {
			t.Fatal("SQL receiver accepted Kerberos or mixed credentials")
		}
	}
	if got, err := pgIPCTakeKerberos(ctx, socket, "valid", 7, uint32(os.Getuid())+1); err == nil || got.KeytabB64 != "" {
		t.Fatal("untrusted worker returned Kerberos material")
	}
}

func TestKerberosFD3ProviderIsExplicitPrivateAndBounded(t *testing.T) {
	binary := filepath.Join(t.TempDir(), "php")
	material := fixtureKerberosMaterial(t)
	peer := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 12345, StartTicks: "987654321"})
	ctx := context.WithValue(peer, pgIPCKerberosBootstrapKey{}, true)
	for _, tc := range []struct { body string; private bool; allowed bool }{
		{string(material), true, true},
		{string(material), false, false},
		{`{"username":"sql_user","password":"sql-fixture"}`, true, false},
		{strings.Replace(string(material), "kerberos_keytab", "sql_password", 1), true, false},
		{strings.Replace(string(material), base64.StdEncoding.EncodeToString([]byte("opaque-keytab-fixture")), strings.Repeat("A", 6000), 1), true, false},
	} {
		fd := ""
		if tc.private { fd = " >&3" }
		script := "#!/bin/sh\ncase \"$*\" in *--kerberos*) printf '%s' '" + tc.body + "'" + fd + ";; *) exit 1;; esac\n"
		if os.WriteFile(binary, []byte(script), 0700) != nil { t.Fatal("stub setup failed") }
		got, err := pgIPCLaravelCredentials(binary, "/trusted/artisan")(ctx, "session", 7)
		if (err == nil) != tc.allowed { t.Fatal("Kerberos FD3 decision mismatch") }
		if err != nil && len(got.Kerberos) != 0 { t.Fatal("failed FD3 grant returned material") }
		if err == nil && (got.Username != "" || got.Password != "") { t.Fatal("Kerberos grant included SQL credential fields") }
	}
	if _, err := pgIPCLaravelCredentials(binary, "/trusted/artisan")(context.WithValue(context.Background(), pgIPCKerberosBootstrapKey{}, true), "session", 7); err == nil {
		t.Fatal("Kerberos provider accepted missing kernel identity")
	}
}
