<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'integration_id', 'location_id', 'environment', 'restaurant_guid', 'management_group_guid', 'restaurant_name', 'location_name', 'external_group_ref', 'external_restaurant_ref', 'status', 'connected_at', 'last_synced_at', 'disconnected_at', 'sync_error', 'sync_summary'])]
class ToastRestaurantConnection extends Model
{
    use HasUlids;

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

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'sync_summary' => 'array',
        ];
    }
}
