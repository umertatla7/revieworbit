<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\MessagingConfiguration;
use RuntimeException;

class TwilioConnectionResolver
{
    public function __construct(private readonly TwilioCredentials $platformCredentials) {}

    /**
     * Resolve effective credentials and sender configuration without exposing
     * secrets through controllers or serialized models.
     *
     * @return array{mode:string,account_sid:string,auth_username:string,auth_secret:string,messaging_service_sid:string,sms_sender:?string}
     */
    public function resolve(MessagingConfiguration $configuration): array
    {
        $mode = $configuration->sender_mode ?: 'platform_dedicated';

        if ($mode === 'platform_shared') {
            $setting = $this->platformCredentials->setting();
            if (! $setting || $setting->status !== 'verified' || ! $setting->shared_sender_enabled) {
                throw new RuntimeException('The B Review default sender is not available. Contact support.');
            }
            if (! $setting->shared_messaging_service_sid || ! $setting->shared_sms_sender) {
                throw new RuntimeException('The B Review default sender is incomplete. Contact support.');
            }

            return $this->connection(
                $mode,
                (string) $setting->account_sid,
                (string) $setting->account_sid,
                (string) $setting->auth_token,
                (string) $setting->shared_messaging_service_sid,
                $setting->shared_sms_sender,
            );
        }

        if ($mode === 'customer_owned') {
            if (! $configuration->twilio_subaccount_sid || ! $configuration->twilio_auth_token || ! $configuration->twilio_messaging_service_sid) {
                throw new RuntimeException('The customer-owned Twilio connection is incomplete.');
            }

            return $this->connection(
                $mode,
                $configuration->twilio_subaccount_sid,
                $configuration->twilio_subaccount_sid,
                $configuration->twilio_auth_token,
                $configuration->twilio_messaging_service_sid,
                $configuration->sms_sender,
            );
        }

        if ($mode !== 'platform_dedicated') {
            throw new RuntimeException('Unsupported Twilio sender mode.');
        }
        if (! $configuration->twilio_subaccount_sid || ! $configuration->twilio_messaging_service_sid) {
            throw new RuntimeException('The dedicated B Review sender has not been assigned yet.');
        }
        if (! $this->platformCredentials->configured()) {
            throw new RuntimeException('Twilio platform credentials are not configured.');
        }

        return $this->connection(
            $mode,
            $configuration->twilio_subaccount_sid,
            (string) $this->platformCredentials->accountSid(),
            (string) $this->platformCredentials->authToken(),
            $configuration->twilio_messaging_service_sid,
            $configuration->sms_sender,
        );
    }

    public function configuredFor(MessagingConfiguration $configuration): bool
    {
        try {
            $this->resolve($configuration);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function connection(string $mode, string $accountSid, string $authUsername, string $authSecret, string $serviceSid, ?string $sender): array
    {
        return [
            'mode' => $mode,
            'account_sid' => $accountSid,
            'auth_username' => $authUsername,
            'auth_secret' => $authSecret,
            'messaging_service_sid' => $serviceSid,
            'sms_sender' => $sender,
        ];
    }
}
