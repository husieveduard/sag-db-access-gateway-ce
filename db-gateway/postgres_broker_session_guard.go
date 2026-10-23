package main

import (
 "bytes"
 "context"
 "encoding/json"
 "net"
 "net/http"
 "strings"
 "time"
)

// checkBrokerSessionActive fails closed on unexpected responses and requires
// started status (the legacy endpoint also permits created/starting).
func checkBrokerSessionActive(ctx context.Context, client *http.Client, controlURL, token, sessionUID string) bool {
 if ctx==nil||ctx.Err()!=nil||token==""||sessionUID==""||controlURL==""{return false}
 payload,_:=json.Marshal(map[string]string{"session_uid":sessionUID})
 req,err:=http.NewRequestWithContext(ctx,http.MethodPost,strings.TrimRight(controlURL,"/")+"/api/internal/db/session-status",bytes.NewReader(payload))
 if err!=nil{return false}
 req.Header.Set("Content-Type","application/json")
 req.Header.Set("X-SAG-Internal-Token",token)
 if client==nil{client=&http.Client{Timeout:3*time.Second}}
 resp,err:=client.Do(req)
 if err!=nil{return false}
 defer resp.Body.Close()
 if resp.StatusCode!=200{return false}
 var result struct {
  OK bool `json:"ok"`
  Allowed bool `json:"allowed"`
  SessionStatus string `json:"session_status"`
  Engine string `json:"db_engine"`
 }
 if json.NewDecoder(http.MaxBytesReader(nil,resp.Body,4096)).Decode(&result)!=nil{return false}
 return result.OK&&result.Allowed&&result.SessionStatus=="started"&&strings.EqualFold(result.Engine,"postgresql")
}

// watchBrokerSession closes BOTH ends of an existing connection when a session
// expires, is revoked, or becomes unverifiable. The caller must also check
// authorization immediately before forwarding each query.
func watchBrokerSession(ctx context.Context, client *http.Client, controlURL, token, sessionUID string, frontend, backend net.Conn) func() {
 stop:=make(chan struct{})
 done:=make(chan struct{})
 go func(){
  defer close(done)
  ticker:=time.NewTicker(2*time.Second)
  defer ticker.Stop()
  for {
   select {
   case <-stop:return
   case <-ctx.Done():
    _=frontend.Close();_=backend.Close();return
   case <-ticker.C:
    checkCtx,cancel:=context.WithTimeout(ctx,3*time.Second)
    ok:=checkBrokerSessionActive(checkCtx,client,controlURL,token,sessionUID)
    cancel()
    if !ok {_=frontend.Close();_=backend.Close();return}
   }
  }
 }()
 return func(){close(stop);<-done}
}
