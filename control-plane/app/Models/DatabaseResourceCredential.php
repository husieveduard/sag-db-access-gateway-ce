<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseResourceCredential extends Model
{
    protected $table = 'database_resource_credentials';

    protected $guarded = ['*'];

    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return ['encryption_version' => 'integer'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(DatabaseResource::class, 'resource_id');
    }
}
