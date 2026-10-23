//go:build ignore

package main

import (
 "encoding/binary"
 "fmt"
 "io"
)

func readPgStartupPacket(r io.Reader) ([]byte,error) {
 var h [4]byte
 if _,e:=io.ReadFull(r,h[:]);e!=nil{return nil,e}
 n:=int(binary.BigEndian.Uint32(h[:]))
 if n<8||n>10000{return nil,fmt.Errorf("startup frame size %d",n)}
 b:=make([]byte,n)
 copy(b,h[:])
 _,e:=io.ReadFull(r,b[4:])
 return b,e
}
func isPgSSLRequest(p []byte)bool{return len(p)==8&&binary.BigEndian.Uint32(p[4:8])==80877103}
func readPgMessage(r io.Reader)(byte,[]byte,[]byte,error){
 var h [5]byte
 if _,e:=io.ReadFull(r,h[:]);e!=nil{return 0,nil,nil,e}
 n:=int(binary.BigEndian.Uint32(h[1:]))
 if n<4||n>1048576{return 0,nil,nil,fmt.Errorf("frame size %d",n)}
 p:=make([]byte,n-4)
 if _,e:=io.ReadFull(r,p);e!=nil{return 0,nil,nil,e}
 return h[0],p,append(h[:],p...),nil
}
