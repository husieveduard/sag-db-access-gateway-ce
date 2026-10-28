package main

import (
	"crypto/tls"
	"encoding/binary"
	"net"
	"strings"
	"time"
)

// Always verified backend TLS. Full caching_sha2 auth never sends a password
// until the peer certificate and hostname have been verified.
func myBackendConnect(address string, tlsConfig *tls.Config, user, password, database string) (net.Conn, error) {
	if tlsConfig == nil || tlsConfig.InsecureSkipVerify || !mySafeName(user) || !mySafeName(database) || password == "" || len(password) > 4096 || strings.ContainsRune(password, 0) {
		return nil, errMyProtocol
	}
	raw, e := net.DialTimeout("tcp", address, 5*time.Second)
	if e != nil {
		return nil, errMyProtocol
	}
	ok := false
	defer func() {
		if !ok {
			raw.Close()
		}
	}()
	raw.SetDeadline(time.Now().Add(15 * time.Second))
	seq := byte(0)
	g, e := myRead(raw, &seq)
	if e != nil {
		return nil, e
	}
	caps, seed, plugin, e := myParseGreeting(g)
	required := myProtocol | mySSL | mySecure | myPlugin | myDatabase
	if e != nil || caps&required != required {
		return nil, errMyProtocol
	}
	flags := myCapabilities & caps
	header := make([]byte, 32)
	binary.LittleEndian.PutUint32(header, flags)
	binary.LittleEndian.PutUint32(header[4:], 1024*1024)
	header[8] = 45
	if myWrite(raw, &seq, header) != nil {
		return nil, errMyProtocol
	}
	config := tlsConfig.Clone()
	if config.ServerName == "" {
		config.ServerName, _, e = net.SplitHostPort(address)
		if e != nil {
			return nil, errMyProtocol
		}
	}
	config.MinVersion = tls.VersionTLS12
	secure := tls.Client(raw, config)
	if secure.Handshake() != nil {
		return nil, errMyProtocol
	}
	auth, e := myScramble(plugin, password, seed)
	if e != nil || len(auth) > 255 {
		return nil, errMyProtocol
	}
	response := append(header, []byte(user)...)
	response = append(response, 0, byte(len(auth)))
	response = append(response, auth...)
	response = append(response, []byte(database)...)
	response = append(response, 0)
	response = append(response, []byte(plugin)...)
	response = append(response, 0)
	if myWrite(secure, &seq, response) != nil {
		return nil, errMyProtocol
	}
	for i := 0; i < 8; i++ {
		p, e := myRead(secure, &seq)
		if e != nil {
			return nil, e
		}
		switch p[0] {
		case 0:
			if myStatus(p) != nil {
				return nil, errMyProtocol
			}
			secure.SetDeadline(time.Time{})
			ok = true
			return secure, nil
		case 0xff:
			return nil, errMyProtocol
		case 0xfe:
			var r []byte
			plugin, r, e = myCString(p[1:])
			if e != nil || len(r) < 20 {
				return nil, errMyProtocol
			}
			auth, e := myScramble(plugin, password, r[:20])
			if e != nil {
				return nil, e
			}
			if myWrite(secure, &seq, auth) != nil {
				return nil, errMyProtocol
			}
		case 1:
			if plugin != "caching_sha2_password" || len(p) != 2 {
				return nil, errMyProtocol
			}
			if p[1] == 3 {
				continue
			}
			if p[1] != 4 {
				return nil, errMyProtocol
			}
			if myWrite(secure, &seq, append([]byte(password), 0)) != nil {
				return nil, errMyProtocol
			}
		default:
			return nil, errMyProtocol
		}
	}
	return nil, errMyProtocol
}
