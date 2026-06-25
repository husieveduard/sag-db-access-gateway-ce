<?php

namespace App\Console\Commands;

use App\Models\DbAccessSession;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class SagDbTerminateSession extends Command
{
    protected $signature = 'sag:db-terminate-session
        {session : DB session public_id or numeric database ID}
        {--actor-user-id= : User ID that initiated termination}
        {--reason=manual_revoke : Audit reason}';

    protected $description = 'Terminate DB session and force-close gateway connections';

    public function handle(DbGatewayLifecycleService $lifecycle): int
    {
        $session = $this->findSession((string) $this->argument('session'));

        if (!$session) {
            $this->error('DB access session was not found.');

            return self::FAILURE;
        }

        try {
            $result = $lifecycle->terminate(
                $session,
                $this->option('actor-user-id')
                    ? (int) $this->option('actor-user-id')
                    : null,
                (string) $this->option('reason'),
            );

            $this->info('DB session termination completed.');
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function findSession(string $identifier): ?DbAccessSession
    {
        return DbAccessSession::query()
            ->with('resource')
            ->where(function ($query) use ($identifier): void {
                $query->where('public_id', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }
}
