<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Services\TwilioMessagingProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingConfigurationController extends Controller
{
    public function show(Request $request, TwilioMessagingProvider $twilio): JsonResponse
    {
        $business = $request->attributes->get('business');

        return response()->json(['data' => [
            'configuration' => MessagingConfiguration::where('business_id', $business->id)->first(),
            'platform' => [
                'provider' => config('services.twilio.provider'),
                'configured' => config('services.twilio.provider') === 'fake' || $twilio->configured(),
                'recommended_architecture' => 'dedicated_subaccount',
                'status_callback_url' => config('services.twilio.status_callback_url'),
                'inbound_webhook_url' => config('services.twilio.inbound_webhook_url'),
            ],
        ]]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'twilio_subaccount_sid' => ['required', 'regex:/^AC[a-fA-F0-9]{32}$/'],
            'twilio_messaging_service_sid' => ['required', 'regex:/^MG[a-fA-F0-9]{32}$/'],
            'sms_sender' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'whatsapp_sender' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'sms_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
        ]);
        if ($data['sms_enabled']) {
            abort_unless($data['sms_sender'], 422, 'Select an approved SMS sender before enabling SMS.');
        }
        if ($data['whatsapp_enabled']) {
            abort_unless($data['whatsapp_sender'], 422, 'Select an approved WhatsApp sender before enabling WhatsApp.');
        }

        $configuration = MessagingConfiguration::updateOrCreate(
            ['business_id' => $business->id],
            [...$data, 'provider' => 'twilio', 'status' => 'draft', 'verified_at' => null, 'last_error' => null],
        );
        $auditor->record($request, 'messaging.configuration.updated', $configuration, ['sms_enabled' => $configuration->sms_enabled, 'whatsapp_enabled' => $configuration->whatsapp_enabled]);

        return response()->json(['data' => $configuration]);
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

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function deliveries(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $deliveries = MessageDelivery::where('business_id', $businessId)
            ->with(['customer:id,first_name,last_name', 'template:id,name'])
            ->latest()
            ->paginate(50);

        return response()->json(['data' => $deliveries->items(), 'meta' => ['total' => $deliveries->total()]]);
    }
}
