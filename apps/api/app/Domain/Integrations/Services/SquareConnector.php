<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Contracts\IntegrationConnector;
use App\Domain\Integrations\Models\PosIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SquareConnector implements IntegrationConnector
{
    public const SCOPES = [
        'MERCHANT_PROFILE_READ',
        'CUSTOMERS_READ',
        'APPOINTMENTS_READ',
        'APPOINTMENTS_ALL_READ',
    ];

    public function configured(): bool
    {
        return (bool) (config('services.square.application_id') && config('services.square.application_secret') && config('services.square.redirect_uri'));
    }

    public function authorizationUrl(string $environment, string $state): string
    {
        $this->assertConfigured();

        return $this->baseUrl($environment).'/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.square.application_id'),
            'scope' => implode(' ', self::SCOPES),
            'session' => 'false',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $environment, string $code): array
    {
        $this->assertConfigured();

        return $this->oauthRequest($environment, [
            'client_id' => config('services.square.application_id'),
            'client_secret' => config('services.square.application_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.square.redirect_uri'),
        ]);
    }

    public function get(PosIntegration $integration, string $path, array $query = []): array
    {
        return $this->request($integration)->get($path, $query)->throw()->json();
    }

    public function post(PosIntegration $integration, string $path, array $payload = []): array
    {
        return $this->request($integration)->post($path, $payload)->throw()->json();
    }

    public function disconnect(PosIntegration $integration): void
    {
        if (! $integration->access_token_encrypted) {
            return;
        }

        $this->assertConfigured();
        Http::baseUrl($this->baseUrl($integration->environment))
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Client '.config('services.square.application_secret'),
                'Square-Version' => config('services.square.api_version'),
            ])
            ->post('/oauth2/revoke', [
                'client_id' => config('services.square.application_id'),
                'access_token' => $integration->access_token_encrypted,
                'revoke_only_access_token' => false,
            ])->throw();
    }

    private function request(PosIntegration $integration): PendingRequest
    {
        $this->refreshIfNeeded($integration);

        if (! $integration->access_token_encrypted) {
            throw new RuntimeException('Square is not connected.');
        }

        return Http::baseUrl($this->baseUrl($integration->environment))
            ->acceptJson()
            ->withToken($integration->access_token_encrypted)
            ->withHeaders(['Square-Version' => config('services.square.api_version')])
            ->timeout(30)
            ->retry(2, 300, throw: false);
    }

    private function refreshIfNeeded(PosIntegration $integration): void
    {
        if (! $integration->refresh_token_encrypted || ! $integration->token_expires_at?->lte(now()->addMinutes(5))) {
            return;
        }

        $token = $this->oauthRequest($integration->environment, [
            'client_id' => config('services.square.application_id'),
            'client_secret' => config('services.square.application_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token_encrypted,
        ]);

        $integration->update([
            'access_token_encrypted' => $token['access_token'],
            'refresh_token_encrypted' => $token['refresh_token'] ?? $integration->refresh_token_encrypted,
            'token_expires_at' => $token['expires_at'] ?? null,
        ]);
        $integration->refresh();
    }

    private function oauthRequest(string $environment, array $payload): array
    {
        $response = Http::baseUrl($this->baseUrl($environment))
            ->acceptJson()
            ->withHeaders(['Square-Version' => config('services.square.api_version')])
            ->post('/oauth2/token', $payload);

        if ($response->failed()) {
            $code = $response->json('errors.0.code') ?? 'SQUARE_OAUTH_ERROR';
            throw new RuntimeException('Square authorization failed: '.$code);
        }

        return $response->json();
    }

    private function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Square application credentials are not configured.');
        }
    }
}
