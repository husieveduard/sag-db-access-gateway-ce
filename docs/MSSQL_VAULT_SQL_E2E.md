# SQL Server managed laboratory broker

The native TDS 7.4 adapter uses the existing encrypted Laravel Vault, one-time
process-bound Unix IPC worker, anonymous launcher control pipes and managed
readiness/activation lifecycle. Backend credentials are never accepted from the
client and never sent through HTTP, argv, environment or a password file.

PRELOGIN requires full-session encryption. TLS 1.2 protects frontend and backend;
the backend CA and hostname must verify before LOGIN7 is sent. TDS password
obfuscation is not encryption and is used only inside verified TLS. The frontend
LOGIN7 contains the session's temporary login. Backend LOGIN7 is constructed
independently from Vault credentials. Backend login replies are consumed locally;
the frontend receives a synthetic acknowledgment with no backend identity data.

Supported: SQL authentication, bounded SQLBatch and `sp_executesql` RPC,
typed SQL results, input parameters and RESETCONNECTION. Reset is preserved at the real
backend after authorization; it never substitutes a different login or database.
The adapter limits client messages to 4 MiB, complete responses to 64 MiB and
query I/O to 30 seconds. Every batch or RPC requires both process-bound local IPC and
HTTP session authorization; a periodic guard closes both ends on invalidation.

The MSSQL resource form stores an explicit SQL Server or Windows Integrated
backend authentication mode. Windows mode blocks SQL password retrieval and
broker launch until a Kerberos adapter and isolated AD tests pass. Existing
resources without a selection retain SQL authentication.

Not supported: Windows Integrated Authentication/SSPI, federated authentication,
MARS, RPC procedures other than `sp_executesql` (including server handles and
cursors), bulk loading, attention/cancellation, TDS 8.0,
nonzero transaction descriptors and change-password/attach-database login modes.
Unsupported requests close the connection. Batch SQL permissions follow the
backend role: this adapter is not a SQL firewall or a parser that restricts SQL
statements. Configure least-privilege backend grants. Explicit transactions and
connection state changes requiring transaction descriptors are outside this lab
protocol surface. Production managed gates remain disabled.

## Isolated testing

Build with scripts/build-mssql-lab-broker.sh /absolute/output/path. The explicit
local/testing launcher additionally requires SAG_LAB_MSSQL_BROKER_BINARY and
SAG_LAB_MSSQL_BACKEND_CA, the shared worker/cert/key configuration, and a managed
resource with target_tls_mode=verify-full. Both mssql and sqlserver select this
adapter; another engine's executable is never selected as fallback.

scripts/start-mssql-lab.sh starts only a disposable SQL Server 2022 Developer
container with an ephemeral CA, forced TLS and a test-only SA fixture. It creates
sag-pr21-mssql and sag-pr21-mssql-lab. No host ports are published by default.
SAG_LAB_PUBLISH_PORTS=1 publishes only 127.0.0.1:31433 for the CI runner. Remove
those exact resources after testing. No production endpoints or volumes are used.

From db-gateway, set SAG_E2E_MSSQL_TARGET, SAG_E2E_MSSQL_CA and
SAG_E2E_MSSQL_BROKER_BINARY, with PHP 8.3 and Laravel dependencies available:

```sh
go test -v -run '^TestMSSQLVaultSQL$' -count=1 -timeout=6m ./...
```

The real-server suite creates a random backend SQL login, grants only SELECT and
INSERT on a disposable table, and provisions the Vault via stdin. Microsoft's
independent go-mssqldb test client checks SELECT 1, parameterized SQL with
NULL/Unicode/integer/decimal/date/binary values, error recovery,
affected rows and actual writes. Nine lifecycle scenarios cover revoke, expiry,
resource deactivation, PID/start-tick replacement, worker loss while HTTP allows,
unsupported RPC, incorrect Vault password and startup revocation. Tests also
reject bad temporary/backend passwords, untrusted backend TLS, plaintext and
cross-process bootstrap replay; inspect argv/environment/inherited descriptors,
check logs/HTTP for secrets, verify blocked writes and real backend session
closure through sys.dm_exec_sessions. The driver is imported only in tests;
production/laboratory native protocol code uses the standard library.

The CI job runs against a real isolated SQL Server container. Local registry
access may require the official Microsoft image CDN westus.data.mcr.microsoft.com.
The former composer:2/PHP 8.5 IPC failure was traced to PID 1 worker rejection;
see [IPC/lifetime hardening](IPC_LIFECYCLE_HARDENING.md) for the fix and regression matrix.

## Validation result

On 2026-10-29, all nine scenarios passed against real SQL Server 2022 in GitHub
CI (native adapter commit 1a258fd). This included the independent Microsoft
client, typed results/writes, negative auth/TLS checks and backend session closure.
The Go suite passed; Laravel reported 114 passed / 527 assertions locally, with
one environment-gated legacy live test skipped. CI separately exercises the real
Linux lifecycle and the PostgreSQL and MySQL/MariaDB SQL regression suites.

Interop regressions cover zero-numbered server packet envelopes, empty deprecated
PRELOGIN fields and one complete TDS packet per encrypted Write. The readiness
probe uses its own live timeout after the one-time IPC bootstrap context closes.
Local SQL Server image download remained blocked by the active environment's CDN
policy; the SQL validation above used the isolated GitHub CI server.
