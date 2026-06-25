<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbAccessSession extends Model
{
    use HasFactory;

    protected $table = 'db_access_sessions';

    protected $fillable = [
        'public_id',
        'resource_id',
        'owner_user_id',
        'created_by_user_id',
        'terminated_by_user_id',
        'mode',
        'status',
        'gateway_host',
        'gateway_port',
        'gateway_username',
        'allowed_source_cidr',
        'target_db_username',
        'query_audit_enabled',
        'expires_at',
        'started_at',
        'ended_at',
        'terminated_at',
        'last_activity_at',
        'termination_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gateway_port' => 'integer',
            'query_audit_enabled' => 'boolean',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(DatabaseResource::class, 'resource_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by_user_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(DbConnection::class, 'session_id');
    }

    public function queryEvents(): HasMany
    {
        return $this->hasMany(DbQueryEvent::class, 'session_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'session_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(DbAccessOperation::class, 'session_id');
    }
}
