# Active Directory settings in SAG CE

**Superadmin → Active Directory** stores AD parameters in the PostgreSQL `ad_settings` table. Web sign-in and `php artisan sag:ce-ad-check` use these DB settings and no longer read LDAP values from application configuration.

## Fields
- Enabled/disabled
- LDAPS (TLS/636) or LDAP + StartTLS (389)
- Domain controller FQDN, port, DNS domain, optional NetBIOS domain
- Search Base DN and required AD authorization group DN
- Optional **public** CA certificate PEM uploaded into the persistent private application storage
- Connectivity test: no password is transmitted; actual user bind should be checked separately with `sag:ce-ad-check --user=...`

The web console does **not** expose insecure plaintext LDAP. It never stores AD user passwords or a bind account password. The local Superadmin remains available for recovery, with MFA.

## Upgrade from an existing .env LDAP deployment
1. Back up PostgreSQL and the existing `deploy/.env` and public CA certificate.
2. Deploy code while retaining the temporary legacy LDAP environment entries in Docker Compose. These are **migration-only**, not used for runtime authentication.
3. Run `php artisan migrate --force`. The migration copies the old AD connection configuration to `ad_settings` and imports the public CA if readable.
4. Sign in as local Superadmin, open **Active Directory**, verify every field and test TLS connectivity. Test an AD account using `sag:ce-ad-check --user=...` and the web MFA flow.
5. After successful acceptance, remove obsolete LDAP values from `deploy/.env`. Their transitional Compose mapping can be removed in a later cleanup release.
6. Recreate containers and confirm AD sign-in still works from the DB-backed settings.

**Do not delete legacy values before the one-time migration runs.** The schema migration intentionally does not override existing DB settings on subsequent deployments.

## Safety
- Only Superadmin may edit/test AD configuration; updates are audit logged.
- LDAP StartTLS is upgraded before sending credentials. LDAPS and StartTLS must validate certificates.
- Protect `APP_KEY` and PostgreSQL backups. Protect the private application storage volume.
- After changing domain controllers, ensure the CA is valid and FQDN matches certificate SAN.
- Changing a configured domain may lock out directory users; keep a tested local Superadmin break-glass account.
- Web UI TLS test checks reachability/certificate only; it does not verify group membership or actual user bind.
