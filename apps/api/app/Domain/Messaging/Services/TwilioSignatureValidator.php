<?php

namespace App\Domain\Messaging\Services;

class TwilioSignatureValidator
{
    public function __construct(private readonly TwilioCredentials $credentials) {}

    public function valid(string $url, array $parameters, ?string $signature): bool
    {
        $authToken = $this->credentials->authToken();
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
