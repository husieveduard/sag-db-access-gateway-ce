package main

import (
 "crypto/hmac"
 "crypto/sha256"
 "encoding/base64"
 "strings"
 "testing"
)

func TestSCRAMPBKDF2Vector(t *testing.T) {
 got:=pgSCRAMPBKDF2([]byte("password"),[]byte("salt"),4096)
 want:="c5e478d59288c841aa530db6845c4c8d962893a001ce4e11a4963873aa98134a"
 if fmtHex(got)!=want{t.Fatalf("PBKDF2 mismatch: %s",fmtHex(got))}
}
func fmtHex(data []byte)string{ const alphabet="0123456789abcdef"; out:=make([]byte,len(data)*2);for i,b:=range data{out[2*i]=alphabet[b>>4];out[2*i+1]=alphabet[b&15]};return string(out)}
func TestSCRAMClientProofAndSignature(t *testing.T){
 s:=&pgSCRAMClient{password:"correct-horse",nonce:"clientNonce",clientFirstBare:"n=,r=clientNonce"}
 salt:=base64.StdEncoding.EncodeToString([]byte("salt-with-entropy"))
 first:="r=clientNonceServerNonce,s="+salt+",i=4096"
 final,err:=s.continueWith(first)
 if err!=nil||!strings.HasPrefix(final,"c=biws,r=clientNonceServerNonce,p="){t.Fatalf("invalid proof %v",err)}
 key:=pgSCRAMPBKDF2([]byte("correct-horse"),[]byte("salt-with-entropy"),4096)
 serverKey:=pgSCRAMHMAC(key,"Server Key")
 auth:="n=,r=clientNonce,"+first+",c=biws,r=clientNonceServerNonce"
 mac:=hmac.New(sha256.New,serverKey);_,_=mac.Write([]byte(auth))
 signature:=base64.StdEncoding.EncodeToString(mac.Sum(nil))
 if err:=s.finish("v="+signature);err!=nil{t.Fatal(err)}
 if err:=s.finish("v="+base64.StdEncoding.EncodeToString(make([]byte,32)));err==nil{t.Fatal("forged server signature accepted")}
}
func TestSCRAMRejectsMalformedChallenges(t *testing.T){
 for _,v:=range []string{
  "r=wrong,s=c2FsdA==,i=4096",
  "r=clientNonceExtended,s=!!,i=4096",
  "r=clientNonceExtended,s=c2FsdA==,i=1",
  "r=clientNonceExtended,r=duplicate,s=c2FsdA==,i=4096",
  "m=required,r=clientNonceExtended,s=c2FsdC1lbm91Z2g=,i=4096",
 }{
  s:=&pgSCRAMClient{password:"test",nonce:"clientNonce",clientFirstBare:"n=,r=clientNonce"}
  if _,err:=s.continueWith(v);err==nil{t.Errorf("accepted %s",v)}
 }
}
