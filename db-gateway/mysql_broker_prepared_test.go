package main

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/binary"
	"math"
	"net"
	"testing"
	"time"
)

func myTestDefinition(kind byte) []byte {
	p := []byte{3, 'd', 'e', 'f', 0, 0, 0, 1, 'c', 0, 12, 45, 0, 64, 0, 0, 0, kind, 0, 0, 0, 0, 0}
	return p
}
func myTestExecute(id uint32, params []byte) []byte {
	p := []byte{0x17, 0, 0, 0, 0, 0, 1, 0, 0, 0}
	binary.LittleEndian.PutUint32(p[1:], id)
	return append(p, params...)
}
func TestMyPreparedExecuteBoundsAndCachedTypes(t *testing.T) {
	stmt := &myPreparedStatement{id: 9, params: 1}
	if myValidateExecute(myTestExecute(9, []byte{0, 0, 1}), stmt) {
		t.Fatal("uncached types accepted")
	}
	p := myTestExecute(9, []byte{0, 1, 3, 0, 42, 0, 0, 0})
	if !myValidateExecute(p, stmt) || !myValidateExecute(myTestExecute(9, []byte{0, 0, 43, 0, 0, 0}), stmt) {
		t.Fatal("bound/cached integer execute denied")
	}
	for _, mutate := range []func([]byte){func(p []byte) { p[1] = 10 }, func(p []byte) { p[5] = 1 }, func(p []byte) { p[6] = 2 }, func(p []byte) { p[11] = 2 }, func(p []byte) { p[12] = 0xee }, func(p []byte) { p[13] = 1 }} {
		bad := append([]byte(nil), p...)
		mutate(bad)
		if myValidateExecute(bad, stmt) {
			t.Fatal("malformed or cursor execute accepted")
		}
	}
	if myValidateExecute(p[:len(p)-1], stmt) || myValidateExecute(append(p, 0), stmt) {
		t.Fatal("truncated/trailing values accepted")
	}
	if myValidateExecute(myTestExecute(9, []byte{1, 1, 0xee, 0}), stmt) {
		t.Fatal("unsupported NULL parameter type accepted")
	}
	if !myValidateExecute(myTestExecute(9, []byte{1, 1, 6, 0}), stmt) {
		t.Fatal("NULL parameter denied")
	}
	if !myValidateExecute(myTestExecute(10, nil), &myPreparedStatement{id: 10}) {
		t.Fatal("zero-parameter execute denied")
	}
}
func TestMyBinaryRowsHaveTypedBounds(t *testing.T) {
	// Seven columns cross the second null-bitmap byte; last is NULL.
	row := []byte{0, 0, 1, 42, 0, 0, 0, 1, 3, 'u', 't', 'f', 2, 0, 255, 0, 0, 0, 0, 0, 0, 0, 0}
	types := []byte{3, 1, 253, 252, 5, 6, 8}
	if !myValidateBinaryRow(row, types) {
		t.Fatal("valid mixed binary row denied")
	}
	for _, bad := range [][]byte{row[:len(row)-1], append(append([]byte(nil), row...), 0), {0, 3, 0}, {0, 0, 0, 0xff}} {
		if myValidateBinaryRow(bad, types) {
			t.Fatal("malformed binary row accepted")
		}
	}
	for _, kind := range []byte{7, 10, 11, 12} {
		if _, e := myBinaryValue([]byte{255}, kind); e == nil {
			t.Fatal("invalid temporal size accepted")
		}
	}
}
func TestMyPrepareMetadataAndStatementReset(t *testing.T) {
	backend, server := net.Pipe()
	defer backend.Close()
	done := make(chan struct{})
	go func() {
		defer close(done)
		defer server.Close()
		seq := byte(1)
		myWrite(server, &seq, []byte{0, 9, 0, 0, 0, 1, 0, 1, 0, 0, 0, 0})
		myWrite(server, &seq, myTestDefinition(253))
		myWrite(server, &seq, []byte{0xfe, 0, 0, 2, 0})
		myWrite(server, &seq, myTestDefinition(3))
		myWrite(server, &seq, []byte{0xfe, 0, 0, 2, 0})
	}()
	var front bytes.Buffer
	stmt, e := myRelayPrepare(backend, &front)
	if e != nil || stmt.id != 9 || stmt.params != 1 || front.Len() == 0 {
		t.Fatal("valid prepare metadata denied")
	}
	<-done
	if kind, e := myColumnType(myTestDefinition(3)); e != nil || kind != 3 {
		t.Fatal("column definition type mismatch")
	}
	if _, e := myColumnType([]byte{0xfb}); e == nil {
		t.Fatal("LOCAL INFILE used as metadata")
	}
}
func TestMyPreparedBinaryResultRejectsLocalFilesAndMultipleResults(t *testing.T) {
	for _, p := range [][]byte{{0xfb, 'f', 'i', 'l', 'e'}, {0, 0, 0, 8, 0, 0, 0}} {
		backend, server := net.Pipe()
		done := make(chan struct{})
		go func() { defer close(done); defer server.Close(); seq := byte(1); myWrite(server, &seq, p) }()
		var front bytes.Buffer
		if myRelayRows(backend, &front, true) == nil || front.Len() != 0 {
			t.Error("forbidden prepared response forwarded")
		}
		backend.Close()
		<-done
	}
}

