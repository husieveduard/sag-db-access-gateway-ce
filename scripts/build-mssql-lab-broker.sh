#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/../db-gateway"
exec go build -o "${1:?output binary path required}" \
 mssql_broker_lab_main.go mssql_broker_wire.go mssql_broker_login.go mssql_broker_session.go \
 managed_broker_launch_linux.go broker_backend_credential.go broker_engine_capabilities.go \
 postgres_broker_session_guard.go temporary_credential_client.go \
 postgres_session_ipc_probe.go postgres_ipc_credentials_linux.go
