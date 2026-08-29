<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessagingConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioMessagingProvider implements MessagingProvider
{
    public function __construct(private readonly TwilioCredentials $credentials) {}

    public function configured(): bool
    {
        return $this->credentials->configured();
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
            ->withBasicAuth($this->credentials->accountSid(), $this->credentials->authToken())
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
        $response = Http::withBasicAuth($this->credentials->accountSid(), $this->credentials->authToken())
            ->get("https://messaging.twilio.com/v1/Services/{$serviceSid}");

        if ($response->failed() || $response->json('account_sid') !== $accountSid) {
            throw new RuntimeException('The Twilio Messaging Service could not be verified for this subaccount.');
        }

        return ['sid' => $serviceSid, 'name' => $response->json('friendly_name')];
    }

    public function verifyPlatform(): array
    {
        if (! $this->credentials->configured(false)) {
            throw new RuntimeException('Twilio platform credentials are not configured.');
        }

        $accountSid = $this->credentials->accountSid();
        $response = Http::withBasicAuth($accountSid, $this->credentials->authToken())
            ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}.json");

        if ($response->failed() || $response->json('sid') !== $accountSid) {
            throw new RuntimeException('Twilio rejected the platform credentials.');
        }

        return [
            'sid' => $accountSid,
            'name' => $response->json('friendly_name'),
            'account_status' => $response->json('status'),
            'type' => $response->json('type'),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Twilio platform credentials are not configured.');
        }
    }
}
