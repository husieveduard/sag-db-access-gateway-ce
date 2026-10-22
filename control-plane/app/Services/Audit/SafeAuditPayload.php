<?php

namespace App\Services\Audit;

class SafeAuditPayload
{
    /**
     * Never render secrets, bearer tokens or raw SQL in the web audit inspector.
     * The original audit event stays unchanged for evidence preservation.
     */
    public function json(mixed $payload): string
    {
        $safe = $this->sanitize($payload, 0);
        $json = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{}' : $json;
    }

    private function sanitize(mixed $value, int $depth): mixed
    {
        if ($depth > 6) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if (++$count > 100) {
                    $result['__truncated'] = '[MORE_FIELDS]';
                    break;
                }
                $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key) ?? '');
                if (preg_match('/(password|passwd|passphrase|credential|secret|token|apikey|authorization|cookie|privatekey|recoverycode|totp|otpcode|sqltext|rawsql|normalizedsql|querytext)/', $normalized)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = $this->sanitize($child, $depth + 1);
                }
            }
            return $result;
        }

        if (is_string($value)) {
            // Values can contain SQL or secrets even if their key is innocuous.
            // Never allow unbounded payloads to reach the DOM.
            return mb_strimwidth($value, 0, 1500, '[TRUNCATED]');
        }

        return is_null($value) || is_scalar($value) ? $value : '[UNSUPPORTED]';
    }
}
