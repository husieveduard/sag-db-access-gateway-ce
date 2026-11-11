<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GatewayTemporaryCredential extends Model
{
    protected $table = 'gateway_temporary_credentials';
    public $timestamps = false;

    protected $guarded = ['*'];
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DbAccessSession::class, 'session_id');
    }
}
