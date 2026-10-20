package main

import (
 "bytes"
 "io"
 "net"
 "sync/atomic"
 "testing"
 "time"
)

func TestMSSQLOpaqueTransportPreservesTDSAndTLSBytes(t *testing.T) {
 cases := [][]byte{
  {0x12,0x01,0x00,0x08,0,0,1,0}, // TDS PRELOGIN
  {0x16,0x03,0x03,0x00,0x06,0x01,0x00,0x00,0x02,0x03,0x03}, // TLS record
  {0x01,0x01,0x00,0x0c,0,0,1,0,0x53,0,0x51,0}, // SQLBatch
  {0x03,0x01,0x00,0x0c,0,0,1,0,0xde,0xad,0xbe,0xef}, // RPC
 }
 for _, payload := range cases {
  t.Run(string([]byte{'p',byte('0'+payload[0]%10)}),func(t *testing.T) {
   client, ingress:=net.Pipe()
   egress, server:=net.Pipe()
   state:=&ConnState{}
   relayDone:=make(chan struct{})
   go func(){relayOpaqueTCP(ingress,egress,state);close(relayDone)}()
   result:=make(chan []byte,1)
   go func(){buf:=make([]byte,len(payload));_,err:=io.ReadFull(server,buf);if err==nil {result<-buf} else {result<-nil}}()
   if _,err:=client.Write(payload);err!=nil {t.Fatal(err)}
   select {
   case got:=<-result:
    if !bytes.Equal(got,payload) {t.Fatalf("payload altered: %x != %x",got,payload)}
   case <-time.After(2*time.Second):t.Fatal("relay stalled")
   }
   reply:=[]byte{0x04,0x01,0x00,0x0c,0,0,1,0,0x11,0x22,0x33,0x44}
   incoming:=make(chan []byte,1)
   go func(){buf:=make([]byte,len(reply));_,err:=io.ReadFull(client,buf);if err==nil {incoming<-buf} else {incoming<-nil}}()
   if _,err:=server.Write(reply);err!=nil {t.Fatal(err)}
   select {
   case got:=<-incoming:
    if !bytes.Equal(got,reply){t.Fatalf("server bytes altered: %x != %x",got,reply)}
   case <-time.After(2*time.Second):t.Fatal("reverse relay stalled")
   }
   client.Close();server.Close()
   select {case <-relayDone:case <-time.After(2*time.Second):t.Fatal("relay did not close")}
   if got:=atomic.LoadUint64(&state.bytesC2D);got!=uint64(len(payload)) {t.Fatalf("C2D bytes: %d",got)}
   if got:=atomic.LoadUint64(&state.bytesD2C);got!=uint64(len(reply)) {t.Fatalf("D2C bytes: %d",got)}
  })
 }
}

func TestMSSQLUsesMetadataOnlyMode(t *testing.T) {
 a:=&MSSQLAdapter{}
 if a.Mode()!="mssql-opaque-tls-passthrough-metadata-only" {t.Fatal(a.Mode())}
}
