<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Integrations\Models\IntegrationWebhookEvent;
use App\Domain\Integrations\Models\PlatformToastSetting;
use App\Domain\Integrations\Models\ToastConnectionRequest;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Integrations\Services\ToastConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformToastController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $environment = $request->validate(['environment' => ['nullable', Rule::in(['sandbox', 'production'])]])['environment'] ?? 'sandbox';

        return response()->json(['data' => $this->payload(PlatformToastSetting::where('environment', $environment)->first(), $environment)]);
    }

    public function update(Request $request, Auditor $auditor, ToastConnector $toast): JsonResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'api_base_url' => ['required', 'url:https', 'max:2048'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'min:12', 'max:1000'],
            'partner_webhook_secret' => ['nullable', 'string', 'min:12', 'max:1000'],
            'orders_webhook_secret' => ['nullable', 'string', 'min:12', 'max:1000'],
            'marketplace_url' => ['nullable', 'url:https', 'max:2048'],
        ]);
        $setting = PlatformToastSetting::where('environment', $data['environment'])->first();
        if (! $setting && empty($data['client_secret'])) {
            throw ValidationException::withMessages(['client_secret' => ['Enter the Toast client secret the first time credentials are saved.']]);
        }
        $attributes = [
            'api_base_url' => rtrim($data['api_base_url'], '/'), 'client_id' => $data['client_id'],
            'marketplace_url' => $data['marketplace_url'] ?? null, 'status' => 'draft',
            'verified_at' => null, 'last_error' => null, 'updated_by_user_id' => $request->user()->id,
        ];
        foreach (['client_secret', 'partner_webhook_secret', 'orders_webhook_secret'] as $secret) {
            if (! empty($data[$secret])) {
                $attributes[$secret] = $data[$secret];
            }
        }
        $setting
            ? $setting->update($attributes)
            : $setting = PlatformToastSetting::create(['environment' => $data['environment'], ...$attributes]);
        $toast->clearToken($setting);
        $auditor->record($request, 'platform.toast.configuration_updated', $setting, [
            'environment' => $setting->environment,
            'client_secret_rotated' => ! empty($data['client_secret']),
            'partner_webhook_secret_rotated' => ! empty($data['partner_webhook_secret']),
            'orders_webhook_secret_rotated' => ! empty($data['orders_webhook_secret']),
        ]);

        return response()->json(['data' => $this->payload($setting->fresh(), $setting->environment)]);
    }

    public function verify(Request $request, ToastConnector $toast, Auditor $auditor): JsonResponse
    {
        $environment = $request->validate(['environment' => ['required', Rule::in(['sandbox', 'production'])]])['environment'];
        $setting = PlatformToastSetting::where('environment', $environment)->firstOrFail();
        try {
            $account = $toast->verify($setting);
            $setting->update(['status' => 'verified', 'verified_at' => now(), 'last_health_check_at' => now(), 'last_error' => null]);
            $auditor->record($request, 'platform.toast.credentials_verified', $setting, ['environment' => $environment]);

            return response()->json(['data' => ['configuration' => $this->payload($setting->fresh(), $environment), 'account' => $account]]);
        } catch (\Throwable $exception) {
            $setting->update(['status' => 'error', 'last_health_check_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function payload(?PlatformToastSetting $setting, string $environment): array
    {
        $base = rtrim(config('app.url'), '/').'/api/v1/webhooks/toast/'.$environment;

        return [
            'configured' => (bool) $setting,
            'ready_for_connections' => (bool) ($setting?->status === 'verified' && $setting->partner_webhook_secret && $setting->orders_webhook_secret),
            'environment' => $environment, 'api_base_url' => $setting?->api_base_url, 'client_id' => $setting?->client_id,
            'client_secret_configured' => (bool) $setting?->client_secret,
            'partner_webhook_secret_configured' => (bool) $setting?->partner_webhook_secret,
            'orders_webhook_secret_configured' => (bool) $setting?->orders_webhook_secret,
            'marketplace_url' => $setting?->marketplace_url, 'status' => $setting?->status ?? 'not_configured',
            'verified_at' => $setting?->verified_at, 'last_health_check_at' => $setting?->last_health_check_at, 'last_error' => $setting?->last_error,
            'partner_webhook_url' => $base.'/partners', 'orders_webhook_url' => $base.'/orders',
            'counts' => [
                'connected_locations' => ToastRestaurantConnection::where('environment', $environment)->where('status', 'connected')->count(),
                'pending_requests' => ToastConnectionRequest::where('status', 'pending')->where('expires_at', '>', now())->count(),
                'failed_events' => IntegrationWebhookEvent::where('provider', 'toast')->where('status', 'failed')->count(),
            ],
            'recent_events' => IntegrationWebhookEvent::where('provider', 'toast')->latest()->limit(15)
                ->get(['id', 'business_id', 'category', 'event_type', 'status', 'attempt_number', 'verified_at', 'processed_at', 'failure_message']),
            'connections' => ToastRestaurantConnection::where('environment', $environment)
                ->with(['business:id,name', 'location:id,name'])
                ->latest()->limit(100)->get(),
        ];
    }
}
