<?php

namespace App\Domain\Integrations\Services;

use Carbon\CarbonImmutable;

class ToastWebhookSignatureValidator
{
    public function valid(string $rawBody, string $signature, string $secret): bool
    {
        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! is_string($payload['timestamp'] ?? null) || $signature === '' || $secret === '') {
            return false;
        }

        try {
            CarbonImmutable::parse($payload['timestamp']);
        } catch (\Throwable) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody.$payload['timestamp'], $secret, true));

        return hash_equals($expected, $signature);
    }
}
