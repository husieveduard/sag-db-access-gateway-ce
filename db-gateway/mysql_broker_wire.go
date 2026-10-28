package main

import (
	"bytes"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"strings"
)

const (
	myLong         uint32 = 1
	myLongFlag     uint32 = 4
	myDatabase     uint32 = 8
	myProtocol     uint32 = 0x200
	mySSL          uint32 = 0x800
	myTransactions uint32 = 0x2000
	mySecure       uint32 = 0x8000
	myPlugin       uint32 = 0x80000
	myCapabilities        = myLong | myLongFlag | myDatabase | myProtocol | mySSL | myTransactions | mySecure | myPlugin
)

var errMyProtocol = errors.New("MySQL broker protocol rejected")

func myRead(r io.Reader, seq *byte) ([]byte, error) {
	var h [4]byte
	if _, e := io.ReadFull(r, h[:]); e != nil {
		return nil, errMyProtocol
	}
	n := int(h[0]) | int(h[1])<<8 | int(h[2])<<16
	if n < 1 || n > 1024*1024 || h[3] != *seq {
		return nil, errMyProtocol
	}
	*seq++
	p := make([]byte, n)
	if _, e := io.ReadFull(r, p); e != nil {
		return nil, errMyProtocol
	}
	return p, nil
}
func myWrite(w io.Writer, seq *byte, p []byte) error {
	if len(p) == 0 || len(p) > 1024*1024 {
		return errMyProtocol
	}
	h := []byte{byte(len(p)), byte(len(p) >> 8), byte(len(p) >> 16), *seq}
	*seq++
	if _, e := io.Copy(w, bytes.NewReader(append(h, p...))); e != nil {
		return errMyProtocol
	}
	return nil
}
func myCString(p []byte) (string, []byte, error) {
	for i, b := range p {
		if b == 0 {
			return string(p[:i]), p[i+1:], nil
		}
	}
	return "", nil, errMyProtocol
}
func myScramble(plugin, password string, seed []byte) ([]byte, error) {
	if password == "" {
		return nil, nil
	}
	switch plugin {
	case "mysql_native_password":
		a := sha1.Sum([]byte(password))
		b := sha1.Sum(a[:])
		h := sha1.New()
		h.Write(seed)
		h.Write(b[:])
		c := h.Sum(nil)
		for i := range c {
			c[i] ^= a[i]
		}
		return c, nil
	case "caching_sha2_password":
		a := sha256.Sum256([]byte(password))
		b := sha256.Sum256(a[:])
		h := sha256.New()
		h.Write(b[:])
		h.Write(seed)
		c := h.Sum(nil)
		for i := range c {
			c[i] ^= a[i]
		}
		return c, nil
	case "mysql_clear_password":
		return append([]byte(password), 0), nil
	default:
		return nil, errMyProtocol
	}
}
func myGreeting(seed []byte) []byte {
	p := append([]byte{10}, []byte("8.0.0-SAG-CE\x00")...)
	p = append(p, 1, 0, 0, 0)
	p = append(p, seed[:8]...)
	p = append(p, 0)
	p = append(p, byte(myCapabilities&255), byte((myCapabilities>>8)&255), 45, 2, 0, byte((myCapabilities>>16)&255), byte(myCapabilities>>24), 21)
	p = append(p, make([]byte, 10)...)
	p = append(p, seed[8:20]...)
	p = append(p, 0)
	return append(p, []byte("mysql_clear_password\x00")...)
}
func myParseGreeting(p []byte) (uint32, []byte, string, error) {
	if len(p) < 2 || p[0] != 10 {
		return 0, nil, "", errMyProtocol
	}
	_, r, e := myCString(p[1:])
	if e != nil || len(r) < 4+8+1+2+1+2+2+1+10 {
		return 0, nil, "", errMyProtocol
	}
	seed := append([]byte(nil), r[4:12]...)
	if r[12] != 0 {
		return 0, nil, "", errMyProtocol
	}
	cap := uint32(binary.LittleEndian.Uint16(r[13:15])) | uint32(binary.LittleEndian.Uint16(r[18:20]))<<16
	n := int(r[20]) - 8
	if n < 13 {
		n = 13
	}
	r = r[31:]
	if len(r) < n || n < 12 {
		return 0, nil, "", errMyProtocol
	}
	seed = append(seed, r[:12]...)
	plugin := "mysql_native_password"
	if cap&myPlugin != 0 {
		plugin, _, e = myCString(r[n:])
		if e != nil {
			return 0, nil, "", errMyProtocol
		}
	}
	return cap, seed, plugin, nil
}
func myLength(p []byte) (uint64, int, error) {
	if len(p) == 0 {
		return 0, 0, errMyProtocol
	}
	switch p[0] {
	case 0xfc:
		if len(p) >= 3 {
			return uint64(binary.LittleEndian.Uint16(p[1:])), 3, nil
		}
	case 0xfd:
		if len(p) >= 4 {
			return uint64(p[1]) | uint64(p[2])<<8 | uint64(p[3])<<16, 4, nil
		}
	case 0xfe:
		if len(p) >= 9 {
			return binary.LittleEndian.Uint64(p[1:]), 9, nil
		}
	case 0xfb, 0xff:
		return 0, 0, errMyProtocol
	default:
		return uint64(p[0]), 1, nil
	}
	return 0, 0, errMyProtocol
}
func myStatus(p []byte) error {
	if len(p) == 5 && p[0] == 0xfe {
		if binary.LittleEndian.Uint16(p[3:])&8 != 0 {
			return errMyProtocol
		}
		return nil
	}
	if len(p) < 7 || p[0] != 0 {
		return errMyProtocol
	}
	_, n, e := myLength(p[1:])
	if e != nil {
		return e
	}
	_, m, e := myLength(p[1+n:])
	if e != nil || len(p) < 1+n+m+4 {
		return errMyProtocol
	}
	if binary.LittleEndian.Uint16(p[1+n+m:])&8 != 0 {
		return errMyProtocol
	}
	return nil
}

