<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'created_by_user_id', 'provider', 'name', 'environment', 'status', 'external_merchant_id', 'settings', 'access_token_encrypted', 'refresh_token_encrypted', 'token_expires_at', 'connected_at', 'last_health_check_at', 'last_synced_at', 'sync_error', 'sync_summary', 'disconnected_at'])]
class PosIntegration extends Model
{
    use HasUlids;

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function toastRestaurants(): HasMany
    {
        return $this->hasMany(ToastRestaurantConnection::class, 'integration_id');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_summary' => 'array',
            'disconnected_at' => 'datetime',
        ];
    }
}
