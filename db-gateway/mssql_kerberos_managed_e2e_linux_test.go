package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"io"
	"os"
	"testing"
)

type tdsKerberosAcceptance struct {
	AdminPassword string
	Profile       tdsKerberosProfile
	keytab        []byte
}

func (a *tdsKerberosAcceptance) disclosed(body []byte) bool {
	return bytes.Contains(body, a.keytab) || bytes.Contains(body, []byte(base64.StdEncoding.EncodeToString(a.keytab))) ||
		bytes.Contains(body, []byte(a.AdminPassword))
}

// Runs only inside the disposable AD/SQL laboratory. The administrator password
// enters stdin, and is never inherited by the broker or encoded in a command.
func TestMSSQLKerberosManagedSQL(t *testing.T) {
	if os.Getenv("SAG_E2E_MSSQL_KERBEROS") != "1" {
		t.Skip("run scripts/test-mssql-kerberos.py with managed acceptance artifacts")
	}
	body, err := io.ReadAll(io.LimitReader(os.Stdin, 4097))
	var input struct {
		Password string `json:"sa_password"`
	}
	if err != nil || len(body) > 4096 || json.Unmarshal(body, &input) != nil || len(input.Password) < 12 {
		t.Fatal("private SQL lab administrator input unavailable")
	}
	profile := tdsKerberosProfile{Username: "gateway", Realm: "SAG.TEST", SPN: "MSSQLSvc/sql.sag.test:1433",
		ConfigFile: "/run/krb5.conf", KeytabFile: "/run/gateway.keytab"}
	keytab, err := os.ReadFile(profile.KeytabFile)
	if err != nil || len(keytab) == 0 {
		t.Fatal("private AD test keytab unavailable")
	}
	acceptance := &tdsKerberosAcceptance{AdminPassword: input.Password, Profile: profile, keytab: keytab}
	for _, scenario := range []string{"revoke", "expire", "deactivate", "replace_pid", "replace_ticks", "worker_lost",
		"idle_revoke", "idle_worker_lost", "pending_revoke", "unsupported_command", "startup_revoke"} {
		t.Run(scenario, func(t *testing.T) {
			tdsVaultScenario(t, "mssql", "sql.sag.test:1433", scenario, acceptance)
		})
	}
}
