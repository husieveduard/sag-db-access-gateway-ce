# MSSQL Windows Integrated Authentication — design contract

Status: **implemented behind explicit managed-runtime and Kerberos candidate
flags; disabled in the default deployment**. The existing lifecycle starts the
MSSQL broker with an explicit Kerberos strategy, pinned service-profile alias,
authentication mode and target binding. The resource form selects only public
profile aliases; clients continue to use temporary SAG credentials. There is no
SQL authentication fallback. Release acceptance must run on an isolated AD/SQL
Server stand before enabling a deployment.

## Isolated native acceptance

`deploy/kerberos-lab/Dockerfile` builds a disposable Samba AD DC for the fixed
`SAG.TEST` realm. Provisioning refuses an existing database and requires an
explicit lab environment flag. AD passwords enter the provisioner through stdin,
and only the two laboratory service accounts' keys are exported. The test
publishes no host ports and contacts no existing AD domain. Its uniquely named
containers, network and short-lived keys are removed in `finally`.

```sh
docker build -f deploy/kerberos-lab/Dockerfile -t sag-ad-acceptance .
CGO_ENABLED=0 sh scripts/build-mssql-kerberos-probe.sh /tmp/sag-mssql-kerberos-probe
python3 scripts/test-mssql-kerberos.py --ad-image sag-ad-acceptance --probe /tmp/sag-mssql-kerberos-probe
```

Use `--kdc-only` for real AD service-ticket checks without a SQL Server image.
The full test creates a least-privilege `SAG\gateway` Windows login, verifies
target TLS, and makes SQL Server assert `ORIGINAL_LOGIN()` and `SUSER_SNAME()`
before `SELECT 1`. Missing keytabs, mismatched or unknown SPNs, unknown target CA,
and KDC loss must fail closed. SQL Server uses its separate `sqlservice` keytab.
The native client uses AES128/AES256, an explicit realm/KDC configuration and a
target-bound MSSQL SPN. It does not discover credentials from the environment,
rewrite SPNs through DNS CNAMEs, or negotiate NTLM. Keytabs must be regular,
owner-only files owned by the broker UID; symlinks are refused. The acceptance also supplies a typed keytab/config envelope on a private stdin
pipe and repeats the real AD principal/SELECT 1 check without mounting keytab or
configuration files in the native client. Neither tickets nor key material are
returned by the probe.

Without the managed arguments, these checks authenticate only the backend
adapter. For the full managed runtime path, install Laravel development
dependencies and build the native broker plus the static Go test executable:

```sh
CGO_ENABLED=0 scripts/build-mssql-lab-broker.sh /tmp/sag-mssql-kerberos-broker
(cd db-gateway && CGO_ENABLED=0 go test -c -o /tmp/sag-mssql-kerberos-tests)
python3 scripts/test-mssql-kerberos.py --ad-image sag-ad-acceptance \
  --probe /tmp/sag-mssql-kerberos-probe \
  --managed-tests /tmp/sag-mssql-kerberos-tests \
  --managed-broker /tmp/sag-mssql-kerberos-broker
```

The managed test runs as UID 33, uses Laravel's real runtime launcher, the
trusted Unix worker and an independent Microsoft SQL client with temporary SAG
credentials. It checks the actual AD principal, SELECT 1, typed RPC, foreign
bootstrap denial, active revoke/expiry, process replacement, worker loss, idle
cleanup and revocation during startup. It checks zero backend sessions after
invalidation and scans captured HTTP/broker logs, broker argv and environment
for the private AD keytab and administrator password. The administrator password
enters the test process on stdin only. CI runs this combined acceptance.

## User-facing connection contract

SAG CE retains the same five connection properties for the client:
gateway IP, temporary port, temporary username, temporary password, and target database.
The SQL client authenticates **to SAG Gateway** using the temporary username/password.
Those temporary credentials are not SQL Server accounts and are not AD passwords.

## Gateway-to-target authentication strategies

Each MSSQL resource must explicitly choose an independent backend strategy:

- `sql_auth`: Gateway signs in to SQL Server with a stored SQL login and secret.
- `kerberos_service`: Gateway uses Kerberos integrated authentication with a
  dedicated AD service principal/service account managed by the organization.
- `kerberos_delegation`: optional future mode for per-user identity propagation,
  only after a separate security review of delegation controls.

The legacy `client_passthrough` path remains independent and unchanged. Never
silently fall back from Kerberos to SQL authentication.

## Kerberos implementation requirements

- Register/validate a dedicated MSSQL SPN, typically `MSSQLSvc/fqdn:port`, for the
  actual service identity. DNS, domain trust, time synchronization and KDC reachability
  must be checked.
- Provide the broker with a dedicated, least-privilege AD service identity, not
  a domain administrator. Protect keytabs or gMSA-like credentials at the runtime layer;
  do not store Kerberos keys in ordinary database resource options or logs.
- Terminate and independently authenticate the client-to-gateway TDS/TLS connection.
  Start a separate authenticated TDS session to the real SQL Server; simply forwarding
  the client's SSPI/Kerberos handshake does not implement temporary SAG credentials.
