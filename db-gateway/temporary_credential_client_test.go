package main

import (
 "context"
 "fmt"
 "net/http"
 "net/http/httptest"
 "strings"
 "testing"
 "time"
)

func TestTemporaryCredentialVerifier(t *testing.T) {
 for _,tc:=range []struct{name string; status int; body string; want bool; fail bool}{
  {"allowed",200,`{"ok":true,"allowed":true}`,true,false},
  {"denied",200,`{"ok":true,"allowed":false}`,false,false},
  {"invalid_response",200,`{"ok":false,"allowed":true}`,false,false},
  {"unauthorized",401,`{"ok":false}`,false,true},
  {"broken_json",200,`{"allowed":`,false,true},
  {"upstream_error",503,`{"ok":false}`,false,true},
 } {
  t.Run(tc.name,func(t *testing.T){
   srv:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
    if r.URL.Path!="/api/internal/db/verify-temporary-credential"||r.Header.Get("X-SAG-Internal-Token")!="internal-test" {
     t.Error("unexpected request");w.WriteHeader(403);return
    }
    w.WriteHeader(tc.status)
    _,_=fmt.Fprint(w,tc.body)
   }))
   defer srv.Close()
   got,err:=verifyTemporaryCredential(context.Background(),srv.Client(),srv.URL,"internal-test","SAG-T1","sag_user","secret")
   if got!=tc.want||(err!=nil)!=tc.fail {t.Fatalf("got=%v err=%v",got,err)}
  })
 }
}
func TestTemporaryCredentialVerifierFailClosed(t *testing.T) {
 if ok,err:=verifyTemporaryCredential(context.Background(),nil,"","token","session","user","secret");ok||err==nil{t.Fatalf("missing URL must fail")}
 srv:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  time.Sleep(30*time.Millisecond);_,_=w.Write([]byte(`{"ok":true,"allowed":true}`))
 }))
 defer srv.Close()
 ctx,cancel:=context.WithTimeout(context.Background(),time.Millisecond)
 defer cancel()
 ok,err:=verifyTemporaryCredential(ctx,srv.Client(),srv.URL,"token","session","user","secret")
 if ok||err==nil {t.Fatalf("timeout must fail closed: %v",err)}
 if !strings.Contains(err.Error(),"unavailable"){t.Fatalf("unexpected error: %v",err)}
}
