package main

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"syscall"
	"time"
)

// The expected worker UID is deployment configuration, never request input.
func pgIPCTakeCredentials(ctx context.Context, socket, session string, resource int64, workerUID uint32) (PgBrokerBackendCredential, error) {
	var empty PgBrokerBackendCredential
	if ctx == nil || ctx.Err() != nil || socket == "" || session == "" || len(session) > 128 || resource <= 0 {
		return empty, errIPCUnavailable
	}
	conn, err := (&net.Dialer{Timeout: 2 * time.Second}).DialContext(ctx, "unix", socket)
	if err != nil {
		return empty, errIPCUnavailable
	}
	defer conn.Close()
	if !pgIPCTrustedWorker(conn, workerUID) {
		return empty, errIPCUnavailable
	}
	deadline := time.Now().Add(3 * time.Second)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	if conn.SetDeadline(deadline) != nil {
		return empty, errIPCUnavailable
	}
	if json.NewEncoder(conn).Encode(map[string]any{"operation": "bootstrap_credentials", "session_uid": session, "resource_id": resource}) != nil {
		return empty, errIPCUnavailable
	}
	body, err := io.ReadAll(io.LimitReader(conn, 8193))
	var reply struct {
		SessionUID string `json:"session_uid"`
		ResourceID int64  `json:"resource_id"`
		Credential struct {
			Username string `json:"username"`
			Password string `json:"password"`
		} `json:"credential"`
	}
	if err != nil || len(body) > 8192 || json.Unmarshal(body, &reply) != nil || reply.SessionUID != session || reply.ResourceID != resource || reply.Credential.Username == "" || reply.Credential.Password == "" {
		return empty, errIPCUnavailable
	}
	return PgBrokerBackendCredential{Username: reply.Credential.Username, Password: reply.Credential.Password}, nil
}

// Used for both the credential grant and all subsequent live session checks.
func pgIPCTrustedWorker(conn net.Conn, workerUID uint32) bool {
	unixConn, ok := conn.(*net.UnixConn)
	if !ok {
		return false
	}
	raw, err := unixConn.SyscallConn()
	if err != nil {
		return false
	}
	trusted := false
	return raw.Control(func(fd uintptr) {
		cred, e := syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
		// PID 1 is a legitimate worker entrypoint inside a PID namespace.
		// PID 0 denotes an unavailable/cross-namespace peer and remains denied.
		// Gateway recipients still require their exact launcher-bound PID > 1.
		trusted = e == nil && cred.Uid == workerUID && cred.Pid > 0
	}) == nil && trusted
}

func pgIPCBoundSessionCheck(socket, session string, resource int64, workerUID uint32) func(context.Context) bool {
	return func(ctx context.Context) bool {
		return pgIPCCheckSessionWithPeer(ctx, socket, session, resource, func(conn net.Conn) bool { return pgIPCTrustedWorker(conn, workerUID) })
	}
}
