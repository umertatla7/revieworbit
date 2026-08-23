<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'integration_id', 'location_id', 'customer_id', 'external_booking_id', 'external_location_id', 'external_customer_id', 'status', 'starts_at', 'ends_at', 'service_summary', 'provider_updated_at', 'synced_at'])]
class SquareAppointment extends Model
{
    use HasUlids;

    public function integration(): BelongsTo
    {
        return $this->belongsTo(PosIntegration::class, 'integration_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'service_summary' => 'array',
            'provider_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
