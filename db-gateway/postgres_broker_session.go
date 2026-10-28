package main

import (
	"context"
	"crypto/tls"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"net/http"
	"time"
)

// PgBrokerBackendCredential is only supplied by a trusted server-side vault
// integration. Do not serialize it to the frontend or place it in logs.

// pgBrokerServeAuthenticatedSession bridges PostgreSQL simple-query traffic
// after two INDEPENDENT authentications. It is a lab-only primitive and is
// intentionally not wired into the existing production listener. The
// credential callback must enforce session + resource authorization.
// At least one live local process-bound check is mandatory.
func pgBrokerServeAuthenticatedSession(
	ctx context.Context, raw net.Conn, frontendTLS *tls.Config,
	controlClient *http.Client, controlURL, internalToken, sessionUID, database string,
	backendAddr, backendTransport string, backendTLS *tls.Config,
	vault func(context.Context) (PgBrokerBackendCredential, error),
	localChecks ...func(context.Context) bool,
) error {
	if raw == nil {
		return errors.New("frontend connection unavailable")
	}
	defer raw.Close()
	if vault == nil {
		return errors.New("backend credential provider unavailable")
	}
	if ctx == nil || ctx.Err() != nil {
		return errors.New("invalid session context")
	}
	if len(localChecks) == 0 {
		return errors.New("local session authorization unavailable")
	}
	for _, check := range localChecks {
		if check == nil || !check(ctx) {
			return errors.New("local session authorization failed")
		}
	}
	frontend, err := pgBrokerAuthenticateFrontend(ctx, raw, frontendTLS, controlClient, controlURL, internalToken, sessionUID, database)
	if err != nil {
		return err
	}
	credentials, err := vault(ctx)
	if err != nil {
		return errors.New("backend credential retrieval failed")
	}
	if credentials.Username == "" || credentials.Password == "" {
		return errors.New("backend credential missing")
	}
	backend, err := pgBrokerBackendConnectWithMode(backendAddr, backendTransport, backendTLS, credentials.Username, credentials.Password, database)
	credentials.Password = ""
	if err != nil {
		return errors.New("backend authentication failed")
	}
	defer backend.Close()
	if !checkBrokerSessionActive(ctx, controlClient, controlURL, internalToken, sessionUID, localChecks...) {
		return errors.New("session no longer authorized")
	}
	stopGuard := watchBrokerSession(ctx, controlClient, controlURL, internalToken, sessionUID, frontend, backend, localChecks...)
	defer stopGuard()
	if err = pgBrokerSendFrontendReady(frontend); err != nil {
		return err
	}
	// Restrict this initial lab broker to the PostgreSQL simple query protocol.
	// Extended query, replication, COPY, cancel and notification forwarding
	// require separate explicit implementations and must fail closed.
	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		_ = frontend.SetReadDeadline(time.Now().Add(30 * time.Second))
		kind, payload, _, readErr := readPgMessage(frontend)
		if readErr != nil {
			return readErr
		}
		if kind == 'X' {
			return nil
		}
		if kind != 'Q' || len(payload) < 2 || payload[len(payload)-1] != 0 {
			return errors.New("unsupported PostgreSQL frontend message")
		}
		if !checkBrokerSessionActive(ctx, controlClient, controlURL, internalToken, sessionUID, localChecks...) {
			return errors.New("session revoked or expired")
		}
		// Avoid silently sending protocol control messages from the frontend.
		frame := make([]byte, 5+len(payload))
		frame[0] = 'Q'
		binary.BigEndian.PutUint32(frame[1:5], uint32(4+len(payload)))
		copy(frame[5:], payload)
		if err = backend.SetDeadline(time.Now().Add(30 * time.Second)); err != nil {
			return err
		}
		if _, err = io.Copy(backend, bytesReader(frame)); err != nil {
			return err
		}
		for n := 0; n < 100000; n++ {
			backendKind, _, rawMessage, backendErr := readPgMessage(backend)
			if backendErr != nil {
				return backendErr
			}
			if backendKind == 'K' || backendKind == 'R' {
				return errors.New("unexpected backend control message")
			}
			if err = frontend.SetWriteDeadline(time.Now().Add(30 * time.Second)); err != nil {
				return err
			}
			if _, err = io.Copy(frontend, bytesReader(rawMessage)); err != nil {
				return err
			}
			if backendKind == 'Z' {
				break
			}
			if n == 99999 {
				return errors.New("backend response message limit exceeded")
			}
		}
	}
}

func pgBrokerSendFrontendReady(conn net.Conn) error {
	// PostgreSQL 3.0 AuthOk plus minimal startup ParameterStatus values.
	// Never leak backend process ID / cancel key or Vault credentials.
	if err := pgBrokerFrame(conn, 'R', []byte{0, 0, 0, 0}); err != nil {
		return err
	}
	for _, pair := range [][2]string{
		{"server_version", "16.0"},
		{"server_encoding", "UTF8"},
		{"client_encoding", "UTF8"},
		{"DateStyle", "ISO, MDY"},
		{"integer_datetimes", "on"},
		{"standard_conforming_strings", "on"},
	} {
		body := append([]byte(pair[0]), 0)
		body = append(body, []byte(pair[1])...)
		body = append(body, 0)
		if err := pgBrokerFrame(conn, 'S', body); err != nil {
			return err
		}
	}
	return pgBrokerFrame(conn, 'Z', []byte{'I'})
}

func pgBrokerFrame(conn net.Conn, kind byte, payload []byte) error {
	if len(payload) > 1024*1024 {
		return errors.New("broker frame too large")
	}
	frame := make([]byte, 5+len(payload))
	frame[0] = kind
	binary.BigEndian.PutUint32(frame[1:5], uint32(4+len(payload)))
	copy(frame[5:], payload)
	_, err := io.Copy(conn, bytesReader(frame))
	return err
}

// Small read-only wrapper avoids exposing a mutable frame after construction.
func bytesReader(b []byte) io.Reader { return &brokerFrameReader{data: b} }

type brokerFrameReader struct{ data []byte }

func (r *brokerFrameReader) Read(p []byte) (int, error) {
	if len(r.data) == 0 {
		return 0, io.EOF
	}
	n := copy(p, r.data)
	r.data = r.data[n:]
	return n, nil
}
