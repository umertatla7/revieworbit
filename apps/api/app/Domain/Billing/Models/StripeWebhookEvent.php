<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stripe_event_id', 'type', 'livemode', 'status', 'payload', 'last_error', 'processed_at'])]
class StripeWebhookEvent extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'livemode' => 'boolean', 'processed_at' => 'datetime'];
    }
}
