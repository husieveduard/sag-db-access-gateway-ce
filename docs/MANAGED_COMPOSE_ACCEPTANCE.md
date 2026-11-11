# Managed Compose SQL acceptance

`scripts/test-managed-compose.py` tests the packaged runtime and the staging
overlay together. It creates a uniquely named disposable Compose project and
target database, and removes its containers, volumes, network and temporary
certificates in `finally`. It publishes no ports. Run engines sequentially on
small Docker disks. The script preserves input images and unrelated resources.

Build the production runtime and the independent static SQL probe:

```sh
docker build --target runtime -t sag-ce-compose-acceptance -f deploy/Dockerfile .
cd db-gateway
CGO_ENABLED=0 go build -o /tmp/sag-managed-compose-probe managed_compose_probe_main.go
cd ..
python3 scripts/test-managed-compose.py --engine mysql --image sag-ce-compose-acceptance --probe /tmp/sag-managed-compose-probe
python3 scripts/test-managed-compose.py --engine mariadb --image sag-ce-compose-acceptance --probe /tmp/sag-managed-compose-probe
python3 scripts/test-managed-compose.py --engine mssql --image sag-ce-compose-acceptance --probe /tmp/sag-managed-compose-probe
```

For managed cloud builds, pass the session CA with
`--secret id=proxy_ca,src="$CODEX_PROXY_CERT"`. For an isolated build that cannot
fetch fonts, add `--build-arg SAG_SKIP_REMOTE_FONTS=true`. Neither option disables
TLS verification or stores the proxy CA in image layers.

The fixture uses the production Laravel/PHP image, existing lifecycle queue,
co-located Unix IPC worker, and normal managed launcher. Nginx uses the deployed
configuration template and a readable public index. Certificates are generated
for both frontend and target; the independent Microsoft/MySQL drivers verify
the frontend certificate and the broker requires target `verify-full`.

Acceptance requires all of the following:

- A least-privilege backend credential enters provisioning and Laravel Vault
  through stdin. Its plaintext is held only in test memory, not a password file,
  HTTP response, broker environment or broker command line.
- The lifecycle queue starts a broker with consumed bootstrap and without
  Laravel secrets or HTTP proxy environment variables.
- Another process under the same Unix UID cannot retrieve bootstrap credentials.
- A standard independent client executes real `SELECT 1` with the temporary SAG
  credential and keeps the SQL connection open.
- A queued terminate operation closes the active client connection and listener;
  the real database reports zero sessions for the backend user.
- Neither application nor broker logs contain the backend password.

The PHP fixture requires `SAG_COMPOSE_ACCEPTANCE=1`, PostgreSQL as the control
database, and an isolated database name beginning with `sag_ce_accept_`. It
creates only fixture resources and sessions. It does not reset a database.
Test admin passwords are separate from the Vault backend role; only disposable
target admin credentials enter the target container environment.

CI runs each engine in its own job. MSSQL requires access to both
`mcr.microsoft.com` and its image CDN `westus.data.mcr.microsoft.com` in restricted
environments. A blocked image download is not a passed SQL acceptance test.

This suite covers SQL password authentication. Windows Integrated Authentication
still requires the Kerberos adapter and isolated AD/SQL Server acceptance.