func myExercisePreparedClient(t *testing.T, db *sql.DB) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	stmt, e := db.PrepareContext(ctx, "SELECT ?, ?, ?, ?, ?, ?, ?")
	if e != nil {
		t.Fatal("independent PREPARE failed")
	}
	for i := 0; i < 2; i++ {
		var number int64
		var real float64
		var yes bool
		var word string
		var blob []byte
		var null sql.NullString
		var large uint64
		e = stmt.QueryRowContext(ctx, int64(-42-i), 1.25, true, "π prepared", []byte{0, 255, 1}, nil, uint64(math.MaxUint64)).Scan(&number, &real, &yes, &word, &blob, &null, &large)
		if e != nil || number != int64(-42-i) || real != 1.25 || !yes || word != "π prepared" || !bytes.Equal(blob, []byte{0, 255, 1}) || null.Valid || large != math.MaxUint64 {
			stmt.Close()
			t.Fatal("independent binary parameter/row mismatch")
		}
	}
	if stmt.Close() != nil {
		t.Fatal("statement CLOSE failed")
	}
	// Decimal and temporal values have different binary encodings from strings.
	dateTime := time.Date(2026, 10, 29, 12, 34, 56, 123456000, time.UTC)
	var stamp, date time.Time
	var duration, decimal string
	e = db.QueryRowContext(ctx, "SELECT CAST(? AS DATETIME(6)), CAST(? AS DATE), CAST(? AS TIME(6)), CAST(? AS DECIMAL(12,4))", dateTime, "2026-10-29", "-12:34:56.123456", "123.2500").Scan(&stamp, &date, &duration, &decimal)
	if e != nil || !stamp.Equal(dateTime) || date.Format("2006-01-02") != "2026-10-29" || duration != "-12:34:56.123456" || decimal != "123.2500" {
		t.Fatalf("prepared temporal/decimal result mismatch: query_error=%t stamp=%s date=%s duration=%q decimal=%q", e != nil, stamp.Format(time.RFC3339Nano), date.Format("2006-01-02"), duration, decimal)
	}
	if invalid, e := db.PrepareContext(ctx, "SELECT FROM"); e == nil {
		invalid.Close()
		t.Fatal("invalid SQL prepared successfully")
	}
	var one int
	if db.QueryRowContext(ctx, "SELECT 1").Scan(&one) != nil || one != 1 {
		t.Fatal("prepare error or CLOSE desynchronized client")
	}
	write, e := db.PrepareContext(ctx, "INSERT INTO guard_probe VALUES (?)")
	if e != nil {
		t.Fatal("write PREPARE failed")
	}
	result, e := write.ExecContext(ctx, 88)
	write.Close()
	if e != nil {
		t.Fatal("prepared INSERT failed")
	}
	n, e := result.RowsAffected()
	if e != nil || n != 1 {
		t.Fatal("prepared affected-row count mismatch")
	}
}

func myNativePreparedInteger(t *testing.T, conn net.Conn, execute []byte, expected uint64) {
	t.Helper()
	seq := byte(0)
	if myWrite(conn, &seq, execute) != nil {
		t.Fatal("native EXECUTE write failed")
	}
	var result bytes.Buffer
	if myRelayRows(conn, &result, true) != nil {
		t.Fatal("native EXECUTE result failed")
	}
	seq = 1
	header, e := myRead(&result, &seq)
	if e != nil || len(header) != 1 || header[0] != 1 {
		t.Fatal("native prepared column count mismatch")
	}
	metadata, e := myRead(&result, &seq)
	if e != nil {
		t.Fatal("native prepared metadata missing")
	}
	kind, e := myColumnType(metadata)
	if e != nil {
		t.Fatal("native prepared column invalid")
	}
	if _, e = myRead(&result, &seq); e != nil {
		t.Fatal("metadata EOF missing")
	}
	row, e := myRead(&result, &seq)
	if e != nil || len(row) < 2 || row[0] != 0 || row[1] != 0 {
		t.Fatal("native binary integer row invalid")
	}
	var actual uint64
	switch kind {
	case 3:
		if len(row) != 6 {
			t.Fatal("LONG row size")
		}
		actual = uint64(binary.LittleEndian.Uint32(row[2:]))
	case 8:
		if len(row) != 10 {
			t.Fatal("LONGLONG row size")
		}
		actual = binary.LittleEndian.Uint64(row[2:])
	default:
		t.Fatal("unexpected native integer type")
	}
	if actual != expected {
		t.Fatal("cached parameter types or RESET changed value")
	}
}

func TestMyPrepareRejectsOversizedFieldCountsBeforeReply(t *testing.T) {
	backend, server := net.Pipe()
	defer backend.Close()
	done := make(chan struct{})
	go func() {
		defer close(done)
		defer server.Close()
		seq := byte(1)
		myWrite(server, &seq, []byte{0, 9, 0, 0, 0, 0, 0, 1, 4, 0, 0, 0})
	}()
	var front bytes.Buffer
	if stmt, e := myRelayPrepare(backend, &front); e == nil || stmt != nil || front.Len() != 0 {
		t.Fatal("oversized prepare exposed a statement handle")
	}
	<-done
}
