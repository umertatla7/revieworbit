<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Jobs\ProcessToastWebhook;
use App\Domain\Integrations\Models\IntegrationWebhookEvent;
use App\Domain\Integrations\Services\ToastConnector;
use App\Domain\Integrations\Services\ToastWebhookSignatureValidator;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToastWebhookController extends Controller
{
    public function ingest(Request $request, string $environment, string $category, ToastConnector $toast, ToastWebhookSignatureValidator $validator): JsonResponse
    {
        validator(['environment' => $environment, 'category' => $category], [
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'category' => ['required', Rule::in(['partners', 'orders'])],
        ])->validate();
        $setting = $toast->setting($environment);
        $raw = $request->getContent();
        $signature = (string) $request->header('Toast-Signature');
        $secret = $category === 'partners' ? $setting->partner_webhook_secret : $setting->orders_webhook_secret;
        abort_unless($validator->valid($raw, $signature, (string) $secret), 401, 'Invalid Toast webhook signature.');
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400, 'Invalid JSON payload.');
        $eventId = (string) ($payload['guid'] ?? '');
        abort_if($eventId === '', 422, 'Toast webhook event GUID is required.');
        $payload['_meta'] = [
            'environment' => $environment,
            'restaurant_guid' => $request->header('Toast-Restaurant-External-ID'),
        ];

        try {
            $event = IntegrationWebhookEvent::create([
                'provider' => 'toast', 'category' => $category,
                'event_type' => strtolower((string) ($payload['eventType'] ?? 'unknown')),
                'external_event_id' => $eventId,
                'payload_fingerprint' => hash('sha256', $raw),
                'payload' => $payload, 'status' => 'received', 'verified_at' => now(),
            ]);
            ProcessToastWebhook::dispatch($event->id);
        } catch (UniqueConstraintViolationException) {
            // Toast retries are acknowledged; the first verified event remains authoritative.
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
