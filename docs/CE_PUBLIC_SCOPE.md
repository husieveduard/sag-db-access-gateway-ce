# SAG DB Access Gateway CE — Public Scope

## Purpose

SAG DB Access Gateway CE is a self-hosted database access gateway for controlled, auditable access to target databases.

It is intended for small teams, internal environments, pilot deployments, and use cases where a lightweight DB access layer is preferred over a full PAM platform.

## Included

* PostgreSQL gateway support.
* MySQL gateway support.
* Experimental Microsoft SQL Server gateway support.
* Dynamic gateway port per DB session.
* Temporary sessions with configurable TTL.
* Persistent sessions for monitoring, reporting, integrations, and long-running jobs.
* Source IP/CIDR restriction.
* DB connection lifecycle audit.
* SQL query audit.
* Basic SQL risk classification.
* Manual session termination.
* Automatic expiration cleanup for TTL sessions.
* One active local CE Operator.
* Mandatory QR/TOTP MFA.
* Recovery codes.
* Local password reset and emergency MFA reset through CLI.
* Docker Compose deployment.
* HTTPS reverse-proxy deployment model.

## Session modes

### Temporary

Temporary sessions have an expiration time.

When TTL expires, the worker closes active gateway connections, interrupts active queries where possible, stops the gateway process, and records the expiration in audit history.

### Persistent

Persistent sessions do not expire automatically.

They are intended for controlled service-style use cases such as monitoring, reporting, integrations, and long-running automation. Persistent sessions remain subject to source restrictions, audit, manual termination, and resource deactivation controls.

## Operator model

CE uses a simplified operational model:

* one active local Operator;
* no self-service user registration;
* no complex role hierarchy;
* no approval workflow;
* no per-user resource grant matrix.

The Operator creates resources, creates sessions, starts or terminates gateway access, and provides approved connection parameters to DB clients.

## Explicit exclusions

The CE public release does not include:

* SSH, RDP, VNC, or web gateway access;
* Apache Guacamole;
* multi-user RBAC policy matrices;
* approval workflow;
* LDAP, Active Directory, SSO, SCIM, or federation;
* credential Vault;
* password rotation;
* managed target DB credentials;
* JIT target account provisioning;
* session recording;
* Incident Manager integration;
* proprietary company integrations;
* enterprise PAM features.

## Security expectations

CE must be deployed behind HTTPS reverse proxy.

Do not expose:

* PostgreSQL;
* Redis;
* Docker Nginx port `18081`;
* internal API endpoints;
* gateway dynamic ports to untrusted networks.

Protect gateway port range access with host firewall rules, upstream firewall rules, cloud security groups, or equivalent controls.

## Compatibility statement

Microsoft SQL Server support is experimental.

The project should be evaluated in a controlled environment before production deployment. Database protocol behavior, query capture, and termination semantics must be validated against each target DB engine and client tool used by the organization.
