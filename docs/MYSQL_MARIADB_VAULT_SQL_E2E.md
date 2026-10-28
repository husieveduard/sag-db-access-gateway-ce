# MySQL / MariaDB managed laboratory broker

The native adapter implements protocol-10 greeting, verified TLS on both sides,
session-scoped frontend authentication, separate Vault-backed backend login and
COM_QUERY text result forwarding. It uses the same private worker, SO_PEERCRED,
PID/start ticks, one-time bootstrap, private launch pipes, readiness and
identity-checked termination as PostgreSQL. Production remains disabled. SQL privileges follow the configured backend role,
whose grants must be restricted to the intended resource/database.

Backend authentication supports mysql_native_password and caching_sha2_password,
including fast auth and full auth only after verified TLS. Unknown plugins and
unverified/plaintext backend transport are refused. The broker never forwards a
backend authentication challenge to the frontend. Frontend logins use
mysql_clear_password solely inside required TLS and are verified by Laravel;
they are temporary credentials, never the actual backend password.

Only COM_QUERY text results and COM_QUIT with utf8mb4_general_ci (collation 45)
are supported; other frontend encodings are refused rather than transcoded. Prepared/binary protocol,
COM_CHANGE_USER, COM_INIT_DB, compression and multi-statement negotiation are
refused. LOCAL INFILE responses and multiple-result status are rejected without
forwarding the prohibited request/terminator. Optional client offers for
LOCAL_FILES/MULTI_RESULTS are ignored and never advertised or negotiated by the
broker. Packets, column counts, response bytes and deadlines are bounded.

The independent go-sql-driver/mysql E2E client uses verified frontend TLS,
AllowCleartextPasswords=true, InterpolateParams=true and MultiStatements=false.
Application clients must use the same TLS identity checks and the clear-password
plugin for their temporary login. Native prepared statements are not enabled.

## Automatic isolated lab launch

Use the existing command with --managed-lab, APP_ENV=local/testing and explicit
SAG_LAB_MANAGED_BROKER_ENABLED=true. Additionally configure
SAG_LAB_MYSQL_BROKER_BINARY and SAG_LAB_MYSQL_BACKEND_CA, plus the shared worker
socket, frontend certificate/key and loopback control plane. MySQL and MariaDB
resources must explicitly use auth_mode=managed and target_tls_mode=verify-full.
There is no downgrade to plaintext or passthrough. MSSQL is still refused.

Build with scripts/build-mysql-lab-broker.sh /absolute/path. Start only disposable
servers with scripts/start-mysql-lab.sh: MySQL 8.4 and MariaDB 11.4, each requiring
TLS, signed by an ephemeral test CA. The default private network has no published
ports. SAG_LAB_PUBLISH_PORTS=1 publishes only CI loopback ports 33306/33307.
The script creates only resources named sag-pr21-mysql, sag-pr21-mariadb and
sag-pr21-mysql-lab. Remove those exact resources after testing.

From db-gateway, with PHP and Laravel dependencies available, set
SAG_E2E_MYSQL_BROKER_BINARY, SAG_E2E_MYSQL_CA, SAG_E2E_MYSQL_TARGET and
SAG_E2E_MARIADB_TARGET, then run:

```sh
go test -v -run '^TestMySQLMariaDBVaultSQL$' -count=1 -timeout=5m ./...
```

The test uses an isolated administrator fixture solely to provision a random
backend password. That password enters Laravel on stdin and stays encrypted in a
temporary SQLite control plane. The native child obtains it only via Vault IPC.
The root fixture credential is not an application/backend credential to reuse.

Each engine verifies its actual native auth plugin, independent-client SELECT 1, NULL/Unicode rows and affected-row writes,
wrong backend/temporary passwords, untrusted backend CA and plaintext frontend
rejection. Lifecycle scenarios cover revoke, expiry, resource deactivation,
process-start tick replacement, worker loss while HTTP still allows, unsupported
prepared commands, bad Vault password during launch and revoke before activation.
The tests check no unauthorized INSERT, backend processlist closure, stopped
failed children and no backend-password disclosure in HTTP/broker logs. The
separate CI job runs against both real server versions; PostgreSQL regressions
continue in their existing job.

Local validation on 2026-10-28 passed all 16 MySQL/MariaDB SQL scenarios and all
9 PostgreSQL SQL regression scenarios. The Go suite passed; Laravel reported
114 passed / 526 assertions, with one environment-gated legacy live test skipped.
These results validate the laboratory adapters, not production readiness.
