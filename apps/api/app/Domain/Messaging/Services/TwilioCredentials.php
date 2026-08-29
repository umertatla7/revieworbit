<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\PlatformTwilioSetting;
use Illuminate\Support\Facades\Schema;

class TwilioCredentials
{
    public function setting(): ?PlatformTwilioSetting
    {
        if (! Schema::hasTable('platform_twilio_settings')) {
            return null;
        }

        return PlatformTwilioSetting::query()->latest()->first();
    }

    public function accountSid(): ?string
    {
        return $this->setting()?->account_sid ?: config('services.twilio.account_sid');
    }

    public function authToken(): ?string
    {
        return $this->setting()?->auth_token ?: config('services.twilio.auth_token');
    }

    public function mode(): string
    {
        return $this->setting()?->mode ?? 'production';
    }

    public function configured(bool $requireVerified = true): bool
    {
        $setting = $this->setting();
        if ($setting) {
            return (bool) ($setting->account_sid && $setting->auth_token && (! $requireVerified || $setting->status === 'verified'));
        }

        return (bool) (config('services.twilio.account_sid') && config('services.twilio.auth_token'));
    }
}
