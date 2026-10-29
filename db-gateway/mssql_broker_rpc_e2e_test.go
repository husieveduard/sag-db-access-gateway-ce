package main

import (
	"bytes"
	"context"
	"database/sql"
	"testing"
	"time"
)

// Uses the independent Microsoft client and a real SQL Server through the
// Laravel-launched broker. This helper runs in every non-idle MSSQL SQL E2E.
func tdsAssertRealRPC(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	stmt, err := db.PrepareContext(ctx, "SELECT @p1, @p2, @p3, @p4, @p5, CAST(@p6 AS decimal(10,2))")
	if err != nil {
		t.Fatal("MSSQL parameterized prepare failed")
	}
	defer stmt.Close()
	wantTime := time.Date(2026, 10, 29, 12, 34, 56, 0, time.UTC)
	for _, size := range []int{4, 10000} {
		wantBlob := bytes.Repeat([]byte{0, 1, 42, 255}, size/4)
		var number int64
		var word, decimal string
		var null sql.NullString
		var blob []byte
		var timestamp time.Time
		err := stmt.QueryRowContext(ctx, int64(9223372036854775000), "π東京", sql.NullString{}, wantBlob, wantTime, 12.5).Scan(&number, &word, &null, &blob, &timestamp, &decimal)
		if err != nil || number != 9223372036854775000 || word != "π東京" || null.Valid || !bytes.Equal(blob, wantBlob) || !timestamp.Equal(wantTime) || decimal != "12.50" {
			t.Fatalf("real MSSQL parameterized values mismatch (blob size %d): %v", size, err)
		}
	}
	if _, err := db.ExecContext(ctx, "SELECT CAST(@p1 AS int)", "invalid-number"); err == nil {
		t.Fatal("expected real SQL conversion error")
	}
	var recovered int
	if db.QueryRowContext(ctx, "SELECT @p1", 42).Scan(&recovered) != nil || recovered != 42 {
		t.Fatal("RPC error recovery failed")
	}
	result, err := db.ExecContext(ctx, "INSERT INTO guard_probe VALUES (@p1)", int64(11))
	if err != nil {
		t.Fatal("real parameterized write failed")
	}
	n, err := result.RowsAffected()
	if err != nil || n != 1 {
		t.Fatal("RPC affected row count mismatch")
	}
	var count int
	if db.QueryRowContext(ctx, "SELECT count(*) FROM guard_probe WHERE value=@p1", int64(11)).Scan(&count) != nil || count != 1 {
		t.Fatal("RPC write did not reach real database")
	}
}
