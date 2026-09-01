<?php

namespace App\Domain\Templates\Models;

use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\LocationReviewDestination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'location_id', 'review_destination_id', 'name', 'channel', 'body', 'media_template_id', 'provider_template_sid', 'status', 'include_media'])]
class MessageTemplate extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function mediaTemplate(): BelongsTo
    {
        return $this->belongsTo(MediaTemplate::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reviewDestination(): BelongsTo
    {
        return $this->belongsTo(LocationReviewDestination::class, 'review_destination_id');
    }

    protected function casts(): array
    {
        return ['include_media' => 'boolean'];
    }
}
