#!/usr/bin/env sh
set -eu
# Only creates disposable, specifically named test resources. No production input.
repo_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
if docker container inspect sag-pr21-mysql >/dev/null 2>&1 || docker container inspect sag-pr21-mariadb >/dev/null 2>&1 || docker network inspect sag-pr21-mysql-lab >/dev/null 2>&1; then
 echo 'Disposable MySQL lab resources already exist; preserve or remove them explicitly before starting a new lab.' >&2
 exit 1
fi
cert_dir="$repo_dir/.work/mysql-lab-tls"
mkdir -p "$cert_dir"
chmod 700 "$cert_dir"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$cert_dir/ca.key" -out "$cert_dir/ca.crt" -days 2 -subj /CN=SAG-Isolated-Test-CA >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$cert_dir/server.key" -out "$cert_dir/server.csr" -subj /CN=mysql >/dev/null 2>&1
cat > "$cert_dir/extensions" <<'EOF'
subjectAltName=DNS:mysql,DNS:mariadb,DNS:localhost,IP:127.0.0.1
extendedKeyUsage=serverAuth
EOF
openssl x509 -req -in "$cert_dir/server.csr" -CA "$cert_dir/ca.crt" -CAkey "$cert_dir/ca.key" -CAcreateserial -out "$cert_dir/server.crt" -days 2 -extfile "$cert_dir/extensions" >/dev/null 2>&1
# Files are individually mounted into the DB images; host parent stays private.
chmod 644 "$cert_dir/server.key" "$cert_dir/server.crt" "$cert_dir/ca.crt"
docker network create --internal sag-pr21-mysql-lab >/dev/null
mysql_publish=''
maria_publish=''
if [ "${SAG_LAB_PUBLISH_PORTS:-0}" = 1 ]; then
 mysql_publish='-p 127.0.0.1:33306:3306'
 maria_publish='-p 127.0.0.1:33307:3306'
fi
# Deliberate word splitting only for the fixed port options above.
# shellcheck disable=SC2086
docker run -d --name sag-pr21-mysql --network sag-pr21-mysql-lab --network-alias mysql $mysql_publish \
 -e MYSQL_ROOT_PASSWORD=isolated_mysql_admin_only -e MYSQL_ROOT_HOST=% -e MYSQL_DATABASE=sag_mysql_e2e \
 -v "$cert_dir/ca.crt:/etc/mysql/test-ca.crt:ro" -v "$cert_dir/server.crt:/etc/mysql/test-server.crt:ro" -v "$cert_dir/server.key:/etc/mysql/test-server.key:ro" \
 mysql:8.4 --ssl-ca=/etc/mysql/test-ca.crt --ssl-cert=/etc/mysql/test-server.crt --ssl-key=/etc/mysql/test-server.key --require-secure-transport=ON >/dev/null
# shellcheck disable=SC2086
docker run -d --name sag-pr21-mariadb --network sag-pr21-mysql-lab --network-alias mariadb $maria_publish \
 -e MARIADB_ROOT_PASSWORD=isolated_mysql_admin_only -e MARIADB_ROOT_HOST=% -e MARIADB_DATABASE=sag_mysql_e2e \
 -v "$cert_dir/ca.crt:/etc/mysql/test-ca.crt:ro" -v "$cert_dir/server.crt:/etc/mysql/test-server.crt:ro" -v "$cert_dir/server.key:/etc/mysql/test-server.key:ro" \
 mariadb:11.4 --ssl-ca=/etc/mysql/test-ca.crt --ssl-cert=/etc/mysql/test-server.crt --ssl-key=/etc/mysql/test-server.key --require-secure-transport=ON >/dev/null
