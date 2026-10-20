<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    protected $fillable = [
        'rule_id', 'resource_id', 'session_id', 'owner_user_id', 'assigned_user_id',
        'last_audit_event_id', 'fingerprint', 'severity', 'status', 'summary',
        'occurrence_count', 'first_seen_at', 'last_seen_at', 'acknowledged_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'occurrence_count' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(DatabaseResource::class, 'resource_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class, 'alert_id');
    }
}
