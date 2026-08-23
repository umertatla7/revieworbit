<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'operation', 'key_hash', 'request_fingerprint', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyRecord extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'datetime'];
    }
}
