package main

import (
 "bytes"
 "context"
 "encoding/json"
 "errors"
 "net/http"
 "strings"
 "time"
)

var errCredentialVerificationUnavailable = errors.New("temporary credential verification unavailable")

// verifyTemporaryCredential consults the trusted control plane. Every failure
// is a denial, including malformed JSON, timeouts and non-2xx HTTP responses.
// Caller must use TLS to the control plane outside a trusted private network.
func verifyTemporaryCredential(ctx context.Context, client *http.Client, baseURL, token, session, user, password string) (bool, error) {
 if strings.TrimSpace(baseURL)=="" || token=="" || session=="" || user=="" || password=="" {
  return false, errCredentialVerificationUnavailable
 }
 payload,err:=json.Marshal(map[string]string{
  "session_uid":session,"username":user,"password":password,
 })
 if err!=nil {return false,errCredentialVerificationUnavailable}
 req,err:=http.NewRequestWithContext(ctx,http.MethodPost,strings.TrimRight(baseURL,"/")+"/api/internal/db/verify-temporary-credential",bytes.NewReader(payload))
 if err!=nil {return false,errCredentialVerificationUnavailable}
 req.Header.Set("Content-Type","application/json")
 req.Header.Set("X-SAG-Internal-Token",token)
 if client==nil {client=&http.Client{Timeout:5*time.Second}}
 resp,err:=client.Do(req)
 if err!=nil {return false,errCredentialVerificationUnavailable}
 defer resp.Body.Close()
 if resp.StatusCode!=http.StatusOK {return false,errCredentialVerificationUnavailable}
 var answer struct { OK bool `json:"ok"`; Allowed bool `json:"allowed"` }
 decoder:=json.NewDecoder(http.MaxBytesReader(nil,resp.Body,4096))
 if err:=decoder.Decode(&answer);err!=nil {return false,errCredentialVerificationUnavailable}
 return answer.OK&&answer.Allowed,nil
}
