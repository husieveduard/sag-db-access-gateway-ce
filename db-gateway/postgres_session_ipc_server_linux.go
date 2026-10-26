package main

import (
 "context"
 "encoding/json"
 "errors"
 "net"
 "os"
 "syscall"
 "time"
)

// pgIPCSessionAuthorize is supplied by a trusted server-side session store.
// It must verify live status, resource ownership and expiry independently.
type pgIPCSessionAuthorize func(context.Context, string, int64) bool

// pgIPCServeAuthorization handles only non-secret allow/deny responses.
// The socket directory must be private; never bind this endpoint to TCP.
// Linux SO_PEERCRED prevents a session UID from acting as authentication.
func pgIPCServeAuthorization(ctx context.Context, socketPath string, trustedUID uint32, authorize pgIPCSessionAuthorize) error {
 if ctx==nil || ctx.Err()!=nil || socketPath=="" || authorize==nil {return errors.New("IPC configuration invalid")}
 if _,err:=os.Lstat(socketPath);err==nil {return errors.New("IPC socket path already exists")}
 listener,err:=net.Listen("unix",socketPath)
 if err!=nil{return err}
 defer listener.Close()
 defer os.Remove(socketPath)
 if err=os.Chmod(socketPath,0600);err!=nil{return err}
 go func(){<-ctx.Done();_ = listener.Close()}()
 for {
  conn,acceptErr:=listener.Accept()
  if acceptErr!=nil {
   if ctx.Err()!=nil{return nil}
   return acceptErr
  }
  pgIPCHandleAuthorization(ctx,conn,trustedUID,authorize)
 }
}

func pgIPCHandleAuthorization(ctx context.Context, conn net.Conn, trustedUID uint32, authorize pgIPCSessionAuthorize) {
 defer conn.Close()
 unixConn,ok:=conn.(*net.UnixConn)
 if !ok {return}
 raw,err:=unixConn.SyscallConn()
 if err!=nil{return}
 authenticated:=false
 if err=raw.Control(func(fd uintptr){
  cred,e:=syscall.GetsockoptUcred(int(fd),syscall.SOL_SOCKET,syscall.SO_PEERCRED)
  authenticated=e==nil && cred.Uid==trustedUID
 });err!=nil || !authenticated{return}
 if conn.SetDeadline(time.Now().Add(3*time.Second))!=nil{return}
 decoder:=json.NewDecoder(conn)
 var request struct {
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }
 if decoder.Decode(&request)!=nil || len(request.SessionUID)==0 || len(request.SessionUID)>128 || request.ResourceID<=0{return}
 allowed:=ctx.Err()==nil && authorize(ctx,request.SessionUID,request.ResourceID)
 _=json.NewEncoder(conn).Encode(struct {
  Allowed bool `json:"allowed"`
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }{allowed,request.SessionUID,request.ResourceID})
}
