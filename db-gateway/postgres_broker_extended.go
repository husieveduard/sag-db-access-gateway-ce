package main

import (
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"time"
	"unicode/utf8"
)

func pgReadBrokerFrame(r io.Reader) (byte, []byte, []byte, error) {
	var h [5]byte
	if _, e := io.ReadFull(r, h[:]); e != nil {
		return 0, nil, nil, errPgExtended
	}
	n := int(binary.BigEndian.Uint32(h[1:]))
	if n < 4 || n > 1<<20 {
		return 0, nil, nil, errPgExtended
	}
	p := make([]byte, n-4)
	if _, e := io.ReadFull(r, p); e != nil {
		return 0, nil, nil, errPgExtended
	}
	return h[0], p, append(h[:], p...), nil
}

var errPgExtended = errors.New("PostgreSQL laboratory extended protocol denied")

const pgMaxHandles = 64
const pgMaxFields = 1024

type pgPreparedMeta struct{ params, columns int }
type pgPortalMeta struct {
	statement string
	columns   int
}
type pgExtendedState struct {
	statements map[string]pgPreparedMeta
	portals    map[string]pgPortalMeta
}
type pgExtendedOp struct {
	kind, target           byte
	name, statement        string
	params, columns, stage int
}
type pgExtendedFrame struct {
	kind    byte
	payload []byte
	op      pgExtendedOp
}

func newPgExtendedState() *pgExtendedState {
	return &pgExtendedState{statements: make(map[string]pgPreparedMeta), portals: make(map[string]pgPortalMeta)}
}
func (s *pgExtendedState) clone() *pgExtendedState {
	copy := newPgExtendedState()
	for k, v := range s.statements {
		copy.statements[k] = v
	}
	for k, v := range s.portals {
		copy.portals[k] = v
	}
	return copy
}
func pgCString(p []byte) (string, []byte, error) {
	i := bytes.IndexByte(p, 0)
	if i < 0 || !utf8.Valid(p[:i]) {
		return "", nil, errPgExtended
	}
	return string(p[:i]), p[i+1:], nil
}
func pgHandle(p []byte) (string, []byte, error) {
	name, rest, e := pgCString(p)
	if e != nil || len(name) > 128 {
		return "", nil, errPgExtended
	}
	return name, rest, nil
}
func pgFormats(p []byte) (int, []byte, error) {
	if len(p) < 2 {
		return 0, nil, errPgExtended
	}
	n := int(binary.BigEndian.Uint16(p))
	p = p[2:]
	if n > pgMaxFields || len(p) < n*2 {
		return 0, nil, errPgExtended
	}
	for i := 0; i < n; i++ {
		if binary.BigEndian.Uint16(p[i*2:]) > 1 {
			return 0, nil, errPgExtended
		}
	}
	return n, p[n*2:], nil
}

