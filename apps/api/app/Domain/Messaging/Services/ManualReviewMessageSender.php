<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\ReviewLink;
use App\Domain\Templates\Services\TemplateRenderer;
use App\Domain\Tenancy\Services\PlanEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ManualReviewMessageSender
{
    public function __construct(
        private readonly MessagingProvider $provider,
        private readonly TemplateRenderer $renderer,
        private readonly MessageCostEstimator $costEstimator,
        private readonly PlanEntitlements $entitlements,
    ) {}

    public function send(ReviewLink $source, string $customBody, string $userId): MessageDelivery
    {
        return DB::transaction(function () use ($source, $customBody, $userId): MessageDelivery {
            $source->loadMissing(['customer.consents', 'customer.suppressions', 'visit.business.messagingConfiguration', 'location', 'deliveries.template']);
            $customer = $source->customer ?? throw new RuntimeException('The customer is no longer available.');
            $original = $source->deliveries()->whereNotNull('message_template_id')->latest()->firstOrFail();
            $channel = $original->channel;
            $configuration = $source->visit->business->messagingConfiguration ?? throw new RuntimeException('Messaging is not configured.');
            abort_unless($configuration->status === 'active', 422, 'Messaging is not active for this business.');
            abort_unless($channel === 'sms' ? $configuration->sms_enabled : $configuration->whatsapp_enabled, 422, strtoupper($channel).' is not enabled.');
            $consent = $customer->consents->where('channel', $channel)->sortByDesc('recorded_at')->first();
            abort_unless($consent?->status === 'granted' && (! $consent->expires_at || $consent->expires_at->isFuture()), 422, 'Valid consent is required before resending.');
            abort_if($customer->suppressions->contains(fn ($entry): bool => $entry->channel === $channel && $entry->released_at === null), 422, 'This customer is suppressed.');

            $plainToken = Str::random(64);
            $link = ReviewLink::create([
                'business_id' => $source->business_id,
                'location_id' => $source->location_id,
                'review_destination_id' => $source->review_destination_id,
                'customer_id' => $source->customer_id,
                'visit_id' => $source->visit_id,
                'token_hash' => hash('sha256', $plainToken),
                'destination_url' => $source->destination_url,
                'expires_at' => now()->addYear(),
            ]);
            $trackingUrl = rtrim(config('services.twilio.tracking_base_url'), '/').'/r/'.$plainToken;
            if (! str_contains($customBody, '{{review_link}}')) {
                $customBody = rtrim($customBody)."\n".'{{review_link}}';
            }
            $body = $this->renderer->render($customBody, [
                'customer_first_name' => $customer->first_name,
                'customer_last_name' => $customer->last_name,
                'business_name' => $source->visit->business->name,
                'location_name' => $source->location->name,
                'review_link' => $trackingUrl,
                'employee_name' => '',
                'visit_date' => $source->visit->completed_at->setTimezone($source->location->timezone)->format('F j, Y'),
            ]);
            $cost = $this->costEstimator->estimate($source->visit->business, $channel, $body);
            $limits = $this->entitlements->for($source->visit->business);
            abort_if(! $limits['allow_overage'] && $limits['message_credits_remaining'] < $cost['billable_credits'], 422, 'This business has no message credits remaining.');

            $delivery = MessageDelivery::create([
                'business_id' => $source->business_id,
                'location_id' => $source->location_id,
                'visit_id' => $source->visit_id,
                'requested_by_user_id' => $userId,
                'delivery_type' => 'manual_resend',
                'customer_id' => $source->customer_id,
                'message_template_id' => $original->message_template_id,
                'review_link_id' => $link->id,
                'provider' => 'twilio',
                'channel' => $channel,
                'body_snapshot' => $body,
                ...$cost,
                'to_hash' => hash('sha256', $customer->phone_e164),
                'to_last_four' => substr($customer->phone_e164, -4),
                'status' => 'pending',
                'queued_at' => now(),
            ]);
            try {
                $result = $this->provider->send($configuration, ['channel' => $channel, 'to' => $customer->phone_e164, 'body' => $body]);
                $delivery->update(['provider_message_sid' => $result['sid'], 'status' => $result['status'] ?? 'queued', 'sent_at' => in_array($result['status'] ?? null, ['sent', 'delivered'], true) ? now() : null]);
            } catch (Throwable $exception) {
                $delivery->update(['status' => 'failed', 'failure_message' => mb_substr($exception->getMessage(), 0, 1000), 'failed_at' => now()]);
            }

            return $delivery->fresh();
        });
    }
}
