<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_totp_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_service_account' => 'boolean',
            'last_login_at' => 'datetime',
            'mfa_totp_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_last_used_at' => 'datetime',
            'mfa_reset_at' => 'datetime',
            'mfa_recovery_codes' => 'array',
        ];
    }

    public function ownedDbSessions(): HasMany
    {
        return $this->hasMany(DbAccessSession::class, 'owner_user_id');
    }

    public function createdDbResources(): HasMany
    {
        return $this->hasMany(DatabaseResource::class, 'created_by_user_id');
    }
}
