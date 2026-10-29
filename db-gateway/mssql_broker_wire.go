package main

import (
	"encoding/binary"
	"errors"
	"io"
	"net"
	"unicode/utf16"
)

var errTDSProtocol = errors.New("MSSQL laboratory protocol denied")

const tdsMessageLimit = 4 << 20

// Reassemble and bound messages before authorization. RESETCONNECTION is
// accepted only on the first SQLBatch packet and is preserved for the backend.
func tdsRead(r io.Reader, expected byte, limit int) ([]byte, error) {
	p, _, e := tdsReadMessage(r, expected, limit, false)
	return p, e
}
func tdsReadMessage(r io.Reader, expected byte, limit int, allowReset bool) ([]byte, bool, error) {
	var result []byte
	seq := byte(1)
	reset := false
	for {
		var h [8]byte
		if _, e := io.ReadFull(r, h[:]); e != nil {
			return nil, false, errTDSProtocol
		}
		allowed := byte(1)
		if allowReset && expected == 1 && len(result) == 0 {
			allowed |= 8
			reset = h[1]&8 != 0
		}
		// SQL Server uses packet id zero for server replies and TLS envelopes.
		if (expected == 0 || expected == 4) && len(result) == 0 && h[6] == 0 {
			seq = 0
		}
		n := int(binary.BigEndian.Uint16(h[2:4]))
		if (h[0] != expected && !(expected == 0 && (h[0] == 4 || h[0] == 18))) || h[1]&^allowed != 0 || h[6] != seq || h[7] != 0 || n <= 8 || n > 32767 || len(result)+n-8 > limit {
			return nil, false, errTDSProtocol
		}
		p := make([]byte, n-8)
		if _, e := io.ReadFull(r, p); e != nil {
			return nil, false, errTDSProtocol
		}
		result = append(result, p...)
		if h[1]&1 != 0 {
			return result, reset, nil
		}
		seq++
	}
}
func tdsWrite(w io.Writer, kind byte, p []byte) error { return tdsWriteMessage(w, kind, p, false) }
func tdsWriteMessage(w io.Writer, kind byte, p []byte, reset bool) error {
	if len(p) == 0 || reset && kind != 1 {
		return errTDSProtocol
	}
	seq := byte(1)
	first := true
	for len(p) > 0 {
		n := len(p)
		if n > 4088 {
			n = 4088
		}
		h := []byte{kind, 0, 0, 0, 0, 0, seq, 0}
		if first && reset {
			h[1] |= 8
		}
		first = false
		if n == len(p) {
			h[1] |= 1
		}
		binary.BigEndian.PutUint16(h[2:], uint16(n+8))
		// SQL Server expects each encrypted TDS packet as one TLS write.
		// Splitting header/payload into separate records can abort LOGIN7.
		frame := make([]byte, n+8)
		copy(frame, h)
		copy(frame[8:], p[:n])
		for len(frame) > 0 {
			k, e := w.Write(frame)
			if e != nil || k <= 0 {
				return errTDSProtocol
			}
			frame = frame[k:]
		}

		p = p[n:]
		seq++
	}
	return nil
}
func tdsUTF16(s string) []byte {
	u := utf16.Encode([]rune(s))
	b := make([]byte, len(u)*2)
	for i, v := range u {
		binary.LittleEndian.PutUint16(b[i*2:], v)
	}
	return b
}
func tdsString(b []byte) (string, error) {
	if len(b)%2 != 0 {
		return "", errTDSProtocol
	}
	u := make([]uint16, len(b)/2)
	for i := range u {
		u[i] = binary.LittleEndian.Uint16(b[i*2:])
		if u[i] == 0 {
			return "", errTDSProtocol
		}
	}
	for i := 0; i < len(u); i++ {
		if u[i] >= 0xd800 && u[i] <= 0xdbff {
			if i+1 >= len(u) || u[i+1] < 0xdc00 || u[i+1] > 0xdfff {
				return "", errTDSProtocol
			}
			i++
		} else if u[i] >= 0xdc00 && u[i] <= 0xdfff {
			return "", errTDSProtocol
		}
	}
	return string(utf16.Decode(u)), nil
}
func tdsPassword(b []byte) []byte {
	out := append([]byte(nil), b...)
	for i, v := range out {
		out[i] = (v<<4 | v>>4) ^ 0xa5
	}
	return out
}
func tdsDecodePassword(b []byte) (string, error) {
	out := append([]byte(nil), b...)
	for i, v := range out {
		v ^= 0xa5
		out[i] = v<<4 | v>>4
	}
	return tdsString(out)
}

// No MARS, integrated/federated auth or session recovery is negotiated.
func tdsPrelogin(encryption byte) []byte {
	return []byte{0, 0, 16, 0, 6, 1, 0, 22, 0, 1, 4, 0, 23, 0, 1, 0xff, 16, 0, 0, 0, 0, 0, encryption, 0}
}
func tdsPreloginEncryption(p []byte) (byte, error) {
	seen := map[byte]bool{}
	end := 0
	type field struct {
		token    byte
		start, n int
	}
	var fields []field
	for i := 0; i < len(p); {
		token := p[i]
		if token == 0xff {
			end = i + 1
			break
		}
		if seen[token] || i+5 > len(p) {
			return 0, errTDSProtocol
		}
		seen[token] = true
		fields = append(fields, field{token, int(binary.BigEndian.Uint16(p[i+1:])), int(binary.BigEndian.Uint16(p[i+3:]))})
		i += 5
	}
	if end == 0 {
		return 0, errTDSProtocol
	}
	encryption := byte(255)
	for i, f := range fields {
		if f.n == 0 {
			if f.token == 1 || f.token == 4 {
				return 0, errTDSProtocol
			}
			continue
		}
		for _, prev := range fields[:i] {
			if f.start < prev.start+prev.n && f.start+f.n > prev.start {
				return 0, errTDSProtocol
			}
		}
		if f.start < end || f.n < 1 || f.start+f.n > len(p) {
			return 0, errTDSProtocol
		}
		switch f.token {
		case 1:
			if f.n != 1 {
				return 0, errTDSProtocol
			}
			encryption = p[f.start]
		case 4:
			if f.n != 1 || p[f.start] != 0 {
				return 0, errTDSProtocol
			}
		case 6:
			if f.n != 1 || p[f.start] != 0 {
				return 0, errTDSProtocol
			}
		case 7:
			return 0, errTDSProtocol
		}
	}
	if encryption > 3 {
		return 0, errTDSProtocol
	}
	return encryption, nil
}

// TLS handshake records are wrapped in PRELOGIN packets. Subsequent application
// TLS records travel directly on the socket (TDS 7.x full-session encryption).
type tdsHandshakeConn struct {
	net.Conn
	pending  []byte
	readKind byte
	wrapped  bool
}

func (c *tdsHandshakeConn) Read(b []byte) (int, error) {
	if !c.wrapped {
		return c.Conn.Read(b)
	}
	if len(c.pending) == 0 {
		p, e := tdsRead(c.Conn, c.readKind, 65536)
		if e != nil {
			return 0, e
		}
		c.pending = p
	}
	n := copy(b, c.pending)
	c.pending = c.pending[n:]
	return n, nil
}
func (c *tdsHandshakeConn) Write(b []byte) (int, error) {
	if !c.wrapped {
		return c.Conn.Write(b)
	}
	if e := tdsWrite(c.Conn, 18, b); e != nil {
		return 0, e
	}
	return len(b), nil
}
