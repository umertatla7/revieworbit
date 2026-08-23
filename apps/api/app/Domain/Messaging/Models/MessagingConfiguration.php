<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'provider', 'status', 'twilio_subaccount_sid', 'twilio_messaging_service_sid', 'sms_sender', 'whatsapp_sender', 'sms_enabled', 'whatsapp_enabled', 'verified_at', 'last_health_check_at', 'last_error'])]
class MessagingConfiguration extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return [
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'verified_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }
}
