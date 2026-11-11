package main

import (
	"context"
	"crypto/tls"
	"encoding/binary"
	"net"
	"time"
)

// Implemented by Kerberos providers. There is no username/password fallback in
// this path; the SQL-password connector remains a separate explicit strategy.
type tdsIntegratedAuthenticator interface {
	InitialBytes() ([]byte, error)
	NextBytes([]byte) ([]byte, error)
	Free()
}

func tdsIntegratedLogin(database string, token []byte) ([]byte, error) {
	if !tdsSafeName(database) || len(token) == 0 || len(token) > 32768 {
		return nil, errTDSProtocol
	}
	p := make([]byte, 94)
	binary.LittleEndian.PutUint32(p[4:], 0x74000004)
	binary.LittleEndian.PutUint32(p[8:], 4096)
	p[24], p[25], p[27] = 0xe0, 3|0x80, 8 // INTEGRATED_SECURITY
	binary.LittleEndian.PutUint32(p[32:], 0x409)
	for _, field := range []struct {
		pos   int
		value string
	}{
		{36, "SAG"}, {40, ""}, {44, ""}, {48, "SAG-CE"}, {52, ""},
		{60, "SAG-CE"}, {64, ""}, {68, database}, {78, ""}, {82, ""}, {86, ""},
	} {
		value := tdsUTF16(field.value)
		binary.LittleEndian.PutUint16(p[field.pos:], uint16(len(p)))
		binary.LittleEndian.PutUint16(p[field.pos+2:], uint16(len(value)/2))
		p = append(p, value...)
	}
	// SSPI is binary; its length is bytes, unlike the UTF-16 LOGIN7 fields.
	binary.LittleEndian.PutUint16(p[78:], uint16(len(p)))
	binary.LittleEndian.PutUint16(p[80:], uint16(len(token)))
	p = append(p, token...)
	binary.LittleEndian.PutUint32(p, uint32(len(p)))
	return p, nil
}

// Extract one bounded SSPI response while preserving normal login tokens for
// the existing strict LOGINACK/DONE parser. Error and routing tokens fail closed.
func tdsIntegratedReply(p []byte) (login, sspi []byte, err error) {
	for len(p) > 0 {
		if p[0] == 0xfd {
			if len(p) != 13 {
				return nil, nil, errTDSProtocol
			}
			return append(login, p...), sspi, nil
		}
		if len(p) < 3 {
			return nil, nil, errTDSProtocol
		}
		n := int(binary.LittleEndian.Uint16(p[1:])) + 3
		if n > len(p) {
			return nil, nil, errTDSProtocol
		}
		switch p[0] {
		case 0xed:
			if sspi != nil || n <= 3 || n > 32771 {
				return nil, nil, errTDSProtocol
			}
			sspi = append([]byte(nil), p[3:n]...)
		case 0xe3:
			if n < 4 || p[3] == 20 {
				return nil, nil, errTDSProtocol
			}
			login = append(login, p[:n]...)
		case 0xad, 0xab:
			login = append(login, p[:n]...)
		default:
			return nil, nil, errTDSProtocol
		}
		p = p[n:]
	}
	return login, sspi, nil
}

func tdsBackendConnectIntegrated(ctx context.Context, address string, config *tls.Config, database string, auth tdsIntegratedAuthenticator) (net.Conn, error) {
	if auth == nil {
		return nil, errTDSProtocol
	}
	defer auth.Free()
	secure, err := tdsBackendTLS(ctx, address, config)
	if err != nil {
		return nil, errTDSProtocol
	}
	success := false
	stop := context.AfterFunc(ctx, func() { _ = secure.Close() })
	defer func() {
		stop()
		if !success {
			secure.Close()
		}
	}()
	initial, err := auth.InitialBytes()
	if err != nil || ctx.Err() != nil {
		return nil, errTDSProtocol
	}
	login, err := tdsIntegratedLogin(database, initial)
	if err != nil || tdsWrite(secure, 16, login) != nil {
		return nil, errTDSProtocol
	}
	for round := 0; round < 4; round++ {
		packet, err := tdsRead(secure, 4, 65536)
		if err != nil {
			return nil, errTDSProtocol
		}
		ack, token, err := tdsIntegratedReply(packet)
		if err != nil {
			return nil, errTDSProtocol
		}
		var reply []byte
		if token != nil {
			reply, err = auth.NextBytes(token)
			if err != nil || ctx.Err() != nil || len(reply) > 32768 {
				return nil, errTDSProtocol
			}
		}
		if len(ack) > 0 {
			if len(reply) != 0 || !tdsAcceptLogin(ack) {
				return nil, errTDSProtocol
			}
			secure.SetDeadline(time.Time{})
			success = true
			return secure, nil
		}
		if token == nil {
			return nil, errTDSProtocol
		}
		if len(reply) > 0 && tdsWrite(secure, 17, reply) != nil {
			return nil, errTDSProtocol
		}
	}
	return nil, errTDSProtocol
}
