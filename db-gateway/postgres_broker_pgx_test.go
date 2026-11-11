package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"errors"
	"net"
	"strconv"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
)

func pgIndependentClient(t *testing.T, address, user, password string, config *tls.Config) *pgx.Conn {
	t.Helper()
	cfg, e := pgx.ParseConfig("postgres://temporary@localhost/sag_e2e?sslmode=verify-full")
	if e != nil {
		t.Fatal("independent configuration failed")
	}
	host, port, _ := net.SplitHostPort(address)
	n, _ := strconv.Atoi(port)
	cfg.Host = host
	cfg.Port = uint16(n)
	cfg.User = user
	cfg.Password = password
	cfg.TLSConfig = config
	cfg.Fallbacks = nil
	cfg.ConnectTimeout = 5 * time.Second
	cfg.StatementCacheCapacity = 32
	cfg.DescriptionCacheCapacity = 32
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	conn, e := pgx.ConnectConfig(ctx, cfg)
	if e != nil {
		t.Fatal("independent verified-TLS PostgreSQL connection failed")
	}
	return conn
}
func pgExerciseExtendedClient(t *testing.T, conn *pgx.Conn) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Second)
	defer cancel()
	query := "SELECT $1::int8,$2::text,$3::bytea,$4::bool,$5::float8,$6::timestamptz,$7::text"
	description, e := conn.Prepare(ctx, "sag_typed", query)
	if e != nil || len(description.ParamOIDs) != 7 {
		t.Fatal("independent Parse/Describe failed")
	}
	stamp := time.Date(2026, 10, 29, 12, 34, 56, 123456000, time.UTC)
	for i := 0; i < 2; i++ {
		var number int64
		var word string
		var blob []byte
		var yes bool
		var real float64
		var returned time.Time
		var null *string
		e = conn.QueryRow(ctx, "sag_typed", int64(-42-i), "π ' OR 1=1 --", []byte{0, 255, 1}, true, 1.25, stamp, nil).Scan(&number, &word, &blob, &yes, &real, &returned, &null)
		if e != nil || number != int64(-42-i) || word != "π ' OR 1=1 --" || !bytes.Equal(blob, []byte{0, 255, 1}) || !yes || real != 1.25 || !returned.Equal(stamp) || null != nil {
			t.Fatal("independent typed Bind/Execute result mismatch")
		}
	}
	if _, e = conn.Prepare(ctx, "bad_statement", "SELECT FROM"); e == nil {
		t.Fatal("invalid Parse unexpectedly succeeded")
	}
	var one int
	if e = conn.QueryRow(ctx, "SELECT $1::int4", int32(1)).Scan(&one); e != nil || one != 1 {
		code := ""
		var sqlError interface{ SQLState() string }
		if errors.As(e, &sqlError) {
			code = sqlError.SQLState()
		}
		t.Fatalf("Sync recovery failed: closed=%t deadline=%t sqlstate=%s", conn.IsClosed(), errors.Is(e, context.DeadlineExceeded), code)
	}
	if conn.Deallocate(ctx, "sag_typed") != nil {
		t.Fatal("Close statement failed")
	}
	if _, e = conn.Prepare(ctx, "sag_typed", query); e != nil {
		t.Fatal("Close did not permit re-Parse")
	}
	if conn.Deallocate(ctx, "sag_typed") != nil {
		t.Fatal("second Close statement failed")
	}
	tx, e := conn.Begin(ctx)
	if e != nil {
		t.Fatal("extended transaction begin failed")
	}
	if tx.QueryRow(ctx, "SELECT $1::int4", int32(2)).Scan(&one) != nil || one != 2 {
		tx.Rollback(ctx)
		t.Fatal("extended query inside transaction failed")
	}
	if tx.Rollback(ctx) != nil {
		t.Fatal("extended transaction rollback failed")
	}
	tag, e := conn.Exec(ctx, "INSERT INTO ipc_guard_probe VALUES ($1)", int32(88))
	if e != nil || tag.RowsAffected() != 1 {
		t.Fatal("independent extended INSERT/affected rows failed")
	}
	t.Log("independent pgx: named/implicit prepare, text/binary parameters, typed rows, Close, Sync recovery and transaction passed")
}
