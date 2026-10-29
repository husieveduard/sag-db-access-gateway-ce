# Laravel Vault / Unix IPC / PostgreSQL laboratory integration

The opt-in laboratory broker now obtains backend credentials from Laravel Vault
through a process-bound, one-time Unix IPC bootstrap. It no longer accepts
`--backend-password-file` or a backend password in argv/environment.
The existing production listener and managed-authentication rollout gate remain
unchanged. This is the existing simple-query PostgreSQL broker, not an extended
protocol implementation.

The worker authenticates the client UID with Linux `SO_PEERCRED`, obtains PID from
the kernel and start ticks from `/proc`, and passes that identity to the existing
atomic `AuthorizedGatewayVault::takeBootstrapCredentials` transaction. Failed
resource/process/session checks never consume the grant. A replay is denied.
The worker socket must live in a private directory (0700); the socket is 0600.
The broker independently checks the worker UID using `SO_PEERCRED`.

Laravel emits a credential frame only to inherited anonymous pipe FD 3. The
hidden command refuses a missing descriptor, terminal or regular file before
accessing Vault. Go discards stdout/stderr, bounds the private frame and the
command lifetime, and returns only generic failures. This trusts the local worker
and its OS account; untrusted users must not share that account or its Laravel DB
credentials/encryption key. No HTTP route returns backend credentials.

The automatic laboratory launcher records the actual broker PID/start ticks in
starting, releases the private start barrier, waits for native backend
authentication/readiness, saves started and releases activation. It refuses
production environments. See [engine status and launch configuration](MANAGED_BROKER_ENGINE_STATUS.md). Credentials stay in broker memory for that session. The broker requires
a live local authorization callback, checked before client startup, after backend
authentication, before each query and by the periodic guard. The post-backend,
per-query and periodic checks also retain independent HTTP authorization. The worker revalidates the original PID/start ticks, consumed bootstrap,
resource activity, session status and expiry. Worker loss, process replacement,
revocation or expiry closes both connections; HTTP allow alone is insufficient.
The periodic guard runs every two seconds with a three-second check deadline.
These lab checks invoke the local Artisan verifier; production rollout remains
gated, including performance validation. The lab runner emits only fixed failure
codes.

## Repeatable test

Use a disposable PostgreSQL 16 database named `sag_e2e`, with an administrative
`postgres` login and SCRAM host authentication. Never point the test at an
existing database: it creates and drops the dedicated `ipc_e2e_backend` role.
Install control-plane Composer dependencies, copy `.env.example` to ignored `.env`,
and make PHP with SQLite available on PATH. Build the native lab runner and run
all SQL and managed-launch cases from `db-gateway`:

```sh
../scripts/build-postgres-lab-broker.sh /tmp/sag-lab-broker
SAG_E2E_BROKER_BINARY=/tmp/sag-lab-broker \
SAG_E2E_POSTGRES=127.0.0.1:5432 \
SAG_E2E_ADMIN_PASSWORD=isolated_e2e_admin_only \
go test -v -run '^TestLaravelVaultIPCPostgresSQL$' -count=1 ./...
```

The password above is only a disposable admin fixture. The backend role password
is generated at runtime, provisioned over the isolated DB connection, passed to
Laravel on stdin, and stored encrypted in a temporary SQLite control-plane DB.
Only session-scoped temporary frontend credentials are returned by the fixture.
The test starts real Laravel HTTP verification, the actual Unix worker callback,
and a TLS broker; it verifies `SELECT 1 = 1`, wrong resource, a foreign process,
bootstrap replay and live connection closure after revocation, expiry, resource deactivation,
PID/start-tick replacement and worker loss. Worker-loss testing leaves the HTTP
gate allowed and does not cancel the broker context. Idle scenarios exercise the
periodic guard; the other scenarios attempt an INSERT and independently assert
that PostgreSQL received no rows. pg_stat_activity verifies backend closure too.
HTTP process output
is captured and checked for backend-password disclosure. PostgreSQL ports need
not be published when running containers on a private test network. Use `--init`
for the test container because the IPC gate rejects PID 1. CI runs this test in
`Laravel Vault Unix IPC SQL E2E` with its own PostgreSQL service.

## Local validation, 2026-10-28

- Go 1.22 suite passed; worker, broker and gateway builds passed.
- Laravel feature suite: 113 passed, 528 assertions. The separately gated real
  Linux gateway lifecycle test also passed (1 test, 8 assertions).
- PostgreSQL 16 on an internal Docker network, without published ports:
  `SELECT 1 = 1`; incorrect backend password, wrong resource, unrelated process
  with the same UID and bootstrap replay rejected; active connection closed
  after all six invalidation scenarios, with no unauthorized INSERT reaching PostgreSQL. The test uses verified frontend TLS and isolated
  SCRAM backend authentication with explicitly disabled backend TLS in the lab.
- Repository preflight and whitespace checks passed.

Managed-launch coverage additionally starts the actual broker as a separate
process through Laravel, checks process identity and a scrubbed environment,
executes SELECT 1 and performs identity-checked termination. An incorrect Vault
password leaves failed with no child/listener/backend connection; a revoke after
bootstrap but before activation leaves terminated, never started. Inherited
control-plane descriptors are removed by same-PID re-exec before bootstrap, so
PHP command completion is independent of the long-lived child. Both broker and
HTTP logs are checked for backend-secret disclosure. All nine scenarios run in
the dedicated CI job; absent SAG_E2E_BROKER_BINARY skips the three launch cases.

The same native broker now also supports bounded Sync-delimited PostgreSQL
extended queries; see [extended protocol SQL E2E](POSTGRES_EXTENDED_SQL_E2E.md).
The current SQL matrix has 17 scenarios; existing validation records below/above
refer to their original commits. Production gates remain disabled.
