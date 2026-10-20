# Microsoft SQL Server — encrypted transport foundation (CE)

Status: **experimental / integration testing required**.

The CE gateway now forwards MSSQL connections as an **opaque bidirectional TCP stream**. Unlike the previous packet-by-packet TDS parser, it does not interfere with TDS PRELOGIN and its wrapped TLS handshake, or with native TLS records used by TDS 8.0. This avoids incorrectly interpreting encrypted data as SQL.

## Guarantees and non-guarantees

- Gateway enforces CE session status and source CIDR **at initial connection acceptance**. Actual DB credentials and certificate validation remain the responsibilities of the SQL Server and DB client.
- Connection-start/connection-end metadata and total forwarded byte counts are recorded on a best-effort basis.
- The gateway **does not terminate TLS**, inspect credentials, decode SQL batches, inspect RPC, or record per-query audit events for MSSQL. SQL Server Audit/Extended Events should be configured on the database server for query auditing.
- The existing `target_tls_mode` and `query_audit_enabled` resource fields do **not** enforce end-to-end TLS nor provide MSSQL SQL audit. They must not be presented as evidence of such protections.
- No automatic integrated Windows/Kerberos delegation is provided by CE. Client-managed Kerberos may require SPN and DNS configurations that must be validated separately.
- This change has synthetic TLS/TDS byte-forwarding tests. **It does not confirm driver compatibility or TLS certificate identity.**

## Test matrix — real SQL Server required

Use only a dedicated test SQL Server with a server certificate trusted by the test client.

1. SQL Server 2019/2022/2025 (as available), SQL authentication.
2. Microsoft ODBC Driver 18 with `Encrypt=yes;TrustServerCertificate=no`; validate server certificate and DNS/SPN naming. The gateway hostname generally differs from the backend SQL Server hostname, so configure the driver's trusted certificate hostname appropriately rather than disabling validation.
3. Microsoft JDBC Driver, `encrypt=true`, `trustServerCertificate=false`; validate server certificate names.
4. Where supported, `Encrypt=Strict` / TDS 8.0 and large result streams.
5. Execute SQL Batch, parameterized `sp_executesql`, transactions, and RPC calls; assert server-side results and client error propagation, **not** gateway SQL audit.
6. Revoke CE session while SQL Server client holds a connection; assert existing TCP connection closes.
7. Try new connection after revocation and after TTL expiration; assert denial.
8. Simulate backend failure, half-close and worker restart.
9. Verify that the gateway log and `db_query_events` contain no SQL literal or plaintext DB credentials.

If certificate checking fails, do **not** work around it using `TrustServerCertificate=yes` for production acceptance.

## Follow-on work before removing experimental label

- Test against real SQL Server and supported Microsoft drivers.
- Confirm live revocation under network faults, delayed connections, and worker restart.
- Separate the UI label `query_audit_enabled` for MSSQL into an explicit connection-metadata-only mode.
- Add gateway health metrics, durable audit delivery and appropriate TLS policy checks.
- Decide whether server-side SQL Server Audit / Extended Events integration belongs in CE scope.
