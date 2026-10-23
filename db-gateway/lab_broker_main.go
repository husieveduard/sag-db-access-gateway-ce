//go:build ignore

package main

import (
 "context"
 "crypto/tls"
 "errors"
 "flag"
 "fmt"
 "net"
 "net/http"
 "os"
 "strings"
 "time"
)

// Explicit laboratory runner. Credentials come from a read-only local secret
// file in the ephemeral container, NOT a production Vault integration.
// Binds localhost by default and permits only one concurrent connection.
func main(){
 listen:=flag.String("listen","127.0.0.1:15432","laboratory bind address")
 target:=flag.String("target","sag-ce-lab-postgres:5432","backend address")
 mode:=flag.String("backend-tls","disable","backend transport")
 db:=flag.String("database","sag_ce_lab","allowed database")
 user:=flag.String("backend-user","sag_gateway_lab","backend identity")
 passwordFile:=flag.String("backend-password-file","","read-only backend secret file")
 certFile:=flag.String("cert","","frontend TLS certificate")
 keyFile:=flag.String("key","","frontend TLS private key")
 control:=flag.String("control","","control plane URL")
 tokenFile:=flag.String("token-file","","internal API token file")
 session:=flag.String("session","","temporary access session UID")
 flag.Parse()
 if *passwordFile==""||*certFile==""||*keyFile==""||*tokenFile==""||*session==""||*control==""{fatal(errors.New("missing required lab configuration"))}
 if *mode!="disable"{fatal(errors.New("this lab runner supports only explicitly selected disable backend TLS mode"))}
 cert,err:=tls.LoadX509KeyPair(*certFile,*keyFile);if err!=nil{fatal(err)}
 readSecret:=func(path string)(string,error){
  data,e:=os.ReadFile(path);if e!=nil{return "",e}
  if len(data)==0||len(data)>4096{return "",errors.New("invalid secret file size")}
  return strings.TrimSuffix(strings.TrimSuffix(string(data),"\n"),"\r"),nil
 }
 token,err:=readSecret(*tokenFile);if err!=nil{fatal(err)}
 if token==""{fatal(errors.New("empty internal token"))}
 listener,err:=net.Listen("tcp",*listen);if err!=nil{fatal(err)}
 defer listener.Close()
 fmt.Printf("LAB_BROKER_LISTEN=%s\n",listener.Addr())
 for {
  conn,e:=listener.Accept();if e!=nil{fatal(e)}
  ctx,cancel:=context.WithTimeout(context.Background(),5*time.Minute)
  e=pgBrokerServeAuthenticatedSession(ctx,conn,&tls.Config{
   Certificates:[]tls.Certificate{cert},MinVersion:tls.VersionTLS12,
  },&http.Client{Timeout:5*time.Second},*control,token,*session,*db,*target,*mode,nil,
  func(context.Context)(PgBrokerBackendCredential,error){
   secret,e:=readSecret(*passwordFile)
   if e!=nil{return PgBrokerBackendCredential{},e}
   return PgBrokerBackendCredential{Username:*user,Password:secret},nil
  })
  cancel()
  if e!=nil{fmt.Fprintln(os.Stderr,"LAB_SESSION_ENDED:",e)}else{fmt.Println("LAB_SESSION_ENDED")}
 }
}
func fatal(err error){fmt.Fprintln(os.Stderr,"LAB_BROKER_ERROR:",err);os.Exit(1)}