// Validate a complete Sync-delimited cycle against staged handle state before
// forwarding anything. The committed state changes only on backend ACKs.
func (s *pgExtendedState) validate(kind byte, p []byte) (pgExtendedOp, error) {
	op := pgExtendedOp{kind: kind, params: -1, columns: -1}
	switch kind {
	case 'P':
		name, r, e := pgHandle(p)
		if e != nil {
			return op, e
		}
		_, r, e = pgCString(r)
		if e != nil || len(r) < 2 {
			return op, errPgExtended
		}
		n := int(binary.BigEndian.Uint16(r))
		if n > pgMaxFields || len(r) != 2+4*n {
			return op, errPgExtended
		}
		if _, ok := s.statements[name]; !ok && len(s.statements) >= pgMaxHandles {
			return op, errPgExtended
		}
		s.statements[name] = pgPreparedMeta{-1, -1}
		op.name = name
	case 'B':
		name, r, e := pgHandle(p)
		if e != nil {
			return op, e
		}
		statement, r, e := pgHandle(r)
		if e != nil {
			return op, e
		}
		meta, ok := s.statements[statement]
		if !ok {
			return op, errPgExtended
		}
		formats, r, e := pgFormats(r)
		if e != nil || len(r) < 2 {
			return op, errPgExtended
		}
		n := int(binary.BigEndian.Uint16(r))
		r = r[2:]
		if n > pgMaxFields || (meta.params >= 0 && meta.params != n) || (formats != 0 && formats != 1 && formats != n) {
			return op, errPgExtended
		}
		for i := 0; i < n; i++ {
			if len(r) < 4 {
				return op, errPgExtended
			}
			size := int32(binary.BigEndian.Uint32(r))
			r = r[4:]
			if size == -1 {
				continue
			}
			if size < 0 || int64(size) > int64(len(r)) {
				return op, errPgExtended
			}
			r = r[int(size):]
		}
		results, r, e := pgFormats(r)
		if e != nil || len(r) != 0 || (meta.columns >= 0 && results != 0 && results != 1 && results != meta.columns) {
			return op, errPgExtended
		}
		if _, ok := s.portals[name]; !ok && len(s.portals) >= pgMaxHandles {
			return op, errPgExtended
		}
		s.portals[name] = pgPortalMeta{statement, meta.columns}
		op.name = name
		op.statement = statement
		op.params = n
		op.columns = meta.columns
	case 'D', 'C':
		if len(p) < 2 || (p[0] != 'S' && p[0] != 'P') {
			return op, errPgExtended
		}
		name, r, e := pgHandle(p[1:])
		if e != nil || len(r) != 0 {
			return op, errPgExtended
		}
		op.target = p[0]
		op.name = name
		if op.target == 'S' {
			if _, ok := s.statements[name]; !ok {
				return op, errPgExtended
			}
			if kind == 'C' {
				delete(s.statements, name)
			}
		} else {
			if _, ok := s.portals[name]; !ok {
				return op, errPgExtended
			}
			if kind == 'C' {
				delete(s.portals, name)
			}
		}
	case 'E':
		name, r, e := pgHandle(p)
		if e != nil || len(r) != 4 || binary.BigEndian.Uint32(r) != 0 {
			return op, errPgExtended
		}
		portal, ok := s.portals[name]
		if !ok {
			return op, errPgExtended
		}
		op.name = name
		op.columns = portal.columns
	case 'S':
		if len(p) != 0 {
			return op, errPgExtended
		}
	default:
		return op, errPgExtended
	}
	return op, nil
}
func pgReadExtendedCycle(front net.Conn, kind byte, p []byte, state *pgExtendedState) ([]pgExtendedFrame, error) {
	staged := state.clone()
	var frames []pgExtendedFrame
	total := 0
	for len(frames) < 128 {
		total += len(p) + 5
		if total > 4<<20 {
			return nil, errPgExtended
		}
		op, e := staged.validate(kind, p)
		if e != nil {
			return nil, e
		}
		frames = append(frames, pgExtendedFrame{kind, p, op})
		if kind == 'S' {
			return frames, nil
		}
		kind, p, _, e = pgReadBrokerFrame(front)
		if e != nil {
			return nil, errPgExtended
		}
	}
	return nil, errPgExtended
}
func pgParameterDescription(p []byte) (int, error) {
	if len(p) < 2 {
		return 0, errPgExtended
	}
	n := int(binary.BigEndian.Uint16(p))
	if n > pgMaxFields || len(p) != 2+4*n {
		return 0, errPgExtended
	}
	return n, nil
}
func pgRowDescription(p []byte) (int, error) {
	if len(p) < 2 {
		return 0, errPgExtended
	}
	n := int(binary.BigEndian.Uint16(p))
	p = p[2:]
	if n > pgMaxFields {
		return 0, errPgExtended
	}
	for i := 0; i < n; i++ {
		_, r, e := pgCString(p)
		if e != nil || len(r) < 18 || binary.BigEndian.Uint16(r[16:]) > 1 {
			return 0, errPgExtended
		}
		p = r[18:]
	}
	if len(p) != 0 {
		return 0, errPgExtended
	}
	return n, nil
}
func pgDataRow(p []byte, expected int) (int, error) {
	if len(p) < 2 {
		return 0, errPgExtended
	}
	n := int(binary.BigEndian.Uint16(p))
	p = p[2:]
	if n > pgMaxFields || (expected >= 0 && expected != n) {
		return 0, errPgExtended
	}
	for i := 0; i < n; i++ {
		if len(p) < 4 {
			return 0, errPgExtended
		}
		size := int32(binary.BigEndian.Uint32(p))
		p = p[4:]
		if size == -1 {
			continue
		}
		if size < 0 || int64(size) > int64(len(p)) {
			return 0, errPgExtended
		}
		p = p[int(size):]
	}
	if len(p) != 0 {
		return 0, errPgExtended
	}
	return n, nil
}
func pgErrorFields(p []byte) bool {
	if len(p) < 2 || p[len(p)-1] != 0 {
		return false
	}
	for len(p) > 1 {
		if p[0] == 0 {
			return false
		}
		_, r, e := pgCString(p[1:])
		if e != nil {
			return false
		}
		p = r
	}
	return len(p) == 1 && p[0] == 0
}
func pgParameterStatus(p []byte) bool {
	_, r, e := pgCString(p)
	if e != nil {
		return false
	}
	_, r, e = pgCString(r)
	return e == nil && len(r) == 0
}

