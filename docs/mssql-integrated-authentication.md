# MSSQL Windows Integrated Authentication — design contract

Status: **the native backend adapter and isolated AD test stand are available
for laboratory acceptance; managed Windows sessions remain disabled**.
Selecting `mssql_kerberos_service` still blocks SQL password retrieval and broker
launch. The native adapter preserves the existing TDS transport and SQL relay;
it adds a distinct SSPI/LOGIN7 backend handshake with no SQL authentication
fallback. Gateway session integration and its release acceptance are still
required before enabling this mode.

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
owner-only files owned by the broker UID; symlinks are refused. Neither tickets
nor key material are returned by the probe.

These checks authenticate only the Gateway-to-target adapter. They do not yet
enable the Laravel managed Windows mode or validate its temporary-client
bootstrap, authorization and revocation path.

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
