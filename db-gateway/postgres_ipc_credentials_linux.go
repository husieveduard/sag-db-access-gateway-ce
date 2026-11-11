package main

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"syscall"
	"time"
)

// Password-only consumers must never accept the separate Kerberos capability.
func pgIPCTakeCredentials(ctx context.Context, socket, session string, resource int64, workerUID uint32) (PgBrokerBackendCredential, error) {
	var empty PgBrokerBackendCredential
	body, err := pgIPCBootstrapExchange(ctx, socket, session, resource, workerUID, "bootstrap_credentials")
	if err != nil {
		return empty, errIPCUnavailable
	}
	var credential struct {
		Username string `json:"username"`
		Password string `json:"password"`
		Kerberos json.RawMessage `json:"kerberos"`
	}
	if json.Unmarshal(body, &credential) != nil || credential.Username == "" || credential.Password == "" || len(credential.Kerberos) != 0 {
		return empty, errIPCUnavailable
	}
	return PgBrokerBackendCredential{Username: credential.Username, Password: credential.Password}, nil
}

// Both grants authenticate the worker and bind the response to the requested
// session/resource. The larger bound applies only to the explicit Kerberos type.
func pgIPCBootstrapExchange(ctx context.Context, socket, session string, resource int64, workerUID uint32, operation string) (json.RawMessage, error) {
	if ctx == nil || ctx.Err() != nil || socket == "" || session == "" || len(session) > 128 || resource <= 0 {
		return nil, errIPCUnavailable
	}
	limit := int64(8192)
	switch operation {
	case "bootstrap_credentials":
	case "bootstrap_kerberos":
		limit = 16384
	default:
		return nil, errIPCUnavailable
	}
	conn, err := (&net.Dialer{Timeout: 2 * time.Second}).DialContext(ctx, "unix", socket)
	if err != nil {
		return nil, errIPCUnavailable
	}
	defer conn.Close()
	if !pgIPCTrustedWorker(conn, workerUID) {
		return nil, errIPCUnavailable
	}
	deadline := time.Now().Add(3 * time.Second)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	if conn.SetDeadline(deadline) != nil {
		return nil, errIPCUnavailable
	}
	if json.NewEncoder(conn).Encode(map[string]any{"operation": operation, "session_uid": session, "resource_id": resource}) != nil {
		return nil, errIPCUnavailable
	}
	body, err := io.ReadAll(io.LimitReader(conn, limit+1))
	var reply struct {
		SessionUID string `json:"session_uid"`
		ResourceID int64 `json:"resource_id"`
		Credential json.RawMessage `json:"credential"`
	}
	if err != nil || int64(len(body)) > limit || json.Unmarshal(body, &reply) != nil || reply.SessionUID != session || reply.ResourceID != resource || len(reply.Credential) == 0 {
		return nil, errIPCUnavailable
	}
	return reply.Credential, nil
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
