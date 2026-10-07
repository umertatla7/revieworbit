<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessagingConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioMessagingProvider implements MessagingProvider
{
    public function __construct(
        private readonly TwilioCredentials $credentials,
        private readonly TwilioConnectionResolver $connections,
    ) {}

    public function configured(): bool
    {
        return $this->credentials->configured();
    }

    public function send(MessagingConfiguration $configuration, array $message): array
    {
        $connection = $this->connections->resolve($configuration);
        $accountSid = $connection['account_sid'];
        $payload = [
            'To' => $message['channel'] === 'whatsapp' ? 'whatsapp:'.$message['to'] : $message['to'],
            'MessagingServiceSid' => $connection['messaging_service_sid'],
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
            ->withBasicAuth($connection['auth_username'], $connection['auth_secret'])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Twilio rejected the message: '.($response->json('code') ?? $response->status()));
        }

        return ['sid' => $response->json('sid'), 'status' => $response->json('status', 'queued')];
    }

    public function verify(MessagingConfiguration $configuration): array
    {
        $connection = $this->connections->resolve($configuration);
        $accountSid = $connection['account_sid'];
        $serviceSid = $connection['messaging_service_sid'];
        $response = Http::withBasicAuth($connection['auth_username'], $connection['auth_secret'])
            ->get("https://messaging.twilio.com/v1/Services/{$serviceSid}");

        if ($response->failed() || $response->json('account_sid') !== $accountSid) {
            throw new RuntimeException('The Twilio Messaging Service could not be verified for this subaccount.');
        }

        $this->assertSenderInService($connection);

        return [
            'sid' => $serviceSid,
            'name' => $response->json('friendly_name'),
            'sender_mode' => $connection['mode'],
            'sender_last_four' => $connection['sms_sender'] ? substr($connection['sms_sender'], -4) : null,
        ];
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

        $setting = $this->credentials->setting();
        if ($setting?->shared_sender_enabled) {
            if (! $setting->shared_messaging_service_sid || ! $setting->shared_sms_sender) {
                throw new RuntimeException('The shared sender configuration is incomplete.');
            }
            $connection = [
                'auth_username' => $accountSid,
                'auth_secret' => (string) $setting->auth_token,
                'messaging_service_sid' => $setting->shared_messaging_service_sid,
                'sms_sender' => $setting->shared_sms_sender,
            ];
            $serviceResponse = Http::withBasicAuth($connection['auth_username'], $connection['auth_secret'])
                ->get("https://messaging.twilio.com/v1/Services/{$connection['messaging_service_sid']}");
            if ($serviceResponse->failed() || $serviceResponse->json('account_sid') !== $accountSid) {
                throw new RuntimeException('The shared Messaging Service could not be verified in the platform account.');
            }
            $this->assertSenderInService($connection);
        }

        return [
            'sid' => $accountSid,
            'name' => $response->json('friendly_name'),
            'account_status' => $response->json('status'),
            'type' => $response->json('type'),
            'shared_sender_verified' => (bool) $setting?->shared_sender_enabled,
        ];
    }

    /** @param array{auth_username:string,auth_secret:string,messaging_service_sid:string,sms_sender:?string} $connection */
    private function assertSenderInService(array $connection): void
    {
        if (! $connection['sms_sender']) {
            throw new RuntimeException('An approved SMS sender is required.');
        }

        $response = Http::withBasicAuth($connection['auth_username'], $connection['auth_secret'])
            ->get("https://messaging.twilio.com/v1/Services/{$connection['messaging_service_sid']}/PhoneNumbers", ['PageSize' => 50]);
        if ($response->failed()) {
            throw new RuntimeException('The Twilio Messaging Service sender pool could not be verified.');
        }
        $senders = collect($response->json('phone_numbers', []));
        if (! $senders->contains(fn (array $sender): bool => ($sender['phone_number'] ?? null) === $connection['sms_sender'])) {
            throw new RuntimeException('The approved SMS sender is not in this Messaging Service sender pool.');
        }
    }
}
