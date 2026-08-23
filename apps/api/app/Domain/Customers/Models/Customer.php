<?php

namespace App\Domain\Customers\Models;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'first_name', 'last_name', 'email', 'phone_e164', 'phone_hash', 'status', 'source'])]
class Customer extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(SuppressionEntry::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(CustomerExternalIdentity::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    protected function casts(): array
    {
        return ['last_visit_at' => 'datetime'];
    }
}
