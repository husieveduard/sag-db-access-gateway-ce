package main

import (
	"context"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func startConcurrentIPCWorker(t *testing.T, authorize pgIPCSessionAuthorize) (string, context.CancelFunc, <-chan error) {
	t.Helper()
	socket := filepath.Join(privateIPCTestDir(t), "worker.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- pgIPCServeAuthorization(ctx, socket, uint32(os.Getuid()), authorize) }()
	t.Cleanup(cancel)
	for i := 0; i < 200; i++ {
		if info, e := os.Lstat(socket); e == nil && info.Mode()&os.ModeSocket != 0 {
			return socket, cancel, done
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatal("worker did not start")
	return "", nil, nil
}
func TestIPCSlowPeerDoesNotBlockOtherSessionsAndShutdown(t *testing.T) {
	socket, cancel, done := startConcurrentIPCWorker(t, func(context.Context, string, int64) bool { return true })
	slow, e := net.Dial("unix", socket)
	if e != nil {
		t.Fatal(e)
	}
	defer slow.Close()
	slow.Write([]byte(`{"session_uid":`))
	ctx, stop := context.WithTimeout(context.Background(), time.Second)
	defer stop()
	if !pgIPCCheckSession(ctx, socket, "other-session", 7) {
		t.Fatal("partial frame blocked another authorized session")
	}
	cancel()
	select {
	case e := <-done:
		if e != nil {
			t.Fatal(e)
		}
	case <-time.After(time.Second):
		t.Fatal("shutdown did not close in-flight IPC")
	}
	if _, e = os.Lstat(socket); !os.IsNotExist(e) {
		t.Fatal("owned worker socket was not removed")
	}
	slow.SetReadDeadline(time.Now().Add(time.Second))
	var b [1]byte
	if _, e = slow.Read(b[:]); e == nil {
		t.Fatal("shutdown left peer open")
	}
}
func TestIPCBoundedParallelRequests(t *testing.T) {
	entered := make(chan struct{}, pgIPCMaxConcurrentRequests+1)
	socket, cancel, done := startConcurrentIPCWorker(t, func(ctx context.Context, _ string, _ int64) bool { entered <- struct{}{}; <-ctx.Done(); return false })
	var clients []net.Conn
	defer func() {
		for _, c := range clients {
			c.Close()
		}
	}()
	for i := 0; i < pgIPCMaxConcurrentRequests; i++ {
		c, e := net.Dial("unix", socket)
		if e != nil {
			t.Fatal(e)
		}
		clients = append(clients, c)
		c.Write([]byte("{\"session_uid\":\"session\",\"resource_id\":7}\n"))
		select {
		case <-entered:
		case <-time.After(time.Second):
			t.Fatal("bounded worker slot unavailable")
		}
	}
	ctx, stop := context.WithTimeout(context.Background(), time.Second)
	defer stop()
	if pgIPCCheckSession(ctx, socket, "overflow", 7) {
		t.Fatal("overflow authorized")
	}
	select {
	case <-entered:
		t.Fatal("worker exceeded concurrency bound")
	default:
	}
	cancel()
	select {
	case e := <-done:
		if e != nil {
			t.Fatal(e)
		}
	case <-time.After(time.Second):
		t.Fatal("blocked providers survived shutdown")
	}
}
func TestIPCShutdownPreservesReplacementSocket(t *testing.T) {
	socket, cancel, done := startConcurrentIPCWorker(t, func(context.Context, string, int64) bool { return true })
	if os.Remove(socket) != nil {
		t.Fatal("could not unlink test socket")
	}
	replacement, e := net.Listen("unix", socket)
	if e != nil {
		t.Fatal(e)
	}
	defer replacement.Close()
	expected, e := os.Lstat(socket)
	if e != nil {
		t.Fatal(e)
	}
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("worker did not stop")
	}
	actual, e := os.Lstat(socket)
	if e != nil || !os.SameFile(expected, actual) {
		t.Fatal("worker removed a replacement socket")
	}
}
