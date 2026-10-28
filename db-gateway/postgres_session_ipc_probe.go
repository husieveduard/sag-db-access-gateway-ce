package main

import (
 "context"
 "encoding/json"
 "errors"
 "io"
 "net"
 "time"
)

// pgIPCCheckSession is an optional, non-secret UNIX IPC authorization
// probe. A separate trusted server must validate peer identity and the
// session/resource binding. Session UID alone is never authentication.
func pgIPCCheckSession(ctx context.Context, socketPath, sessionUID string, resourceID int64) bool {
 if ctx == nil || ctx.Err() != nil || socketPath == "" || sessionUID == "" || resourceID <= 0 {
  return false
 }
 dialer := net.Dialer{Timeout: 2*time.Second}
 conn, err := dialer.DialContext(ctx, "unix", socketPath)
 if err != nil { return false }
 defer conn.Close()
 deadline := time.Now().Add(3*time.Second)
 if d, ok := ctx.Deadline(); ok && d.Before(deadline) { deadline = d }
 if conn.SetDeadline(deadline) != nil { return false }
 request := struct {
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }{sessionUID, resourceID}
 if json.NewEncoder(conn).Encode(request) != nil { return false }
 body, err := io.ReadAll(io.LimitReader(conn, 1025))
 if err != nil || len(body) > 1024 { return false }
 var reply struct {
  Allowed bool `json:"allowed"`
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }
 if json.Unmarshal(body, &reply) != nil { return false }
 return reply.Allowed && reply.SessionUID == sessionUID && reply.ResourceID == resourceID
}

// pgIPCConsumeBootstrap requests a one-time, non-secret bootstrap decision.
// The worker derives process identity from SO_PEERCRED, never this request.
// Failure is always a denial; this function does not retrieve credentials.
func pgIPCConsumeBootstrap(ctx context.Context, socketPath, sessionUID string, resourceID int64) bool {
 if ctx==nil||ctx.Err()!=nil||socketPath==""||sessionUID==""||resourceID<=0{return false}
 dialer:=net.Dialer{Timeout:2*time.Second}
 conn,err:=dialer.DialContext(ctx,"unix",socketPath)
 if err!=nil{return false}
 defer conn.Close()
 deadline:=time.Now().Add(3*time.Second)
 if d,ok:=ctx.Deadline();ok&&d.Before(deadline){deadline=d}
 if conn.SetDeadline(deadline)!=nil{return false}
 request:=struct{
  Operation string `json:"operation"`
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }{"consume_bootstrap",sessionUID,resourceID}
 if json.NewEncoder(conn).Encode(request)!=nil{return false}
 body,err:=io.ReadAll(io.LimitReader(conn,1025))
 if err!=nil||len(body)>1024{return false}
 var reply struct{
  Allowed bool `json:"allowed"`
  SessionUID string `json:"session_uid"`
  ResourceID int64 `json:"resource_id"`
 }
 if json.Unmarshal(body,&reply)!=nil{return false}
 return reply.Allowed&&reply.SessionUID==sessionUID&&reply.ResourceID==resourceID
}

var errIPCUnavailable = errors.New("trusted gateway IPC unavailable")
