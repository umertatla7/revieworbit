<?php

namespace App\Domain\Templates\Models;

use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'name', 'channel', 'body', 'media_template_id', 'provider_template_sid', 'status', 'include_media'])]
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

    protected function casts(): array
    {
        return ['include_media' => 'boolean'];
    }
}
