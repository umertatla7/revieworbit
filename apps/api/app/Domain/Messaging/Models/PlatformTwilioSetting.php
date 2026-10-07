<?php

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_sid', 'auth_token', 'shared_messaging_service_sid', 'shared_sms_sender', 'shared_sender_enabled', 'shared_sender_compliance_confirmed_at', 'mode', 'status', 'updated_by_user_id', 'verified_at', 'last_health_check_at', 'last_error'])]
class PlatformTwilioSetting extends Model
{
    use HasUlids;

    protected $hidden = ['auth_token'];

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'shared_sender_enabled' => 'boolean',
            'shared_sender_compliance_confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }
}