- Require server certificate verification and fail closed on absent/invalid credentials,
  unapproved SPN, unsupported encryption negotiation or unreachable KDC.
- Restrict the target database, track connections, enforce per-session revocation and
  expiry, and audit principal, resource, connection outcomes and authentication mode
  without storing tickets, passwords, or security tokens.
- Do not claim per-user Windows identity unless explicit constrained delegation is
  engineered and verified. In `kerberos_service` mode SQL Server sees the service
  identity; SAG CE must retain the end-user mapping in its own audit.

## Acceptance tests before enabling in a deployment

1. Authorized temporary SAG credentials + service Kerberos ticket connect to a
   restricted MSSQL lab database.
2. Invalid temporary SAG password fails before target connection.
3. Missing/expired ticket, wrong SPN, unknown CA and KDC loss fail closed.
4. Terminating a SAG session closes active backend connections.
5. SQL Server's observed principal matches the documented backend mode.
6. No AD secret, ticket or temporary password appears in HTTP responses, GUI history,
   structured logs, telemetry or container environment dumps.

No MSSQL Integrated Authentication option should be enabled in the GUI until these
tests pass with a working TDS/Kerberos adapter.

## Process-bound Kerberos material bootstrap

The default-off `SAG_MSSQL_KERBEROS_ENABLED` capability permits a distinct
`bootstrap_kerberos` Unix operation and enables validated service profiles for
managed launch when the managed runtime is explicitly enabled. The request carries only the session/resource IDs;
the Unix worker supplies the kernel peer PID/start ticks to the private Laravel
command. Only its inherited FD 3 pipe may carry the response.

A runtime-owned profile is selected by `target_options.kerberos_profile`, with
its alias pinned in session metadata together with `mssql_kerberos_service`.
The configured target and SPN must match the resource. Profile data comes from
the trusted file referenced by `SAG_MSSQL_KERBEROS_PROFILES_FILE`, for example:

```json
{
  "service-a": {
    "target": "sql.sag.test:1433",
    "spn": "MSSQLSvc/sql.sag.test:1433",
    "username": "gateway",
    "realm": "SAG.TEST",
    "config_file": "/etc/sag/kerberos/krb5.conf",
    "keytab_file": "/etc/sag/kerberos/gateway.keytab"
  }
}
```

The profile/config files must be regular, owned by root or the worker UID, and
not writable by group or others. Keytabs must be regular files owned by the
worker UID with no group/other access; symlinks are rejected. Configuration and
keytab inputs are each limited to 4096 bytes. Profile files contain references,
not AD passwords or key material.

The grant revalidates active resource, expiry, process identity, pinned
authentication mode/profile and unused bootstrap under a session row lock. It
returns only a typed Kerberos envelope through FD 3 and Unix IPC. The response
contains base64-encoded config/keytab bytes in server memory, never a SQL
password or keytab path. SQL and Kerberos receivers reject cross-type or mixed
responses. Windows sessions cannot retrieve a stale SQL Vault credential.

## Broker in-memory strategy

The MSSQL binary explicitly selects `--backend-auth=mssql_sql_auth` (default)
or `--backend-auth=mssql_kerberos_service`. Unknown strategies and delegation
are rejected. The Windows strategy consumes only `bootstrap_kerberos`, validates
the target-bound material, and constructs a fresh Kerberos client for the
readiness probe and each backend connection. It does not open a keytab/config
file, read a credential cache, or request the SQL Vault slot.

Both strategies share the existing TDS frontend and SQL relay. A valid temporary
SAG login and a fresh local authorization check are required before connecting
to the backend. The same active-session watcher closes frontend/backend
connections on invalidation. The application launcher requires an enabled profile and pins its public alias,
authentication mode and target. A change to any bound target value invalidates
the session. Profile changes are refused while a session is starting, active or
stopping.

The real Laravel FD 3/Unix integration test uses a disposable SQLite control
plane and private opaque keytab fixture. It verifies the typed grant, persisted
one-time consumption, replay denial, activation and live invalidation after
changing authentication mode/revoking the session. The separate AD job proves
the native in-memory material path against real Kerberos and SQL Server.
Neither component test is the full temporary-SAG-client Windows acceptance.

## Optional isolated deployment

Apply `deploy/compose.kerberos-candidate.yaml` after the base Compose and managed
candidate overlay. Set `SAG_CE_KERBEROS_DIR` to a private absolute directory with
`profiles.json`, `krb5.conf` and the profile's keytab. The example profile paths
above match this mount. Keytabs must be owned by container UID 33 with mode 0600;
the profile/config must satisfy the regular-file ownership rules above. Keep
DNS/KDC reachability and clock synchronization working inside the worker.

Only the public profile-map file is mounted into the web application. The worker
receives the private configuration directory; the default Compose receives
neither this mount nor the Kerberos enable flag. The UI never accepts a keytab,
keytab path, realm password or arbitrary SPN from an HTTP request. To disable the
capability, remove the optional overlay/flag and restart the isolated workers;
existing session authorization then fails closed. This document does not
authorize changing a Production deployment.
