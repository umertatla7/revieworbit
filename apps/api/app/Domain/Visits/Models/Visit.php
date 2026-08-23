<?php

namespace App\Domain\Visits\Models;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'location_id', 'customer_id', 'source', 'external_visit_id', 'external_payment_id', 'external_order_id', 'external_booking_id', 'type', 'status', 'amount', 'currency', 'completed_at', 'raw_metadata'])]
class Visit extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(AutomationDispatch::class);
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'amount' => 'decimal:2', 'raw_metadata' => 'array'];
    }
}
