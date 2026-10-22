<?php

namespace App\Services\Settings;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class DisplayClock
{
    private ?string $timezone = null;

    // UI only. Do not change app.timezone (UTC), DB storage, TTL or scheduling.
    public function timezone(): string
    {
        return $this->timezone ??= (
            DB::table('ce_display_settings')->where('id', 1)->value('display_timezone') ?: 'UTC'
        );
    }

    public function change(string $timezone, int $actorId): void
    {
        DB::table('ce_display_settings')->where('id', 1)->update([
            'display_timezone' => $timezone,
            'updated_by_user_id' => $actorId,
            'updated_at' => now(),
        ]);
        $this->timezone = $timezone;
    }

    public function format(DateTimeInterface|string|null $value, string $format = 'd.m.Y H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->setTimezone($this->timezone())->format($format);
    }
}
