<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'customer_id', 'channel', 'status', 'source', 'disclosure_version', 'consented_at', 'revoked_at', 'recorded_at', 'expires_at', 'evidence'])]
class CustomerConsent extends Model
{
    use HasUlids;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'revoked_at' => 'datetime', 'recorded_at' => 'datetime', 'expires_at' => 'datetime', 'evidence' => 'array'];
    }
}
