<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertDeliveryService;
use App\Services\Alerts\AlertEngine;
use Illuminate\Console\Command;

class SagProcessAlerts extends Command
{
    protected $signature = 'sag:process-alerts {--limit=200}';
    protected $description = 'Evaluate audit event rules and deliver alert notifications';

    public function handle(AlertEngine $engine, AlertDeliveryService $deliveries): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $processed = $engine->process($limit);
        $sent = $deliveries->deliver();
        $this->info("Audit events processed: {$processed}; deliveries attempted: {$sent}");
        return self::SUCCESS;
    }
}
