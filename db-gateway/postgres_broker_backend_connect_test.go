package main

import (
 "crypto/tls"
 "net"
 "strings"
 "testing"
)

func TestPgBackendTransportPolicyRejectsImplicitDowngrade(t *testing.T) {
 for _,mode:=range []string{"", "prefer", "allow", "require", "invalid"} {
  if _,err:=pgBrokerBackendConnectWithMode("127.0.0.1:1",mode,nil,"user","secret","db");err==nil||!strings.Contains(err.Error(),"unsupported") {
   t.Fatalf("mode %q must be rejected: %v",mode,err)
  }
 }
 if _,err:=pgBrokerBackendConnectWithMode("127.0.0.1:1","verify-full",&tls.Config{InsecureSkipVerify:true},"user","secret","db");err==nil {
  t.Fatal("unverified TLS configuration accepted")
 }
}

func TestPgBackendExplicitPlaintextModeReachesServer(t *testing.T) {
 listener,err:=net.Listen("tcp","127.0.0.1:0")
 if err!=nil{t.Fatal(err)}
 defer listener.Close()
 done:=make(chan bool,1)
 go func(){
  conn,e:=listener.Accept()
  if e!=nil {done<-false;return}
  defer conn.Close()
  buf:=make([]byte,256)
  n,e:=conn.Read(buf)
  done<-e==nil&&n>=8&&buf[4]==0&&buf[5]==3
 }()
 _,err=pgBrokerBackendConnectWithMode(listener.Addr().String(),"disable",nil,"sag_gateway_lab","secret","sag_ce_lab")
 if err==nil{t.Fatal("incomplete PostgreSQL auth must not succeed")}
 if !<-done{t.Fatal("plaintext mode did not send PostgreSQL StartupMessage")}
}
