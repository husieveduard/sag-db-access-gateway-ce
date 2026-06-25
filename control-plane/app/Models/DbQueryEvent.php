<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbQueryEvent extends Model
{
    use HasFactory;

    protected $table = 'db_query_events';

    protected $fillable = [
        'query_uid',
        'connection_id',
        'session_id',
        'resource_id',
        'owner_user_id',
        'event_sequence',
        'statement_type',
        'risk_level',
        'risk_reason',
        'sql_text',
        'sql_hash',
        'sql_redacted',
        'query_status',
        'duration_ms',
        'rows_affected',
        'error_code',
        'error_message',
        'occurred_at',
        'ended_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_sequence' => 'integer',
            'sql_redacted' => 'boolean',
            'duration_ms' => 'integer',
            'rows_affected' => 'integer',
            'occurred_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DbConnection::class, 'connection_id');
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
}
