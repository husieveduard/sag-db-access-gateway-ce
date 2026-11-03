//go:build ignore

package main

import (
 "context"
 "flag"
 "fmt"
 "os"
 "time"
)

// Lab-only non-secret IPC probe. This binary never handles Vault secrets.
func main() {
 socket:=flag.String("socket","","private Unix socket")
 uid:=flag.String("session","","session UID")
 resource:=flag.Int64("resource",0,"database resource ID")
 flag.Parse()
 if *socket==""||*uid==""||*resource<=0 {fmt.Println("IPC_PROBE_DENY");os.Exit(2)}
 ctx,cancel:=context.WithTimeout(context.Background(),4*time.Second)
 defer cancel()
 if !pgIPCCheckSession(ctx,*socket,*uid,*resource) {
  fmt.Println("IPC_PROBE_DENY")
  os.Exit(1)
 }
 fmt.Println("IPC_PROBE_ALLOW")
}
