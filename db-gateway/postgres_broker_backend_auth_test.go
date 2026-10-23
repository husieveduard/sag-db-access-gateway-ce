package main

import (
 "encoding/binary"
 "io"
 "net"
 "strings"
 "testing"
 "time"
)

func backendAuthFrame(code uint32, extra []byte) []byte {
 payload:=make([]byte,4+len(extra))
 binary.BigEndian.PutUint32(payload[:4],code)
 copy(payload[4:],extra)
 msg:=make([]byte,5+len(payload))
 msg[0]='R'
 binary.BigEndian.PutUint32(msg[1:5],uint32(4+len(payload)))
 copy(msg[5:],payload)
 return msg
}
func TestPgBackendRejectsSCRAMWithoutFallback(t *testing.T) {
 client,server:=net.Pipe();defer client.Close();defer server.Close()
 _=client.SetDeadline(time.Now().Add(2*time.Second))
 _=server.SetDeadline(time.Now().Add(2*time.Second))
 result:=make(chan error,1)
 go func(){result<-pgBackendAuthenticatePassword(client,"vault-password")}()
 mechanisms:=[]byte("SCRAM-SHA-256\x00\x00")
 if _,err:=server.Write(backendAuthFrame(10,mechanisms));err!=nil{t.Fatal(err)}
 err:=<-result
 if err==nil||!strings.Contains(err.Error(),"not yet implemented"){t.Fatalf("SCRAM must fail closed: %v",err)}
}
func TestPgBackendPasswordAuthOverExistingTransport(t *testing.T) {
 client,server:=net.Pipe();defer client.Close();defer server.Close()
 _=client.SetDeadline(time.Now().Add(2*time.Second))
 _=server.SetDeadline(time.Now().Add(2*time.Second))
 result:=make(chan error,1)
 go func(){result<-pgBackendAuthenticatePassword(client,"vault-password")}()
 if _,err:=server.Write(backendAuthFrame(3,nil));err!=nil{t.Fatal(err)}
 kind,payload,_,err:=readPgMessage(server)
 if err!=nil||kind!='p'||string(payload)!="vault-password\x00"{t.Fatalf("invalid reply: %v",err)}
 if _,err:=server.Write(backendAuthFrame(0,nil));err!=nil{t.Fatal(err)}
 if err:=<-result;err!=nil{t.Fatal(err)}
}
func TestPgBackendRejectsUnexpectedAuthMessage(t *testing.T){
 client,server:=net.Pipe();defer client.Close();defer server.Close()
 _=client.SetDeadline(time.Now().Add(2*time.Second))
 _=server.SetDeadline(time.Now().Add(2*time.Second))
 result:=make(chan error,1)
 go func(){result<-pgBackendAuthenticatePassword(client,"vault-password")}()
 msg:=[]byte{'Z',0,0,0,5,'I'}
 if _,err:=server.Write(msg);err!=nil{t.Fatal(err)}
 if err:=<-result;err==nil{t.Fatal("accepted unexpected frame")}
}
var _ io.Writer = (net.Conn)(nil)
