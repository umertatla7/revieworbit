<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Billing\Services\StripeGateway;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformStripeController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(PlatformStripeSetting::latest()->first())]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'publishable_key' => ['required', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/', 'max:255'],
            'secret_key' => ['nullable', 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/', 'max:255'],
            'webhook_secret' => ['nullable', 'regex:/^whsec_[A-Za-z0-9]+$/', 'max:255'],
            'mode' => ['required', Rule::in(['test', 'live'])],
        ]);
        $setting = PlatformStripeSetting::latest()->first();
        $connectionChanged = ! $setting || $setting->mode !== $data['mode'] || ! empty($data['secret_key']);
        if (! $setting && empty($data['secret_key'])) {
            throw ValidationException::withMessages(['secret_key' => ['Enter the Stripe secret key the first time.']]);
        }
        $prefix = $data['mode'] === 'live' ? 'live' : 'test';
        if (! str_starts_with($data['publishable_key'], 'pk_'.$prefix.'_') || (! empty($data['secret_key']) && ! str_starts_with($data['secret_key'], 'sk_'.$prefix.'_'))) {
            throw ValidationException::withMessages(['mode' => ['The selected mode must match the Stripe key type.']]);
        }
        $attributes = [
            'publishable_key' => $data['publishable_key'], 'mode' => $data['mode'], 'status' => 'draft',
            'account_id' => null, 'account_name' => null, 'verified_at' => null, 'last_error' => null,
            'portal_configuration_id' => $connectionChanged ? null : $setting?->portal_configuration_id,
            'updated_by_user_id' => $request->user()->id,
        ];
        foreach (['secret_key', 'webhook_secret'] as $secret) {
            if (! empty($data[$secret])) {
                $attributes[$secret] = $data[$secret];
            }
        }
        $setting ? $setting->update($attributes) : $setting = PlatformStripeSetting::create($attributes);
        if ($connectionChanged) {
            SubscriptionPlan::query()->update(['stripe_product_id' => null, 'stripe_monthly_price_id' => null, 'stripe_annual_price_id' => null]);
        }
        $auditor->record($request, 'platform.stripe.configuration_updated', $setting, ['mode' => $setting->mode, 'secret_key_rotated' => ! empty($data['secret_key']), 'webhook_secret_rotated' => ! empty($data['webhook_secret'])]);

        return response()->json(['data' => $this->payload($setting->fresh())]);
    }

    public function verify(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $setting = PlatformStripeSetting::latest()->firstOrFail();
        try {
            $account = $stripe->verify($setting);
            $setting->update(['status' => 'verified', 'account_id' => $account['id'] ?? null, 'account_name' => data_get($account, 'business_profile.name') ?: data_get($account, 'settings.dashboard.display_name'), 'verified_at' => now(), 'last_health_check_at' => now(), 'last_error' => null]);
            $auditor->record($request, 'platform.stripe.configuration_verified', $setting, ['mode' => $setting->mode, 'account_id' => $setting->account_id]);

            return response()->json(['data' => $this->payload($setting->fresh())]);
        } catch (\Throwable $exception) {
            $setting->update(['status' => 'error', 'last_health_check_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);

            return response()->json(['message' => 'Stripe could not verify these credentials. Check the key and mode.'], 422);
        }
    }

    private function payload(?PlatformStripeSetting $setting): array
    {
        return [
            'configured' => (bool) $setting, 'publishable_key' => $setting?->publishable_key,
            'secret_key_configured' => (bool) $setting?->secret_key, 'webhook_secret_configured' => (bool) $setting?->webhook_secret,
            'mode' => $setting?->mode ?? 'test', 'status' => $setting?->status ?? 'not_configured',
            'account_id' => $setting?->account_id, 'account_name' => $setting?->account_name,
            'portal_configured' => (bool) $setting?->portal_configuration_id,
            'verified_at' => $setting?->verified_at, 'last_health_check_at' => $setting?->last_health_check_at,
            'last_error' => $setting?->last_error, 'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/webhooks/stripe',
        ];
    }
}
