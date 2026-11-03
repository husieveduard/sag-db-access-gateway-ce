package main

import (
 "crypto/tls"
 "encoding/binary"
 "errors"
 "fmt"
 "io"
 "net"
 "time"
)

// pgBackendTLS establishes an independent, verified TLS session to PostgreSQL.
// It is intentionally not connected to the running passthrough adapter.
// A caller must supply a verified TLS configuration and authenticate separately.
func pgBackendTLS(addr string, config *tls.Config) (*tls.Conn,error) {
 if config==nil || config.InsecureSkipVerify || config.ServerName=="" {
  return nil, errors.New("backend PostgreSQL TLS verification is required")
 }
 raw,err:=net.DialTimeout("tcp",addr,5*time.Second)
 if err!=nil{return nil,err}
 ok:=false
 defer func(){if !ok { _=raw.Close() }}()
 _=raw.SetDeadline(time.Now().Add(10*time.Second))
 request:=make([]byte,8)
 binary.BigEndian.PutUint32(request[:4],8)
 binary.BigEndian.PutUint32(request[4:8],80877103)
 if _,err=raw.Write(request);err!=nil{return nil,err}
 var response [1]byte
 if _,err=io.ReadFull(raw,response[:]);err!=nil{return nil,err}
 if response[0]!='S'{return nil,fmt.Errorf("backend PostgreSQL refused TLS")}
 secure:=tls.Client(raw,config)
 if err=secure.Handshake();err!=nil{return nil,fmt.Errorf("backend TLS handshake failed: %w",err)}
 if err=secure.SetDeadline(time.Time{});err!=nil{return nil,err}
 ok=true
 return secure,nil
}

// pgBackendStartup constructs an isolated PostgreSQL 3.0 startup packet
// from values obtained through a trusted internal credential mechanism.
func pgBackendStartup(user,database string) ([]byte,error) {
 if user==""||database=="" {return nil,errors.New("backend user/database required")}
 for _,v:=range []string{user,database} {
  if len(v)>128{return nil,errors.New("backend startup value too long")}
  for i:=0;i<len(v);i++ {if v[i]==0 {return nil,errors.New("backend startup NUL forbidden")}}
 }
 body:=[]byte("user\x00"+user+"\x00database\x00"+database+"\x00\x00")
 packet:=make([]byte,8+len(body))
 binary.BigEndian.PutUint32(packet[:4],uint32(len(packet)))
 binary.BigEndian.PutUint32(packet[4:8],196608)
 copy(packet[8:],body)
 return packet,nil
}
