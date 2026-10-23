package main

import (
 "crypto/tls"
 "encoding/binary"
 "net"
 "testing"
)

func TestPostgresBrokerTLSFailsClosedWithoutCertificate(t *testing.T) {
 client, server := net.Pipe()
 defer client.Close()
 defer server.Close()
 if _, err := upgradePostgresFrontendTLS(server, nil); err == nil {
  t.Fatal("accepted missing TLS identity")
 }
}

func TestPostgresBrokerTLSRejectsPlaintextStartup(t *testing.T) {
 client, server := net.Pipe()
 defer client.Close()
 defer server.Close()
 cert := tls.Certificate{Certificate: [][]byte{{1}}, PrivateKey: nil}
 done := make(chan error, 1)
 go func() {
  _, err := upgradePostgresFrontendTLS(server, &tls.Config{MinVersion: tls.VersionTLS12, Certificates: []tls.Certificate{cert}})
  done <- err
 }()
 packet := make([]byte, 8)
 binary.BigEndian.PutUint32(packet[:4], 8)
 binary.BigEndian.PutUint32(packet[4:], 196608)
 if _, err := client.Write(packet); err != nil { t.Fatal(err) }
 if err := <-done; err == nil { t.Fatal("accepted plaintext PostgreSQL startup") }
}
