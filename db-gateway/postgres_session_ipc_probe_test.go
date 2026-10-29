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

func TestPGIPCProbeRejectsUnavailable(t *testing.T) {
 if pgIPCCheckSession(context.Background(),filepath.Join(t.TempDir(),"missing.sock"),"SAG-TEST",1) {t.Fatal("missing socket allowed")}
 if pgIPCCheckSession(context.Background(),"","SAG-TEST",1) {t.Fatal("empty socket allowed")}
 ctx,cancel:=context.WithCancel(context.Background());cancel()
 if pgIPCCheckSession(ctx,"/tmp/not-used","SAG-TEST",1) {t.Fatal("cancelled context allowed")}
}

func TestPGIPCProbeEnforcesResponseBinding(t *testing.T) {
 cases:=[]struct{name string; allowed bool; uid string; resource int64; want bool}{
  {"exact",true,"SAG-TEST",7,true},
  {"denied",false,"SAG-TEST",7,false},
  {"wrong_session",true,"OTHER",7,false},
  {"wrong_resource",true,"SAG-TEST",8,false},
 }
 for _,tc:=range cases {t.Run(tc.name,func(t *testing.T){
  path:=filepath.Join(t.TempDir(),"auth.sock")
  l,err:=net.Listen("unix",path);if err!=nil{t.Fatal(err)};defer l.Close()
  defer os.Remove(path)
  done:=make(chan struct{})
  go func(){defer close(done);c,e:=l.Accept();if e!=nil{return};defer c.Close();var request map[string]any
   if json.NewDecoder(c).Decode(&request)!=nil{return}
   _=json.NewEncoder(c).Encode(map[string]any{"allowed":tc.allowed,"session_uid":tc.uid,"resource_id":tc.resource})
  }()
  ctx,cancel:=context.WithTimeout(context.Background(),time.Second);defer cancel()
  if got:=pgIPCCheckSession(ctx,path,"SAG-TEST",7);got!=tc.want{t.Fatalf("got %v want %v",got,tc.want)}
  <-done
 })}
}
