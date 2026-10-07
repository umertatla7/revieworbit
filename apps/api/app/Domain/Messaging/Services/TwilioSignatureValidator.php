<?php

namespace App\Domain\Messaging\Services;

class TwilioSignatureValidator
{
    public function __construct(private readonly TwilioCredentials $credentials) {}

    public function valid(string $url, array $parameters, ?string $signature): bool
    {
        return $this->validWithToken($url, $parameters, $signature, $this->credentials->authToken());
    }

    public function validWithToken(string $url, array $parameters, ?string $signature, ?string $authToken): bool
    {
        if (! is_string($signature) || $signature === '' || ! $authToken) {
            return false;
        }

        ksort($parameters, SORT_STRING);
        $data = $url;
        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $data .= $key.(string) $value;
            }
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
