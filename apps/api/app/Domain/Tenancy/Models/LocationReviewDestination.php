<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'location_id', 'provider', 'url', 'status', 'is_primary'])]
class LocationReviewDestination extends Model
{
    use HasUlids;

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
