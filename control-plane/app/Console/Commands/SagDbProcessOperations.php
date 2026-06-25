<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\DbAccessOperation;
use App\Models\DbAccessSession;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SagDbProcessOperations extends Command
{
    protected $signature = 'sag:db-process-operations
        {--once : Process available operations once and exit}
        {--loop : Keep the worker running}
        {--sleep-ms=1000 : Idle delay for loop mode}
        {--limit=5 : Maximum operations per pass}';

    protected $description = 'Process queued SAG DB Access Gateway CE lifecycle operations';

    public function handle(DbGatewayLifecycleService $lifecycle): int
    {
        $loop = (bool) $this->option('loop');
        $once = (bool) $this->option('once');

        if ($loop && $once) {
            $this->error('Use either --once or --loop, not both.');

            return self::FAILURE;
        }

        $limit = min(50, max(1, (int) $this->option('limit')));
        $sleepMs = min(10000, max(250, (int) $this->option('sleep-ms')));

        do {
            $processed = $this->processPass($lifecycle, $limit);

            if (!$loop) {
                $this->line("Processed {$processed} operation(s).");

                return self::SUCCESS;
            }

            if ($processed === 0) {
                usleep($sleepMs * 1000);
            }
        } while (true);
    }

    private function processPass(
        DbGatewayLifecycleService $lifecycle,
        int $limit,
    ): int {
        $processed = 0;

        while ($processed < $limit) {
            $operation = $this->claimNextOperation();

            if (!$operation) {
                break;
            }

            $processed++;
            $this->processOperation($operation, $lifecycle);
        }

        return $processed;
    }

    private function claimNextOperation(): ?DbAccessOperation
    {
        return DB::transaction(function (): ?DbAccessOperation {
            $operation = DbAccessOperation::query()
                ->where('status', 'queued')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (!$operation) {
                return null;
            }

            $operation->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'error_message' => null,
            ])->save();

            return $operation->fresh();
        });
    }

    private function processOperation(
        DbAccessOperation $operation,
        DbGatewayLifecycleService $lifecycle,
    ): void {
        $session = null;

        try {
            $session = DbAccessSession::query()
                ->with('resource')
                ->findOrFail($operation->session_id);

            $result = match ($operation->operation_type) {
                'start_session' => $lifecycle->start($session),

                'terminate_session' => $lifecycle->terminate(
                    $session,
                    $operation->requested_by_user_id,
                    $operation->reason ?: 'manual_revoke',
                ),

                default => throw new RuntimeException(
                    "Unsupported DB access operation: {$operation->operation_type}"
                ),
            };

            $operation->forceFill([
                'status' => 'succeeded',
                'result_payload' => $result,
                'completed_at' => now(),
            ])->save();

            $this->writeAudit(
                operation: $operation,
                session: $session,
                eventType: 'access.db.operation.succeeded',
                severity: 'info',
                eventData: [
                    'operation_uid' => $operation->operation_uid,
                    'operation_type' => $operation->operation_type,
                    'result' => $result,
                ],
            );

            $this->info(
                "Succeeded: {$operation->operation_uid} {$operation->operation_type}"
            );
        } catch (Throwable $exception) {
            $message = Str::limit(
                trim($exception->getMessage()),
                2000,
                '',
            );

            $operation->forceFill([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ])->save();

            if ($session) {
                $this->writeAudit(
                    operation: $operation,
                    session: $session,
                    eventType: 'access.db.operation.failed',
                    severity: 'high',
                    eventData: [
                        'operation_uid' => $operation->operation_uid,
                        'operation_type' => $operation->operation_type,
                        'error' => $message,
                    ],
                );
            }

            $this->error(
                "Failed: {$operation->operation_uid} {$operation->operation_type}: {$message}"
            );
        }
    }

    private function writeAudit(
        DbAccessOperation $operation,
        DbAccessSession $session,
        string $eventType,
        string $severity,
        array $eventData,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $operation->requested_by_user_id,
            'resource_id' => $session->resource_id,
            'session_id' => $session->id,
            'category' => 'db_access',
            'event_type' => $eventType,
            'severity' => $severity,
            'event_data' => $eventData,
            'occurred_at' => now(),
        ]);
    }
}
