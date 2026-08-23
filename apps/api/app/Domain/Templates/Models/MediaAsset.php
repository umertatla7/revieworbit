<?php

namespace App\Domain\Templates\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'message_template_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'status', 'expires_at'])]
class MediaAsset extends Model
{
    use HasUlids;

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
