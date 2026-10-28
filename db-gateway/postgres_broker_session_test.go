package main

import (
 "encoding/binary"
 "net"
 "testing"
 "time"
)

func TestPgBrokerFrontendReadyProtocol(t *testing.T) {
 client,server:=net.Pipe()
 defer client.Close()
 defer server.Close()
 _=client.SetDeadline(time.Now().Add(3*time.Second))
 _=server.SetDeadline(time.Now().Add(3*time.Second))
 done:=make(chan error,1)
 go func(){done<-pgBrokerSendFrontendReady(server)}()
 kind,payload,_,err:=readPgMessage(client)
 if err!=nil||kind!='R'||len(payload)!=4||binary.BigEndian.Uint32(payload)!=0 {
  t.Fatalf("invalid AuthenticationOk: %v",err)
 }
 count:=0
 for {
  kind,payload,_,err=readPgMessage(client)
  if err!=nil{t.Fatal(err)}
  if kind=='Z' {
   if len(payload)!=1||payload[0]!='I'{t.Fatal("invalid ReadyForQuery")}
   break
  }
  if kind!='S'{t.Fatalf("unexpected startup message %q",kind)}
  count++
 }
 if count<2{t.Fatal("missing parameter status")}
 if err:=<-done;err!=nil{t.Fatal(err)}
}

func TestPgBrokerLargeFrameRoundTrip(t *testing.T) {
 client,server:=net.Pipe()
 defer client.Close()
 defer server.Close()
 _=client.SetDeadline(time.Now().Add(3*time.Second))
 _=server.SetDeadline(time.Now().Add(3*time.Second))
 payload:=make([]byte,128*1024)
 for i:=range payload {payload[i]=byte(i%251)}
 done:=make(chan error,1)
 go func(){done<-pgBrokerFrame(server,'D',payload)}()
 kind,got,_,err:=readPgMessage(client)
 if err!=nil{t.Fatal(err)}
 if kind!='D'||len(got)!=len(payload){t.Fatal("incomplete frame")}
 for i:=range payload{if got[i]!=payload[i]{t.Fatalf("frame mismatch at %d",i)}}
 if err:=<-done;err!=nil{t.Fatal(err)}
}
