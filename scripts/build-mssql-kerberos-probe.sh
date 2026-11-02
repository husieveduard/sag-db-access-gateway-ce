#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/../db-gateway"
exec go build -o "${1:?output binary path required}" \
 mssql_kerberos_probe_main.go mssql_broker_integrated.go mssql_kerberos_linux.go \
 mssql_broker_wire.go mssql_broker_login.go
