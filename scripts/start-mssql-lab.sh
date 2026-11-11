#!/usr/bin/env sh
set -eu
repo_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
if docker container inspect sag-pr21-mssql >/dev/null 2>&1 || docker network inspect sag-pr21-mssql-lab >/dev/null 2>&1; then
 echo 'Disposable MSSQL resources already exist; preserve or remove them explicitly.' >&2
 exit 1
fi
cert_dir="$repo_dir/.work/mssql-lab-tls"
mkdir -p "$cert_dir"
chmod 700 "$cert_dir"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$cert_dir/ca.key" -out "$cert_dir/ca.crt" -days 2 -subj /CN=SAG-Isolated-MSSQL-CA >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$cert_dir/server.key" -out "$cert_dir/server.csr" -subj /CN=mssql >/dev/null 2>&1
cat > "$cert_dir/extensions" <<'EXT'
subjectAltName=DNS:mssql,DNS:localhost,IP:127.0.0.1
extendedKeyUsage=serverAuth
EXT
openssl x509 -req -in "$cert_dir/server.csr" -CA "$cert_dir/ca.crt" -CAkey "$cert_dir/ca.key" -CAcreateserial -out "$cert_dir/server.crt" -days 2 -extfile "$cert_dir/extensions" >/dev/null 2>&1
# Files are individually mounted for the unprivileged SQL Server UID. Host parent is private.
chmod 644 "$cert_dir/server.key" "$cert_dir/server.crt" "$cert_dir/ca.crt"
cat > "$cert_dir/mssql.conf" <<'CONF'
[network]
forceencryption = 1
tlscert = /var/opt/mssql/test-server.crt
tlskey = /var/opt/mssql/test-server.key
tlsprotocols = 1.2
CONF
ports=''
if [ "${SAG_LAB_PUBLISH_PORTS:-0}" = 1 ]; then
 ports='-p 127.0.0.1:31433:1433'
 docker network create sag-pr21-mssql-lab >/dev/null
else
 docker network create --internal sag-pr21-mssql-lab >/dev/null
fi
# Disposable Developer edition only; the SA fixture is not a Vault backend role.
# shellcheck disable=SC2086
docker run -d --name sag-pr21-mssql --network sag-pr21-mssql-lab --network-alias mssql $ports \
 -e ACCEPT_EULA=Y -e MSSQL_PID=Developer -e MSSQL_SA_PASSWORD=Isolated_Mssql_Admin_Only_42! \
 -e MSSQL_MEMORY_LIMIT_MB=2048 \
 -v "$cert_dir/server.crt:/var/opt/mssql/test-server.crt:ro" \
 -v "$cert_dir/server.key:/var/opt/mssql/test-server.key:ro" \
 -v "$cert_dir/mssql.conf:/var/opt/mssql/mssql.conf:ro" \
 mcr.microsoft.com/mssql/server:2022-latest >/dev/null
