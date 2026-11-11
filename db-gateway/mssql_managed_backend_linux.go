package main

import (
	"context"
	"crypto/tls"
	"net"
)

// Bootstrap selects exactly one typed IPC capability. A Kerberos failure cannot
// read the SQL Vault slot. Each connection gets its own Kerberos client/tickets.
func tdsBootstrapBackend(ctx context.Context, socket, session string, resource int64, workerUID uint32, mode, target, database string, tlsConfig *tls.Config) (func(context.Context) (net.Conn, error), error) {
	switch mode {
	case "mssql_sql_auth":
		credential, err := pgIPCTakeCredentials(ctx, socket, session, resource, workerUID)
		if err != nil {
			return nil, errTDSProtocol
		}
		return func(ctx context.Context) (net.Conn, error) {
			return tdsBackendConnect(ctx, target, tlsConfig, credential.Username, credential.Password, database)
		}, nil
	case "mssql_kerberos_service":
		material, err := pgIPCTakeKerberos(ctx, socket, session, resource, workerUID)
		if err != nil {
			return nil, errTDSProtocol
		}
		// Check target/SPN and material before reporting readiness. No tickets or
		// backend credentials are sent until the target TLS certificate verifies.
		auth, err := tdsNewKerberosAuthMaterial(target, material)
		if err != nil {
			return nil, errTDSProtocol
		}
		auth.Free()
		return func(ctx context.Context) (net.Conn, error) {
			auth, err := tdsNewKerberosAuthMaterial(target, material)
			if err != nil {
				return nil, errTDSProtocol
			}
			return tdsBackendConnectIntegrated(ctx, target, tlsConfig, database, auth)
		}, nil
	default:
		return nil, errTDSProtocol
	}
}
