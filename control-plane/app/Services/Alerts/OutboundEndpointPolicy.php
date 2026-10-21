<?php

namespace App\Services\Alerts;

use RuntimeException;

/**
 * Only send to explicitly configured HTTPS destinations. Outbound firewall
 * restrictions and trusted DNS remain necessary against DNS rebinding.
 */
class OutboundEndpointPolicy
{
    public function assertAllowed(string $channel, string $url, ?string $allowedHosts = null): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('invalid_outbound_endpoint');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !preg_match('/^[a-z0-9][a-z0-9.-]{0,251}[a-z0-9]$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('invalid_outbound_host');
        }

        if ($channel === 'slack'
            && in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true)) {
            return;
        }

        // Teams Workflows, self-hosted Talk and generic webhooks require
        // an exact administrator-maintained egress hostname allowlist.
        $approved = array_map(
            fn ($value) => strtolower(trim($value)),
            explode(',', (string) ($allowedHosts ?? config('sag.alerts.allowed_hosts', '')))
        );
        if (!in_array($host, $approved, true)) {
            throw new RuntimeException('unapproved_notification_host');
        }
    }
}
