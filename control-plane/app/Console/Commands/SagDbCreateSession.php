<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SagDbCreateSession extends Command
{
    protected $signature = 'sag:db-create-session
        {resource : Database resource ID or name}
        {--owner-user-id= : Owner user ID}
        {--mode=temporary : temporary, persistent or service}
        {--ttl-seconds= : Required for temporary sessions}
        {--source-cidr= : Allowed source IPv4/IPv6 address or CIDR}
        {--db-username= : Target DB username for audit only}
        {--start : Start DB Gateway immediately}';

    protected $description = 'Create a CE DB access session without storing DB credentials';

    public function handle(DbGatewayLifecycleService $lifecycle): int
    {
        $resource = $this->findResource((string) $this->argument('resource'));

        if (!$resource || !$resource->is_active) {
            $this->error('Active database resource was not found.');

            return self::FAILURE;
        }

        $ownerId = (int) $this->option('owner-user-id');
        $owner = User::query()->find($ownerId);

        if (!$owner || !$owner->is_active) {
            $this->error('Active owner user was not found.');

            return self::FAILURE;
        }

        $mode = strtolower(trim((string) $this->option('mode')));

        if (!in_array($mode, ['temporary', 'persistent', 'service'], true)) {
            $this->error('Mode must be temporary, persistent or service.');

            return self::FAILURE;
        }

        if ($mode === 'service' && !$owner->is_service_account) {
            $this->error('Service sessions require a service-account owner.');

            return self::FAILURE;
        }

        $sourceCidr = trim((string) $this->option('source-cidr'));

        if (!$this->isValidIpOrCidr($sourceCidr)) {
            $this->error('A valid --source-cidr is required.');

            return self::FAILURE;
        }

        $ttlSeconds = null;

        if ($mode === 'temporary') {
            $ttlSeconds = (int) $this->option('ttl-seconds');

            if ($ttlSeconds < 1) {
                $this->error('Temporary sessions require --ttl-seconds greater than zero.');

                return self::FAILURE;
            }
        }

        $dbUsername = trim((string) $this->option('db-username'));

        try {
            $session = DB::transaction(function () use (
                $resource,
                $owner,
                $mode,
                $ttlSeconds,
                $sourceCidr,
                $dbUsername,
            ): DbAccessSession {
                do {
                    $publicId = 'SAG-CE-DB-'
                        .strtoupper((string) $resource->engine)
                        .'-'.now()->format('Ymd-His')
                        .'-'.Str::upper(Str::random(8));
                } while (
                    DbAccessSession::query()
                        ->where('public_id', $publicId)
                        ->exists()
                );

                $session = new DbAccessSession();

                $session->forceFill([
                    'public_id' => $publicId,
                    'resource_id' => $resource->id,
                    'owner_user_id' => $owner->id,
                    'created_by_user_id' => $owner->id,
                    'mode' => $mode,
                    'status' => 'created',
                    'allowed_source_cidr' => $sourceCidr,
                    'target_db_username' => $dbUsername ?: null,
                    'query_audit_enabled' => (bool) $resource->query_audit_enabled,
                    'expires_at' => $ttlSeconds
                        ? now()->addSeconds($ttlSeconds)
                        : null,
                    'metadata' => [
                        'db' => [
                            'engine' => $resource->engine,
                            'credential_storage' => 'none',
                            'created_via' => 'sag:db-create-session',
                        ],
                    ],
                ])->save();

                $audit = new AuditEvent();

                $audit->forceFill([
                    'actor_user_id' => $owner->id,
                    'resource_id' => $resource->id,
                    'session_id' => $session->id,
                    'category' => 'db_access',
                    'event_type' => 'access.db.session.created',
                    'severity' => 'info',
                    'event_data' => [
                        'mode' => $mode,
                        'ttl_seconds' => $ttlSeconds,
                        'allowed_source_cidr' => $sourceCidr,
                        'db_engine' => $resource->engine,
                        'created_via' => 'artisan',
                    ],
                    'occurred_at' => now(),
                ])->save();

                return $session;
            });

            $this->line("SESSION_UID={$session->public_id}");

            if (!$this->option('start')) {
                return self::SUCCESS;
            }

            $result = $lifecycle->start($session);

            $this->info('DB Gateway started.');
            $this->line("GATEWAY_HOST={$result['gateway_host']}");
            $this->line("GATEWAY_PORT={$result['gateway_port']}");
            $this->line("GATEWAY_PID={$result['gateway_pid']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function findResource(string $identifier): ?DatabaseResource
    {
        return DatabaseResource::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('name', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }

    private function isValidIpOrCidr(string $value): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        if (!ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? 128
            : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
