# PR21: Temporary Broker readiness and Vault IPC acceptance contract

## Observed integration gap

- DbGatewayLifecycleService starts a session in `starting`.
- AuthorizedGatewayVault::isActiveSessionResource and forBoundActiveSession accept only `started`.
- The PostgreSQL lab Broker needs a backend credential before backend authentication.
- The currently implemented Unix IPC server returns only non-secret allow/deny; the lab Broker reads a separate password file.
- Thus the live Broker cannot be claimed production-ready even when Go and PHP CI are green.

## Required state transitions

1. Control Plane validates resource, issuance and expiry and transitions `created` to `starting`.
2. Launcher records the unique OS process identity (PID, process start ticks, exact binary, session UID) and a session-bound, one-time launch authorization available only to that process, not to the browser or command-line arguments.
3. A dedicated local Worker authenticates the IPC peer, validates the exact session/resource, live process identity, launch authorization and expiry atomically; it rejects replay and all other sessions.
4. Only that specifically bound `starting` Broker may complete its initial bootstrap. General secret retrieval must continue to reject arbitrary `starting` sessions.
5. The Broker verifies the client's temporary password and completes native backend authentication. Only after successful authentication is `started` reported.
6. Revocation, expiry, deactivation, process replacement, IPC failure or vault failure must close the connection without falling back to passthrough.

## Security invariants

- Session UID and resource ID are *identifiers*, never proof of process identity.
- SO_PEERCRED UID alone is insufficient when unrelated processes share a Unix UID.
- Never expose technical credentials through the web API, logs, process argv or client wire protocol.
- IPC must use a private filesystem location with restrictive permissions, bounded frames and timeouts.
- Never infer a successful backend authentication merely from opening a TCP connection.
- Keep TemporaryBroker and CredentialVaultIPC capabilities disabled until their implementations and integration tests exist.
- Keep default production runtime on the existing explicitly selected safe mode; do not auto-downgrade managed authentication.

## Required acceptance tests

- No bootstrap for `created`, `failed`, `terminated`, `expired`, wrong resource or disabled resource.
- `starting` without the exact live launch identity is denied.
- Same Unix UID but unrelated PID/launch capability is denied.
- Stale PID and PID reuse are denied; capability replay is denied.
- Full PostgreSQL SQL E2E using a SAG temporary login and server-side Vault credential, without backend-password-file.
- Native MySQL/MariaDB and MSSQL E2E with matching lifecycle checks.
- Revocation during an established session closes it, and all future attempts fail.
- Nothing changes in the production Compose stack before isolated LAB validation.

## Rollout gate

Draft PR21 cannot be merged as an implemented Temporary Broker until the above are checked and the integration tests are green.