// Buffer an extended response up to ReadyForQuery, correlating each completion
// with its request. Authentication/cancel keys, COPY, notifications and cursors
// cannot escape to the frontend. Error recovery is completed only at Sync.
func pgRelayExtendedCycle(parent context.Context, backend, front net.Conn, frames []pgExtendedFrame, state *pgExtendedState, authorize func(context.Context) bool) error {
	ctx, cancel := context.WithTimeout(parent, 30*time.Second)
	defer cancel()
	stopClose := context.AfterFunc(ctx, func() { _ = backend.Close(); _ = front.Close() })
	defer stopClose()
	if backend.SetDeadline(time.Now().Add(30*time.Second)) != nil {
		return errPgExtended
	}
	type result struct {
		data []byte
		err  error
	}
	done := make(chan result, 1)
	go func() {
		data, e := pgReadExtendedResponse(backend, frames, state)
		if e != nil {
			cancel()
		}
		done <- result{data, e}
	}()
	// Read concurrently with writes: a backend can produce enough metadata/rows
	// to fill its send window before receiving the rest of this bounded cycle.
	for i := range frames {
		if ctx.Err() != nil || authorize == nil || !authorize(ctx) || pgBrokerFrame(backend, frames[i].kind, frames[i].payload) != nil {
			_ = backend.Close()
			<-done
			return errPgExtended
		}
	}
	response := <-done
	if response.err != nil {
		return response.err
	}
	if ctx.Err() != nil {
		return errPgExtended
	}
	front.SetWriteDeadline(time.Now().Add(30 * time.Second))
	_, e := io.Copy(front, bytesReader(response.data))
	return e
}
func pgReadExtendedResponse(backend net.Conn, frames []pgExtendedFrame, state *pgExtendedState) ([]byte, error) {
	var response []byte
	index := 0
	failed := false
	for messages := 0; messages < 100000; messages++ {
		kind, p, raw, e := pgReadBrokerFrame(backend)
		if e != nil {
			return nil, errPgExtended
		}
		if len(response)+len(raw) > 64<<20 {
			return nil, errPgExtended
		}
		switch kind {
		case 'N':
			if !pgErrorFields(p) {
				return nil, errPgExtended
			}
		case 'S':
			if !pgParameterStatus(p) {
				return nil, errPgExtended
			}
		case 'E':
			if failed || !pgErrorFields(p) {
				return nil, errPgExtended
			}
			failed = true
		case 'Z':
			if len(p) != 1 || (p[0] != 'I' && p[0] != 'T' && p[0] != 'E') {
				return nil, errPgExtended
			}
			if !failed && index != len(frames)-1 {
				return nil, errPgExtended
			}
			if p[0] != 'T' {
				clear(state.portals)
			}
			response = append(response, raw...)
			return response, nil
		default:
			if failed || index >= len(frames)-1 {
				return nil, errPgExtended
			}
			op := &frames[index].op
			switch op.kind {
			case 'P':
				if kind != '1' || len(p) != 0 {
					return nil, errPgExtended
				}
				state.statements[op.name] = pgPreparedMeta{-1, -1}
				index++
			case 'B':
				if kind != '2' || len(p) != 0 {
					return nil, errPgExtended
				}
				meta := state.statements[op.statement]
				state.portals[op.name] = pgPortalMeta{op.statement, meta.columns}
				if meta.params < 0 {
					meta.params = op.params
					state.statements[op.statement] = meta
				}
				index++
			case 'C':
				if kind != '3' || len(p) != 0 {
					return nil, errPgExtended
				}
				if op.target == 'S' {
					delete(state.statements, op.name)
				} else {
					delete(state.portals, op.name)
				}
				index++
			case 'D':
				if op.target == 'S' && op.stage == 0 {
					if kind != 't' {
						return nil, errPgExtended
					}
					n, e := pgParameterDescription(p)
					if e != nil {
						return nil, e
					}
					meta := state.statements[op.name]
					meta.params = n
					state.statements[op.name] = meta
					op.stage = 1
					break
				}
				cols := 0
				if kind == 'T' {
					var e error
					cols, e = pgRowDescription(p)
					if e != nil {
						return nil, e
					}
				} else if kind != 'n' || len(p) != 0 {
					return nil, errPgExtended
				}
				if op.target == 'S' {
					meta := state.statements[op.name]
					meta.columns = cols
					state.statements[op.name] = meta
				} else {
					meta := state.portals[op.name]
					meta.columns = cols
					state.portals[op.name] = meta
				}
				index++
			case 'E':
				if op.stage == 0 {
					if portal, ok := state.portals[op.name]; ok {
						op.columns = portal.columns
					}
					op.stage = 1
				}
				if kind == 'D' {
					n, e := pgDataRow(p, op.columns)
					if e != nil {
						return nil, e
					}
					op.columns = n
					break
				}
				if kind == 'C' {
					_, r, e := pgCString(p)
					if e != nil || len(r) != 0 {
						return nil, errPgExtended
					}
				} else if kind != 'I' || len(p) != 0 {
					return nil, errPgExtended
				}
				index++
			default:
				return nil, errPgExtended
			}
		}
		response = append(response, raw...)
	}
	return nil, errPgExtended
}

