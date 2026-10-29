package main

import (
	"bytes"
	"encoding/binary"
	"io"
	"net"
)

const myMaxStatements = 64
const myMaxStatementFields = 1024

type myParameterType struct{ kind, flags byte }
type myPreparedStatement struct {
	id     uint32
	params int
	types  []myParameterType
}

func myStatementID(p []byte) (uint32, bool) {
	if len(p) < 5 {
		return 0, false
	}
	id := binary.LittleEndian.Uint32(p[1:])
	return id, id != 0
}

// Decode a column/parameter definition without retaining names or schema data.
func myColumnType(p []byte) (byte, error) {
	for i := 0; i < 6; i++ {
		n, k, e := myLength(p)
		if e != nil || n > uint64(len(p)-k) {
			return 0, errMyProtocol
		}
		p = p[k+int(n):]
	}
	if len(p) != 13 || p[0] != 12 {
		return 0, errMyProtocol
	}
	return p[7], nil
}

// Complete metadata is bounded and validated before PREPARE_OK reaches a client.
// No backend statement handle is reusable across frontend/backend connections.
func myRelayPrepare(backend net.Conn, frontend io.Writer) (*myPreparedStatement, error) {
	seq := byte(1)
	p, e := myRead(backend, &seq)
	if e != nil {
		return nil, e
	}
	var out bytes.Buffer
	relay := func(p []byte) error {
		if out.Len()+len(p)+4 > 4<<20 {
			return errMyProtocol
		}
		s := seq - 1
		return myWrite(&out, &s, p)
	}
	if p[0] == 0xff {
		if len(p) < 3 {
			return nil, errMyProtocol
		}
		s := byte(1)
		return nil, myWrite(frontend, &s, p)
	}
	if len(p) != 12 || p[0] != 0 || p[9] != 0 {
		return nil, errMyProtocol
	}
	id := binary.LittleEndian.Uint32(p[1:])
	cols := int(binary.LittleEndian.Uint16(p[5:]))
	params := int(binary.LittleEndian.Uint16(p[7:]))
	if id == 0 || cols > myMaxStatementFields || params > myMaxStatementFields {
		return nil, errMyProtocol
	}
	if relay(p) != nil {
		return nil, errMyProtocol
	}
	for _, count := range []int{params, cols} {
		for i := 0; i < count; i++ {
			p, e = myRead(backend, &seq)
			if e != nil {
				return nil, e
			}
			if _, e = myColumnType(p); e != nil {
				return nil, e
			}
			if relay(p) != nil {
				return nil, errMyProtocol
			}
		}
		if count > 0 {
			p, e = myRead(backend, &seq)
			if e != nil || len(p) != 5 || myStatus(p) != nil {
				return nil, errMyProtocol
			}
			if relay(p) != nil {
				return nil, errMyProtocol
			}
		}
	}
	if _, e = io.Copy(frontend, &out); e != nil {
		return nil, errMyProtocol
	}
	return &myPreparedStatement{id: id, params: params}, nil
}

func myParameterTypeAllowed(kind, flags byte) bool {
	switch kind {
	case 1, 2, 3, 4, 5, 8, 9, 13:
		return flags == 0 || flags == 0x80
	case 0, 6, 7, 10, 11, 12, 15, 16, 245, 246, 249, 250, 251, 252, 253, 254, 255:
		return flags == 0
	default:
		return false
	}
}

// Consume one binary-protocol value, using protocol 4.1 field types. Unsupported
// extensions are denied; bounds are checked before advancing over values.
func myBinaryValue(p []byte, kind byte) ([]byte, error) {
	size := -1
	switch kind {
	case 6:
		return p, nil // MYSQL_TYPE_NULL
	case 1:
		size = 1
	case 2, 13:
		size = 2
	case 3, 4, 9:
		size = 4
	case 5, 8:
		size = 8
	case 7, 10, 11, 12:
		if len(p) < 1 {
			return nil, errMyProtocol
		}
		n := int(p[0])
		valid := n == 0
		switch kind {
		case 10:
			valid = valid || n == 4
		case 11:
			valid = valid || n == 8 || n == 12
		default:
			valid = valid || n == 4 || n == 7 || n == 11
		}
		if !valid || n > len(p)-1 {
			return nil, errMyProtocol
		}
		return p[n+1:], nil
	case 0, 15, 16, 245, 246, 249, 250, 251, 252, 253, 254, 255:
		n, k, e := myLength(p)
		if e != nil || n > uint64(len(p)-k) {
			return nil, errMyProtocol
		}
		return p[k+int(n):], nil
	default:
		return nil, errMyProtocol
	}
	if size > len(p) {
		return nil, errMyProtocol
	}
	return p[size:], nil
}
func myValidateExecute(p []byte, statement *myPreparedStatement) bool {
	if statement == nil || len(p) < 10 || p[0] != 0x17 || binary.LittleEndian.Uint32(p[1:]) != statement.id || p[5] != 0 || binary.LittleEndian.Uint32(p[6:]) != 1 {
		return false
	}
	// No server-side cursor, bulk/array execute or optional query attributes.
	if statement.params == 0 {
		return len(p) == 10
	}
	bitmapSize := (statement.params + 7) / 8
	if len(p) < 10+bitmapSize+1 {
		return false
	}
	bitmap := p[10 : 10+bitmapSize]
	bound := p[10+bitmapSize]
	values := p[11+bitmapSize:]
	types := statement.types
	if bound == 1 {
		if len(values) < statement.params*2 {
			return false
		}
		types = make([]myParameterType, statement.params)
		for i := range types {
			kind, flags := values[i*2], values[i*2+1]
			if !myParameterTypeAllowed(kind, flags) {
				return false
			}
			types[i] = myParameterType{kind, flags}
		}
		values = values[statement.params*2:]
	} else if bound != 0 || len(types) != statement.params {
		return false
	}
	for i, typ := range types {
		if bitmap[i/8]&(1<<uint(i%8)) != 0 {
			continue
		}
		var e error
		values, e = myBinaryValue(values, typ.kind)
		if e != nil {
			return false
		}
	}
	if len(values) != 0 {
		return false
	}
	statement.types = types
	return true
}
func myValidateBinaryRow(p []byte, types []byte) bool {
	bitmapSize := (len(types) + 9) / 8
	if len(p) < 1+bitmapSize || p[0] != 0 {
		return false
	}
	bitmap := p[1 : 1+bitmapSize]
	if bitmap[0]&3 != 0 {
		return false
	}
	values := p[1+bitmapSize:]
	for i, kind := range types {
		offset := i + 2
		if bitmap[offset/8]&(1<<uint(offset%8)) != 0 {
			continue
		}
		var e error
		values, e = myBinaryValue(values, kind)
		if e != nil {
			return false
		}
	}
	return len(values) == 0
}
func myRelayStatementReset(backend net.Conn, frontend io.Writer) error {
	seq := byte(1)
	p, e := myRead(backend, &seq)
	if e != nil {
		return e
	}
	if p[0] == 0xff {
		if len(p) < 3 {
			return errMyProtocol
		}
	} else if myStatus(p) != nil {
		return errMyProtocol
	}
	seq = 1
	return myWrite(frontend, &seq, p)
}
