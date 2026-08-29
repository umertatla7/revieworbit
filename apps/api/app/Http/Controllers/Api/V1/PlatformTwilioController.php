<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Messaging\Models\PlatformTwilioSetting;
use App\Domain\Messaging\Services\TwilioMessagingProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformTwilioController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(PlatformTwilioSetting::query()->latest()->first())]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'account_sid' => ['required', 'regex:/^AC[a-fA-F0-9]{32}$/'],
            'auth_token' => ['nullable', 'string', 'min:20', 'max:255'],
            'mode' => ['required', Rule::in(['trial', 'production'])],
        ]);
        $setting = PlatformTwilioSetting::query()->latest()->first();
        if (! $setting && empty($data['auth_token'])) {
            throw ValidationException::withMessages(['auth_token' => ['Enter the Twilio Auth Token the first time credentials are saved.']]);
        }

        $attributes = [
            'account_sid' => $data['account_sid'],
            'mode' => $data['mode'],
            'status' => 'draft',
            'verified_at' => null,
            'last_error' => null,
            'updated_by_user_id' => $request->user()->id,
        ];
        if (! empty($data['auth_token'])) {
            $attributes['auth_token'] = $data['auth_token'];
        }

        if ($setting) {
            $setting->update($attributes);
        } else {
            $setting = PlatformTwilioSetting::create($attributes);
        }
        $auditor->record($request, 'platform.twilio.credentials_updated', $setting, [
            'account_sid_last_four' => substr($setting->account_sid, -4),
            'mode' => $setting->mode,
            'auth_token_rotated' => ! empty($data['auth_token']),
        ]);

        return response()->json(['data' => $this->payload($setting->fresh())]);
    }

    public function verify(Request $request, TwilioMessagingProvider $twilio, Auditor $auditor): JsonResponse
    {
        $setting = PlatformTwilioSetting::query()->latest()->firstOrFail();

        try {
            $details = $twilio->verifyPlatform();
            $setting->update([
                'status' => 'verified',
                'verified_at' => now(),
                'last_health_check_at' => now(),
                'last_error' => null,
            ]);
            $auditor->record($request, 'platform.twilio.credentials_verified', $setting, [
                'account_sid_last_four' => substr($setting->account_sid, -4),
                'mode' => $setting->mode,
            ]);

            return response()->json(['data' => ['configuration' => $this->payload($setting->fresh()), 'account' => $details]]);
        } catch (\Throwable $exception) {
            $setting->update([
                'status' => 'error',
                'last_health_check_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function payload(?PlatformTwilioSetting $setting): array
    {
        return [
            'configured' => (bool) $setting,
            'account_sid' => $setting?->account_sid,
            'auth_token_configured' => (bool) $setting?->auth_token,
            'mode' => $setting?->mode ?? 'trial',
            'status' => $setting?->status ?? 'not_configured',
            'verified_at' => $setting?->verified_at,
            'last_health_check_at' => $setting?->last_health_check_at,
            'last_error' => $setting?->last_error,
            'status_callback_url' => config('services.twilio.status_callback_url'),
            'inbound_webhook_url' => config('services.twilio.inbound_webhook_url'),
        ];
    }
}
