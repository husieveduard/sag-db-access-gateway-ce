# Managed broker implementation by engine

Passthrough support does not imply managed authentication support. The production
capability gates remain disabled for every engine until its native adapter,
launch lifecycle, TLS policy, SQL behavior and regression tests are ready.

| Engine | Vault and process-bound IPC | Native managed SQL | Automatic lab lifecycle | Production managed mode |
| --- | --- | --- | --- | --- |
| PostgreSQL | Implemented and SQL-tested | Simple query and bounded Sync-delimited extended protocol; real pgx SQL E2E | Implemented behind explicit local/testing opt-in; explicit `verify-full` CA/hostname support added | Disabled |
| MySQL / MariaDB | Implemented with the shared process-bound worker | Verified TLS, native auth, COM_QUERY and bounded native prepared/binary results; real SELECT 1 | Same explicit local/testing launcher | Disabled |
| MSSQL / SQL Server | Shared process-bound worker | Native TDS 7.4, verified TLS, SQL authentication, SQLBatch and bounded `sp_executesql` RPC; real SELECT 1 and lifecycle E2E | Same explicit local/testing launcher | Disabled |

The shared parts are encrypted resource credentials, session-scoped temporary
logins, the authenticated Unix worker, PID/start-tick binding, one-time bootstrap,
private launcher pipes and readiness/activation ordering. Each native adapter
must still use those checks throughout the SQL session.

MySQL/MariaDB now use their own native adapter and isolated MySQL 8.4/MariaDB
11.4 SQL tests; see [MySQL/MariaDB lab details](MYSQL_MARIADB_VAULT_SQL_E2E.md).
MSSQL now has a separate native TDS laboratory adapter and isolated SQL Server
2022 CI suite; see [MSSQL lab details](MSSQL_VAULT_SQL_E2E.md). Its limited protocol
surface does not enable Integrated Authentication or production managed mode.
An explicit Windows backend selection is stored and shown but remains blocked
until its Kerberos implementation and AD E2E verification are complete.

The CE runtime image now packages the three native broker executables and the
Unix IPC worker as separate binaries. CI checks their presence and executable
permissions in the non-root application image. They are not started by the
existing Production Compose; runtime wiring and release acceptance remain open.

## Explicit managed runtime path

`SAG_MANAGED_RUNTIME_ENABLED` defaults to false. When explicitly enabled, the
normal lifecycle service routes temporary managed sessions to the same native
launcher used in the laboratory. Runtime configuration is separate from all
`SAG_LAB_*` settings. It requires a private live Unix worker socket, exact
broker binaries, a frontend certificate/key, verified backend CA per engine,
an internal control URL whose host matches `SAG_MANAGED_CONTROL_HOST`, and a
public endpoint. PostgreSQL, MySQL and MSSQL all require `verify-full` in this
mode. The launcher records a distinct `managed_runtime` process marker so
termination checks the correct executable even if the feature flag changes.

The broker and trusted Unix worker must share a PID namespace so SO_PEERCRED
PIDs match the process identity recorded by Laravel. The worker's trusted UID
must match `SAG_MANAGED_WORKER_UID` or the launcher UID. Keep the socket in a
private owner-only directory. The worker needs Laravel's Vault key; the broker
receives neither that key nor the backend password in its environment or args.
Current Production Compose does not start this worker or set the runtime flag.
The isolated PostgreSQL TLS E2E exercises the runtime launcher, credential
bootstrap, SQL and revocation with loopback listener in APP_ENV=testing.

The optional `deploy/compose.managed-candidate.yaml` staging overlay mounts
frontend TLS files and backend CA bundles, creates an owner-only IPC tmpfs,
and enables the trusted worker within the existing lifecycle worker container.
The worker entrypoint starts and monitors the IPC process only with the explicit
flag. Set `SAG_CE_MANAGED_CERT_DIR` to an absolute private directory containing
`frontend.crt`, `frontend.key`, `postgresql-ca.crt`, `mysql-ca.crt`, and
`mssql-ca.crt`; the frontend key must be readable by the container's UID 33.
The certificate must match `SAG_CE_GATEWAY_PUBLIC_HOST`. The backend CA files
must validate each target's hostname. The overlay is a staging candidate,
not the default installation path; production activation awaits full release
acceptance including Windows Integrated Authentication.

## PostgreSQL automatic laboratory launch

The normal production launch path is unchanged. The explicit CLI option is:

```sh
php artisan sag:db-start-session SESSION --managed-lab
```

With the same local/testing opt-in enabled, the normal lifecycle start also
routes temporary managed sessions to this native launcher. This lets queued
application operations use the verified bootstrap path. The isolated SQL E2E
fixture exercises the normal lifecycle start for all three adapters. Production
still refuses managed mode until a separately packaged, tested runtime is ready.

It requires Linux, APP_ENV=local/testing and SAG_LAB_MANAGED_BROKER_ENABLED=true.
Configure SAG_LAB_POSTGRES_BROKER_BINARY, SAG_LAB_VAULT_SOCKET,
SAG_LAB_BROKER_CERT, SAG_LAB_BROKER_KEY and optionally SAG_LAB_WORKER_UID. Start the
trusted Vault worker separately with its private directory/socket. The session
must be temporary with an issued temporary login, its resource must explicitly
use auth_mode=managed and choose target_tls_mode=disable or verify-full. The
verified mode additionally requires SAG_LAB_POSTGRES_BACKEND_CA; the broker
checks that CA and the target hostname before backend authentication.
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

Shared idle process cleanup, concurrent IPC and PID namespace compatibility are
covered in [IPC/lifetime hardening](IPC_LIFECYCLE_HARDENING.md). Production gates
remain disabled.

PostgreSQL prepared/parameterized wire support and its limits are described in
[extended protocol SQL E2E](POSTGRES_EXTENDED_SQL_E2E.md).
