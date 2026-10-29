# PostgreSQL extended protocol laboratory adapter

The existing PostgreSQL laboratory broker now accepts bounded Sync-delimited
extended-query cycles, in addition to simple Query. It still authenticates the
frontend temporary login separately from its Vault backend role, through the
same process-bound one-time Unix IPC grant and supervised local/testing launcher.
No backend password or real BackendKeyData/cancel key reaches the frontend.
Production managed gates and Compose are unchanged.

## Protocol surface

Supported frontend messages: Parse, Bind, Describe (statement/portal), Execute
(maxRows=0), Close (statement/portal), Sync, simple Query and Terminate. Bind
supports text/binary format codes, NULL and bounded opaque typed values; the real
PostgreSQL server enforces OIDs/value semantics, while broker framing/count/length
validation prevents protocol confusion. Backend RowDescription and DataRow
framing is validated; typed data and metadata are preserved for the SQL driver.

The broker buffers and validates each complete cycle before forwarding, then
rechecks both process-bound local IPC and HTTP authorization before EVERY message,
including Execute and Sync. A statement/portal name is not authorization. Request
validation uses staged state; only backend ParseComplete/BindComplete/CloseComplete
ACKs commit handles. Describe updates cached field counts. Backend errors recover
at Sync/ReadyForQuery; failed/unacknowledged operations never create handles.

Statement/portal state belongs to one frontend/backend connection pair. Portals
are cleared when ReadyForQuery reports idle/failed transaction, while named
prepared statements survive normal transaction boundaries. Simple Query
invalidates unnamed statement/portal state. SQL text privileges follow the
configured backend role; this is not a SQL firewall. SQL-created prepared plans
are not automatically admitted into the tracked wire-handle namespace.

Limits: 64 tracked wire statements and 64 portals per connection; names up to
128 bytes; 1024 parameters/result fields; 128 messages and 4 MiB per Sync cycle;
1 MiB wire message; 64 MiB/100000 messages per backend result cycle. An extended
cycle has a 30-second context, including writes and response reads. Backend reads
run concurrently with writes to avoid send-window deadlocks; responses are
buffered to the bounded ReadyForQuery boundary before frontend relay. Unsupported
or malformed traffic closes both ends rather than falling back to passthrough.

Not supported: protocol Flush/pipeline operation, suspended/cursor execution,
COPY (including COPY protocol responses from simple queries), replication,
asynchronous notifications, FunctionCall and cancel-request mapping. Backend
authentication/control messages are forbidden after startup. No real backend PID
or cancel key is forwarded. This is a laboratory implementation, not a claim of
complete libpq/ORM/pipeline compatibility or production readiness.

## Independent client and real SQL validation

The pgx/v5 dependency is imported only by tests. Native laboratory protocol code
uses the standard library. Tests require verified frontend TLS with no plaintext
fallback and a statement/description cache smaller than the broker quota (32).

The real isolated PostgreSQL suite covers:

- Named and implicit prepares, text/binary Bind, NULL, Unicode/injection-like text,
  bytea, signed numbers, floats and timestamp results, plus actual INSERT and
  affected-row counts checked from an independent administrator connection.
- Repeated Execute, statement Close/re-Parse, transactions and Sync recovery after
  invalid Parse; existing simple SQL and encrypted frontend login tests remain.
- Prepared writes after revoke and worker loss while HTTP still allows: no rows
  inserted, no backend sessions and no remaining managed broker process.
- Closed statement reuse and COPY: denied, no COPY rows/bytes exposed and no
  backend session leak. Lifecycle/bootstrap secrecy and replay checks remain.
- Unit/race tests for staged vs ACK state, names/quota/formats/lengths, portal
  closure, denial immediately before Execute, backend control filtering and
  response backpressure while sending a cycle.

The expanded suite has 17 SQL scenarios, including five extended-protocol cases.
CI executes it on PHP 8.3 and 8.5, and repeats all 11 managed cases with the actual
worker as PID 1 in official PHP containers. Run in the same isolated setup as
[Vault IPC SQL E2E](VAULT_IPC_SQL_E2E.md), with the built broker configured:

```sh
go test -v -run '^TestLaravelVaultIPC(PostgresSQL|ParallelBootstrap)$' -count=1 -timeout=5m ./...
```

Set SAG_E2E_LARAVEL=1 to include the real concurrent Laravel bootstrap test.
PostgreSQL backend transport remains explicitly selected disable only inside the
isolated lab runner; this stage does not change that deployment gate or silently
downgrade a configured transport. Frontend TLS and all session checks are required.

Local final validation on PHP 8.5 passed all 17 PostgreSQL SQL scenarios and the
real concurrent Laravel Vault grant test. Extended-protocol unit/race tests and
the complete Go suite passed. Earlier isolated retries were needed for two local
readiness/connection failures; authentication deadlines were not weakened. The
full final run passed with all existing simple-query and lifecycle regressions.
