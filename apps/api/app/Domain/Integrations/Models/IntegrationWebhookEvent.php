<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'provider', 'category', 'event_type', 'external_event_id', 'payload_fingerprint', 'payload', 'status', 'attempt_number', 'verified_at', 'processed_at', 'failure_message'])]
class IntegrationWebhookEvent extends Model
{
    use HasUlids;

    protected $hidden = ['payload'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
