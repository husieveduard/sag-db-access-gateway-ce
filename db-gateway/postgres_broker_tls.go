package main

import (
 "crypto/tls"
 "errors"
 "io"
 "net"
 "time"
)

// upgradePostgresFrontendTLS negotiates a PostgreSQL SSLRequest using a
// configured server identity. This is a broker primitive, not enabled by
// client_passthrough. A broker must refuse plaintext authentication.
func upgradePostgresFrontendTLS(conn net.Conn, config *tls.Config) (*tls.Conn, error) {
 if config == nil || len(config.Certificates) == 0 {
  return nil, errors.New("postgres broker TLS server certificate unavailable")
 }
 _ = conn.SetDeadline(time.Now().Add(10 * time.Second))
 first, err := readPgStartupPacket(conn)
 if err != nil { return nil, err }
 if !isPgSSLRequest(first) {
  return nil, errors.New("postgres broker requires SSLRequest before authentication")
 }
 if _, err := conn.Write([]byte{'S'}); err != nil { return nil, err }
 tlsConn := tls.Server(conn, config)
 if err := tlsConn.Handshake(); err != nil { return nil, err }
 _ = tlsConn.SetDeadline(time.Time{})
 return tlsConn, nil
}

// readBrokerStartupPacket extracts the first protocol startup frame *after*
// TLS is established. A repeated SSLRequest or protocol mutation is denied.
func readBrokerStartupPacket(conn net.Conn) ([]byte, error) {
 packet, err := readPgStartupPacket(conn)
 if err != nil { return nil, err }
 if isPgSSLRequest(packet) { return nil, errors.New("nested postgres SSLRequest") }
 if len(packet) < 8 { return nil, io.ErrUnexpectedEOF }
 return packet, nil
}
