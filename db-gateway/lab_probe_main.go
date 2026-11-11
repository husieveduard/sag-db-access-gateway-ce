//go:build ignore

package main

import (
 "crypto/tls"
 "encoding/binary"
 "errors"
 "flag"
 "fmt"
 "io"
 "net"
 "os"
 "strings"
 "time"
)

// Standalone lab probe. Never starts the production gateway listener.
// Password is read from stdin, never command-line flags or env.
func main() {
 host:=flag.String("target","","PostgreSQL target host:port")
 mode:=flag.String("tls-mode","verify-full","verify-full or disable")
 tlsName:=flag.String("tls-name","","TLS server name")
 user:=flag.String("user","","backend user")
 database:=flag.String("database","","database")
 flag.Parse()
 if *host==""||*user==""||*database==""{fmt.Fprintln(os.Stderr,"missing target/user/database");os.Exit(2)}
 if *mode!="verify-full"&&*mode!="disable"{fmt.Fprintln(os.Stderr,"invalid tls mode");os.Exit(2)}
 // Read password once, bounded. A newline ends the secret.
 secret:=make([]byte,0,128)
 var one [1]byte
 for len(secret)<4096{
  n,err:=os.Stdin.Read(one[:])
  if n>0 {if one[0]=='\n'{break};secret=append(secret,one[0])}
  if err!=nil{break}
 }
 password:=strings.TrimSuffix(string(secret),"\r")
 for i:=range secret{secret[i]=0}
 if password==""{fmt.Fprintln(os.Stderr,"missing backend password");os.Exit(2)}
 var cfg *tls.Config
 if *mode=="verify-full" {cfg=&tls.Config{MinVersion:tls.VersionTLS12,ServerName:*tlsName}}
 conn,err:=pgBrokerBackendConnectWithMode(*host,*mode,cfg,*user,password,*database)
 password=""
 if err!=nil {fmt.Fprintln(os.Stderr,"BACKEND_AUTH_FAILED:",err);os.Exit(1)}
 defer conn.Close()
 _=conn.SetDeadline(time.Now().Add(10*time.Second))
 sql:="SELECT current_user, current_database()"
 data:=append([]byte(sql),0)
 msg:=make([]byte,5+len(data))
 msg[0]='Q'
 binary.BigEndian.PutUint32(msg[1:5],uint32(4+len(data)))
 copy(msg[5:],data)
 if _,err=conn.Write(msg);err!=nil{fail(err)}
 var rowUser,rowDB string
 received:=false
 for i:=0;i<64;i++{
  typ,payload,_,e:=readPgMessage(conn)
  if e!=nil{fail(e)}
  switch typ{
  case 'D':
   if len(payload)<2||binary.BigEndian.Uint16(payload[:2])!=2{fail(errors.New("unexpected row shape"))}
   offset:=2
   values:=[]string{}
   for j:=0;j<2;j++{
    if offset+4>len(payload){fail(errors.New("malformed data row"))}
    size:=int(int32(binary.BigEndian.Uint32(payload[offset:offset+4])));offset+=4
    if size<0||offset+size>len(payload){fail(errors.New("invalid data length"))}
    values=append(values,string(payload[offset:offset+size]));offset+=size
   }
   rowUser,rowDB=values[0],values[1];received=true
  case 'E':fail(errors.New("SQL query rejected"))
  case 'Z':
   if !received||rowUser!=*user||rowDB!=*database{fail(errors.New("unexpected backend identity"))}
   fmt.Println("LAB_BACKEND_CONNECT_PASS")
   fmt.Printf("USER=%s DATABASE=%s TRANSPORT=%s\n",rowUser,rowDB,*mode)
   return
  }
 }
 fail(errors.New("no ReadyForQuery"))
}
func fail(err error){fmt.Fprintln(os.Stderr,"LAB_BACKEND_FAILED:",err);os.Exit(1)}
var _ net.Conn

func readPgMessage(r io.Reader)(byte,[]byte,[]byte,error){
 header:=make([]byte,5)
 if _,err:=io.ReadFull(r,header);err!=nil{return 0,nil,nil,err}
 size:=int(binary.BigEndian.Uint32(header[1:5]))
 if size<4||size>1024*1024{return 0,nil,nil,errors.New("invalid PostgreSQL frame length")}
 payload:=make([]byte,size-4)
 if _,err:=io.ReadFull(r,payload);err!=nil{return 0,nil,nil,err}
 return header[0],payload,append(header,payload...),nil
}
