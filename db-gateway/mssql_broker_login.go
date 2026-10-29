package main

import (
	"context"
	"crypto/tls"
	"encoding/binary"
	"fmt"
	"net"
	"strings"
	"time"
)

func tdsSafeName(s string) bool { return len(s) > 0 && len(s) <= 128 && !strings.ContainsRune(s, 0) }
func tdsLogin(user, password, database string) ([]byte, error) {
	if !tdsSafeName(user) || !tdsSafeName(database) || len(password) == 0 || len(password) > 4096 || strings.ContainsRune(password, 0) {
		return nil, errTDSProtocol
	}
	p := make([]byte, 94)
	binary.LittleEndian.PutUint32(p[4:], 0x74000004)
	binary.LittleEndian.PutUint32(p[8:], 4096)
	p[24] = 0xe0
	p[25] = 3
	p[27] = 8
	binary.LittleEndian.PutUint32(p[32:], 0x409)
	for _, f := range []struct {
		pos    int
		s      string
		secret bool
	}{{36, "SAG", false}, {40, user, false}, {44, password, true}, {48, "SAG-CE", false}, {52, "", false}, {60, "SAG-CE", false}, {64, "", false}, {68, database, false}, {78, "", false}, {82, "", false}, {86, "", false}} {
		b := tdsUTF16(f.s)
		if f.secret {
			b = tdsPassword(b)
		}
		binary.LittleEndian.PutUint16(p[f.pos:], uint16(len(p)))
		binary.LittleEndian.PutUint16(p[f.pos+2:], uint16(len(b)/2))
		p = append(p, b...)
	}
	binary.LittleEndian.PutUint32(p, uint32(len(p)))
	return p, nil
}
func tdsParseLogin(p []byte, database string) (string, string, error) {
	if len(p) < 94 || len(p) > 16384 || int(binary.LittleEndian.Uint32(p)) != len(p) || binary.LittleEndian.Uint32(p[4:]) != 0x74000004 {
		return "", "", errTDSProtocol
	}
	size := binary.LittleEndian.Uint32(p[8:])
	if size < 512 || size > 32767 {
		return "", "", errTDSProtocol
	}
	// SQL authentication only. No change password, attach file, feature extension,
	// SSPI, replication, read-only routing or user instances.
	if p[24]&31 != 0 || p[25]&^byte(3) != 0 || p[26] != 0 || p[27]&^byte(8) != 0 || binary.LittleEndian.Uint32(p[90:]) != 0 {
		return "", "", errTDSProtocol
	}
	var ranges [][2]int
	field := func(pos int, secret bool) (string, error) {
		off := int(binary.LittleEndian.Uint16(p[pos:]))
		n := int(binary.LittleEndian.Uint16(p[pos+2:])) * 2
		if n == 0 {
			return "", nil
		}
		if off < 94 || off+n > len(p) {
			return "", errTDSProtocol
		}
		for _, r := range ranges {
			if off < r[1] && off+n > r[0] {
				return "", errTDSProtocol
			}
		}
		ranges = append(ranges, [2]int{off, off + n})
		if secret {
			return tdsDecodePassword(p[off : off+n])
		}
		return tdsString(p[off : off+n])
	}
	values := map[int]string{}
	for _, pos := range []int{36, 40, 44, 48, 52, 56, 60, 64, 68, 78, 82, 86} {
		v, e := field(pos, pos == 44)
		if e != nil {
			return "", "", e
		}
		values[pos] = v
	}
	if values[56] != "" || values[78] != "" || values[82] != "" || values[86] != "" || !tdsSafeName(values[40]) || (values[44] == "" || len(values[44]) > 4096) || values[68] != database {
		return "", "", errTDSProtocol
	}
	return values[40], values[44], nil
}
func tdsLoginAck() []byte {
	name := tdsUTF16("SAG CE")
	ack := []byte{0xad, 0, 0, 1, 0x74, 0, 0, 4, byte(len(name) / 2)}
	ack = append(ack, name...)
	ack = append(ack, 16, 0, 0, 0)
	binary.LittleEndian.PutUint16(ack[1:], uint16(len(ack)-3))
	return append(ack, 0xfd, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)
}
func tdsAcceptLogin(p []byte) bool {
	ack := false
	for len(p) > 0 {
		token := p[0]
		p = p[1:]
		if token == 0xfd {
			return ack && len(p) == 12 && binary.LittleEndian.Uint16(p)&0x103 == 0
		}
		if token != 0xad && token != 0xab && token != 0xe3 {
			return false
		}
		if len(p) < 2 {
			return false
		}
		n := int(binary.LittleEndian.Uint16(p))
		p = p[2:]
		if n > len(p) {
			return false
		}
		if token == 0xad {
			if ack || n < 10 || p[0] != 1 || binary.BigEndian.Uint32(p[1:]) != 0x74000004 {
				return false
			}
			ack = true
		}
		// Backend routing is not followed, and no server-side redirection/auth token
		// is ever exposed to the frontend.
		if token == 0xe3 && (n < 1 || p[0] == 20) {
			return false
		}
		p = p[n:]
	}
	return false
}
func tdsBackendConnect(ctx context.Context, address string, config *tls.Config, user, password, database string) (net.Conn, error) {
	if config == nil || config.InsecureSkipVerify {
		return nil, errTDSProtocol
	}
	login, e := tdsLogin(user, password, database)
	if e != nil {
		return nil, e
	}
	raw, e := (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, "tcp", address)
	if e != nil {
		return nil, errTDSProtocol
	}
	success := false
	defer func() {
		if !success {
			raw.Close()
		}
	}()
	raw.SetDeadline(time.Now().Add(10 * time.Second))
	if tdsWrite(raw, 18, tdsPrelogin(1)) != nil {
		return nil, errTDSProtocol
	}
	p, e := tdsRead(raw, 4, 4096)
	if e != nil {
		return nil, fmt.Errorf("backend prelogin frame: %w", errTDSProtocol)
	}
	encryption, e := tdsPreloginEncryption(p)
	if e != nil || (encryption != 1 && encryption != 3) {
		return nil, fmt.Errorf("backend prelogin encryption: %w", errTDSProtocol)
	}
	cfg := config.Clone()
	cfg.MinVersion = tls.VersionTLS12
	cfg.MaxVersion = tls.VersionTLS12
	cfg.DynamicRecordSizingDisabled = true
	if cfg.ServerName == "" {
		host, _, e := net.SplitHostPort(address)
		if e != nil {
			return nil, errTDSProtocol
		}
		cfg.ServerName = host
	}
	transport := &tdsHandshakeConn{Conn: raw, wrapped: true, readKind: 0}
	secure := tls.Client(transport, cfg)
	if e := secure.HandshakeContext(ctx); e != nil {
		return nil, fmt.Errorf("backend TLS handshake: %w", e)
	}
	if len(transport.pending) != 0 {
		return nil, fmt.Errorf("backend TLS trailing bytes: %w", errTDSProtocol)
	}
	transport.wrapped = false
	if tdsWrite(secure, 16, login) != nil {
		return nil, errTDSProtocol
	}
	p, e = tdsRead(secure, 4, 65536)
	if e != nil {
		return nil, fmt.Errorf("backend LOGIN7 frame: %w", e)
	}
	if !tdsAcceptLogin(p) {
		return nil, fmt.Errorf("backend LOGIN7 response: %w", errTDSProtocol)
	}
	secure.SetDeadline(time.Time{})
	success = true
	return secure, nil
}
func tdsFrontend(ctx context.Context, raw net.Conn, config *tls.Config, database string, verify func(string, string) bool) (net.Conn, error) {
	if config == nil || len(config.Certificates) == 0 || verify == nil {
		return nil, errTDSProtocol
	}
	raw.SetDeadline(time.Now().Add(10 * time.Second))
	p, e := tdsRead(raw, 18, 4096)
	if e != nil {
		return nil, fmt.Errorf("frontend login frame: %w", e)
	}
	encryption, e := tdsPreloginEncryption(p)
	if e != nil || (encryption != 1 && encryption != 3) {
		return nil, fmt.Errorf("frontend encryption: %w", errTDSProtocol)
	}
	if tdsWrite(raw, 4, tdsPrelogin(3)) != nil {
		return nil, fmt.Errorf("frontend login denied: %w", errTDSProtocol)
	}
	cfg := config.Clone()
	cfg.MinVersion = tls.VersionTLS12
	cfg.MaxVersion = tls.VersionTLS12
	transport := &tdsHandshakeConn{Conn: raw, wrapped: true, readKind: 18}
	secure := tls.Server(transport, cfg)
	if e := secure.HandshakeContext(ctx); e != nil || len(transport.pending) != 0 {
		return nil, fmt.Errorf("frontend TLS: %w", errTDSProtocol)
	}
	transport.wrapped = false
	p, e = tdsRead(secure, 16, 16384)
	if e != nil {
		return nil, fmt.Errorf("frontend login frame: %w", e)
	}
	user, password, e := tdsParseLogin(p, database)
	if e != nil || !verify(user, password) {
		return nil, fmt.Errorf("frontend login denied: %w", errTDSProtocol)
	}
	secure.SetDeadline(time.Time{})
	return secure, nil
}
