<?php

namespace App\Domain\Messaging\Services;

class TwilioSignatureValidator
{
    public function valid(string $url, array $parameters, ?string $signature): bool
    {
        if (! is_string($signature) || $signature === '' || ! config('services.twilio.auth_token')) {
            return false;
        }

        ksort($parameters, SORT_STRING);
        $data = $url;
        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $data .= $key.(string) $value;
            }
        }
        $expected = base64_encode(hash_hmac('sha1', $data, config('services.twilio.auth_token'), true));

        return hash_equals($expected, $signature);
    }
}
