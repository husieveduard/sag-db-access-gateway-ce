<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatabaseResource extends Model
{
    use HasFactory;

    protected $table = 'database_resources';

    protected $fillable = [
        'name',
        'description',
        'engine',
        'target_host',
        'target_port',
        'target_database',
        'target_tls_mode',
        'auth_mode',
        'query_audit_enabled',
        'is_active',
        'target_options',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'target_port' => 'integer',
            'query_audit_enabled' => 'boolean',
            'is_active' => 'boolean',
            'target_options' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DbAccessSession::class, 'resource_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(DbConnection::class, 'resource_id');
    }

    public function queryEvents(): HasMany
    {
        return $this->hasMany(DbQueryEvent::class, 'resource_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'resource_id');
    }
}
