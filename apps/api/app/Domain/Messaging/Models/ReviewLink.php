<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\LocationReviewDestination;
use App\Domain\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'location_id', 'review_destination_id', 'customer_id', 'visit_id', 'token_hash', 'destination_url', 'click_count', 'first_clicked_at', 'last_clicked_at', 'expires_at'])]
class ReviewLink extends Model
{
    use HasUlids;

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(LocationReviewDestination::class, 'review_destination_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MessageDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
