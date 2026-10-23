package main

import (
    "errors"
    "strings"
)

// BackendAuthMode describes how SAG authenticates to the target database.
// Client-to-gateway temporary credentials are a separate authentication step.
type BackendAuthMode string

const (
    BackendClientPassthrough BackendAuthMode = "client_passthrough"
    BackendStoredPassword BackendAuthMode = "stored_password"
    BackendMSSQLSQLAuth BackendAuthMode = "mssql_sql_auth"
    BackendMSSQLKerberosService BackendAuthMode = "mssql_kerberos_service"
    BackendMSSQLKerberosDelegation BackendAuthMode = "mssql_kerberos_delegation"
)

var errUnsupportedBackendAuth = errors.New("unsupported backend authentication mode")

// validateBackendAuthMode does not activate a mode. Integration must independently
// implement authentication and explicitly reject any unimplemented strategy.
func validateBackendAuthMode(engine string, mode BackendAuthMode) error {
    engine = strings.ToLower(strings.TrimSpace(engine))
    switch mode {
    case BackendClientPassthrough:
        return nil
    case BackendStoredPassword:
        if engine == "postgresql" || engine == "postgres" || engine == "mysql" || engine == "mariadb" {
            return nil
        }
    case BackendMSSQLSQLAuth, BackendMSSQLKerberosService, BackendMSSQLKerberosDelegation:
        if engine == "mssql" || engine == "sqlserver" {
            return nil
        }
    }
    return errUnsupportedBackendAuth
}
