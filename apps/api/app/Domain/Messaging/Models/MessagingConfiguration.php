<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'provider', 'sender_mode', 'status', 'twilio_subaccount_sid', 'twilio_auth_token', 'twilio_messaging_service_sid', 'sms_sender', 'whatsapp_sender', 'sms_enabled', 'whatsapp_enabled', 'verified_at', 'last_health_check_at', 'last_error'])]
class MessagingConfiguration extends Model
{
    use HasUlids;

    protected $hidden = ['twilio_auth_token'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return [
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'twilio_auth_token' => 'encrypted',
            'verified_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }
}
