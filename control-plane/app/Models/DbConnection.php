<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbConnection extends Model
{
    use HasFactory;

    protected $table = 'db_connections';

    protected $fillable = [
        'public_id',
        'session_id',
        'resource_id',
        'owner_user_id',
        'engine',
        'client_address',
        'client_port',
        'target_host',
        'target_port',
        'target_database',
        'db_username',
        'status',
        'opened_at',
        'closed_at',
        'close_reason',
        'bytes_in',
        'bytes_out',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'client_port' => 'integer',
            'target_port' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DbAccessSession::class, 'session_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(DatabaseResource::class, 'resource_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function queryEvents(): HasMany
    {
        return $this->hasMany(DbQueryEvent::class, 'connection_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'db_connection_id');
    }
}
