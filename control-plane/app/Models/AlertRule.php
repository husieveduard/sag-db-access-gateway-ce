<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = ['code', 'event_type', 'title', 'severity', 'cooldown_minutes', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'cooldown_minutes' => 'integer'];
    }
}
