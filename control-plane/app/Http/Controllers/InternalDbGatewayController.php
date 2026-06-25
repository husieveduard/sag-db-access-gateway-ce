<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\DbAccessSession;
use App\Models\DbConnection;
use App\Models\DbQueryEvent;
use App\Models\DatabaseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InternalDbGatewayController extends Controller
{
    public function connectionStarted(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfInvalidInternalToken($request)) {
            return $deny;
        }

        $data = $request->validate([
            'session_uid' => ['required', 'string', 'max:80'],
            'connection_uid' => ['required', 'string', 'max:80'],
            'client_ip' => ['nullable', 'string', 'max:128'],
            'client_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_engine' => ['required', 'string', 'max:40'],
            'db_name' => ['nullable', 'string', 'max:160'],
            'db_username' => ['nullable', 'string', 'max:160'],
            'target_host' => ['nullable', 'string', 'max:253'],
            'target_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $this->findSession($data['session_uid']);

        if ($session->expires_at?->isPast()) {
            return response()->json([
                'ok' => false,
                'error' => 'session_expired',
            ], 410);
        }

        if (!in_array($session->status, ['created', 'starting', 'started'], true)) {
            return response()->json([
                'ok' => false,
                'error' => 'session_not_allowed',
                'status' => $session->status,
            ], 409);
        }

        $resource = $session->resource;
        $engine = $this->normalizeEngine($data['db_engine']);

        if ($engine !== $this->normalizeEngine($resource->engine)) {
            return response()->json([
                'ok' => false,
                'error' => 'db_engine_mismatch',
                'incoming_engine' => $engine,
                'expected_engine' => $resource->engine,
            ], 409);
        }

        $connection = DbConnection::query()
            ->where('public_id', $data['connection_uid'])
            ->first();

        if ($connection && $connection->session_id !== $session->id) {
            return response()->json([
                'ok' => false,
                'error' => 'connection_session_mismatch',
            ], 409);
        }

        $attributes = [
            'session_id' => $session->id,
            'resource_id' => $resource->id,
            'owner_user_id' => $session->owner_user_id,
            'engine' => $engine,
            'client_address' => $data['client_ip'] ?? null,
            'client_port' => $data['client_port'] ?? null,
            'target_host' => $resource->target_host,
            'target_port' => $resource->target_port,
            'target_database' => $data['db_name'] ?? $resource->target_database,
            'db_username' => $data['db_username'] ?? $session->target_db_username,
            'status' => 'opened',
            'opened_at' => $connection?->opened_at ?? now(),
            'metadata' => [
                'gateway' => $data['metadata'] ?? [],
                'reported_target_host' => $data['target_host'] ?? null,
                'reported_target_port' => $data['target_port'] ?? null,
            ],
        ];

        if ($connection) {
            $connection->update($attributes);
        } else {
            $connection = DbConnection::query()->create(
                ['public_id' => $data['connection_uid']] + $attributes
            );
        }

        $session->forceFill([
            'status' => 'started',
            'started_at' => $session->started_at ?? now(),
            'last_activity_at' => now(),
        ])->save();

        $this->writeAudit(
            session: $session,
            connection: $connection,
            eventType: 'access.db.connection.started',
            severity: 'info',
            eventData: [
                'connection_uid' => $connection->public_id,
                'client_ip' => $connection->client_address,
                'db_engine' => $connection->engine,
                'db_username' => $connection->db_username,
            ],
        );

        return response()->json([
            'ok' => true,
            'connection_id' => $connection->id,
        ]);
    }

    public function connectionDenied(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfInvalidInternalToken($request)) {
            return $deny;
        }

        $data = $request->validate([
            'session_uid' => ['required', 'string', 'max:80'],
            'client_ip' => ['nullable', 'string', 'max:128'],
            'client_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_engine' => ['nullable', 'string', 'max:40'],
            'db_name' => ['nullable', 'string', 'max:160'],
            'db_username' => ['nullable', 'string', 'max:160'],
            'allowed_source_cidr' => ['nullable', 'string', 'max:128'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $this->findSession($data['session_uid']);
        $resource = $session->resource;
        $engine = $this->normalizeEngine($data['db_engine'] ?? $resource->engine);

        if ($engine !== $this->normalizeEngine($resource->engine)) {
            return response()->json([
                'ok' => false,
                'error' => 'db_engine_mismatch',
            ], 409);
        }

        $session->forceFill([
            'last_activity_at' => now(),
        ])->save();

        $this->writeAudit(
            session: $session,
            connection: null,
            eventType: 'access.db.connection.denied',
            severity: 'high',
            eventData: [
                'client_ip' => $data['client_ip'] ?? null,
                'client_port' => $data['client_port'] ?? null,
                'db_engine' => $engine,
                'db_name' => $data['db_name'] ?? null,
                'db_username' => $data['db_username'] ?? null,
                'allowed_source_cidr' => $data['allowed_source_cidr']
                    ?? $session->allowed_source_cidr,
                'reason_code' => $data['reason_code'] ?? 'gateway_denied',
                'metadata' => $data['metadata'] ?? [],
            ],
        );

        return response()->json([
            'ok' => true,
            'status' => 'audited',
        ], 202);
    }

    public function connectionEnded(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfInvalidInternalToken($request)) {
            return $deny;
        }

        $data = $request->validate([
            'connection_uid' => ['required', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:40'],
            'bytes_client_to_db' => ['nullable', 'integer', 'min:0'],
            'bytes_db_to_client' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $connection = DbConnection::query()
            ->with('session')
            ->where('public_id', $data['connection_uid'])
            ->first();

        if (!$connection) {
            return response()->json([
                'ok' => false,
                'error' => 'connection_not_found',
            ], 404);
        }

        $status = strtolower((string) ($data['status'] ?? 'ended'));

        $connection->update([
            'status' => in_array($status, ['failed', 'denied'], true)
                ? $status
                : 'closed',
            'closed_at' => now(),
            'close_reason' => Str::limit($status, 255, ''),
            'bytes_in' => $data['bytes_client_to_db'] ?? $connection->bytes_in,
            'bytes_out' => $data['bytes_db_to_client'] ?? $connection->bytes_out,
            'metadata' => array_merge(
                $connection->metadata ?? [],
                ['ended' => $data['metadata'] ?? []],
            ),
        ]);

        $connection->session->forceFill([
            'last_activity_at' => now(),
        ])->save();

        $this->writeAudit(
            session: $connection->session,
            connection: $connection,
            eventType: 'access.db.connection.ended',
            severity: 'info',
            eventData: [
                'connection_uid' => $connection->public_id,
                'status' => $connection->status,
                'bytes_in' => $connection->bytes_in,
                'bytes_out' => $connection->bytes_out,
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function queryEvent(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfInvalidInternalToken($request)) {
            return $deny;
        }

        $data = $request->validate([
            'session_uid' => ['required', 'string', 'max:80'],
            'connection_uid' => ['required', 'string', 'max:80'],
            'query_uid' => ['required', 'string', 'max:80'],
            'db_engine' => ['nullable', 'string', 'max:40'],
            'protocol_mode' => ['nullable', 'string', 'max:80'],
            'statement_name' => ['nullable', 'string', 'max:255'],
            'portal_name' => ['nullable', 'string', 'max:255'],
            'query_type' => ['nullable', 'string', 'max:80'],
            'query_text_masked' => ['nullable', 'string'],
            'normalized_sql' => ['nullable', 'string'],
            'query_hash' => ['nullable', 'string', 'max:64'],
            'risk_level' => ['nullable', 'string', 'max:40'],
            'risk_reasons' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:40'],
            'success' => ['nullable', 'boolean'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'error_code' => ['nullable', 'string', 'max:80'],
            'error_message_masked' => ['nullable', 'string'],
            'command_tag' => ['nullable', 'string', 'max:255'],
            'rows_affected' => ['nullable', 'integer'],
            'rows_returned' => ['nullable', 'integer'],
            'database_name' => ['nullable', 'string', 'max:160'],
            'schema_name' => ['nullable', 'string', 'max:160'],
            'object_name' => ['nullable', 'string', 'max:255'],
            'source_ip' => ['nullable', 'string', 'max:128'],
            'client_app' => ['nullable', 'string', 'max:160'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $this->findSession($data['session_uid']);

        $connection = DbConnection::query()
            ->where('public_id', $data['connection_uid'])
            ->first();

        if (!$connection) {
            return response()->json([
                'ok' => false,
                'error' => 'connection_not_found',
            ], 404);
        }

        if ($connection->session_id !== $session->id) {
            return response()->json([
                'ok' => false,
                'error' => 'connection_session_mismatch',
            ], 409);
        }

        $resource = $session->resource;
        $engine = $this->normalizeEngine($data['db_engine'] ?? $resource->engine);

        if ($engine !== $this->normalizeEngine($resource->engine)) {
            return response()->json([
                'ok' => false,
                'error' => 'db_engine_mismatch',
            ], 409);
        }

        $query = DbQueryEvent::query()
            ->where('query_uid', $data['query_uid'])
            ->first();

        if ($query && $query->connection_id !== $connection->id) {
            return response()->json([
                'ok' => false,
                'error' => 'query_connection_mismatch',
            ], 409);
        }

        $risk = $this->normalizeRisk($data['risk_level'] ?? null);
        $reason = Str::limit(
            implode(',', array_map('strval', $data['risk_reasons'] ?? [])),
            128,
            '',
        );

        $queryStatus = $this->normalizeQueryStatus(
            $data['status'] ?? null,
            $data['success'] ?? null,
        );

        $queryAttributes = [
            'connection_id' => $connection->id,
            'session_id' => $session->id,
            'resource_id' => $resource->id,
            'owner_user_id' => $session->owner_user_id,
            'statement_type' => Str::upper($data['query_type'] ?? 'UNKNOWN'),
            'risk_level' => $risk,
            'risk_reason' => $reason ?: null,
            'sql_text' => $data['query_text_masked']
                ?? $data['normalized_sql']
                ?? null,
            'sql_hash' => $data['query_hash'] ?? null,
            'sql_redacted' => !empty($data['query_text_masked']),
            'query_status' => $queryStatus,
            'duration_ms' => $data['duration_ms'] ?? null,
            'rows_affected' => $data['rows_affected'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message_masked'] ?? null,
            'occurred_at' => $this->eventTimestamp(
                $data['ended_at'] ?? $data['started_at'] ?? null
            ),
            'metadata' => [
                'protocol_mode' => $data['protocol_mode'] ?? null,
                'statement_name' => $data['statement_name'] ?? null,
                'portal_name' => $data['portal_name'] ?? null,
                'command_tag' => $data['command_tag'] ?? null,
                'rows_returned' => $data['rows_returned'] ?? null,
                'database_name' => $data['database_name'] ?? null,
                'schema_name' => $data['schema_name'] ?? null,
                'object_name' => $data['object_name'] ?? null,
                'source_ip' => $data['source_ip'] ?? null,
                'client_app' => $data['client_app'] ?? null,
                'gateway' => $data['metadata'] ?? [],
            ],
        ];

        if (!empty($data['ended_at'])) {
            $queryAttributes['ended_at'] = $this->eventTimestamp(
                $data['ended_at']
            );
        }

        if ($query) {
            $query->update($queryAttributes);
        } else {
            $query = DbQueryEvent::query()->create(
                ['query_uid' => $data['query_uid']] + $queryAttributes
            );
        }

        $session->forceFill([
            'last_activity_at' => now(),
        ])->save();

        if ($risk === 'high' || $queryStatus === 'failed') {
            $this->writeAudit(
                session: $session,
                connection: $connection,
                eventType: $risk === 'high'
                    ? 'access.db.query.high_risk'
                    : 'access.db.query.failed',
                severity: $risk === 'high' ? 'high' : 'medium',
                eventData: [
                    'query_uid' => $query->query_uid,
                    'statement_type' => $query->statement_type,
                    'risk_level' => $risk,
                    'query_status' => $queryStatus,
                    'sql_hash' => $query->sql_hash,
                ],
            );
        }

        return response()->json([
            'ok' => true,
            'query_event_id' => $query->id,
        ]);
    }


    public function sessionStatus(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfInvalidInternalToken($request)) {
            return $deny;
        }

        $data = $request->validate([
            'session_uid' => ['required', 'string', 'max:80'],
        ]);

        $session = DbAccessSession::query()
            ->with('resource')
            ->where('public_id', $data['session_uid'])
            ->first();

        if (!$session) {
            return response()->json([
                'ok' => true,
                'allowed' => false,
                'reason' => 'session_not_found',
            ]);
        }

        $reason = null;

        if (!$session->resource?->is_active) {
            $reason = 'resource_inactive';
        } elseif (!in_array($session->status, ['created', 'starting', 'started'], true)) {
            $reason = 'session_not_allowed';
        } elseif ($session->expires_at?->isPast()) {
            $reason = 'session_expired';
        }

        return response()->json([
            'ok' => true,
            'allowed' => $reason === null,
            'reason' => $reason,
            'session_status' => $session->status,
            'db_engine' => $session->resource?->engine,
            'allowed_source_cidr' => $session->allowed_source_cidr,
        ]);
    }

    private function findSession(string $publicId): DbAccessSession
    {
        $session = DbAccessSession::query()
            ->with('resource')
            ->where('public_id', $publicId)
            ->first();

        abort_unless($session, 404, 'DB access session not found.');

        return $session;
    }

    private function denyIfInvalidInternalToken(Request $request): ?JsonResponse
    {
        $configuredToken = (string) config('sag.internal_token', '');
        $incomingToken = (string) $request->header('X-SAG-Internal-Token', '');

        if ($configuredToken === '') {
            return response()->json([
                'ok' => false,
                'error' => 'internal_token_not_configured',
            ], 503);
        }

        if ($incomingToken === '' || !hash_equals($configuredToken, $incomingToken)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_internal_token',
            ], 401);
        }

        return null;
    }

    private function writeAudit(
        DbAccessSession $session,
        ?DbConnection $connection,
        string $eventType,
        string $severity,
        array $eventData,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $session->owner_user_id,
            'resource_id' => $session->resource_id,
            'session_id' => $session->id,
            'db_connection_id' => $connection?->id,
            'category' => 'db_access',
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $connection?->client_address,
            'event_data' => $eventData,
            'occurred_at' => now(),
        ]);
    }

    private function normalizeEngine(string $engine): string
    {
        return match (strtolower(trim($engine))) {
            'postgres', 'postgresql' => 'postgresql',
            'mysql' => 'mysql',
            'mssql', 'sqlserver', 'sql_server' => 'mssql',
            default => strtolower(trim($engine)),
        };
    }

    private function normalizeRisk(?string $risk): string
    {
        return match (strtolower((string) $risk)) {
            'medium' => 'medium',
            'high', 'critical' => 'high',
            default => 'low',
        };
    }

    private function normalizeQueryStatus(?string $status, ?bool $success): string
    {
        $status = strtolower((string) $status);

        if ($success === false || str_contains($status, 'fail') || str_contains($status, 'error')) {
            return 'failed';
        }

        if (str_contains($status, 'deny')) {
            return 'denied';
        }

        if (
            str_contains($status, 'interrupt')
            || str_contains($status, 'terminat')
            || str_contains($status, 'expire')
        ) {
            return 'interrupted';
        }

        if ($status === 'running') {
            return 'running';
        }

        return 'executed';
    }

    private function eventTimestamp(?string $value): Carbon
    {
        return $value ? Carbon::parse($value) : now();
    }
}
