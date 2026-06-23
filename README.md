# SAG DB Access Gateway CE

Community Edition of the Security Access Gateway database access module.

> Status: pre-release.

## Included

- PostgreSQL and MySQL support;
- experimental Microsoft SQL Server support;
- temporary, persistent and service DB sessions;
- source IP/CIDR restrictions;
- connection lifecycle audit;
- SQL query audit and risk classification;
- manual session termination;
- local users and roles;
- Docker Compose deployment.

## Not included

- SSH, RDP and web access;
- Guacamole;
- MFA;
- LDAP, Active Directory and SSO;
- approval workflows;
- credential vault;
- Incident Manager integrations.

## Security

Never commit `.env`, credentials, private keys, production DB targets, logs or database dumps.
