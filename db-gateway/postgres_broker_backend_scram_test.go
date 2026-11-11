package main

import (
 "encoding/base64"
 "encoding/binary"
 "net"
 "strings"
 "testing"
 "time"
)
func TestPgBackendSCRAMRejectsUntrustedMechanisms(t *testing.T) {
 client,server:=net.Pipe()
 defer client.Close();defer server.Close()
 if err:=pgBackendSCRAM(client,[]byte("SCRAM-SHA-256-PLUS\x00\x00"),"secret");err==nil{t.Fatal("accepted unsupported mechanism")}
}
func TestPgBackendSCRAMRejectsInvalidServerSignature(t *testing.T) {
 client,server:=net.Pipe()
 defer client.Close();defer server.Close()
 _=client.SetDeadline(time.Now().Add(3*time.Second))
 _=server.SetDeadline(time.Now().Add(3*time.Second))
 done:=make(chan error,1)
 go func(){done<-pgBackendSCRAM(client,[]byte("SCRAM-SHA-256\x00\x00"),"vault-secret")}()
 kind,payload,_,err:=readPgMessage(server)
 if err!=nil||kind!='p'{t.Fatalf("initial SASL: %v",err)}
 prefix:=[]byte("SCRAM-SHA-256\x00")
 if !strings.HasPrefix(string(payload),string(prefix)){t.Fatal("unexpected SASL mechanism")}
 offset:=len(prefix)
 size:=int(binary.BigEndian.Uint32(payload[offset:offset+4]))
 first:=string(payload[offset+4:])
 if size!=len(first)||!strings.HasPrefix(first,"n,,n=,r="){t.Fatal("invalid SASL initial")}
 nonce:=strings.TrimPrefix(first,"n,,n=,r=")
 challenge:="r="+nonce+"servernonce,s="+base64.StdEncoding.EncodeToString([]byte("long-unique-salt"))+",i=4096"
 if _,err:=server.Write(backendAuthFrame(11,[]byte(challenge)));err!=nil{t.Fatal(err)}
 kind,_,_,err=readPgMessage(server)
 if err!=nil||kind!='p'{t.Fatalf("client proof: %v",err)}
 forged:="v="+base64.StdEncoding.EncodeToString(make([]byte,32))
 if _,err:=server.Write(backendAuthFrame(12,[]byte(forged)));err!=nil{t.Fatal(err)}
 if err:=<-done;err==nil||!strings.Contains(err.Error(),"signature mismatch"){t.Fatalf("forged signature accepted: %v",err)}
}
