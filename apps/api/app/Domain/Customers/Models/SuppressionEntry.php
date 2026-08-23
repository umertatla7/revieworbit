<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'customer_id', 'channel', 'phone_e164', 'reason', 'source', 'suppressed_at', 'released_at'])]
class SuppressionEntry extends Model
{
    use HasUlids;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return ['suppressed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
