# Active Directory sign-in (optional, CE)

SAG DB Access Gateway CE can authenticate web-console users against **Microsoft Active Directory via LDAPS (TCP/636)**. This is separate from SAG Enterprise. It is not AD single sign-on, Kerberos DB authentication, Vault, or resource-level RBAC.

**Authorization model:** members of one explicitly configured AD security group receive CE `admin` rights scoped to their own resources, sessions, and audit. A `superadmin` can promote an existing AD administrator in the web console. Nested AD groups are evaluated using the AD transitive membership matching rule. Local break-glass administrators remain available; `sag:ce-create-admin` may be repeated with different email addresses. Each AD user gets their own local profile and **their own mandatory TOTP MFA**. Target database credentials are still supplied directly to the DB client.

## Preconditions

1. The domain controller has a valid certificate for LDAPS, with a hostname/SAN matching the URL. The app container must trust the issuing CA.
2. Create a **dedicated** AD security group for trusted CE Operators (do not use Domain Users).
3. The gateway host can reach domain controllers on TCP/636.
4. Run the Laravel migration before enabling LDAP.
5. Do not use LDAP without transport encryption. The integration deliberately rejects ldap://.

## Configuration

Add to **deploy/.env** (not Git), for example:

~~~dotenv
SAG_CE_LDAP_ENABLED=true
SAG_CE_LDAP_URL=ldaps://dc01.example.internal:636
SAG_CE_LDAP_DOMAIN=example.internal
SAG_CE_LDAP_NETBIOS_DOMAIN=EXAMPLE
SAG_CE_LDAP_BASE_DN=DC=example,DC=internal
SAG_CE_LDAP_ALLOWED_GROUP_DN=CN=SAG-CE-Operators,OU=Groups,DC=example,DC=internal
SAG_CE_LDAP_CA_FILE=/etc/sag/ca/ad-ca.pem
~~~

For a private CA, place its PEM certificate at deploy/ca/ad-ca.pem on the host (ignored by Git). The folder is mounted read-only in the app container at /etc/sag/ca. If the domain controller uses a publicly trusted certificate, leave SAG_CE_LDAP_CA_FILE empty to use the system CA store.

Apply changes:

~~~bash
mkdir -p deploy/ca && chmod 700 deploy/ca
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d --build
docker compose --env-file deploy/.env -f deploy/compose.yaml exec app php artisan migrate --force
docker compose --env-file deploy/.env -f deploy/compose.yaml exec app php artisan config:clear
~~~

**A production change should first deploy with SAG_CE_LDAP_ENABLED=false, run migrations, then enable it and recreate the app container.** Do not publish LDAP settings or CA private keys. Only the public CA certificate belongs in deploy/ca.

## Login

Accepted AD identities:

- samAccountName: alice (binds as alice@example.internal)
- UPN: alice@example.internal (the suffix must match SAG_CE_LDAP_DOMAIN)
- NETBIOS\samAccountName: EXAMPLE\alice (requires the NETBIOS setting)

A local Operator logs in with their email address and local password. The login form never falls back from a failed local password to an AD login of the same email; email collisions with the local Operator are rejected. AD users never have a usable local password.

On successful LDAPS bind and authorization-group lookup, the user's AD objectGUID is linked to a locally stored profile; no AD password or LDAP service-account password is persisted. Account changes are correlated by objectGUID. Any locally deactivated AD profile remains blocked even when AD permits a bind.

Every new AD administrator must enroll personal TOTP MFA. There is no fixed number of administrators. Existing TOTP and recovery codes belong to their individual profile.

## Security boundaries

- Member removal and AD account disablement are checked **at the next password sign-in**, not continuously for existing Laravel sessions. In emergency revoke the web session in the Laravel session store (or restart app/session storage), disable the user locally, and terminate their active DB gateway sessions.
- `admin` is limited to self-owned resources, sessions, and their own audit events; `superadmin` has global access. Restrict the AD group to trusted administrators.
- Trust the issuing CA; never disable certificate verification to make LDAPS work.
- Domain sign-in controls **web console access only**. It does not implement integrated Windows authentication for PostgreSQL, MySQL or MSSQL.
- The known gateway protocol/TLS and SQL audit limitations remain independent of this feature. Do not treat enabling LDAPS as remediation for DB-client transport security.

## Safe LDAPS verification on the CE host

After enabling LDAP and applying migrations, run the read-only connectivity check:

~~~bash
docker compose --env-file deploy/.env -f deploy/compose.yaml \
  exec app php artisan sag:ce-ad-check
~~~

This checks TCP/TLS and validates the domain controller certificate and hostname without sending credentials. To additionally verify a specific AD administrator's password and required AD-group membership:

~~~bash
docker compose --env-file deploy/.env -f deploy/compose.yaml \
  exec -it app php artisan sag:ce-ad-check --user='EXAMPLE\alice'
~~~

The command requests the AD password interactively without terminal echo; it does not save credentials or create a CE profile. A successful check does not validate the entire MFA web flow, which should be tested via HTTPS in the target environment.

## Role and revocation acceptance checklist

1. Sign in as an AD group member: create an individual `admin` profile, then enroll TOTP MFA.
2. Sign in as the local `superadmin` and promote that AD admin in **Administrators**.
3. Sign out, sign in to AD again and finish MFA; confirm the `superadmin` role is preserved.
4. Verify that a different `admin` cannot access the promoted user's resources, sessions, or audit events through direct URLs.
5. Remove the test AD account from the configured security group; its **next password sign-in** must fail.
6. Verify that an existing logged-in AD web session does **not** automatically expire when the AD membership changes: to revoke immediately, disable the local profile and invalidate the web session. Active database gateway sessions require separate termination.

**Important:** GitHub CI mocks AD and verifies application behavior only. Real certificate chain, network, bind, nested-group behavior and login MFA require tests against an actual domain controller.
