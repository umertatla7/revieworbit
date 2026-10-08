<?php

namespace App\Domain\Messaging\Services;

class SmsMessageFormatter
{
    public function format(string $body, string $businessName): string
    {
        $body = trim($body);
        $businessName = trim($businessName);
        if ($businessName !== '' && mb_stripos($body, $businessName) === false) {
            $body = $businessName.': '.$body;
        }
        if (! preg_match('/reply\s+stop\s+to\s+(unsubscribe|opt[ -]?out)/i', $body)) {
            $body .= "\nReply STOP to unsubscribe.";
        }

        return $body;
    }
}
