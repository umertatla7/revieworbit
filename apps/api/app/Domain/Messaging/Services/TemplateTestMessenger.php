<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Models\GeneratedMedia;
use App\Domain\Media\Services\PersonalizedMediaRenderer;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\TestMessageDelivery;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Templates\Services\TemplateRenderer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TemplateTestMessenger
{
    public function __construct(
        private readonly MessagingProvider $provider,
        private readonly TemplateRenderer $renderer,
        private readonly PersonalizedMediaRenderer $mediaRenderer,
        private readonly TwilioCredentials $credentials,
        private readonly TrialMessageLimiter $trialLimiter,
    ) {}

    public function send(MessageTemplate $template, Customer $customer, string $requestedByUserId, bool $trialRecipientVerified): TestMessageDelivery
    {
        if (app()->environment('production') && ! $this->credentials->configured()) {
            throw ValidationException::withMessages(['configuration' => ['A super administrator must verify the Twilio platform connection first.']]);
        }

        $channel = $template->channel === 'mms' ? 'sms' : $template->channel;
        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            throw ValidationException::withMessages(['template' => ['This template channel cannot be tested.']]);
        }
        if ($this->credentials->mode() === 'trial' && ! $trialRecipientVerified) {
            throw ValidationException::withMessages(['trial_recipient_verified' => ['Confirm that this recipient is verified in the Twilio Trial account.']]);
        }

        $configuration = MessagingConfiguration::where('business_id', $template->business_id)->first();
        if (! $configuration || $configuration->status !== 'active') {
            throw ValidationException::withMessages(['configuration' => ['Verify and activate this business’s Twilio configuration before sending a test.']]);
        }
        if ($channel === 'sms' && ! $configuration->sms_enabled) {
            throw ValidationException::withMessages(['configuration' => ['SMS is not enabled for this business.']]);
        }
        if ($channel === 'whatsapp' && ! $configuration->whatsapp_enabled) {
            throw ValidationException::withMessages(['configuration' => ['WhatsApp is not enabled for this business.']]);
        }
        if (! $customer->phone_e164) {
            throw ValidationException::withMessages(['customer_id' => ['The selected customer has no mobile number.']]);
        }

        $this->trialLimiter->assertMaySend($template->business, isTest: true);

        $consent = $customer->consents()->where('channel', $channel)->latest('recorded_at')->first();
        if (! $consent || $consent->status !== 'granted' || ($consent->expires_at && $consent->expires_at->isPast())) {
            throw ValidationException::withMessages(['customer_id' => ['The selected customer does not have valid '.$channel.' consent.']]);
        }
        if ($customer->suppressions()->where('channel', $channel)->whereNull('released_at')->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['The selected customer is suppressed for '.$channel.'.']]);
        }
        if ($channel === 'whatsapp' && ! $template->provider_template_sid) {
            throw ValidationException::withMessages(['template' => ['An approved Twilio Content Template SID is required for WhatsApp.']]);
        }

        $template->loadMissing(['business.locations', 'mediaTemplate']);
        $location = $template->business->locations->first();
        $testLink = rtrim(config('services.frontend.url'), '/').'/dashboard/templates?test=1';
        $body = '[B Reviews test] '.$this->renderer->render($template->body, [
            'customer_first_name' => $customer->first_name,
            'customer_last_name' => $customer->last_name,
            'business_name' => $template->business->name,
            'location_name' => $location?->name ?? 'Test location',
            'review_link' => $testLink,
            'employee_name' => 'Test team member',
            'visit_date' => now()->setTimezone($location?->timezone ?? 'UTC')->format('F j, Y'),
        ]);

        $mediaUrl = null;
        if ($channel === 'sms' && $template->include_media) {
            if (! $template->mediaTemplate) {
                throw ValidationException::withMessages(['template' => ['The selected personalized media template no longer exists.']]);
            }
            $plainMediaToken = Str::random(64);
            $generatedMedia = GeneratedMedia::create([
                'business_id' => $template->business_id,
                'customer_id' => $customer->id,
                'media_template_id' => $template->media_template_id,
                'disk' => $template->mediaTemplate->disk,
                'access_token_hash' => hash('sha256', $plainMediaToken),
                'expires_at' => now()->addDays(30),
            ]);
            $this->mediaRenderer->render($generatedMedia);
            $mediaUrl = rtrim(config('app.url'), '/').'/api/v1/m/'.$plainMediaToken;
        }

        $delivery = TestMessageDelivery::create([
            'business_id' => $template->business_id,
            'customer_id' => $customer->id,
            'message_template_id' => $template->id,
            'requested_by_user_id' => $requestedByUserId,
            'provider' => 'twilio',
            'channel' => $channel,
            'to_hash' => hash('sha256', $customer->phone_e164),
            'to_last_four' => substr($customer->phone_e164, -4),
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        try {
            $result = $this->provider->send($configuration, [
                'channel' => $channel,
                'to' => $customer->phone_e164,
                'body' => $body,
                'content_sid' => $template->provider_template_sid,
                'content_variables' => ['1' => $customer->first_name, '2' => $template->business->name, '3' => $testLink],
                'media_url' => $mediaUrl,
            ]);
            $delivery->update([
                'provider_message_sid' => $result['sid'],
                'status' => $result['status'] ?? 'queued',
                'sent_at' => in_array($result['status'] ?? null, ['sent', 'delivered'], true) ? now() : null,
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failure_message' => mb_substr($exception->getMessage(), 0, 1000),
                'failed_at' => now(),
            ]);
            throw ValidationException::withMessages(['delivery' => [$exception->getMessage()]]);
        }

        return $delivery->fresh();
    }
}
