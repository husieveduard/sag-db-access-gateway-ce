<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbAccessOperation extends Model
{
    use HasFactory;

    protected $table = 'db_access_operations';

    protected $fillable = [
        'operation_uid',
        'session_id',
        'requested_by_user_id',
        'operation_type',
        'status',
        'reason',
        'request_payload',
        'result_payload',
        'error_message',
        'requested_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'request_payload' => 'array',
            'result_payload' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DbAccessSession::class, 'session_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
