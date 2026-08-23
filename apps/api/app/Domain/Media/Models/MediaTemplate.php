<?php

namespace App\Domain\Media\Models;

use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'name', 'disk', 'background_image_path', 'text_configuration', 'width', 'height', 'status'])]
class MediaTemplate extends Model
{
    use HasUlids;

    public function generatedMedia(): HasMany
    {
        return $this->hasMany(GeneratedMedia::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    protected function casts(): array
    {
        return ['text_configuration' => 'array', 'width' => 'integer', 'height' => 'integer'];
    }
}
