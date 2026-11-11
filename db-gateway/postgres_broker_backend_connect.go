package main

import (
 "crypto/tls"
 "errors"
 "fmt"
 "net"
 "strings"
 "time"
)

// pgBrokerBackendConnect opens an isolated authenticated backend connection.
// Its caller must obtain the service credential from a trusted vault channel.
// No temporary client password is ever sent to the target database.
func pgBrokerBackendConnect(addr string, tlsConfig *tls.Config, username, password, database string) (net.Conn, error) {
 return pgBrokerBackendConnectWithMode(addr, "verify-full", tlsConfig, username, password, database)
}

// Only a deliberately selected backend transport mode may permit plaintext.
// There is no fallback from verified TLS to plaintext on handshake errors.
func pgBrokerBackendConnectWithMode(addr, transportMode string, tlsConfig *tls.Config, username, password, database string) (net.Conn, error) {
 if password=="" {return nil,errors.New("backend credential missing")}
 startup,err:=pgBackendStartup(username,database)
 if err!=nil{return nil,err}
 var conn net.Conn
 switch strings.ToLower(strings.TrimSpace(transportMode)) {
 case "verify-full":
  conn,err=pgBackendTLS(addr,tlsConfig)
 case "disable":
  conn,err=net.DialTimeout("tcp",addr,5*time.Second)
 default:
  return nil,errors.New("unsupported PostgreSQL backend transport mode")
 }
 if err!=nil{return nil,err}
 ready:=false
 defer func(){if !ready{_ = conn.Close()}}()
 if err=conn.SetDeadline(time.Now().Add(15*time.Second));err!=nil{return nil,err}
 if _,err=conn.Write(startup);err!=nil{return nil,err}
 if err=pgBackendAuthenticatePassword(conn,password);err!=nil{return nil,err}
 // Consume all startup metadata rather than confusing ParameterStatus,
 // BackendKeyData, NoticeResponse or ReadyForQuery with query responses.
 for i:=0;i<128;i++{
  kind,payload,_,readErr:=readPgMessage(conn)
  if readErr!=nil{return nil,readErr}
  switch kind {
  case 'S': // ParameterStatus (name/value)
   if len(payload)<3 || payload[len(payload)-1]!=0 {return nil,errors.New("malformed backend parameter status")}
  case 'K': // BackendKeyData (never expose to client)
   if len(payload)!=8 {return nil,errors.New("malformed backend key data")}
  case 'N': // NoticeResponse
   if len(payload)==0 || payload[len(payload)-1]!=0 {return nil,errors.New("malformed backend notice")}
  case 'Z': // ReadyForQuery
   if len(payload)!=1 || (payload[0]!='I' && payload[0]!='T' && payload[0]!='E'){
    return nil,errors.New("invalid backend ready state")
   }
   if payload[0]!='I'{return nil,errors.New("backend not idle after authentication")}
   if err=conn.SetDeadline(time.Time{});err!=nil{return nil,err}
   ready=true
   return conn,nil
  case 'E':
   return nil,errors.New("backend PostgreSQL startup rejected")
  default:
   return nil,fmt.Errorf("unexpected backend startup message %d",kind)
  }
 }
 return nil,errors.New("backend startup exceeded message limit")
}
