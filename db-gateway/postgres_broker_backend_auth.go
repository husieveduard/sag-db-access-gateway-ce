package main

import (
 "encoding/binary"
 "errors"
 "fmt"
 "io"
 "net"
)

// pgBackendAuthChallenge reads one authentication frame from a TLS-protected
// PostgreSQL backend. Callers must not forward this frame to the SAG client:
// backend credentials and frontend credentials are deliberately independent.
func pgBackendAuthChallenge(conn net.Conn) (uint32, []byte, error) {
 kind, payload, _, err := readPgMessage(conn)
 if err != nil { return 0,nil,err }
 if kind == 'E' { return 0,nil,errors.New("backend PostgreSQL authentication rejected") }
 if kind != 'R' || len(payload)<4 { return 0,nil,errors.New("unexpected backend authentication frame") }
 return binary.BigEndian.Uint32(payload[:4]), payload[4:],nil
}

// pgBackendCleartextReply sends the backend password only inside the TLS
// channel. PostgreSQL SCRAM authentication (code 10) must be implemented
// separately; never fall back to plaintext or client_passthrough.
func pgBackendCleartextReply(conn net.Conn, password string) error {
 if password=="" || len(password)>4096 { return errors.New("invalid backend password") }
 for i:=0;i<len(password);i++ { if password[i]==0 { return errors.New("backend password contains NUL") } }
 body:=append([]byte(password),0)
 frame:=make([]byte,5+len(body))
 frame[0]='p'
 binary.BigEndian.PutUint32(frame[1:5],uint32(4+len(body)))
 copy(frame[5:],body)
 _,err:=conn.Write(frame)
 return err
}

// pgBackendAuthenticatePassword handles a narrow, explicit subset of
// PostgreSQL auth negotiation. This does NOT establish a complete SQL session
// (ParameterStatus, BackendKeyData and ReadyForQuery remain outstanding).
func pgBackendAuthenticatePassword(conn net.Conn, password string) error {
 code, data, err:=pgBackendAuthChallenge(conn)
 if err!=nil { return err }
 switch code {
 case 0:
  if len(data)!=0 {return errors.New("malformed PostgreSQL AuthenticationOk")}
  return nil
 case 3:
  if len(data)!=0 {return errors.New("malformed PostgreSQL cleartext challenge")}
  if err:=pgBackendCleartextReply(conn,password);err!=nil{return err}
  next, payload,err:=pgBackendAuthChallenge(conn)
  if err!=nil{return err}
  if next!=0||len(payload)!=0{return errors.New("backend authentication was not completed")}
  return nil
 case 10:
  return pgBackendSCRAM(conn,data,password)
 default:
  return fmt.Errorf("unsupported PostgreSQL backend authentication code: %d",code)
 }
}

var _ io.Reader = (net.Conn)(nil)
