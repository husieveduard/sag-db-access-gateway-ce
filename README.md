[🇬🇧 English](README.md) | [🇺🇦 Українська](README.uk.md)

# SAG DB Access Gateway CE

> **Pilot / pre-release.** A self-hosted database access gateway for controlled and auditable access to PostgreSQL, MySQL, and experimental Microsoft SQL Server targets.

SAG DB Access Gateway CE provides a lightweight control plane for temporary and persistent database access. It is designed for teams that need controlled DB connectivity, SQL audit, session lifecycle management, and mandatory MFA without deploying a full PAM platform.

## Documentation

- [Deployment guide](docs/DEPLOYMENT.md)
- [Host Nginx reverse-proxy template](deploy/reverse-proxy/nginx-host.conf.example)


## Core capabilities

* Temporary database sessions with configurable TTL.
* Persistent sessions for monitoring, integrations, reporting, and long-running jobs.
* Dynamic gateway port for every session.
* PostgreSQL and MySQL support.
* Experimental Microsoft SQL Server support.
* Source IP/CIDR restriction for database sessions.
* Connection start and end audit.
* SQL query audit with basic risk classification.
* Manual session termination.
* Automatic TTL expiration cleanup.
* One local CE Operator.
* Mandatory TOTP MFA with QR enrollment and recovery codes.
* Emergency MFA reset and password reset through local console commands.
* Docker Compose deployment for a clean server.

## Security model

SAG DB Access Gateway CE intentionally remains simple.

* Only one active CE Operator can exist at a time.
* The initial Operator is created interactively after installation.
* Operator credentials are never stored in Git, Docker Compose, or `deploy/.env`.
* MFA enrollment is mandatory during the first web login.
* TOTP secrets are encrypted by Laravel.
* Recovery codes are stored only as hashes and are shown once.
* Target database passwords are not stored by the CE control plane.
* Gateway lifecycle operations and TTL cleanup run in the same worker container.
* Internal gateway API paths are accessible only from the Docker network.
* The CE web console is bound to localhost and must be exposed through HTTPS reverse proxy.

This Community Edition does not include complex RBAC matrices, approval workflows, LDAP, Active Directory, SSO, Vault, SSH/RDP access, Guacamole, or Incident Manager integrations.

## Architecture

```text
Browser
  |
  | HTTPS :443
  v
Host Nginx / Reverse Proxy
  |
  | HTTP 127.0.0.1:18081
  v
Docker Nginx
  |
  v
Laravel Control Plane
  |
  +--> PostgreSQL
  +--> Redis
  +--> Worker + Scheduler + Gateway child processes
          |
          | Dynamic TCP ports 16000-17999
          v
      Go DB Gateway
          |
          v
Target PostgreSQL / MySQL / experimental MSSQL
```

## Runtime services

The production deployment starts:

* `postgres` — control-plane database.
* `redis` — cache and runtime support.
* `app` — Laravel PHP-FPM application.
* `worker` — lifecycle worker, scheduler, and Go DB gateway child processes.
* `nginx` — internal web frontend, bound to localhost.

## Requirements

* Linux server.
* Docker Engine and Docker Compose v2.
* `git`, `openssl`, and Bash.
* DNS name for the console, for example `db-ce.example.com`.
* Host Nginx or another reverse proxy for HTTPS.
* Network connectivity from the gateway server to target databases.
* Firewall rules for the dynamic gateway port range.

## Quick installation

Clone the repository:

```bash
cd /opt

git clone https://github.com/husieveduard/sag-db-access-gateway-ce.git \
  sag-db-access-gateway-ce

cd sag-db-access-gateway-ce
```

For production, deploy a specific release tag:

```bash
git fetch --tags
git checkout <release-tag>
```

Run the installer:

```bash
chmod +x scripts/install.sh
sudo ./scripts/install.sh
```

The installer asks for:

```text
Public console URL [https://server-name]:
Gateway public DNS/IP [server-name]:
```

Example:

```text
Public console URL: https://db-ce.example.com
Gateway public DNS/IP: db-ce.example.com
```

The installer automatically:

1. Creates `deploy/.env` with permissions `0600`.
2. Generates Laravel `APP_KEY`.
3. Generates the internal gateway API token.
4. Generates PostgreSQL and Redis passwords.
5. Builds Docker images.
6. Starts the CE services.
7. Applies database migrations.
8. Starts interactive initial Operator creation.

## Initial CE Operator

