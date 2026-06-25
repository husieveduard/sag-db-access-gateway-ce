<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasFactory;

    protected $table = 'audit_events';

    public $timestamps = true;

    protected $fillable = [
        'actor_user_id',
        'resource_id',
        'session_id',
        'db_connection_id',
        'category',
        'event_type',
        'severity',
        'ip_address',
        'user_agent',
        'event_data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(DatabaseResource::class, 'resource_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DbAccessSession::class, 'session_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DbConnection::class, 'db_connection_id');
    }
}
