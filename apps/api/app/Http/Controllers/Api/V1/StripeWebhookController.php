<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Billing\Models\StripeWebhookEvent;
use App\Domain\Billing\Services\StripeWebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeWebhookProcessor $processor): JsonResponse
    {
        $setting = PlatformStripeSetting::where('status', 'verified')->latest()->first();
        abort_unless($setting?->webhook_secret, 503, 'Stripe webhook is not configured.');
        $payload = $request->getContent();
        abort_unless($this->validSignature($payload, (string) $request->header('Stripe-Signature'), $setting->webhook_secret), 400, 'Invalid Stripe signature.');
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($event['livemode'] ?? false) === ($setting->mode === 'live'), 400, 'Stripe event mode does not match configuration.');
        $stored = StripeWebhookEvent::firstOrCreate(['stripe_event_id' => $event['id']], ['type' => $event['type'], 'livemode' => $event['livemode'] ?? false, 'payload' => $event]);
        if (! $stored->wasRecentlyCreated || $stored->status === 'processed') {
            return response()->json(['received' => true]);
        }
        try {
            $processor->process($event);
            $stored->update(['status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
        } catch (\Throwable $exception) {
            $stored->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            report($exception);

            return response()->json(['message' => 'Event processing failed.'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function validSignature(string $payload, string $header, string $secret): bool
    {
        preg_match('/(?:^|,\s*)t=(\d+)/', $header, $timestampMatch);
        $timestamp = isset($timestampMatch[1]) ? (int) $timestampMatch[1] : 0;
        preg_match_all('/(?:^|,\s*)v1=([a-f0-9]+)/i', $header, $signatureMatches);
        if (! $timestamp || abs(time() - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return collect($signatureMatches[1] ?? [])->contains(fn (string $signature): bool => hash_equals($expected, $signature));
    }
}
