# Deployment Guide

This guide describes a clean production deployment of SAG DB Access Gateway CE on a new Linux server.

## Operational model

The deployment contains five services:

* `postgres` — control-plane database.
* `redis` — application cache and runtime support.
* `app` — Laravel PHP-FPM application.
* `worker` — lifecycle operations worker, scheduler, and Go DB gateway child processes.
* `nginx` — internal Docker web frontend.

The `worker` container owns gateway processes. Commands that start, terminate, or expire DB sessions must run inside this container. Do not run lifecycle commands through `app`, because it does not share the same process namespace as gateway processes.

The Docker web frontend listens only on `127.0.0.1:18081`. Public access must be provided through a host-level HTTPS reverse proxy.

## Prerequisites

Prepare a Linux server with:

* Docker Engine and Docker Compose v2.
* Git, OpenSSL, Bash, and a host Nginx installation.
* DNS record for the public console name.
* Network access from the gateway server to target database servers.
* A firewall policy that allows only approved client networks to use dynamic DB gateway ports.

Choose:

* a public console URL, for example `https://db-ce.example.com`;
* a public DNS name or IP for DB clients;
* a dynamic TCP port range, by default `16000-17999`.

Do not expose PostgreSQL, Redis, or port `18081` publicly.

## Installation

Clone a specific release tag:

```bash
cd /opt

git clone https://github.com/husieveduard/sag-db-access-gateway-ce.git \
  sag-db-access-gateway-ce

cd sag-db-access-gateway-ce

git fetch --tags
git checkout <release-tag>
```

Start the installer:

```bash
chmod +x scripts/install.sh
sudo ./scripts/install.sh
```

The installer requests:

```text
Public console URL [https://server-name]:
Gateway public DNS/IP [server-name]:
```

The installer creates `deploy/.env` with permissions `0600`, generates application and service secrets, builds images, starts services, runs migrations, and creates the initial CE Operator interactively.

The Operator password is entered through the terminal and is never stored in Git or `deploy/.env`.

## Initial Operator

The installer runs:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-create-admin
```

The command requests an email, display name, and password.

Only one active CE Operator is allowed. MFA setup is mandatory when that Operator first opens the web console.

## HTTPS reverse proxy

Copy and adapt the template:

```bash
sudo cp \
  deploy/reverse-proxy/nginx-host.conf.example \
  /etc/nginx/sites-available/sag-db-access-gateway-ce.conf
```

Replace all occurrences of `__SAG_CE_DOMAIN__` with the production domain.

Enable the site:

```bash
sudo ln -s \
  /etc/nginx/sites-available/sag-db-access-gateway-ce.conf \
  /etc/nginx/sites-enabled/sag-db-access-gateway-ce.conf
```

Obtain or install the TLS certificate, then validate and reload Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

The application trusts reverse-proxy headers, so the proxy must set `X-Forwarded-Proto: https`.

## Firewall rules

Allow only:

| Port or range     | Purpose                          | Allowed source              |
| ----------------- | -------------------------------- | --------------------------- |
| `443/TCP`         | Web console                      | Operator networks           |
| `80/TCP`          | ACME challenge or HTTPS redirect | Optional                    |
| `16000-17999/TCP` | Dynamic DB gateway ports         | Approved DB client networks |

Keep these inaccessible from untrusted networks:

| Port        | Purpose                        |
| ----------- | ------------------------------ |
| `18081/TCP` | Internal Docker Nginx listener |
| `5432/TCP`  | PostgreSQL                     |
| `6379/TCP`  | Redis                          |

Apply DB gateway restrictions through the host firewall, upstream firewall, cloud security group, or a combination appropriate for the environment.

## Post-install validation

Check service state:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  ps
```

Check local health:

```bash
curl -fsS http://127.0.0.1:18081/api/health
```

Expected response:

```json
{
  "status": "ok",
  "edition": "ce"
}
```

Open the public HTTPS URL, log in with the initial Operator account, configure QR/TOTP MFA, and store recovery codes offline.

## Routine operations

View worker logs:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  logs -f worker
```

Apply migrations:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan migrate --force
```

Run TTL cleanup manually:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:cleanup-expired
```

Terminate a DB session manually:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:db-terminate-session \
  <SESSION_UID> \
  --reason=manual_revoke
```

Reset the Operator password:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-set-password \
  operator@example.com
```

Reset MFA in an emergency:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec app php artisan sag:ce-reset-mfa \
  operator@example.com \
  --reason='Emergency MFA recovery' \
  --confirm
```

## Backup

Back up both PostgreSQL and `deploy/.env`.

`deploy/.env` contains Laravel `APP_KEY`; it is required to restore encrypted TOTP MFA secrets.

Create a PostgreSQL backup:

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

## Upgrade

Before an upgrade, create a backup and notify DB users. Rebuilding the worker container interrupts active DB gateway sessions.

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

Verify health and worker logs after every upgrade.

## Decommissioning

Before decommissioning:

1. Export database backups.
2. Securely retain `deploy/.env`.
3. Terminate active DB sessions.
4. Remove public DNS and reverse-proxy configuration.
5. Remove Docker volumes only after verified backup retention.

Do not run `docker compose down -v` until the backup and retention process is complete.
