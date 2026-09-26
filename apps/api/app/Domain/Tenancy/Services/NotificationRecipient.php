<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\PlatformNotificationSetting;
use Illuminate\Support\Facades\Schema;

class NotificationRecipient
{
    public function registrationEmail(): string
    {
        $fallback = (string) config('services.notifications.registration_email', 'zee@buckeyerank.com');
        if (! Schema::hasTable('platform_notification_settings')) {
            return $fallback;
        }

        return (string) (PlatformNotificationSetting::query()->value('registration_email') ?: $fallback);
    }
}