func pgRelaySimpleQuery(backend, front net.Conn, query []byte, state *pgExtendedState) error {
	_, rest, e := pgCString(query)
	if e != nil || len(rest) != 0 || len(query) < 2 {
		return errPgExtended
	}
	delete(state.statements, "")
	delete(state.portals, "")
	if backend.SetDeadline(time.Now().Add(30*time.Second)) != nil || pgBrokerFrame(backend, 'Q', query) != nil {
		return errPgExtended
	}
	var result []byte
	columns := -1
	for messages := 0; messages < 100000; messages++ {
		kind, p, raw, e := pgReadBrokerFrame(backend)
		if e != nil || len(result)+len(raw) > 64<<20 {
			return errPgExtended
		}
		switch kind {
		case 'T':
			columns, e = pgRowDescription(p)
			if e != nil {
				return e
			}
		case 'D':
			if columns < 0 {
				return errPgExtended
			}
			if _, e = pgDataRow(p, columns); e != nil {
				return e
			}
		case 'C':
			_, rest, e := pgCString(p)
			if e != nil || len(rest) != 0 {
				return errPgExtended
			}
			columns = -1
		case 'I':
			if len(p) != 0 {
				return errPgExtended
			}
		case 'E', 'N':
			if !pgErrorFields(p) {
				return errPgExtended
			}
		case 'S':
			if !pgParameterStatus(p) {
				return errPgExtended
			}
		case 'Z':
			if len(p) != 1 || (p[0] != 'I' && p[0] != 'T' && p[0] != 'E') {
				return errPgExtended
			}
			if p[0] != 'T' {
				clear(state.portals)
			}
			result = append(result, raw...)
			front.SetWriteDeadline(time.Now().Add(30 * time.Second))
			_, e := io.Copy(front, bytesReader(result))
			return e
		default:
			return errPgExtended
		}
		result = append(result, raw...)
	}
	return errPgExtended
}
