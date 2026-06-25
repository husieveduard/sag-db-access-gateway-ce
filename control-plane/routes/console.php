<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


if (config('sag.db_gateway.maintenance_schedule_enabled', true)) {
    Schedule::command('sag:cleanup-expired')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
