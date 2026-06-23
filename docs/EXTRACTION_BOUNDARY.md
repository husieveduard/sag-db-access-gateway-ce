# CE Extraction Boundary

The public CE repository is not a mirror of Full SAG.

## Included

- Go DB Gateway;
- DB resource management;
- temporary, persistent and service DB sessions;
- local users and roles;
- source IP/CIDR restrictions;
- DB connection audit;
- SQL audit and risk classification;
- manual termination;
- TTL cleanup;
- Docker Compose runtime.

## Explicitly excluded

- SSH access;
- RDP access;
- web access and Guacamole;
- MFA;
- LDAP, Active Directory and SSO;
- approval workflows;
- Vault and managed credential checkout;
- session recordings;
- Incident Manager;
- internal company integrations.

## Extraction principle

The public CE implementation uses dedicated DB-only controllers, routes and views.

Do not copy generic Full SAG controllers when they contain SSH, RDP, web, MFA, Vault or approval logic.
