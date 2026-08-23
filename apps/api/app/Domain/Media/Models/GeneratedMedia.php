<?php

namespace App\Domain\Media\Models;

use App\Domain\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'customer_id', 'media_template_id', 'disk', 'path', 'mime_type', 'status', 'failure_message', 'access_token_hash', 'expires_at'])]
class GeneratedMedia extends Model
{
    use HasUlids;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function mediaTemplate(): BelongsTo
    {
        return $this->belongsTo(MediaTemplate::class);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
