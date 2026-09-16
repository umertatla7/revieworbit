<?php

namespace App\Models;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessInvitation;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\PlatformUserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'avatar_path', 'password', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    public function businessMemberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    public function platformRoles(): HasMany
    {
        return $this->hasMany(PlatformUserRole::class);
    }

    public function sentBusinessInvitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class, 'invited_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
