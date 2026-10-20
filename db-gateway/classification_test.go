package main

import "testing"

func TestClassifySQL(t *testing.T) {
	cases := []struct {
		name          string
		sql           string
		statementType string
		riskLevel     string
	}{
		{"select", "SELECT 1", "SELECT", "low"},
		{"show", "SHOW TABLES", "SHOW", "low"},
		{"update with where", "UPDATE users SET enabled = 1 WHERE id = 42", "UPDATE", "medium"},
		{"update where in string only", "UPDATE users SET note = 'where'", "UPDATE", "high"},
		{"delete without where", "DELETE FROM users", "DELETE", "high"},
		{"temporary table", "CREATE TEMPORARY TABLE tmp_a (id int)", "CREATE_TEMP_TABLE", "medium"},
		{"drop table", "DROP TABLE users", "DROP", "high"},
		{"leading comment", "/* audit */ DELETE FROM users WHERE id = 1", "DELETE", "medium"},
		{"unknown", "PRAGMA table_info(users)", "UNKNOWN", "medium"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := classifySQL(tc.sql)

			if got.StatementType != tc.statementType {
				t.Fatalf("statement type: got %q, want %q", got.StatementType, tc.statementType)
			}

			if got.RiskLevel != tc.riskLevel {
				t.Fatalf("risk level: got %q, want %q", got.RiskLevel, tc.riskLevel)
			}
		})
	}
}

func TestSQLEventRedactionNeverExposesLiterals(t *testing.T) {
	cases := []string{
		"UPDATE users SET password='hunter2' WHERE email='alice@example.com'",
		"INSERT INTO people VALUES ('+380501234567', 1234)",
		"EXEC sp_executesql N'SELECT * FROM payment WHERE card=1234'",
		"SELECT $$confidential$$, E'\\\\secret', 'unicode-🔐'",
		"/*secret*/ SELECT 'token=abc'",
	}
	for _, input := range cases {
		for _, got := range []string{maskSQL(input), summarizeSQL(input)} {
			if got != "[REDACTED]" {
				t.Fatalf("unexpected SQL redaction output for test case: %q", got)
			}
		}
	}
	if got := maskSQL("  "); got != "" {
		t.Fatalf("empty input should stay empty: %q", got)
	}
}