During installation, the installer starts:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-create-admin
```

The command requests:

```text
Administrator email:
Operator display name:
Initial password:
Confirm initial password:
```

The password must contain at least 14 characters, uppercase letters, lowercase letters, and a digit.

Only one active CE Operator is allowed. The command refuses to create another active Operator.

## First login and MFA

After HTTPS is configured:

1. Open the public console URL.
2. Sign in using the Operator email and password.
3. Scan the QR code using a TOTP application.
4. Enter the six-digit verification code.
5. Store recovery codes offline.

Compatible applications include Microsoft Authenticator, Google Authenticator, 1Password, and other TOTP-compatible apps.

MFA is required for every later console login.

## HTTPS reverse proxy

The Docker web frontend is bound to localhost on port `18081`. Do not expose this port publicly.

Example host Nginx configuration:

```nginx
server {
    listen 80;
    server_name db-ce.example.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name db-ce.example.com;

    ssl_certificate     /etc/letsencrypt/live/db-ce.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/db-ce.example.com/privkey.pem;

    client_max_body_size 10m;

    location / {
        proxy_pass http://127.0.0.1:18081;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }
}
```

Validate and reload:

```bash
nginx -t
systemctl reload nginx
```

## Firewall model

Expose only the required ports.

| Port or range      | Purpose                  | Access                           |
| ------------------ | ------------------------ | -------------------------------- |
| `443/TCP`          | Web console              | Operator network                 |
| `80/TCP`           | HTTP redirect or ACME    | Optional                         |
| `16000-17999/TCP`  | Dynamic DB gateway ports | Approved DB client networks only |
| `18081/TCP`        | Internal Docker frontend | Localhost only                   |
| PostgreSQL / Redis | Internal services        | Never expose publicly            |

Change the gateway port range in `deploy/.env`:

```env
SAG_CE_DB_GATEWAY_PORT_MIN=16000
SAG_CE_DB_GATEWAY_PORT_MAX=17999
```

## Health and operations

Check deployment status:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  ps
```

Check application health:

```bash
curl -fsS http://127.0.0.1:18081/api/health
```

View worker logs:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  logs -f worker
```

Run migrations manually:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan migrate --force
```

Manual expiration cleanup:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:cleanup-expired
```

Manual session termination:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:db-terminate-session \
  <SESSION_UID> \
  --reason=manual_revoke
```

Lifecycle commands that start or stop gateway processes must run in the `worker` container.

## Operator recovery

Reset the Operator password:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-set-password \
  operator@example.com
```

Emergency MFA reset:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-reset-mfa \
  operator@example.com \
  --reason='Emergency MFA recovery' \
  --confirm
```

After MFA reset, the next web login requires a new QR/TOTP enrollment.

## Backup

The PostgreSQL database contains sessions, audit events, resources, users, MFA configuration, and encrypted TOTP secrets.

Create a database backup:

```bash
set -a
. deploy/.env
set +a

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec -T postgres \
  pg_dump \
    -U "$SAG_CE_POSTGRES_USER" \
    "$SAG_CE_POSTGRES_DB" \
  > "sag-db-ce-$(date +%F-%H%M%S).sql"
```

Store backups encrypted and outside the gateway server where possible.

Back up `deploy/.env` separately and securely. It contains the Laravel `APP_KEY`; without this key, encrypted MFA secrets cannot be recovered after restoration.

## Upgrade

```bash
git fetch --tags
git checkout <release-tag>

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  up -d --build

docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan migrate --force
```

> Upgrades restart the worker container. Active DB gateway sessions are interrupted. Plan maintenance windows for production upgrades.

## Public repository checks

Before publishing changes:

```bash
./scripts/preflight-public.sh

docker compose \
  --project-directory "$PWD" \
  --env-file deploy/.env.example \
  -f deploy/compose.yaml \
  config -q
```

Do not commit:

* `deploy/.env`;
* Laravel `.env` files;
* passwords, tokens, certificates, or private keys;
* database backups;
* generated logs;
* local build artifacts.

## Scope and limitations

Included:

* PostgreSQL gateway.
* MySQL gateway.
* Experimental MSSQL gateway.
* Temporary and persistent DB sessions.
* SQL audit and basic SQL risk classification.
* Manual termination and automatic TTL cleanup.
* Mandatory QR/TOTP MFA.
* Docker Compose runtime.

Excluded:

* SSH, RDP, and web-access gateway.
* Complex multi-user RBAC policy matrices.
* Approval workflows.
* LDAP, Active Directory, SSO, and SCIM.
* Vault and managed credential rotation.
* Incident Manager integration.
* Proprietary company integrations.

## Security

Report security-sensitive issues privately. Never publish credentials, target database details, access tokens, internal address ranges, or exploit proof-of-concepts in public issues.

See `SECURITY.md` for the security policy.

## License

See `LICENSE-DECISION.md`.
