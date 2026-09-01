<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Media\Models\GeneratedMedia;
use App\Domain\Media\Services\PersonalizedMediaRenderer;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\ReviewLink;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Templates\Services\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MessagingManager
{
    public function __construct(
        private readonly MessagingProvider $provider,
        private readonly TemplateRenderer $renderer,
        private readonly PersonalizedMediaRenderer $mediaRenderer,
        private readonly MessageCostEstimator $costEstimator,
    ) {}

    public function send(AutomationDispatch $dispatch): MessageDelivery
    {
        return DB::transaction(function () use ($dispatch): MessageDelivery {
            $dispatch = AutomationDispatch::whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $existing = MessageDelivery::where('automation_dispatch_id', $dispatch->id)->first();
            if ($existing && in_array($existing->status, ['queued', 'sent', 'delivered'], true)) {
                return $existing;
            }

            $dispatch->loadMissing(['visit.business.messagingConfiguration', 'visit.customer.consents', 'visit.customer.suppressions', 'visit.location.reviewDestinations', 'rule.messageTemplate.reviewDestination', 'rule.followUps.messageTemplate.reviewDestination']);
            abort_unless($dispatch->decision === 'scheduled' && $dispatch->scheduled_for?->lte(now()), 422, 'This message is not due.');
            $visit = $dispatch->visit;
            $customer = $visit->customer ?? throw new RuntimeException('The customer is missing.');
            $configuration = $visit->business->messagingConfiguration ?? throw new RuntimeException('Messaging is not configured.');
            abort_unless($configuration->status === 'active', 422, 'Messaging is not active for this business.');
            $template = $this->template($dispatch);
            $channel = $template->channel === 'mms' ? 'sms' : $template->channel;
            abort_unless(in_array($channel, ['sms', 'whatsapp'], true), 422, 'Unsupported messaging channel.');
            $consentChannel = $channel;
            abort_unless($consentChannel === 'sms' ? $configuration->sms_enabled : $configuration->whatsapp_enabled, 422, strtoupper($channel).' is not enabled.');
            abort_unless($this->hasConsent($customer, $consentChannel), 422, 'Valid '.$consentChannel.' consent is required.');
            abort_unless(! $this->suppressed($customer, $consentChannel), 422, 'The customer is suppressed for '.$consentChannel.'.');
            $destination = $template->reviewDestination ?? $visit->location->reviewDestinations->firstWhere('is_primary', true) ?? $visit->location->reviewDestinations->first();
            if ($destination && $destination->location_id !== $visit->location_id) {
                $destination = $visit->location->reviewDestinations->firstWhere('is_primary', true) ?? $visit->location->reviewDestinations->first();
            }
            $destinationUrl = $destination?->url ?? $visit->location->google_review_url;
            abort_unless($destinationUrl && (! $destination || ($destination->business_id === $visit->business_id && $destination->location_id === $visit->location_id && $destination->status === 'active')), 422, 'The template review link must belong to this visit location and remain active.');

            $plainToken = Str::random(64);
            $link = ReviewLink::create([
                'business_id' => $visit->business_id,
                'location_id' => $visit->location_id,
                'review_destination_id' => $destination?->id,
                'customer_id' => $customer->id,
                'visit_id' => $visit->id,
                'token_hash' => hash('sha256', $plainToken),
                'destination_url' => $destinationUrl,
                'expires_at' => now()->addYear(),
            ]);
            $reviewUrl = rtrim(config('services.twilio.tracking_base_url'), '/').'/r/'.$plainToken;
            $values = [
                'customer_first_name' => $customer->first_name,
                'customer_last_name' => $customer->last_name,
                'business_name' => $visit->business->name,
                'location_name' => $visit->location->name,
                'review_link' => $reviewUrl,
                'employee_name' => '',
                'visit_date' => $visit->completed_at->setTimezone($visit->location->timezone)->format('F j, Y'),
            ];
            $body = $this->renderer->render($template->body, $values);
            $cost = $this->costEstimator->estimate($visit->business, $channel, $body, (bool) $template->include_media);
            $this->costEstimator->assertAvailable($visit->business, $cost['billable_credits']);
            if ($channel === 'whatsapp' && ! $template->provider_template_sid) {
                throw new RuntimeException('An approved Twilio Content Template SID is required for WhatsApp.');
            }

            $generatedMedia = null;
            $mediaUrl = null;
            if ($channel === 'sms' && $template->include_media) {
                abort_unless($template->media_template_id, 422, 'Select personalized media before sending an SMS with an image.');
                $plainMediaToken = Str::random(64);
                $generatedMedia = GeneratedMedia::create([
                    'business_id' => $visit->business_id,
                    'location_id' => $visit->location_id,
                    'visit_id' => $visit->id,
                    'delivery_type' => 'automation',
                    'customer_id' => $customer->id,
                    'media_template_id' => $template->media_template_id,
                    'disk' => $template->mediaTemplate->disk,
                    'access_token_hash' => hash('sha256', $plainMediaToken),
                    'expires_at' => now()->addDays(30),
                ]);
                $this->mediaRenderer->render($generatedMedia);
                $mediaUrl = rtrim(config('app.url'), '/').'/api/v1/m/'.$plainMediaToken;
            }

            $delivery = MessageDelivery::updateOrCreate(
                ['automation_dispatch_id' => $dispatch->id],
                [
                    'business_id' => $visit->business_id,
                    'customer_id' => $customer->id,
                    'message_template_id' => $template->id,
                    'review_link_id' => $link->id,
                    'generated_media_id' => $generatedMedia?->id,
                    'provider' => 'twilio',
                    'channel' => $channel,
                    'body_snapshot' => $body,
                    ...$cost,
                    'to_hash' => hash('sha256', $customer->phone_e164),
                    'to_last_four' => substr($customer->phone_e164, -4),
                    'status' => 'pending',
                    'queued_at' => now(),
                    'provider_error_code' => null,
                    'failure_message' => null,
                    'failed_at' => null,
                ],
            );

            try {
                $result = $this->provider->send($configuration, [
                    'channel' => $channel,
                    'to' => $customer->phone_e164,
                    'body' => $body,
                    'content_sid' => $template->provider_template_sid,
                    'content_variables' => ['1' => $customer->first_name, '2' => $visit->business->name, '3' => $reviewUrl],
                    'media_url' => $mediaUrl,
                ]);
                $delivery->update([
                    'provider_message_sid' => $result['sid'],
                    'status' => $result['status'] ?? 'queued',
                    'sent_at' => in_array($result['status'] ?? null, ['sent', 'delivered'], true) ? now() : null,
                ]);
            } catch (Throwable $exception) {
                $delivery->update(['status' => 'failed', 'failure_message' => mb_substr($exception->getMessage(), 0, 1000), 'failed_at' => now()]);
            }

            return $delivery->fresh();
        });
    }

    private function template(AutomationDispatch $dispatch): MessageTemplate
    {
        if ($dispatch->sequence_number === 0) {
            return $dispatch->rule->messageTemplate;
        }

        return $dispatch->rule->followUps->firstWhere('sequence_number', $dispatch->sequence_number)?->messageTemplate
            ?? throw new RuntimeException('The follow-up template is missing.');
    }

    private function hasConsent($customer, string $channel): bool
    {
        $consent = $customer->consents->where('channel', $channel)->sortByDesc('recorded_at')->first();

        return $consent?->status === 'granted' && (! $consent->expires_at || $consent->expires_at->isFuture());
    }

    private function suppressed($customer, string $channel): bool
    {
        return $customer->suppressions->contains(fn ($entry): bool => $entry->channel === $channel && $entry->released_at === null);
    }
}
