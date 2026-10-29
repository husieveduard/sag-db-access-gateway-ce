package main

import (
	"context"
	"encoding/binary"
	"net"
	"strings"
	"testing"
	"time"
)

func TestPgBrokerFrontendReadyProtocol(t *testing.T) {
	client, server := net.Pipe()
	defer client.Close()
	defer server.Close()
	_ = client.SetDeadline(time.Now().Add(3 * time.Second))
	_ = server.SetDeadline(time.Now().Add(3 * time.Second))
	done := make(chan error, 1)
	go func() { done <- pgBrokerSendFrontendReady(server) }()
	kind, payload, _, err := readPgMessage(client)
	if err != nil || kind != 'R' || len(payload) != 4 || binary.BigEndian.Uint32(payload) != 0 {
		t.Fatalf("invalid AuthenticationOk: %v", err)
	}
	count := 0
	for {
		kind, payload, _, err = readPgMessage(client)
		if err != nil {
			t.Fatal(err)
		}
		if kind == 'Z' {
			if len(payload) != 1 || payload[0] != 'I' {
				t.Fatal("invalid ReadyForQuery")
			}
			break
		}
		if kind != 'S' {
			t.Fatalf("unexpected startup message %q", kind)
		}
		count++
	}
	if count < 2 {
		t.Fatal("missing parameter status")
	}
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestPgBrokerLargeFrameRoundTrip(t *testing.T) {
	client, server := net.Pipe()
	defer client.Close()
	defer server.Close()
	_ = client.SetDeadline(time.Now().Add(3 * time.Second))
	_ = server.SetDeadline(time.Now().Add(3 * time.Second))
	payload := make([]byte, 128*1024)
	for i := range payload {
		payload[i] = byte(i % 251)
	}
	done := make(chan error, 1)
	go func() { done <- pgBrokerFrame(server, 'D', payload) }()
	kind, got, _, err := readPgMessage(client)
	if err != nil {
		t.Fatal(err)
	}
	if kind != 'D' || len(got) != len(payload) {
		t.Fatal("incomplete frame")
	}
	for i := range payload {
		if got[i] != payload[i] {
			t.Fatalf("frame mismatch at %d", i)
		}
	}
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestPgBrokerRejectsMissingProviderAndClosesFrontend(t *testing.T) {
	client, server := net.Pipe()
	defer client.Close()
	err := pgBrokerServeAuthenticatedSession(context.Background(), server, nil, nil, "", "", "", "", "", "", nil, nil)
	if err == nil || !strings.Contains(err.Error(), "credential provider") {
		t.Fatalf("unexpected error: %v", err)
	}
	_ = client.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if _, err = client.Read(b[:]); err == nil {
		t.Fatal("frontend not closed")
	}
}

func TestPgBrokerRejectsCanceledSessionAndClosesFrontend(t *testing.T) {
	client, server := net.Pipe()
	defer client.Close()
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	err := pgBrokerServeAuthenticatedSession(ctx, server, nil, nil, "", "", "", "", "", "", nil,
		func(context.Context) (PgBrokerBackendCredential, error) { return PgBrokerBackendCredential{}, nil })
	if err == nil {
		t.Fatal("canceled session accepted")
	}
	_ = client.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if _, err = client.Read(b[:]); err == nil {
		t.Fatal("frontend not closed")
	}
}

func TestBrokerRequiresLiveLocalAuthorizationBeforeClientOrVault(t *testing.T) {
	for _, checks := range [][]func(context.Context) bool{nil, {nil}, {func(context.Context) bool { return false }}} {
		client, server := net.Pipe()
		called := false
		err := pgBrokerServeAuthenticatedSession(context.Background(), server, nil, nil, "http://control", "token", "session", "database", "backend", "disable", nil,
			func(context.Context) (PgBrokerBackendCredential, error) {
				called = true
				return PgBrokerBackendCredential{}, nil
			}, checks...)
		if err == nil || !strings.Contains(err.Error(), "local session authorization") || called {
			t.Fatal("missing local authorization reached client or Vault")
		}
		client.SetReadDeadline(time.Now().Add(time.Second))
		var b [1]byte
		if _, e := client.Read(b[:]); e == nil {
			t.Fatal("unauthorized frontend remained open")
		}
		client.Close()
	}
}
