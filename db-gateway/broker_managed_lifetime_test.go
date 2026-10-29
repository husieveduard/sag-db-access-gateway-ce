package main

import (
	"context"
	"io"
	"net"
	"testing"
	"time"
)

func TestManagedLifetimeStopsIdleListenerAndCancelsClients(t *testing.T) {
	listener, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	checked := make(chan struct{})
	revoke := make(chan struct{})
	lifetime, stop := brokerWatchManagedListener(context.Background(), listener, func(ctx context.Context) bool {
		close(checked)
		select {
		case <-revoke:
			return false
		case <-ctx.Done():
			return false
		}
	})
	defer stop()
	<-checked
	client, e := net.Dial("tcp", listener.Addr().String())
	if e != nil {
		t.Fatal(e)
	}
	defer client.Close()
	server, e := listener.Accept()
	if e != nil {
		t.Fatal(e)
	}
	defer server.Close()
	stopClient := context.AfterFunc(lifetime, func() { server.Close() })
	defer stopClient()
	close(revoke)
	select {
	case <-lifetime.Done():
	case <-time.After(time.Second):
		t.Fatal("revocation did not cancel lifetime")
	}
	client.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if _, e = client.Read(b[:]); e == nil {
		t.Fatal("pre-login connection remained open")
	}
	if _, e = listener.Accept(); e == nil {
		t.Fatal("idle listener remained open")
	}
}
func TestManagedLifetimeStopCancelsBlockedAuthorization(t *testing.T) {
	listener, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	entered := make(chan struct{})
	lifetime, stop := brokerWatchManagedListener(context.Background(), listener, func(ctx context.Context) bool { close(entered); <-ctx.Done(); return false })
	<-entered
	done := make(chan struct{})
	go func() { stop(); close(done) }()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("authorization goroutine survived shutdown")
	}
	if lifetime.Err() == nil {
		t.Fatal("listener lifetime remained active")
	}
}

func assertManagedBrokerExited(t *testing.T, pid int, address string) {
	t.Helper()
	until := time.Now().Add(7 * time.Second)
	for time.Now().Before(until) {
		if _, e := pgIPCPeerProcessStartTicks(int32(pid)); e != nil {
			c, e := net.DialTimeout("tcp", address, 200*time.Millisecond)
			if e == nil {
				c.Close()
				t.Fatal("stopped managed process left a listener")
			}
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("invalidated managed broker process remained alive")
}
func assertIdleManagedBrokerStops(t *testing.T, pid int, address string, pending bool, trigger func()) {
	t.Helper()
	var client net.Conn
	if pending {
		var e error
		client, e = net.DialTimeout("tcp", address, time.Second)
		if e != nil {
			t.Fatal(e)
		}
		defer client.Close()
	}
	trigger()
	assertManagedBrokerExited(t, pid, address)
	if client != nil {
		client.SetReadDeadline(time.Now().Add(time.Second))
		_, e := io.Copy(io.Discard, client)
		if e != nil {
			if n, ok := e.(net.Error); ok && n.Timeout() {
				t.Fatal("pending frontend handshake survived invalidation")
			}
		}
	}
	t.Log("managed listener and process stopped without a SQL client")
}
