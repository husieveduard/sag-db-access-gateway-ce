package main

import "testing"

func TestBackendAuthModeCompatibility(t *testing.T) {
    cases := []struct {
        engine string
        mode BackendAuthMode
        allowed bool
    }{
        {"postgresql", BackendClientPassthrough, true},
        {"mysql", BackendClientPassthrough, true},
        {"mssql", BackendClientPassthrough, true},
        {"postgresql", BackendStoredPassword, true},
        {"mysql", BackendStoredPassword, true},
        {"mariadb", BackendStoredPassword, true},
        {"mssql", BackendMSSQLSQLAuth, true},
        {"mssql", BackendMSSQLKerberosService, true},
        {"sqlserver", BackendMSSQLKerberosDelegation, true},
        {"postgresql", BackendMSSQLKerberosService, false},
        {"mysql", BackendMSSQLSQLAuth, false},
        {"mssql", BackendStoredPassword, false},
        {"mssql", BackendAuthMode("unknown"), false},
    }
    for _, tc := range cases {
        err := validateBackendAuthMode(tc.engine, tc.mode)
        if (err == nil) != tc.allowed {
            t.Errorf("engine=%s mode=%s allowed=%v got err=%v", tc.engine, tc.mode, tc.allowed, err)
        }
    }
}
