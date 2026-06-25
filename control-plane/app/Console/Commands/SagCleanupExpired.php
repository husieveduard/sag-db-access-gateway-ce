<?php

namespace App\Console\Commands;

use App\Models\DbAccessSession;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class SagCleanupExpired extends Command
{
    protected $signature = 'sag:cleanup-expired
        {--dry-run : Show sessions that would be expired without changing data}';

    protected $description = 'Expire temporary DB sessions and stop their gateway processes';

    public function handle(DbGatewayLifecycleService $lifecycle): int
    {
        $sessions = DbAccessSession::query()
            ->with('resource')
            ->whereIn('status', ['created', 'starting', 'started'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('No expired DB sessions found.');

            return self::SUCCESS;
        }

        $stats = [
            'found' => $sessions->count(),
            'expired' => 0,
            'failed' => 0,
        ];

        foreach ($sessions as $session) {
            if ($this->option('dry-run')) {
                $this->line(
                    "Would expire {$session->public_id} "
                    ."({$session->expires_at?->toDateTimeString()})"
                );

                continue;
            }

            try {
                $result = $lifecycle->expire($session);

                $stats['expired']++;

                $this->line(
                    "Expired {$result['session_uid']} "
                    ."gateway={$result['gateway']['state']} "
                    ."connections={$result['activity']['closed_connections']} "
                    ."queries={$result['activity']['interrupted_queries']}"
                );
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->error(
                    "Failed {$session->public_id}: {$e->getMessage()}"
                );
            }
        }

        $this->table(
            ['found', 'expired', 'failed'],
            [[
                $stats['found'],
                $stats['expired'],
                $stats['failed'],
            ]],
        );

        return $stats['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
