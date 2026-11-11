package main

import (
 "context"
 "crypto/tls"
 "errors"
 "net"
 "net/http"
 "time"
)

// pgBrokerAuthenticateFrontend completes the encrypted PostgreSQL startup and
// verifies a session-scoped login against the control plane. It deliberately
// does not send AuthenticationOk: a caller must first establish a separate
// authenticated backend session and then complete protocol startup.
// This function is NOT wired into the legacy listener.
func pgBrokerAuthenticateFrontend(
 ctx context.Context,
 raw net.Conn,
 tlsConfig *tls.Config,
 controlClient *http.Client,
 controlEndpoint, token, sessionID, expectedDatabase string,
) (*tls.Conn, error) {
 if ctx == nil { return nil, errors.New("missing context") }
 if ctx.Err()!=nil { return nil, ctx.Err() }
 if expectedDatabase=="" { return nil, errors.New("missing target database") }
 secure,err:=upgradePostgresFrontendTLS(raw,tlsConfig)
 if err!=nil { return nil,err }
 // The transport must be closed on every failed authentication attempt.
 authenticated:=false
 defer func(){ if !authenticated { _=secure.Close() } }()
 if err:=secure.SetDeadline(time.Now().Add(10*time.Second));err!=nil{return nil,err}
 startup,err:=readBrokerStartupPacket(secure)
 if err!=nil{return nil,err}
 err=pgBrokerVerifyFrontend(secure,startup,expectedDatabase,func(user,password string)bool{
  allowed,verifyErr:=verifyTemporaryCredential(ctx,controlClient,controlEndpoint,token,sessionID,user,password)
  return verifyErr==nil&&allowed
 })
 if err!=nil{return nil,err}
 if err:=secure.SetDeadline(time.Time{});err!=nil{return nil,err}
 authenticated=true
 return secure,nil
}