// Text results only: no LOCAL INFILE, multiple results, prepared statements or
// session identity changes. Validate the full framing before each packet relay.
func myRelayResult(backend net.Conn, frontend io.Writer) error {
	seq := byte(1)
	p, e := myRead(backend, &seq)
	if e != nil {
		return e
	}
	relay := func(p []byte) error { s := seq - 1; return myWrite(frontend, &s, p) }
	if p[0] == 0xff {
		return relay(p)
	}
	if p[0] == 0 {
		if myStatus(p) != nil {
			return errMyProtocol
		}
		return relay(p)
	}
	count, used, e := myLength(p)
	if e != nil || count == 0 || count > 1024 || used != len(p) {
		return errMyProtocol
	}
	if relay(p) != nil {
		return errMyProtocol
	}
	for i := uint64(0); i < count; i++ {
		p, e = myRead(backend, &seq)
		if e != nil || p[0] == 0xfb {
			return errMyProtocol
		}
		if relay(p) != nil {
			return errMyProtocol
		}
	}
	p, e = myRead(backend, &seq)
	if e != nil || len(p) != 5 || myStatus(p) != nil {
		return errMyProtocol
	}
	if relay(p) != nil {
		return errMyProtocol
	}
	total := 0
	for i := 0; i < 100000; i++ {
		p, e = myRead(backend, &seq)
		if e != nil {
			return e
		}
		total += len(p)
		if total > 64*1024*1024 {
			return errMyProtocol
		}
		if p[0] == 0xff {
			return relay(p)
		}
		if p[0] == 0xfe && len(p) < 9 {
			if myStatus(p) != nil {
				return errMyProtocol
			}
			return relay(p)
		}
		r := p
		for col := uint64(0); col < count; col++ {
			if len(r) == 0 {
				return errMyProtocol
			}
			if r[0] == 0xfb {
				r = r[1:]
				continue
			}
			n, k, e := myLength(r)
			if e != nil || n > uint64(len(r)-k) {
				return errMyProtocol
			}
			r = r[k+int(n):]
		}
		if len(r) != 0 {
			return errMyProtocol
		}
		if relay(p) != nil {
			return errMyProtocol
		}
	}
	return errMyProtocol
}
func mySafeName(s string) bool { return s != "" && len(s) <= 128 && !strings.ContainsRune(s, 0) }
