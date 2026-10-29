package main

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/binary"
	"net"
	"net/http"
	"time"
)

// Only the standard non-MARS zero transaction descriptor is accepted.
func tdsValidateHeaders(p []byte) bool {
	return len(p) >= 22 && binary.LittleEndian.Uint32(p) == 22 && binary.LittleEndian.Uint32(p[4:]) == 18 && binary.LittleEndian.Uint16(p[8:]) == 2 && binary.LittleEndian.Uint64(p[10:]) == 0 && binary.LittleEndian.Uint32(p[18:]) == 1
}
func tdsValidateBatch(p []byte) bool {
	if len(p) < 24 || !tdsValidateHeaders(p) {
		return false
	}
	_, e := tdsString(p[22:])
	return e == nil
}
func tdsServeSession(ctx context.Context, raw net.Conn, frontendTLS, backendTLS *tls.Config, client *http.Client, control, token, session, engine, database, target string, vault func(context.Context) (BrokerBackendCredential, error), local func(context.Context) bool) error {
	if raw == nil {
		return errTDSProtocol
	}
	defer raw.Close()
	if ctx == nil || ctx.Err() != nil || local == nil || vault == nil || !local(ctx) {
		return errTDSProtocol
	}
	front, e := tdsFrontend(ctx, raw, frontendTLS, database, func(user, password string) bool {
		allowed, e := verifyTemporaryCredential(ctx, client, control, token, session, user, password)
		return e == nil && allowed
	})
	if e != nil {
		return e
	}
	credentials, e := vault(ctx)
	if e != nil {
		return errTDSProtocol
	}
	backend, e := tdsBackendConnect(ctx, target, backendTLS, credentials.Username, credentials.Password, database)
	credentials.Password = ""
	if e != nil {
		return e
	}
	defer backend.Close()
	if !checkBrokerEngineSessionActive(ctx, client, control, token, session, engine, local) {
		return errTDSProtocol
	}
	stop := watchBrokerEngineSession(ctx, client, control, token, session, engine, front, backend, local)
	defer stop()
	if tdsWrite(front, 4, tdsLoginAck()) != nil {
		return errTDSProtocol
	}
	reader := bufio.NewReader(front)
	for {
		front.SetReadDeadline(time.Now().Add(30 * time.Second))
		header, e := reader.Peek(1)
		if e != nil || (header[0] != 1 && header[0] != 3) {
			return errTDSProtocol
		}
		kind := header[0]
		p, reset, e := tdsReadMessage(reader, kind, tdsMessageLimit, true)
		if e != nil || (kind == 1 && !tdsValidateBatch(p)) || (kind == 3 && !tdsValidateRPC(p)) {
			return errTDSProtocol
		}
		if !checkBrokerEngineSessionActive(ctx, client, control, token, session, engine, local) {
			return errTDSProtocol
		}
		backend.SetDeadline(time.Now().Add(30 * time.Second))
		front.SetWriteDeadline(time.Now().Add(30 * time.Second))
		if tdsWriteMessage(backend, kind, p, reset) != nil {
			return errTDSProtocol
		}
		result, e := tdsRead(backend, 4, 64<<20)
		// A complete SQL response must end in the 7.4 DONE/DONEPROC token, never an auth
		// exchange or incomplete result. Tokens/typed rows are preserved for clients.
		if e != nil || len(result) < 13 || (result[len(result)-13] != 0xfd && !(kind == 3 && result[len(result)-13] == 0xfe)) || binary.LittleEndian.Uint16(result[len(result)-12:])&1 != 0 {
			return errTDSProtocol
		}
		if tdsWrite(front, 4, result) != nil {
			return errTDSProtocol
		}
	}
}
