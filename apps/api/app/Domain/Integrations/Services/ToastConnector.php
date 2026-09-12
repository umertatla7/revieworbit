<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Contracts\IntegrationConnector;
use App\Domain\Integrations\Models\PlatformToastSetting;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ToastConnector implements IntegrationConnector
{
    public function configured(?string $environment = null): bool
    {
        $setting = $this->setting($environment, false);

        return (bool) ($setting?->status === 'verified'
            && $setting->api_base_url
            && $setting->client_id
            && $setting->client_secret
            && $setting->partner_webhook_secret
            && $setting->orders_webhook_secret);
    }

    public function setting(?string $environment = null, bool $required = true): ?PlatformToastSetting
    {
        $setting = PlatformToastSetting::where('environment', $environment ?? config('services.toast.environment'))->first();
        if ($required && ! $setting) {
            throw new RuntimeException('Toast has not been configured for this environment.');
        }

        return $setting;
    }

    public function verify(PlatformToastSetting $setting): array
    {
        $token = $this->authenticate($setting);

        return [
            'authenticated' => true,
            'environment' => $setting->environment,
            'expires_in' => (int) ($token['expiresIn'] ?? 0),
        ];
    }

    public function get(ToastRestaurantConnection $connection, string $path, array $query = []): array
    {
        $setting = $this->setting($connection->environment);

        return $this->request($setting)
            ->withHeaders(['Toast-Restaurant-External-ID' => $connection->restaurant_guid])
            ->get($path, $query)->throw()->json();
    }

    public function platformGet(PlatformToastSetting $setting, string $path, array $query = []): array
    {
        return $this->request($setting)->get($path, $query)->throw()->json();
    }

    public function clearToken(PlatformToastSetting $setting): void
    {
        Cache::forget($this->tokenCacheKey($setting));
    }

    private function request(PlatformToastSetting $setting): PendingRequest
    {
        $token = $this->authenticate($setting);

        return Http::baseUrl(rtrim((string) $setting->api_base_url, '/'))
            ->acceptJson()
            ->withToken((string) data_get($token, 'accessToken'))
            ->timeout(25)
            ->retry(2, 300, throw: false);
    }

    private function authenticate(PlatformToastSetting $setting, bool $cache = true): array
    {
        if (! $setting->api_base_url || ! $setting->client_id || ! $setting->client_secret) {
            throw new RuntimeException('Toast API URL, client ID, and client secret are required.');
        }

        $key = $this->tokenCacheKey($setting);
        if ($cache && ($existing = Cache::get($key))) {
            return $existing;
        }

        $response = Http::baseUrl(rtrim($setting->api_base_url, '/'))
            ->acceptJson()
            ->timeout(20)
            ->post('/authentication/v1/authentication/login', [
                'clientId' => $setting->client_id,
                'clientSecret' => $setting->client_secret,
                'userAccessType' => 'TOAST_MACHINE_CLIENT',
            ]);
        if ($response->failed()) {
            throw new RuntimeException('Toast authentication failed. Confirm the environment and partner credentials.');
        }

        $token = $response->json('token');
        if (! is_array($token) || empty($token['accessToken'])) {
            throw new RuntimeException('Toast returned an invalid authentication response.');
        }
        if ($cache) {
            Cache::put($key, $token, now()->addSeconds(max(60, (int) ($token['expiresIn'] ?? 3600) - 120)));
        }

        return $token;
    }

    private function tokenCacheKey(PlatformToastSetting $setting): string
    {
        return 'toast:machine-token:'.$setting->environment.':'.hash('sha256', (string) $setting->client_id);
    }
}
