<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'customer_id', 'provider', 'external_customer_id'])]
class CustomerExternalIdentity extends Model
{
    use HasUlids;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
