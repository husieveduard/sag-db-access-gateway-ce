#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
if docker container inspect sag-pr21-pg-tls >/dev/null 2>&1 || docker network inspect sag-pr21-pg-tls-net >/dev/null 2>&1; then
    echo 'Disposable PostgreSQL TLS resources already exist; preserve or remove them explicitly.' >&2
    exit 1
fi

dir="$root/.work/postgres-lab-tls"
mkdir -p "$dir"
chmod 0700 "$dir"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$dir/ca.key" -out "$dir/ca.crt" -days 2 -subj /CN=SAG-Isolated-PostgreSQL-CA >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$dir/server.key" -out "$dir/server.csr" -subj /CN=localhost >/dev/null 2>&1
cat > "$dir/extensions" <<'EXT'
subjectAltName=DNS:localhost,DNS:sag-pr21-pg-tls,IP:127.0.0.1
extendedKeyUsage=serverAuth
EXT
openssl x509 -req -in "$dir/server.csr" -CA "$dir/ca.crt" -CAkey "$dir/ca.key" -CAcreateserial -out "$dir/server.crt" -days 2 -extfile "$dir/extensions" >/dev/null 2>&1

# Copy the ephemeral key into a private container directory owned by postgres.
# The sandbox may forbid chown on the host checkout, so ownership changes occur
# only inside this disposable container.
chmod 0755 "$dir"
chmod 0644 "$dir/server.key" "$dir/server.crt" "$dir/ca.crt"

docker network create sag-pr21-pg-tls-net >/dev/null
docker run -d --name sag-pr21-pg-tls --network sag-pr21-pg-tls-net \
    -p 127.0.0.1:35432:5432 \
    -e POSTGRES_DB=sag_e2e -e POSTGRES_PASSWORD=isolated_e2e_admin_only \
    -v "$dir:/run/sag-pg-fixture:ro" \
    --user root --entrypoint sh postgres:16-alpine -ec '
      install -d -m 0700 -o postgres -g postgres /run/sag-pg-owned
      cp /run/sag-pg-fixture/server.crt /run/sag-pg-owned/server.crt
      cp /run/sag-pg-fixture/server.key /run/sag-pg-owned/server.key
      chown postgres:postgres /run/sag-pg-owned/server.crt /run/sag-pg-owned/server.key
      chmod 0600 /run/sag-pg-owned/server.key
      exec docker-entrypoint.sh postgres -c ssl=on \
        -c ssl_cert_file=/run/sag-pg-owned/server.crt \
        -c ssl_key_file=/run/sag-pg-owned/server.key
    ' >/dev/null

ready=0
for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    if docker exec sag-pr21-pg-tls pg_isready -U postgres -d sag_e2e >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 2
done
if [ "$ready" != 1 ]; then
    echo 'Isolated PostgreSQL did not become ready.' >&2
    exit 1
fi
