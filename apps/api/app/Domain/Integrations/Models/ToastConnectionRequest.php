<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'integration_id', 'location_id', 'initiated_by_user_id', 'connection_code_hash', 'connection_code_encrypted', 'status', 'expires_at', 'connected_at', 'cancelled_at'])]
class ToastConnectionRequest extends Model
{
    use HasUlids;

    protected $hidden = ['connection_code_hash', 'connection_code_encrypted'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(PosIntegration::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'connection_code_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
