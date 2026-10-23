package main

import (
 "crypto/hmac"
 "crypto/rand"
 "crypto/sha256"
 "encoding/base64"
 "errors"
 "fmt"
 "hash"
 "strconv"
 "strings"
 "crypto/pbkdf2"
)

type pgSCRAMClient struct {
 password string
 clientFirstBare string
 serverFirst string
 nonce string
 expectedServerSignature []byte
}

func newPgSCRAMClient(password string) (*pgSCRAMClient,error) {
 b:=make([]byte,18)
 if _,err:=rand.Read(b);err!=nil{return nil,err}
 nonce:=base64.RawStdEncoding.EncodeToString(b)
 return &pgSCRAMClient{password:password,nonce:nonce,clientFirstBare:"n=,r="+nonce},nil
}
func (s *pgSCRAMClient) first() string {return "n,,"+s.clientFirstBare}
func pgSCRAMAttributes(message string)(map[string]string,error){
 attrs:=map[string]string{}
 for _,entry:=range strings.Split(message,","){
  if len(entry)<3||entry[1]!='=' {return nil,errors.New("invalid SCRAM attribute")}
  key:=entry[:1]
  if _,exists:=attrs[key];exists{return nil,errors.New("duplicate SCRAM attribute")}
  attrs[key]=entry[2:]
 }
 return attrs,nil
}
func pgSCRAMHMAC(key []byte, parts ...string)[]byte{
 mac:=hmac.New(sha256.New,key)
 for _,p:=range parts{_,_=mac.Write([]byte(p))}
 return mac.Sum(nil)
}
func (s *pgSCRAMClient) continueWith(serverFirst string)(string,error){
 fields,err:=pgSCRAMAttributes(serverFirst)
 if err!=nil{return "",err}
 nonce:=fields["r"]
 if !strings.HasPrefix(nonce,s.nonce)||len(nonce)<=len(s.nonce){return "",errors.New("invalid SCRAM server nonce")}
 salt,err:=base64.StdEncoding.Strict().DecodeString(fields["s"])
 if err!=nil||len(salt)<8||len(salt)>1024{return "",errors.New("invalid SCRAM salt")}
 iterations,err:=strconv.Atoi(fields["i"])
 if err!=nil||iterations<4096||iterations>1000000{return "",errors.New("invalid SCRAM iteration count")}
 if fields["m"]!=""{return "",errors.New("unsupported SCRAM mandatory extension")}
 salted,err:=pbkdf2.Key(sha256.New,[]byte(s.password),salt,iterations,32)
 if err!=nil{return "",err}
 clientKey:=pgSCRAMHMAC(salted,"Client Key")
 storedKey:=sha256.Sum256(clientKey)
 withoutProof:="c=biws,r="+nonce
 authMessage:=s.clientFirstBare+","+serverFirst+","+withoutProof
 clientSignature:=pgSCRAMHMAC(storedKey[:],authMessage)
 proof:=make([]byte,len(clientKey))
 for i:=range proof {proof[i]=clientKey[i]^clientSignature[i]}
 serverKey:=pgSCRAMHMAC(salted,"Server Key")
 s.expectedServerSignature=pgSCRAMHMAC(serverKey,authMessage)
 s.serverFirst=serverFirst
 return withoutProof+",p="+base64.StdEncoding.EncodeToString(proof),nil
}
func (s *pgSCRAMClient) finish(serverFinal string)error{
 attrs,err:=pgSCRAMAttributes(serverFinal);if err!=nil{return err}
 if attrs["e"]!=""{return errors.New("SCRAM server rejected authentication")}
 if len(s.expectedServerSignature)==0 {return errors.New("SCRAM state missing")}
 signature,err:=base64.StdEncoding.Strict().DecodeString(attrs["v"])
 if err!=nil||!hmac.Equal(signature,s.expectedServerSignature){return errors.New("SCRAM server signature mismatch")}
 return nil
}
var _ hash.Hash = sha256.New()
var _ = fmt.Sprintf
