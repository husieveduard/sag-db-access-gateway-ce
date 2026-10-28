package main

import (
	"context"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLaravelCredentialPipeIsPrivateAndBounded(t *testing.T) {
	dir := t.TempDir()
	binary := filepath.Join(dir, "php")
	ctx := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 123, StartTicks: "456"})
	for _, tc := range []struct {
		body    string
		allowed bool
	}{
		{"printf '%s' '{\"username\":\"service\",\"password\":\"fixture-only\"}' >&3", true},
		{"printf '%s' '{\"username\":\"service\",\"password\":\"fixture-only\"}'", false},
		{"printf '%s' '{\"username\":\"service\",\"password\":\"fixture-only\"}' >&3; exit 1", false},
		{"printf '%s' '{\"username\":\"service\"}' >&3", false},
	} {
		if os.WriteFile(binary, []byte("#!/bin/sh\n"+tc.body+"\n"), 0700) != nil {
			t.Fatal("stub setup failed")
		}
		got, err := pgIPCLaravelCredentials(binary, "/trusted/artisan")(ctx, "session", 7)
		if (err == nil) != tc.allowed {
			t.Fatal("pipe trust decision mismatch")
		}
		if !tc.allowed && got.Password != "" {
			t.Fatal("failed command returned secret")
		}
	}
	if _, err := pgIPCLaravelCredentials(binary, "/trusted/artisan")(context.Background(), "session", 7); err == nil {
		t.Fatal("missing peer accepted")
	}
}

func TestCredentialClientRejectsWrongWorkerIdentity(t *testing.T) {
	dir := privateIPCTestDir(t)
	socket := filepath.Join(dir, "worker.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() {
		done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()), func(context.Context, string, int64) bool { return true }, func(context.Context, string, int64) (pgIPCCredential, error) {
			return pgIPCCredential{Username: "service", Password: "fixture-only"}, nil
		})
	}()
	defer func() { cancel(); <-done }()
	for i := 0; i < 100; i++ {
		conn, e := net.Dial("unix", socket)
		if e == nil {
			conn.Close()
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	if got, err := pgIPCTakeCredentials(ctx, socket, "session", 7, uint32(os.Getuid())+1); err == nil || got.Password != "" {
		t.Fatal("untrusted worker returned credentials")
	}
}
