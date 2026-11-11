package main

import (
	"encoding/binary"
	"strings"
)

// Only sp_executesql is accepted. This covers parameterized Query/Exec and
// client-side prepared statements without exposing server cursor/handle RPCs,
// bulk upload, encryption negotiation or arbitrary stored-procedure RPCs.
func tdsValidateRPC(p []byte) bool {
	if len(p) > tdsMessageLimit || !tdsValidateHeaders(p) {
		return false
	}
	r := tdsRPCReader{p: p[22:]}
	n := r.u16()
	if n == 0xffff {
		if r.u16() != 10 {
			return false
		}
	} else {
		name, err := tdsString(r.take(int(n) * 2))
		if err != nil || !strings.EqualFold(name, "sp_executesql") {
			return false
		}
	}
	if r.u16() != 0 || r.bad {
		return false
	}
	count := 0
	names := map[string]bool{}
	for len(r.p) > 0 && !r.bad {
		if count >= 1024 {
			return false
		}
		n := int(r.u8())
		name, err := tdsString(r.take(n * 2))
		if err != nil || r.u8() != 0 { // input parameters only
			return false
		}
		if count < 2 {
			expected := "@stmt"
			if count == 1 {
				expected = "@params"
			}
			if name != "" && !strings.EqualFold(name, expected) {
				return false
			}
		} else {
			if !tdsRPCParameterName(name) || names[strings.ToLower(name)] {
				return false
			}
			names[strings.ToLower(name)] = true
		}
		typ := r.u8()
		value, nullable := r.value(typ)
		if count < 2 {
			if typ != 0xe7 || nullable {
				return false
			}
			text, err := tdsString(value)
			if err != nil || (count == 0 && text == "") {
				return false
			}
		}
		count++
	}
	return !r.bad && count >= 2 && len(r.p) == 0
}

func tdsRPCParameterName(name string) bool {
	if len(name) < 2 || len(name) > 128 || name[0] != '@' {
		return false
	}
	for _, c := range name[1:] {
		if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || c == '_') {
			return false
		}
	}
	return !strings.EqualFold(name, "@stmt") && !strings.EqualFold(name, "@params")
}

type tdsRPCReader struct {
	p   []byte
	bad bool
}

func (r *tdsRPCReader) take(n int) []byte {
	if r.bad || n < 0 || n > len(r.p) {
		r.bad = true
		return nil
	}
	p := r.p[:n]
	r.p = r.p[n:]
	return p
}
func (r *tdsRPCReader) u8() byte {
	p := r.take(1)
	if len(p) == 0 {
		return 0
	}
	return p[0]
}
func (r *tdsRPCReader) u16() uint16 {
	p := r.take(2)
	if len(p) == 0 {
		return 0
	}
	return binary.LittleEndian.Uint16(p)
}
func (r *tdsRPCReader) u32() uint32 {
	p := r.take(4)
	if len(p) == 0 {
		return 0
	}
	return binary.LittleEndian.Uint32(p)
}
func (r *tdsRPCReader) u64() uint64 {
	p := r.take(8)
	if len(p) == 0 {
		return 0
	}
	return binary.LittleEndian.Uint64(p)
}

func (r *tdsRPCReader) value(typ byte) ([]byte, bool) {
	// Nullable scalar metadata fixes the exact non-NULL value size.
	size := 0
	switch typ {
	case 0x24, 0x26, 0x68, 0x6d, 0x6e, 0x6f, 0x6a, 0x6c:
		size = int(r.u8())
		valid := false
		switch typ {
		case 0x24:
			valid = size == 16
		case 0x26:
			valid = size == 1 || size == 2 || size == 4 || size == 8
		case 0x68:
			valid = size == 1
		case 0x6d, 0x6e, 0x6f:
			valid = size == 4 || size == 8
		case 0x6a, 0x6c:
			precision, scale := int(r.u8()), int(r.u8())
			expected := 5
			if precision > 9 {
				expected = 9
			}
			if precision > 19 {
				expected = 13
			}
			if precision > 28 {
				expected = 17
			}
			valid = precision >= 1 && precision <= 38 && scale <= precision && size == expected
		}
		if !valid {
			r.bad = true
		}
	case 0x28:
		size = 3
	case 0x29, 0x2a, 0x2b:
		scale := r.u8()
		if scale > 7 {
			r.bad = true
		}
		size = 3
		if scale > 2 {
			size = 4
		}
		if scale > 4 {
			size = 5
		}
		if typ == 0x2a {
			size += 3
		}
		if typ == 0x2b {
			size += 5
		}
	case 0xa5, 0xad, 0xa7, 0xaf, 0xe7, 0xef:
		max := int(r.u16())
		unicode := typ == 0xe7 || typ == 0xef
		if typ != 0xa5 && typ != 0xad {
			r.take(5)
		} // collation
		if max == 0xffff {
			if typ == 0xad || typ == 0xaf || typ == 0xef {
				r.bad = true
			}
			return r.plp(unicode)
		}
		if max < 1 || max > 8000 || unicode && max%2 != 0 {
			r.bad = true
		}
		n := int(r.u16())
		if n == 0xffff {
			return nil, true
		}
		if n > max || unicode && n%2 != 0 {
			r.bad = true
		}
		return r.take(n), false
	default:
		r.bad = true
		return nil, false
	}
	n := int(r.u8())
	if n == 0 {
		return nil, true
	}
	if n != size {
		r.bad = true
	}
	return r.take(n), false
}

func (r *tdsRPCReader) plp(unicode bool) ([]byte, bool) {
	total := r.u64()
	if total == ^uint64(0) {
		return nil, true
	}
	if total != ^uint64(1) && total > tdsMessageLimit {
		r.bad = true
		return nil, false
	}
	var result []byte
	for chunks := 0; chunks <= 1024 && !r.bad; chunks++ {
		n := uint64(r.u32())
		if n == 0 {
			if total != ^uint64(1) && total != uint64(len(result)) || unicode && len(result)%2 != 0 {
				r.bad = true
			}
			return result, false
		}
		if n > uint64(len(r.p)) || n+uint64(len(result)) > tdsMessageLimit {
			break
		}
		result = append(result, r.take(int(n))...)
	}
	r.bad = true
	return nil, false
}
