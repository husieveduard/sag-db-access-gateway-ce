# Architecture

## Overview

SAG DB Access Gateway CE separates the **control plane** from the **database data plane**.

The control plane manages DB resources, access sessions, lifecycle operations, audit events, MFA, and the local CE Operator. The data plane is a Go gateway process that listens on a dynamic TCP port and proxies database protocol traffic to a target database.

```text
Browser
  |
  | HTTPS :443
  v
Host Reverse Proxy
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
  +--> Worker
          |
          +--> Scheduler
          +--> Go DB Gateway child processes
                    |
                    v
              Target databases
```

## Control plane

The Laravel control plane provides:

* CE Operator authentication and mandatory QR/TOTP MFA.
* DB resource inventory.
* Temporary and persistent DB session creation.
* Lifecycle operation queueing.
* Session status, audit records, SQL events, and risk classification.
* Manual session termination.
* Automatic expiration of TTL-based sessions.
* Internal API endpoints used by Go gateway processes.

The CE edition intentionally supports one active local Operator. It does not implement a granular RBAC matrix, approval workflow, LDAP, Active Directory, SSO, Vault, or managed credential rotation.

## Data plane

Each started session receives a dynamic gateway port from the configured range.

```text
DB client
  |
  | TCP to gateway public host and dynamic port
  v
Go DB Gateway
  |
  | PostgreSQL / MySQL / experimental MSSQL protocol
  v
Target database
```

The gateway validates the session through the internal control-plane API and reports:

* connection started;
* connection denied;
* connection ended;
* SQL query events;
* query completion or interruption.

PostgreSQL and MySQL are supported. Microsoft SQL Server support is experimental.

## Session lifecycle

A DB access session is created in the control plane and then started through an asynchronous lifecycle operation.

```text
created
  |
  +--> start operation queued
          |
          v
       worker starts Go gateway
          |
          v
       started
          |
          +--> terminated
          +--> expired
          +--> failed
```

Temporary sessions have `expires_at` and are expired automatically. Persistent sessions do not have TTL and are stopped manually.

## Worker and process ownership

The `worker` container runs:

* `sag:db-process-operations`;
* scheduled Laravel commands;
* child Go DB Gateway processes.

This design is mandatory because gateway processes are controlled by PID. Start, terminate, and TTL expiration actions must execute in the same container and process namespace as the gateway child process.

Lifecycle CLI commands must therefore run through the `worker` container:

```bash
docker compose \
  --env-file deploy/.env \
  -f deploy/compose.yaml \
  exec worker php artisan sag:cleanup-expired
```

## Credentials

The CE control plane does not persist target database passwords.

Database clients authenticate to target databases through the proxied database protocol. The control plane stores resource and session metadata, audit data, and encrypted MFA material only.

## Network model

The deployment exposes:

* `443/TCP` through host HTTPS reverse proxy for the web console.
* Configured dynamic DB gateway ports, default `16000-17999/TCP`, only to approved client networks.

The following services must remain private:

* Docker Nginx listener on `127.0.0.1:18081`;
* PostgreSQL;
* Redis;
* Laravel PHP-FPM;
* internal gateway API endpoints.

Internal gateway API routes are restricted to the Docker network and authenticated with an internal token.

## Persistence

Persistent Docker volumes store:

* PostgreSQL control-plane data;
* Redis data;
* Laravel storage;
* DB gateway logs.

`deploy/.env` contains the Laravel `APP_KEY`. It must be retained securely with backups because encrypted MFA secrets cannot be restored without it.

## Extension boundary

SAG DB Access Gateway CE is a focused DB access component.

The full Security Access Gateway platform may add:

* multi-user RBAC;
* resource-level access policies;
* approval workflows;
* enterprise identity integration;
* managed credentials and Vault;
* SSH, RDP, web access, and session recordings;
* Incident Manager and SIEM integrations.
