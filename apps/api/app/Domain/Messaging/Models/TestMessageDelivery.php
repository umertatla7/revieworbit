<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Templates\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'customer_id', 'message_template_id', 'requested_by_user_id', 'provider', 'channel', 'to_hash', 'to_last_four', 'provider_message_sid', 'status', 'provider_error_code', 'failure_message', 'queued_at', 'sent_at', 'delivered_at', 'failed_at'])]
class TestMessageDelivery extends Model
{
    use HasUlids;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
