//go:build linux

package main

import (
 "context"
 "net"
 "os"
 "path/filepath"
 "testing"
 "time"
)

func TestPGIPCAuthorizationServerPeerAndBinding(t *testing.T) {
 path:=filepath.Join(t.TempDir(),"auth.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 defer cancel()
 ready:=make(chan error,1)
 go func(){ready<-pgIPCServeAuthorization(ctx,path,uint32(os.Getuid()),func(_ context.Context,uid string,res int64)bool{return uid=="SAG-TEST"&&res==7})}()
 for i:=0;i<100;i++{
  if _,err:=os.Stat(path);err==nil{break}
  select {case err:=<-ready:t.Fatalf("server exited: %v",err);default:}
  time.Sleep(5*time.Millisecond)
 }
 if !pgIPCCheckSession(context.Background(),path,"SAG-TEST",7){t.Fatal("valid binding rejected")}
 if pgIPCCheckSession(context.Background(),path,"SAG-OTHER",7){t.Fatal("wrong session allowed")}
 if pgIPCCheckSession(context.Background(),path,"SAG-TEST",8){t.Fatal("wrong resource allowed")}
 cancel()
 select{case err:=<-ready:if err!=nil{t.Fatal(err)};case <-time.After(2*time.Second):t.Fatal("server did not shut down")}
}

func TestPGIPCAuthorizationServerRejectsWrongPeer(t *testing.T) {
 path:=filepath.Join(t.TempDir(),"auth.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 defer cancel()
 done:=make(chan error,1)
 go func(){done<-pgIPCServeAuthorization(ctx,path,uint32(os.Getuid())+1,func(context.Context,string,int64)bool{return true})}()
 for i:=0;i<100;i++{if _,err:=net.DialTimeout("unix",path,5*time.Millisecond);err==nil{break};time.Sleep(5*time.Millisecond)}
 if pgIPCCheckSession(context.Background(),path,"SAG-TEST",7){t.Fatal("unauthorized peer allowed")}
 cancel()
 select{case err:=<-done:if err!=nil{t.Fatal(err)};case <-time.After(2*time.Second):t.Fatal("server did not shut down")}
}
