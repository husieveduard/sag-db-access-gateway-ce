package main

import (
 "encoding/binary"
 "net"
 "strings"
 "testing"
)

func pgTestStartup(fields ...string) []byte {
 body := []byte{}
 for _, field := range fields { body = append(body, []byte(field)...); body = append(body, 0) }
 body = append(body, 0)
 pkt := make([]byte, 8)
 binary.BigEndian.PutUint32(pkt[:4], uint32(8+len(body)))
 binary.BigEndian.PutUint32(pkt[4:8], 196608)
 return append(pkt, body...)
}
func TestPgBrokerStartupValidation(t *testing.T) {
 good := pgTestStartup("user","sag_one","database","sag_ce_lab")
 u,d,err := pgBrokerStartupUserDatabase(good)
 if err!=nil || u!="sag_one" || d!="sag_ce_lab" { t.Fatalf("unexpected startup %q %q %v",u,d,err) }
 bad := [][]byte{
  pgTestStartup("user","sag_one"),
  pgTestStartup("user","a","user","b","database","sag_ce_lab"),
  pgTestStartup("user","sag_one","database","sag_ce_lab","database","other"),
  []byte{0,0,0,8,4,210,22,47},
 }
 for _,pkt:=range bad { if _,_,err:=pgBrokerStartupUserDatabase(pkt); err==nil {t.Fatal("accepted invalid packet")} }
}
func TestPgBrokerRejectsWrongDatabaseBeforePassword(t *testing.T) {
 client,server:=net.Pipe(); defer client.Close(); defer server.Close()
 if err:=pgBrokerVerifyFrontend(server,pgTestStartup("user","sag_one","database","other"),"sag_ce_lab",func(string,string)bool{return true});err==nil {
  t.Fatal("allowed access to a different database")
 }
}
func TestPgBrokerVerifiesTemporaryPassword(t *testing.T) {
 for _,tc:=range []struct{password string; allowed bool}{{"valid",true},{"wrong",false}} {
  client,server:=net.Pipe()
  done:=make(chan error,1)
  go func(){done<-pgBrokerVerifyFrontend(server,pgTestStartup("user","sag_one","database","sag_ce_lab"),"sag_ce_lab",func(u,p string)bool{return u=="sag_one"&&p=="valid"})}()
  request:=make([]byte,9)
  if _,err:=client.Read(request);err!=nil{t.Fatal(err)}
  if request[0]!='R'||binary.BigEndian.Uint32(request[5:])!=3 {t.Fatal("missing cleartext auth challenge")}
  payload:=[]byte(tc.password+"\x00")
  response:=make([]byte,5+len(payload)); response[0]='p'
  binary.BigEndian.PutUint32(response[1:5],uint32(4+len(payload)))
  copy(response[5:],payload)
  if _,err:=client.Write(response);err!=nil{t.Fatal(err)}
  err:=<-done
  if tc.allowed && err!=nil {t.Fatal(err)}
  if !tc.allowed && (err==nil||!strings.Contains(err.Error(),"rejected")){t.Fatalf("failed open: %v",err)}
  client.Close();server.Close()
 }
}
