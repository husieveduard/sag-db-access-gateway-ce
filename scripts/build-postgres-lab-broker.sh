#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/../db-gateway"
exec go build -o "${1:?output binary path required}" \
  lab_broker_main.go lab_broker_wire.go managed_broker_launch_linux.go \
  postgres_broker_session.go postgres_broker_session_guard.go \
  postgres_broker_frontend.go postgres_broker_tls.go postgres_broker_auth.go \
  postgres_broker_backend.go postgres_broker_backend_auth.go \
  postgres_broker_backend_connect.go postgres_broker_backend_scram.go \
  postgres_scram.go temporary_credential_client.go \
  postgres_session_ipc_probe.go postgres_ipc_credentials_linux.go
