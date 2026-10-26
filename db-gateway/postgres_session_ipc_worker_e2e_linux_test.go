//go:build linux

package main

import (
 "context"
 "os"
 "path/filepath"
 "testing"
 "time"
)

// Full Unix client -> peer-checked server -> local worker callback boundary.
// The fixture imitates Artisan response; database integration is tested in PHP.
func TestPGIPCWorkerRouteEndToEnd(t *testing.T) {
 dir:=t.TempDir()
 binary:=filepath.Join(dir,"php-stub")
 body:="#!/bin/sh\nif [ \"$2\" = \"sag:verify-gateway-ipc-session\" ] && [ \"$3\" = \"SAG-ACTIVE\" ] && [ \"$4\" = \"7\" ]; then echo ALLOW; exit 0; fi\necho DENY; exit 1\n"
 if err:=os.WriteFile(binary,[]byte(body),0700);err!=nil{t.Fatal(err)}
 socket:=filepath.Join(dir,"auth.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 done:=make(chan error,1)
 go func(){done<-pgIPCServeAuthorization(ctx,socket,uint32(os.Getuid()),pgIPCLaravelAuthorizer(binary,"/trusted/artisan"))}()
 defer func(){cancel();select{case err:=<-done:if err!=nil{t.Errorf("worker: %v",err)};case <-time.After(2*time.Second):t.Error("worker shutdown timeout")}}()
 for i:=0;i<100;i++{if _,err:=os.Stat(socket);err==nil{break};time.Sleep(5*time.Millisecond)}
 if !pgIPCCheckSession(context.Background(),socket,"SAG-ACTIVE",7){t.Fatal("active session rejected")}
 if pgIPCCheckSession(context.Background(),socket,"SAG-REVOKED",7){t.Fatal("revoked session allowed")}
 if pgIPCCheckSession(context.Background(),socket,"SAG-ACTIVE",8){t.Fatal("wrong resource allowed")}
}
