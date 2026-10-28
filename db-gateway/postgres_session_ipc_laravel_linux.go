//go:build linux

package main

import (
 "context"
 "errors"
 "os/exec"
 "strconv"
 "strings"
 "time"
)

// pgIPCLaravelAuthorizer invokes only the non-secret local Laravel
// session/resource gate. No shell, environment secrets, or Vault data.
// The worker image must contain PHP, artisan and its private DB access.
// Never use user-controlled executable or artisan paths.
func pgIPCLaravelAuthorizer(phpBinary, artisanPath string) pgIPCSessionAuthorize {
 return func(ctx context.Context, sessionUID string, resourceID int64) bool {
  if ctx==nil || ctx.Err()!=nil || phpBinary=="" || artisanPath=="" || resourceID<=0 ||
   len(sessionUID)==0 || len(sessionUID)>128 || strings.ContainsAny(sessionUID,"\x00\r\n") {
   return false
  }
  checkCtx,cancel:=context.WithTimeout(ctx,2*time.Second)
  defer cancel()
  args:=[]string{artisanPath,"sag:verify-gateway-ipc-session",sessionUID,strconv.FormatInt(resourceID,10),"--no-ansi","--no-interaction"}
  if peer,ok:=ctx.Value(pgIPCPeerIdentityKey{}).(pgIPCPeerIdentity);ok && peer.PID>1 && peer.StartTicks!="" {
   args=append(args,"--peer-pid="+strconv.FormatInt(int64(peer.PID),10),"--peer-start-ticks="+peer.StartTicks)
  }
  cmd:=exec.CommandContext(checkCtx,phpBinary,args...)
  output,err:=cmd.Output()
  if err!=nil || checkCtx.Err()!=nil || len(output)>128{return false}
  return strings.TrimSpace(string(output))=="ALLOW"
 }
}

var errIPCLocalWorkerRequired = errors.New("IPC authorization requires local trusted Laravel worker")
