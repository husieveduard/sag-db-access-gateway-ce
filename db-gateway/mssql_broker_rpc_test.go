package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"encoding/binary"
	"net"
	"testing"
	"time"
)

func tdsTestRPCString(s string) []byte {
	v := tdsUTF16(s)
	p := []byte{0, 0, 0xe7, 0xff, 0xff, 0, 0, 0, 0, 0}
	p = binary.LittleEndian.AppendUint64(p, uint64(len(v)))
	if len(v) > 0 {
		p = binary.LittleEndian.AppendUint32(p, uint32(len(v)))
		p = append(p, v...)
	}
	return binary.LittleEndian.AppendUint32(p, 0)
}
func tdsTestRPC() []byte {
	return tdsTestRPCQuery("SELECT @p1")
}
func tdsTestRPCQuery(query string) []byte {
	p := append(tdsTestBatch("")[:22], 0xff, 0xff, 10, 0, 0, 0)
	p = append(p, tdsTestRPCString(query)...)
	p = append(p, tdsTestRPCString("@p1 bigint")...)
	p = append(p, 3)
	p = append(p, tdsUTF16("@p1")...)
	return append(p, 0, 0x26, 8, 8, 42, 0, 0, 0, 0, 0, 0, 0)
}

func TestTDSRPCValidationBoundaries(t *testing.T) {
	p := tdsTestRPC()
	if !tdsValidateRPC(p) {
		t.Fatal("valid parameterized SQL rejected")
	}
	for n := 0; n < len(p); n++ {
		// The complete two-control-parameter prefix is itself a valid RPC.
		if n == len(p)-19 {
			continue
		}
		if tdsValidateRPC(p[:n]) {
			t.Fatalf("truncated RPC accepted at %d", n)
		}
	}
	for _, mutate := range []func([]byte){
		func(p []byte) { p[10] = 1 },
		func(p []byte) { p[24] = 11 }, // sp_prepare requires separate handle tracking
		func(p []byte) { p[26] = 1 },
		func(p []byte) { p[29] = 8 },        // encrypted parameter
		func(p []byte) { p[len(p)-10] = 7 }, // incorrect integer size
	} {
		q := bytes.Clone(p)
		mutate(q)
		if tdsValidateRPC(q) {
			t.Fatal("unsafe RPC accepted")
		}
	}
	if tdsValidateRPC(append(bytes.Clone(p), 0x80)) {
		t.Fatal("RPC batching accepted")
	}
	var wire bytes.Buffer
	if tdsWriteMessage(&wire, 3, p, true) != nil {
		t.Fatal("RPC reset write")
	}
	got, reset, err := tdsReadMessage(&wire, 3, tdsMessageLimit, true)
	if err != nil || !reset || !bytes.Equal(got, p) {
		t.Fatal("RPC reset lost")
	}

	// Explicit PLP lengths must agree with the actual chunks, including UTF-16
	// code units split across chunks. No unbounded allocation from wire lengths.
	for _, raw := range [][]byte{
		binary.LittleEndian.AppendUint64(nil, uint64(tdsMessageLimit+1)),
		append(binary.LittleEndian.AppendUint64(nil, 5), 0, 0, 0, 0),
		append(binary.LittleEndian.AppendUint64(nil, ^uint64(1)), 0xff, 0xff, 0xff, 0xff),
	} {
		r := tdsRPCReader{p: raw}
		r.plp(true)
		if !r.bad {
			t.Fatal("invalid PLP accepted")
		}
	}
}

func TestTDSIndependentClientRPCParameters(t *testing.T) {
	cert := testBrokerCertificate(t)
	parsed, _ := x509.ParseCertificate(cert.Certificate[0])
	roots := x509.NewCertPool()
	roots.AddCert(parsed)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	done := make(chan error, 1)
	go func() {
		raw, err := listener.Accept()
		if err != nil {
			done <- err
			return
		}
		defer raw.Close()
		raw.SetDeadline(time.Now().Add(8 * time.Second))
		ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
		defer cancel()
		front, err := tdsFrontend(ctx, raw, &tls.Config{Certificates: []tls.Certificate{cert}}, "lab", func(u, p string) bool { return u == "temporary" && p == "secret" })
		if err != nil {
			done <- err
			return
		}
		if tdsWrite(front, 4, tdsLoginAck()) != nil {
			done <- errTDSProtocol
			return
		}
		for i := 0; i < 2; i++ {
			p, _, err := tdsReadMessage(front, 3, tdsMessageLimit, true)
			if err != nil || !tdsValidateRPC(p) {
				done <- errTDSProtocol
				return
			}
			result := []byte{0xfe, 0x10, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0}
			if tdsWrite(front, 4, result) != nil {
				done <- errTDSProtocol
				return
			}
		}
		done <- nil
	}()
	db := tdsTestClient(t, listener.Addr().String(), "temporary", "secret", "lab", &tls.Config{RootCAs: roots, ServerName: "localhost"})
	defer db.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()
	stmt, err := db.PrepareContext(ctx, "INSERT INTO probe VALUES(@p1,@p2,@p3,@p4,@p5,@p6)")
	if err != nil {
		t.Fatal(err)
	}
	defer stmt.Close()
	for _, blob := range [][]byte{{0, 1, 2, 255}, bytes.Repeat([]byte{42}, 10000)} {
		result, err := stmt.ExecContext(ctx, int64(42), "π東京", sql.NullString{}, blob, time.Date(2026, 10, 29, 12, 0, 0, 0, time.UTC), 12.5)
		if err != nil {
			t.Fatalf("independent RPC client failed: %v; server: %v", err, <-done)
		}
		n, err := result.RowsAffected()
		if err != nil || n != 1 {
			t.Fatal("RPC DONEPROC affected rows mismatch")
		}
	}
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func FuzzTDSRPCValidation(f *testing.F) {
	f.Add(tdsTestRPC())
	f.Add([]byte{})
	f.Fuzz(func(t *testing.T, p []byte) { tdsValidateRPC(p) })
}
