<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'monthly_upload_reset' => 'datetime',
            'storage_used_bytes' => 'integer',
            'storage_limit_bytes' => 'integer',
            'monthly_upload_count' => 'integer',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function storageUsagePercent(): float
    {
        if ($this->storage_limit_bytes === 0) {
            return 0.0;
        }

        return round(($this->storage_used_bytes / $this->storage_limit_bytes) * 100, 1);
    }

    public function hasStorageAvailable(int $fileSizeBytes): bool
    {
        return ($this->storage_used_bytes + $fileSizeBytes) <= $this->storage_limit_bytes;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
