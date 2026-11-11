package main

import (
 "crypto/tls"
 "encoding/binary"
 "net"
 "testing"
 "time"
)

func TestPgBackendStartup(t *testing.T) {
 pkt,err:=pgBackendStartup("sag_gateway_lab","sag_ce_lab")
 if err!=nil{t.Fatal(err)}
 u,d,err:=pgBrokerStartupUserDatabase(pkt)
 if err!=nil||u!="sag_gateway_lab"||d!="sag_ce_lab"{t.Fatalf("round trip: %q %q %v",u,d,err)}
 if _,err:=pgBackendStartup("bad\x00user","db");err==nil{t.Fatal("NUL accepted")}
}
func TestPgBackendTLSRejectsInsecureConfig(t *testing.T) {
 for _,c:=range []*tls.Config{nil,{}, {ServerName:"postgres.local",InsecureSkipVerify:true}} {
  if _,err:=pgBackendTLS("127.0.0.1:1",c);err==nil{t.Fatal("insecure TLS config accepted")}
 }
}
func TestPgBackendTLSRejectsPlaintextServer(t *testing.T) {
 listener,err:=net.Listen("tcp","127.0.0.1:0")
 if err!=nil{t.Fatal(err)}
 defer listener.Close()
 done:=make(chan struct{})
 go func(){
  defer close(done)
  c,e:=listener.Accept();if e!=nil{return}
  defer c.Close()
  _=c.SetDeadline(time.Now().Add(3*time.Second))
  request:=make([]byte,8)
  if _,e=c.Read(request);e!=nil{return}
  if binary.BigEndian.Uint32(request[4:])==80877103 {_,_=c.Write([]byte{'N'})}
 }()
 _,err=pgBackendTLS(listener.Addr().String(),&tls.Config{ServerName:"postgres.local",MinVersion:tls.VersionTLS12})
 if err==nil{t.Fatal("plaintext PostgreSQL accepted")}
 <-done
}
