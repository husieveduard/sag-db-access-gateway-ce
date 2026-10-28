//go:build linux

package main

import (
 "context"
 "encoding/json"
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

func TestPGIPCRejectsOversizedAndConcatenatedRequests(t *testing.T) {
 path:=filepath.Join(t.TempDir(),"bounded.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 done:=make(chan error,1)
 go func(){done<-pgIPCServeAuthorization(ctx,path,uint32(os.Getuid()),func(context.Context,string,int64)bool{return true})}()
 defer func(){cancel();<-done}()
 for i:=0;i<100;i++{if _,err:=os.Stat(path);err==nil{break};time.Sleep(5*time.Millisecond)}
 for _,payload:=range []string{
  `{"session_uid":"SAG","resource_id":1}{"session_uid":"OTHER","resource_id":2}`,
  `{"session_uid":"SAG","resource_id":1,"padding":"`+string(make([]byte,1100))+`"}`,
 } {
  conn,err:=net.DialTimeout("unix",path,time.Second);if err!=nil{t.Fatal(err)}
  _=conn.SetDeadline(time.Now().Add(time.Second))
  _,_=conn.Write([]byte(payload+"\n"))
  var reply map[string]any
  if json.NewDecoder(conn).Decode(&reply)==nil&&reply["allowed"]==true{t.Fatal("malformed IPC request authorized")}
  _=conn.Close()
 }
}

func TestPGIPCPeerProcessIdentityFromKernel(t *testing.T) {
 ticks,err:=pgIPCPeerProcessStartTicks(int32(os.Getpid()))
 if err!=nil||ticks==""{t.Fatalf("live peer identity missing: %v",err)}
 if _,err:=pgIPCPeerProcessStartTicks(0);err==nil{t.Fatal("invalid process accepted")}
 if _,err:=pgIPCPeerProcessStartTicks(-1);err==nil{t.Fatal("negative process accepted")}
}

func TestPGIPCBootstrapOperationIsExplicit(t *testing.T) {
 path:=filepath.Join(t.TempDir(),"bootstrap.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 done:=make(chan error,1)
 go func(){done<-pgIPCServeAuthorization(ctx,path,uint32(os.Getuid()),func(ctx context.Context,_ string,_ int64)bool{
  consume,_:=ctx.Value(pgIPCBootstrapConsumeKey{}).(bool)
  return consume
 })}()
 defer func(){cancel();<-done}()
 for i:=0;i<100;i++{if _,err:=os.Stat(path);err==nil{break};time.Sleep(5*time.Millisecond)}
 send:=func(operation string)bool{
  conn,err:=net.DialTimeout("unix",path,time.Second);if err!=nil{t.Fatal(err)}
  defer conn.Close()
  _=conn.SetDeadline(time.Now().Add(time.Second))
  if err:=json.NewEncoder(conn).Encode(map[string]any{"session_uid":"SAG-TEST","resource_id":7,"operation":operation});err!=nil{t.Fatal(err)}
  var reply struct{Allowed bool `json:"allowed"`}
  if err:=json.NewDecoder(conn).Decode(&reply);err!=nil{return false}
  return reply.Allowed
 }
 if send("authorize"){t.Fatal("read-only authorization consumed bootstrap")}
 if !send("consume_bootstrap"){t.Fatal("explicit bootstrap operation rejected")}
 if send("unknown"){t.Fatal("unknown operation accepted")}
}

func TestPGIPCConsumeBootstrapHasOneTimeSemantics(t *testing.T) {
 path:=filepath.Join(t.TempDir(),"once.sock")
 ctx,cancel:=context.WithCancel(context.Background())
 done:=make(chan error,1)
 count:=0
 go func(){done<-pgIPCServeAuthorization(ctx,path,uint32(os.Getuid()),func(ctx context.Context,uid string,id int64) bool {
  if uid!="SAG-ONCE"||id!=42{return false}
  consume,_:=ctx.Value(pgIPCBootstrapConsumeKey{}).(bool)
  if !consume{return false}
  count++
  return count==1
 })}()
 defer func(){cancel();<-done}()
 for i:=0;i<100;i++{if _,err:=os.Stat(path);err==nil{break};time.Sleep(5*time.Millisecond)}
 if pgIPCCheckSession(ctx,path,"SAG-ONCE",42){t.Fatal("read-only probe unexpectedly allowed")}
 if !pgIPCConsumeBootstrap(ctx,path,"SAG-ONCE",42){t.Fatal("first use denied")}
 if pgIPCConsumeBootstrap(ctx,path,"SAG-ONCE",42){t.Fatal("replay allowed")}
 if count!=2{t.Fatalf("unexpected consume count: %d",count)}
}
