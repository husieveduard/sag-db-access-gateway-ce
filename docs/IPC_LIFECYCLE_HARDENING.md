# Unix IPC and managed laboratory lifecycle hardening

The former composer:2/PHP 8.5 bootstrap failure was caused by the worker being
PID 1 inside its container, rather than a PHP language incompatibility. On the
same PHP 8.5.12 image, a child worker passed managed SQL while a PID 1 worker was
rejected before bootstrap was consumed. The broker required every worker peer
to have PID > 1. A container entrypoint legitimately has PID 1.

The credential client now accepts a kernel-reported worker PID > 0 with its
configured trusted UID. PID 0 and wrong UID remain denied. This changes only
worker authentication: credential recipients still require their exact
launcher-bound PID > 1, executable and start ticks, matching resource/session,
one-time grant, active resource and live expiry/status. No client-supplied PID
or UID is trusted. Backend passwords still travel only through the inherited
private credential pipe and authenticated Unix socket.

## Concurrent worker requests and shutdown

The shared worker handles at most 16 requests concurrently. Extra connections
close without authorization. Each request has a three-second context/deadline;
shutdown cancels PHP providers, closes in-flight connections, waits for handlers
and removes the worker's own socket. A replacement socket at the same pathname
is preserved using filesystem identity. A partial frame can no longer block all
other session checks. Read-only checks still cannot consume bootstrap.

Authorization command stdout is bounded to 128 bytes and overflow is denied.
Inherited subprocess I/O has a bounded WaitDelay, while secret output remains
restricted to bounded pipe FD 3. Diagnostics are not treated as authorization.

A real Laravel transaction test races eight credential requests from the same
kernel-bound process. At most one concurrent request may receive credentials;
a single follow-up can succeed only if the burst committed none under SQLite
lock contention. Exactly one grant must commit overall. Further replay and
post-revocation authorization are denied. Missing/wrong resource requests cannot
consume the valid grant. This tests the existing Vault transaction boundary,
without replacing it with a mock counter.

## Entire process lifetime

All three native laboratory adapters monitor their session while idle, as well
as before/through SQL. Failed local/HTTP authorization stops the owned listener
and process. Cancellation also closes a frontend stalled before authentication;
SQL guards continue to close both frontend and backend connections. The monitor
polls every two seconds with a three-second authorization timeout. It exits its
own process and never signals a PID taken from changed session metadata.

Real SQL tests now include idle revoke, idle worker loss and revoke with a pending
frontend handshake for PostgreSQL, MySQL/MariaDB and SQL Server. They verify the
original broker process exited, the listener refuses new clients and no backend
session remains. Live invalidation tests also verify process exit; an unsupported
client command closes that connection while the still-authorized session remains
usable. Production managed gates and Compose are unchanged.

## Validation

Locally on PHP 8.5.12, the unchanged baseline failed in PID 1 mode with
bootstrap_consumed=false and passed as a child. After the worker fix, all managed
PostgreSQL launch scenarios passed with a PID 1 worker, including real SELECT 1,
invalid Vault password and startup revoke. The expanded tests add idle/pending
closure, bounded-worker shutdown, replacement-socket preservation, independent
session progress and real concurrent Laravel bootstrap.

CI covers PHP 8.3 and 8.5 for Laravel and all three SQL families. Dedicated jobs
run a static test binary as the actual PID 1 worker inside official versioned PHP
containers, with a separate isolated PostgreSQL. The PID 1 assertion is mandatory
in those jobs; they do not silently run the worker as a child. Go race detection
covers concurrent worker/lifetime tests. Native protocol limitations documented
in each engine's SQL E2E guide remain in force.
