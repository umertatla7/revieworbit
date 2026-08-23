<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'name', 'external_reference', 'address', 'timezone', 'phone', 'google_review_url', 'status'])]
class Location extends Model
{
    use HasFactory, HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reviewDestinations(): HasMany
    {
        return $this->hasMany(LocationReviewDestination::class);
    }

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'status' => RecordStatus::class,
        ];
    }
}
