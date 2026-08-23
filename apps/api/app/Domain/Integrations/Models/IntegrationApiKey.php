<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'name', 'key_prefix', 'token_hash', 'hmac_secret_encrypted', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash', 'hmac_secret_encrypted'])]
class IntegrationApiKey extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'hmac_secret_encrypted' => 'encrypted'];
    }
}
