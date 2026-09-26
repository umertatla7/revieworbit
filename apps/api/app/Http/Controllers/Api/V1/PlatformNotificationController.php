<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Tenancy\Models\PlatformNotificationSetting;
use App\Domain\Tenancy\Services\NotificationRecipient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformNotificationController extends Controller
{
    public function show(NotificationRecipient $recipient): JsonResponse
    {
        return response()->json(['data' => [
            'registration_email' => $recipient->registrationEmail(),
        ]]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'registration_email' => ['required', 'email:rfc', 'max:255'],
        ]);
        $setting = PlatformNotificationSetting::query()->first();
        if ($setting) {
            $setting->update($data);
        } else {
            $setting = PlatformNotificationSetting::query()->create($data);
        }
        $auditor->record($request, 'platform.notifications.updated', $setting, [
            'registration_email' => $setting->registration_email,
        ]);

        return response()->json(['data' => [
            'registration_email' => $setting->registration_email,
        ]]);
    }
}
