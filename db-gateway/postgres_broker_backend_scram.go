package main

import (
 "bytes"
 "encoding/binary"
 "errors"
 "net"
)

func pgSASLFrame(conn net.Conn, payload []byte) error {
 if len(payload)>16384 {return errors.New("SASL frame too large")}
 frame:=make([]byte,5+len(payload))
 frame[0]='p'
 binary.BigEndian.PutUint32(frame[1:5],uint32(4+len(payload)))
 copy(frame[5:],payload)
 _,err:=conn.Write(frame)
 return err
}

// pgBackendSCRAM authenticates exclusively with SCRAM-SHA-256 over
// an already certificate-verified backend TLS transport.
// SASL channel binding is unavailable here: SCRAM-SHA-256-PLUS is NOT offered.
func pgBackendSCRAM(conn net.Conn, mechanisms []byte, password string) error {
 if len(mechanisms)<2||len(mechanisms)>4096||mechanisms[len(mechanisms)-1]!=0 {
  return errors.New("invalid backend SASL mechanisms")
 }
 offered:=false
 for _,mechanism:=range bytes.Split(mechanisms[:len(mechanisms)-1],[]byte{0}) {
  if string(mechanism)=="SCRAM-SHA-256" {offered=true}
 }
 if !offered{return errors.New("SCRAM-SHA-256 not offered")}
 scram,err:=newPgSCRAMClient(password)
 if err!=nil{return err}
 first:=[]byte(scram.first())
 initial:=make([]byte,0,15+len(first))
 initial=append(initial,[]byte("SCRAM-SHA-256")...)
 initial=append(initial,0)
 var length [4]byte
 binary.BigEndian.PutUint32(length[:],uint32(len(first)))
 initial=append(initial,length[:]...)
 initial=append(initial,first...)
 if err=pgSASLFrame(conn,initial);err!=nil{return err}
 code,data,err:=pgBackendAuthChallenge(conn)
 if err!=nil{return err}
 if code!=11||len(data)>16384{return errors.New("expected AuthenticationSASLContinue")}
 final,err:=scram.continueWith(string(data))
 if err!=nil{return err}
 if err=pgSASLFrame(conn,[]byte(final));err!=nil{return err}
 code,data,err=pgBackendAuthChallenge(conn)
 if err!=nil{return err}
 if code!=12||len(data)>16384{return errors.New("expected AuthenticationSASLFinal")}
 if err=scram.finish(string(data));err!=nil{return err}
 code,data,err=pgBackendAuthChallenge(conn)
 if err!=nil{return err}
 if code!=0||len(data)!=0{return errors.New("expected AuthenticationOk after verified SCRAM")}
 return nil
}
