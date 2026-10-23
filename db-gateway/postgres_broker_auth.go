package main

import (
 "bytes"
 "encoding/binary"
 "errors"
 "fmt"
 "io"
 "net"
 "strings"
)

// pgBrokerStartupUserDatabase accepts only a normal protocol 3.0 startup
// frame, never an SSL/GSS request or a cancel packet.
func pgBrokerStartupUserDatabase(packet []byte) (string, string, error) {
 if len(packet) < 8 || len(packet) > 10000 || int(binary.BigEndian.Uint32(packet[:4])) != len(packet) {
  return "", "", errors.New("invalid postgres startup packet")
 }
 if binary.BigEndian.Uint32(packet[4:8]) != 196608 {
  return "", "", errors.New("unsupported postgres startup protocol")
 }
 body := packet[8:]
 if len(body) < 2 || body[len(body)-1] != 0 || body[len(body)-2] != 0 {
  return "", "", errors.New("malformed postgres startup options")
 }
 fields := bytes.Split(body[:len(body)-2], []byte{0})
 if len(fields)%2 != 0 { return "", "", errors.New("uneven postgres startup options") }
 options := make(map[string]string, len(fields)/2)
 for i:=0; i<len(fields); i+=2 {
  key, value := string(fields[i]), string(fields[i+1])
  if key == "" || strings.ContainsRune(value, 0) {
   return "", "", errors.New("invalid postgres startup option")
  }
  if _, exists:=options[key]; exists { return "", "", errors.New("duplicate postgres startup option") }
  options[key] = value
 }
 user, db := options["user"], options["database"]
 if user == "" || db == "" { return "", "", errors.New("postgres startup requires explicit user and database") }
 return user, db, nil
}

// pgBrokerReadPassword reads PostgreSQL PasswordMessage. Caller MUST have
// completed server-authenticated TLS before invoking this function.
func pgBrokerReadPassword(conn net.Conn) (string, error) {
 kind, payload, _, err := readPgMessage(conn)
 if err != nil { return "", err }
 if kind != 'p' || len(payload) < 2 || len(payload) > 4097 || payload[len(payload)-1] != 0 ||
  bytes.IndexByte(payload[:len(payload)-1], 0) >= 0 {
  return "", errors.New("invalid postgres password message")
 }
 return string(payload[:len(payload)-1]), nil
}

func pgBrokerWriteAuthenticationCleartext(conn net.Conn) error {
 var frame [9]byte
 frame[0] = 'R'
 binary.BigEndian.PutUint32(frame[1:5], 8)
 binary.BigEndian.PutUint32(frame[5:9], 3)
 _, err := conn.Write(frame[:])
 return err
}

// pgBrokerVerifyFrontend is an isolated auth primitive. It must NOT be
// called for ordinary passthrough connections. The caller owns TLS, session
// checks, authorization and backend connection setup.
func pgBrokerVerifyFrontend(conn net.Conn, startup []byte, expectedDB string, verify func(string,string) bool) error {
 username, db, err := pgBrokerStartupUserDatabase(startup)
 if err != nil { return err }
 if db != expectedDB { return fmt.Errorf("postgres target database not allowed") }
 if verify == nil { return errors.New("no credential verifier configured") }
 if err := pgBrokerWriteAuthenticationCleartext(conn); err != nil { return err }
 password, err := pgBrokerReadPassword(conn)
 if err != nil { return err }
 if !verify(username, password) { return errors.New("postgres client authentication rejected") }
 return nil
}

var _ io.Reader = (*net.TCPConn)(nil)
