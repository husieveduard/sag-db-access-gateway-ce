# Alert Engine v1.1: outbound notification safety and deployment

## Channels

- Telegram: Bot API; configure bot token and destination chat.
- Slack: Incoming Webhook on hooks.slack.com or hooks.slack-gov.com.
- Microsoft Teams: Power Automate Workflows webhook; validate the flow's actual accepted JSON schema and post-to-channel action using a real test flow before production.
- Nextcloud Talk: OCS chat API, with a dedicated user and app password; only post to an approved room.
- Email: configure a real SMTP transport; the Docker default MAIL_MAILER=log does not deliver email.
- HTTPS webhook: generic JSON payload of safe alert metadata only.

All channel secrets must be stored outside Git and rotated according to organizational policy. Do not put webhook URLs with secrets in logs, tickets or screenshots.

## Destination policy

Set SAG_CE_ALERT_ALLOWED_HOSTS to an exact comma-separated list of HTTPS hostnames for Teams Workflows, Nextcloud Talk and generic webhooks. Slack uses a limited built-in host list. Telegram uses the fixed official Bot API endpoint. HTTP redirects are disabled.

This is defense in depth, **not complete SSRF protection**: DNS rebinding, domain resolution to private IP space, proxy settings and container egress remain relevant. Production deployments must use firewall/egress proxy allow rules for verified destinations, and should restrict and validate DNS resolution at connect time.

## Operational acceptance

- Test one channel at a time on the Alert Health page, signed in as Superadmin with MFA.
- Confirm delivered messages in the destination system, not just HTTP status.
- Test non-2xx, HTTP 200 with Telegram `ok=false`, Talk OCS error, timeout, unreachable host and process crash.
- Validate delivery retry/backoff, heartbeat, alert cursor backlog and duplicate suppression.
- Configure real mail transport before marking email functional.
- Alert records contain metadata only: SQL statement text, DB credentials and raw error text must never be transmitted.

Do not claim complete SQL monitoring for MSSQL in TLS passthrough; server-side SQL Server Audit/Extended Events ingestion is separate future work.

## Updates

Dependabot proposes weekly dependency update PRs. Manually verify channel API compatibility, run all CI suites and staging contract tests before approving deployment. Production updates require explicit authorization and rollback readiness.
