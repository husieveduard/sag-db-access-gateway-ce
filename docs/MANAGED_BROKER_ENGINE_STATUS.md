# Managed broker implementation by engine

Passthrough support does not imply managed authentication support. The production
capability gates remain disabled for every engine until its native adapter,
launch lifecycle, TLS policy, SQL behavior and regression tests are ready.

| Engine | Vault and process-bound IPC | Native managed SQL | Automatic lab lifecycle | Production managed mode |
| --- | --- | --- | --- | --- |
| PostgreSQL | Implemented and SQL-tested | Simple-query broker; real SELECT 1 | Implemented behind explicit local/testing opt-in | Disabled |
| MySQL / MariaDB | Implemented with the shared process-bound worker | Verified TLS, native auth and COM_QUERY text results; real SELECT 1 | Same explicit local/testing launcher | Disabled |
| MSSQL / SQL Server | Shared foundation available; no native SQL proof yet | Not implemented | Refused before launch | Disabled |

The shared parts are encrypted resource credentials, session-scoped temporary
logins, the authenticated Unix worker, PID/start-tick binding, one-time bootstrap,
private launcher pipes and readiness/activation ordering. Each native adapter
must still use those checks throughout the SQL session.

MySQL/MariaDB now use their own native adapter and isolated MySQL 8.4/MariaDB
11.4 SQL tests; see [MySQL/MariaDB lab details](MYSQL_MARIADB_VAULT_SQL_E2E.md).
MSSQL remains the next required native adapter: TDS PRELOGIN/TLS, LOGIN7, backend
authentication and SQL batch/result handling, with the same SQL/lifecycle tests.
It must never be routed to another engine or downgraded to client passthrough.

## PostgreSQL automatic laboratory launch

The normal production launch path is unchanged. The explicit CLI option is:

```sh
php artisan sag:db-start-session SESSION --managed-lab
```

It requires Linux, APP_ENV=local/testing and SAG_LAB_MANAGED_BROKER_ENABLED=true.
Configure SAG_LAB_POSTGRES_BROKER_BINARY, SAG_LAB_VAULT_SOCKET,
SAG_LAB_BROKER_CERT, SAG_LAB_BROKER_KEY and optionally SAG_LAB_WORKER_UID. Start the
trusted Vault worker separately with its private directory/socket. The session
must be temporary with an issued temporary login, its resource must explicitly
use auth_mode=managed and target_tls_mode=disable for this isolated lab runner.
Frontend listen and control-plane HTTP are restricted to loopback; no production
transport setting is silently downgraded.

The launcher claims starting under a DB row lock, directly execs the configured
native binary without a shell, records its executable/PID/start ticks, and only
then sends the start message and internal token through an anonymous stdin pipe.
The child receives no Laravel DB credentials, APP_KEY or Vault encryption key in
its environment. After one-time Vault bootstrap, native backend authentication
and listener binding, the child reports readiness only on inherited pipe FD 3.
The launcher revalidates session/resource/process state, saves started and sends
activation before the child accepts clients. The established lifecycle performs
identity-checked termination. Failed bootstrap/backend authentication/readiness
stops the child and leaves the session failed, never started. Safe phase codes in
termination_reason/audit describe startup failures without recording secrets.

The real SQL E2E now includes a separately launched broker process using this
lifecycle, SELECT 1, verified termination, privilege/environment and log checks,
an incorrect Vault password that must fail native backend authentication, and
a revoke between bootstrap and activation that must end terminated without
starting. A same-PID re-exec removes unintended inherited PHP descriptors before
bootstrap while preserving the recorded process identity.
See [Vault IPC SQL E2E](VAULT_IPC_SQL_E2E.md) for the full test command. Build the
lab adapter with scripts/build-postgres-lab-broker.sh /absolute/output/path and set
SAG_E2E_BROKER_BINARY to that executable to include the managed-launch cases.
