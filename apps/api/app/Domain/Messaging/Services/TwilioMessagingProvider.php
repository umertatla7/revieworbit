<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessagingConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioMessagingProvider implements MessagingProvider
{
    public function configured(): bool
    {
        return (bool) (config('services.twilio.account_sid') && config('services.twilio.auth_token'));
    }

    public function send(MessagingConfiguration $configuration, array $message): array
    {
        $this->assertConfigured();
        $accountSid = $configuration->twilio_subaccount_sid;
        $payload = [
            'To' => $message['channel'] === 'whatsapp' ? 'whatsapp:'.$message['to'] : $message['to'],
            'MessagingServiceSid' => $configuration->twilio_messaging_service_sid,
            'StatusCallback' => config('services.twilio.status_callback_url'),
        ];

        if ($message['channel'] === 'whatsapp') {
            $payload['ContentSid'] = $message['content_sid'];
            $payload['ContentVariables'] = json_encode($message['content_variables'], JSON_THROW_ON_ERROR);
        } else {
            $payload['Body'] = $message['body'];
            if (! empty($message['media_url'])) {
                $payload['MediaUrl'] = $message['media_url'];
            }
        }

        $response = Http::asForm()
            ->withBasicAuth(config('services.twilio.account_sid'), config('services.twilio.auth_token'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Twilio rejected the message: '.($response->json('code') ?? $response->status()));
        }

        return ['sid' => $response->json('sid'), 'status' => $response->json('status', 'queued')];
    }

    public function verify(MessagingConfiguration $configuration): array
    {
        $this->assertConfigured();
        $accountSid = $configuration->twilio_subaccount_sid;
        $serviceSid = $configuration->twilio_messaging_service_sid;
        $response = Http::withBasicAuth(config('services.twilio.account_sid'), config('services.twilio.auth_token'))
            ->get("https://messaging.twilio.com/v1/Services/{$serviceSid}");

        if ($response->failed() || $response->json('account_sid') !== $accountSid) {
            throw new RuntimeException('The Twilio Messaging Service could not be verified for this subaccount.');
        }

        return ['sid' => $serviceSid, 'name' => $response->json('friendly_name')];
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Twilio platform credentials are not configured.');
        }
    }
}
