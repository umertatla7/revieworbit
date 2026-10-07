<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\Auditor;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\PlatformTwilioSetting;
use App\Domain\Messaging\Models\TestMessageDelivery;
use App\Domain\Messaging\Services\TwilioMessagingProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessagingConfigurationController extends Controller
{
    public function show(Request $request, TwilioMessagingProvider $twilio): JsonResponse
    {
        $business = $request->attributes->get('business');
        $liveConfigured = $twilio->configured();
        $fakeAllowed = app()->environment('local', 'testing') && config('services.twilio.provider') === 'fake';
        $activity = AuditLog::where('business_id', $business->id)
            ->whereIn('action', [
                'messaging.configuration.updated',
                'messaging.configuration.verified',
                'messaging.configuration.verification_failed',
                'template.test_message_sent',
                'template.test_message_failed',
            ])
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'action', 'changes', 'created_at']);

        $configuration = MessagingConfiguration::where('business_id', $business->id)->first();
        $platformSetting = PlatformTwilioSetting::query()->latest()->first();

        return response()->json(['data' => [
            'configuration' => $this->configurationPayload($configuration),
            'activity' => $activity,
            'platform' => [
                'provider' => $liveConfigured ? 'twilio' : config('services.twilio.provider'),
                'configured' => $liveConfigured || $fakeAllowed,
                'mode' => $platformSetting?->mode ?? 'production',
                'default_sender_available' => (bool) ($platformSetting?->status === 'verified' && $platformSetting?->shared_sender_enabled),
                'default_sender_last_four' => $platformSetting?->shared_sms_sender ? substr($platformSetting->shared_sms_sender, -4) : null,
                'support_access' => $request->attributes->get('support_access') === true,
                'status_callback_url' => config('services.twilio.status_callback_url'),
                'inbound_webhook_url' => config('services.twilio.inbound_webhook_url'),
            ],
        ]]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'sender_mode' => ['required', Rule::in(['platform_shared', 'platform_dedicated', 'customer_owned'])],
            'twilio_subaccount_sid' => ['nullable', 'regex:/^AC[a-fA-F0-9]{32}$/'],
            'twilio_auth_token' => ['nullable', 'string', 'min:20', 'max:255'],
            'twilio_messaging_service_sid' => ['nullable', 'regex:/^MG[a-fA-F0-9]{32}$/'],
            'sms_sender' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'sms_enabled' => ['required', 'boolean'],
        ]);
        $existing = MessagingConfiguration::where('business_id', $business->id)->first();
        $attributes = [
            'provider' => 'twilio',
            'sender_mode' => $data['sender_mode'],
            'status' => 'draft',
            'sms_enabled' => $data['sms_enabled'],
            'whatsapp_enabled' => false,
            'verified_at' => null,
            'last_error' => null,
        ];

        if ($data['sender_mode'] === 'platform_shared') {
            $platform = PlatformTwilioSetting::query()->latest()->first();
            if (! $platform || $platform->status !== 'verified' || ! $platform->shared_sender_enabled) {
                throw ValidationException::withMessages(['sender_mode' => ['The B Review default sender is not available yet. Contact support.']]);
            }
            $attributes += [
                'twilio_subaccount_sid' => null,
                'twilio_auth_token' => null,
                'twilio_messaging_service_sid' => null,
                'sms_sender' => null,
            ];
        } elseif ($data['sender_mode'] === 'platform_dedicated') {
            if ($request->attributes->get('support_access') !== true) {
                $attributes += [
                    'status' => 'pending_assignment',
                    'sms_enabled' => false,
                    'twilio_subaccount_sid' => null,
                    'twilio_auth_token' => null,
                    'twilio_messaging_service_sid' => null,
                    'sms_sender' => null,
                ];
            } else {
                $this->requireTwilioFields($data, false);
                $attributes += [
                    'twilio_subaccount_sid' => $data['twilio_subaccount_sid'],
                    'twilio_auth_token' => null,
                    'twilio_messaging_service_sid' => $data['twilio_messaging_service_sid'],
                    'sms_sender' => $data['sms_sender'],
                ];
            }
        } else {
            $this->requireTwilioFields($data, true, $existing);
            $attributes += [
                'twilio_subaccount_sid' => $data['twilio_subaccount_sid'],
                'twilio_messaging_service_sid' => $data['twilio_messaging_service_sid'],
                'sms_sender' => $data['sms_sender'],
            ];
            if (! empty($data['twilio_auth_token'])) {
                $attributes['twilio_auth_token'] = $data['twilio_auth_token'];
            }
        }

        $configuration = MessagingConfiguration::updateOrCreate(['business_id' => $business->id], $attributes);
        $auditor->record($request, 'messaging.configuration.updated', $configuration, [
            'sender_mode' => $configuration->sender_mode,
            'sms_enabled' => $configuration->sms_enabled,
            'dedicated_assignment_pending' => $configuration->status === 'pending_assignment',
        ]);

        return response()->json(['data' => $this->configurationPayload($configuration)]);
    }

    public function verify(Request $request, MessagingProvider $provider, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $configuration = MessagingConfiguration::where('business_id', $business->id)->firstOrFail();

        try {
            $details = $provider->verify($configuration);
            $configuration->update(['status' => 'active', 'verified_at' => now(), 'last_health_check_at' => now(), 'last_error' => null]);
            $auditor->record($request, 'messaging.configuration.verified', $configuration, ['provider' => config('services.twilio.provider')]);

            return response()->json(['data' => ['configuration' => $configuration->fresh(), 'provider' => $details]]);
        } catch (\Throwable $exception) {
            $configuration->update(['status' => 'error', 'last_health_check_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $auditor->record($request, 'messaging.configuration.verification_failed', $configuration, [
                'reason' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function requireTwilioFields(array $data, bool $customerOwned, ?MessagingConfiguration $existing = null): void
    {
        $errors = [];
        if (empty($data['twilio_subaccount_sid'])) {
            $errors['twilio_subaccount_sid'][] = 'Enter the Twilio Account SID.';
        }
        if (empty($data['twilio_messaging_service_sid'])) {
            $errors['twilio_messaging_service_sid'][] = 'Enter the Messaging Service SID.';
        }
        if (empty($data['sms_sender'])) {
            $errors['sms_sender'][] = 'Enter the approved SMS phone number.';
        }
        $hasStoredToken = $existing?->sender_mode === 'customer_owned' && (bool) $existing->twilio_auth_token;
        if ($customerOwned && empty($data['twilio_auth_token']) && ! $hasStoredToken) {
            $errors['twilio_auth_token'][] = 'Enter the Auth Token the first time this Twilio account is connected.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function configurationPayload(?MessagingConfiguration $configuration): ?array
    {
        if (! $configuration) {
            return null;
        }

        return [
            'id' => $configuration->id,
            'sender_mode' => $configuration->sender_mode,
            'status' => $configuration->status,
            'twilio_subaccount_sid' => $configuration->twilio_subaccount_sid,
            'twilio_auth_token_configured' => (bool) $configuration->twilio_auth_token,
            'twilio_messaging_service_sid' => $configuration->twilio_messaging_service_sid,
            'sms_sender' => $configuration->sms_sender,
            'sms_enabled' => $configuration->sms_enabled,
            'verified_at' => $configuration->verified_at,
            'last_health_check_at' => $configuration->last_health_check_at,
            'last_error' => $configuration->last_error,
        ];
    }

    public function deliveries(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $deliveries = MessageDelivery::where('business_id', $businessId)
            ->with(['customer:id,first_name,last_name', 'template:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (MessageDelivery $delivery): array => $this->deliveryPayload($delivery, false));
        $tests = TestMessageDelivery::where('business_id', $businessId)
            ->with(['customer:id,first_name,last_name', 'template:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (TestMessageDelivery $delivery): array => $this->deliveryPayload($delivery, true));
        $items = $deliveries->concat($tests)->sortByDesc('created_at')->take(50)->values();

        return response()->json(['data' => $items, 'meta' => ['total' => $items->count()]]);
    }

    private function deliveryPayload(MessageDelivery|TestMessageDelivery $delivery, bool $isTest): array
    {
        return [
            'id' => $delivery->id,
            'channel' => $delivery->channel,
            'status' => $delivery->status,
            'to_last_four' => $delivery->to_last_four,
            'created_at' => $delivery->created_at,
            'provider_error_code' => $delivery->provider_error_code,
            'failure_message' => $delivery->failure_message,
            'is_test' => $isTest,
            'customer' => $delivery->customer?->only(['first_name', 'last_name']),
            'template' => $delivery->template?->only(['name']),
        ];
    }
}
