<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Models\GeneratedMedia;
use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'location_id', 'visit_id', 'requested_by_user_id', 'delivery_type', 'automation_dispatch_id', 'customer_id', 'message_template_id', 'review_link_id', 'generated_media_id', 'provider', 'channel', 'body_snapshot', 'billable_credits', 'estimated_cost_minor', 'provider_cost_minor', 'provider_currency', 'to_hash', 'to_last_four', 'provider_message_sid', 'status', 'provider_error_code', 'failure_message', 'queued_at', 'sent_at', 'delivered_at', 'failed_at'])]
class MessageDelivery extends Model
{
    use HasUlids;

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(AutomationDispatch::class, 'automation_dispatch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function reviewLink(): BelongsTo
    {
        return $this->belongsTo(ReviewLink::class);
    }

    public function generatedMedia(): BelongsTo
    {
        return $this->belongsTo(GeneratedMedia::class);
    }

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'body_snapshot' => 'encrypted',
            'billable_credits' => 'integer',
            'estimated_cost_minor' => 'integer',
        ];
    }
}
