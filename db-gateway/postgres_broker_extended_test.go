package main

import (
	"bytes"
	"context"
	"encoding/binary"
	"net"
	"strconv"
	"testing"
	"time"
)

func pgTestParse(name, sql string) []byte {
	return append(append(append([]byte(name), 0), append([]byte(sql), 0)...), 0, 0)
}
func pgTestBind(portal, stmt string) []byte {
	return append(append(append([]byte(portal), 0), append([]byte(stmt), 0)...), 0, 0, 0, 0, 0, 0)
}
func pgTestExecute(portal string) []byte { return append(append([]byte(portal), 0), 0, 0, 0, 0) }
func pgTestDescription() []byte {
	p := []byte{0, 1, 'v', 0}
	fixed := make([]byte, 18)
	binary.BigEndian.PutUint32(fixed[6:], 23)
	binary.BigEndian.PutUint16(fixed[10:], 4)
	return append(p, fixed...)
}
func TestPgExtendedHandleAndBindValidation(t *testing.T) {
	state := newPgExtendedState()
	if _, e := state.validate('P', pgTestParse("stmt", "SELECT 1")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('B', pgTestBind("portal", "stmt")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('E', pgTestExecute("portal")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('E', pgTestExecute("foreign")); e == nil {
		t.Fatal("unknown portal accepted")
	}
	if _, e := state.validate('B', pgTestBind("foreign", "other-statement")); e == nil {
		t.Fatal("unknown prepared statement accepted")
	}
	cursor := pgTestExecute("portal")
	cursor[len(cursor)-1] = 1
	if _, e := state.validate('E', cursor); e == nil {
		t.Fatal("cursor execute accepted")
	}
	if _, e := state.validate('H', nil); e == nil {
		t.Fatal("Flush/pipeline accepted")
	}
	if _, e := state.validate('C', []byte("Sstmt\x00")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('B', pgTestBind("p", "stmt")); e == nil {
		t.Fatal("closed statement accepted")
	}
	var bad bytes.Buffer
	bad.Write([]byte{'P', 0, 16, 0, 5})
	if _, _, _, e := pgReadBrokerFrame(&bad); e == nil {
		t.Fatal("oversized frame accepted before body")
	}
}
func TestPgExtendedBindLengthsAndFormats(t *testing.T) {
	state := newPgExtendedState()
	state.statements["s"] = pgPreparedMeta{1, -1}
	bind := []byte{'p', 0, 's', 0, 0, 1, 0, 1, 0, 1, 0, 0, 0, 4, 0, 0, 0, 42, 0, 1, 0, 1}
	if _, e := state.validate('B', bind); e != nil {
		t.Fatal("binary Bind denied")
	}
	for _, change := range []func([]byte){func(b []byte) { b[7] = 2 }, func(b []byte) { b[13] = 255 }, func(b []byte) { b[len(b)-1] = 2 }} {
		bad := append([]byte(nil), bind...)
		change(bad)
		if _, e := state.validate('B', bad); e == nil {
			t.Fatal("malformed Bind accepted")
		}
	}
	if _, e := state.validate('B', append(bind, 0)); e == nil {
		t.Fatal("trailing Bind bytes accepted")
	}
	if _, e := pgDataRow([]byte{0, 1, 255, 255, 255, 255}, 1); e != nil {
		t.Fatal("NULL row denied")
	}
	if _, e := pgDataRow([]byte{0, 1, 255, 255, 255, 254}, 1); e == nil {
		t.Fatal("invalid negative row size accepted")
	}
}
func TestPgExtendedACKStateAndErrorSyncRecovery(t *testing.T) {
	state := newPgExtendedState()
	staged := state.clone()
	parse, _ := staged.validate('P', pgTestParse("s", "SELECT 1"))
	describe, _ := staged.validate('D', []byte("Ss\x00"))
	frames := []pgExtendedFrame{{kind: 'P', op: parse}, {kind: 'D', op: describe}, {kind: 'S'}}
	backend, server := net.Pipe()
	defer backend.Close()
	go func() {
		defer server.Close()
		pgBrokerFrame(server, '1', nil)
		pgBrokerFrame(server, 't', []byte{0, 0})
		pgBrokerFrame(server, 'T', pgTestDescription())
		pgBrokerFrame(server, 'Z', []byte{'I'})
	}()
	response, e := pgReadExtendedResponse(backend, frames, state)
	if e != nil || len(response) == 0 || state.statements["s"].params != 0 || state.statements["s"].columns != 1 {
		t.Fatal("acknowledged metadata not committed")
	}
	backend.Close()
	backend, server = net.Pipe()
	defer backend.Close()
	go func() {
		defer server.Close()
		pgBrokerFrame(server, 'E', []byte("SERROR\x00C42601\x00Mfixture syntax error\x00\x00"))
		pgBrokerFrame(server, 'Z', []byte{'I'})
	}()
	parse.name = "failed"
	if _, e = pgReadExtendedResponse(backend, []pgExtendedFrame{{kind: 'P', op: parse}, {kind: 'S'}}, state); e != nil {
		t.Fatal("error did not recover at Sync")
	}
	if _, ok := state.statements["failed"]; ok {
		t.Fatal("unacknowledged Parse committed a handle")
	}
}
func TestPgExtendedRejectsBackendSecretsAndCopy(t *testing.T) {
	for _, kind := range []byte{'R', 'K', 'G', 'H', 'W', 'A', 's'} {
		backend, server := net.Pipe()
		go func() { defer server.Close(); pgBrokerFrame(server, kind, []byte{0}) }()
		if response, e := pgReadExtendedResponse(backend, []pgExtendedFrame{{kind: 'E', op: pgExtendedOp{kind: 'E'}}, {kind: 'S'}}, newPgExtendedState()); e == nil || len(response) != 0 {
			t.Error("backend control/COPY bytes escaped")
		}
		backend.Close()
	}
}

func TestPgExtendedRechecksBeforeExecute(t *testing.T) {
	backend, server := net.Pipe()
	front, client := net.Pipe()
	defer backend.Close()
	defer server.Close()
	defer front.Close()
	defer client.Close()
	staged := newPgExtendedState()
	p := pgTestParse("s", "SELECT 1")
	po, _ := staged.validate('P', p)
	b := pgTestBind("p", "s")
	bo, _ := staged.validate('B', b)
	e := pgTestExecute("p")
	eo, _ := staged.validate('E', e)
	frames := []pgExtendedFrame{{kind: 'P', payload: p, op: po}, {kind: 'B', payload: b, op: bo}, {kind: 'E', payload: e, op: eo}, {kind: 'S'}}
	received := make(chan []byte, 1)
	go func() {
		var kinds []byte
		for {
			kind, _, _, err := readPgMessage(server)
			if err != nil {
				received <- kinds
				return
			}
			kinds = append(kinds, kind)
			switch kind {
			case 'P':
				pgBrokerFrame(server, '1', nil)
			case 'B':
				pgBrokerFrame(server, '2', nil)
			}
		}
	}()
	checks := 0
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	err := pgRelayExtendedCycle(ctx, backend, front, frames, newPgExtendedState(), func(context.Context) bool { checks++; return checks < 3 })
	if err == nil || checks != 3 || !bytes.Equal(<-received, []byte{'P', 'B'}) {
		t.Fatal("Execute crossed an invalidated authorization boundary")
	}
}
func TestPgExtendedReadsWhileWritingCycle(t *testing.T) {
	backend, server := net.Pipe()
	front, client := net.Pipe()
	defer backend.Close()
	defer server.Close()
	defer front.Close()
	defer client.Close()
	op := pgExtendedOp{kind: 'P', name: "s"}
	frames := []pgExtendedFrame{{kind: 'P', payload: pgTestParse("s", "SELECT 1"), op: op}, {kind: 'S'}}
	serverDone := make(chan error, 1)
	go func() {
		kind, _, _, e := readPgMessage(server)
		if e != nil || kind != 'P' {
			serverDone <- errPgExtended
			return
		}
		if pgBrokerFrame(server, '1', nil) != nil {
			serverDone <- errPgExtended
			return
		}
		kind, _, _, e = readPgMessage(server)
		if e != nil || kind != 'S' {
			serverDone <- errPgExtended
			return
		}
		serverDone <- pgBrokerFrame(server, 'Z', []byte{'I'})
	}()
	clientDone := make(chan bool, 1)
	go func() {
		kind, _, _, e := readPgMessage(client)
		if e != nil || kind != '1' {
			clientDone <- false
			return
		}
		kind, _, _, e = readPgMessage(client)
		clientDone <- e == nil && kind == 'Z'
	}()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if pgRelayExtendedCycle(ctx, backend, front, frames, newPgExtendedState(), func(context.Context) bool { return true }) != nil {
		t.Fatal("bounded send window deadlocked cycle")
	}
	if <-serverDone != nil || !<-clientDone {
		t.Fatal("cycle response changed")
	}
}
func TestPgExtendedHandleQuotaAndPortalLifetime(t *testing.T) {
	state := newPgExtendedState()
	for i := 0; i < pgMaxHandles; i++ {
		if _, e := state.validate('P', pgTestParse("s"+strconv.Itoa(i), "SELECT 1")); e != nil {
			t.Fatal(e)
		}
	}
	if _, e := state.validate('P', pgTestParse("overflow", "SELECT 1")); e == nil {
		t.Fatal("prepared handle quota exceeded")
	}
	if _, e := state.validate('B', pgTestBind("portal", "s0")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('C', []byte("Pportal\x00")); e != nil {
		t.Fatal(e)
	}
	if _, e := state.validate('E', pgTestExecute("portal")); e == nil {
		t.Fatal("closed portal accepted")
	}
}
