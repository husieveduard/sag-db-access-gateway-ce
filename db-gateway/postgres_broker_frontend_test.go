package main

import (
 "context"
 "crypto/rand"
 "crypto/rsa"
 "crypto/tls"
 "crypto/x509"
 "crypto/x509/pkix"
 "encoding/binary"
 "math/big"
 "net"
 "net/http"
 "net/http/httptest"
 "testing"
 "time"
)

func testBrokerCertificate(t *testing.T) tls.Certificate {
 t.Helper()
 key,err:=rsa.GenerateKey(rand.Reader,2048);if err!=nil{t.Fatal(err)}
 cert,err:=x509.CreateCertificate(rand.Reader,&x509.Certificate{
  SerialNumber:big.NewInt(1),Subject:pkix.Name{CommonName:"localhost"},
  NotBefore:time.Now().Add(-time.Hour),NotAfter:time.Now().Add(time.Hour),
  KeyUsage:x509.KeyUsageDigitalSignature,ExtKeyUsage:[]x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
  DNSNames:[]string{"localhost"},
 },&x509.Certificate{
  SerialNumber:big.NewInt(1),Subject:pkix.Name{CommonName:"localhost"},
  NotBefore:time.Now().Add(-time.Hour),NotAfter:time.Now().Add(time.Hour),
  KeyUsage:x509.KeyUsageDigitalSignature,ExtKeyUsage:[]x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
  DNSNames:[]string{"localhost"},
 },&key.PublicKey,key);if err!=nil{t.Fatal(err)}
 return tls.Certificate{Certificate:[][]byte{cert},PrivateKey:key}
}

func TestBrokerFrontendRejectsInvalidCredentialsOverTLS(t *testing.T) {
 cert:=testBrokerCertificate(t)
 server,client:=net.Pipe()
 defer server.Close();defer client.Close()
 _=server.SetDeadline(time.Now().Add(5*time.Second))
 _=client.SetDeadline(time.Now().Add(5*time.Second))
 upstream:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  w.Header().Set("Content-Type","application/json")
  _,_=w.Write([]byte(`{"ok":true,"allowed":false}`))
 }))
 defer upstream.Close()
 done:=make(chan error,1)
 go func(){
  _,err:=pgBrokerAuthenticateFrontend(context.Background(),server,&tls.Config{
   MinVersion:tls.VersionTLS12,Certificates:[]tls.Certificate{cert},
  },upstream.Client(),upstream.URL,"token","session","sag_ce_lab")
  done<-err
 }()
 sslRequest:=make([]byte,8);binary.BigEndian.PutUint32(sslRequest[:4],8);binary.BigEndian.PutUint32(sslRequest[4:],80877103)
 if _,err:=client.Write(sslRequest);err!=nil{t.Fatal(err)}
 ack:=make([]byte,1);if _,err:=client.Read(ack);err!=nil{t.Fatal(err)}
 if ack[0]!='S'{t.Fatal("gateway did not negotiate TLS")}
 secure:=tls.Client(client,&tls.Config{MinVersion:tls.VersionTLS12,InsecureSkipVerify:true}) // local self-signed test only
 if err:=secure.Handshake();err!=nil{t.Fatal(err)}
 if _,err:=secure.Write(pgTestStartup("user","sag_tmp","database","sag_ce_lab"));err!=nil{t.Fatal(err)}
 challenge:=make([]byte,9)
 if _,err:=secure.Read(challenge);err!=nil{t.Fatal(err)}
 if challenge[0]!='R'{t.Fatal("missing auth challenge")}
 pass:=[]byte("wrong\x00")
 frame:=make([]byte,5+len(pass));frame[0]='p'
 binary.BigEndian.PutUint32(frame[1:5],uint32(len(pass)+4));copy(frame[5:],pass)
 if _,err:=secure.Write(frame);err!=nil{t.Fatal(err)}
 if err:=<-done;err==nil{t.Fatal("broker accepted rejected credentials")}
}
