<?php

namespace Tests\Feature;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Messaging\Jobs\SendAutomationDispatch;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\ReviewLink;
use App\Domain\Messaging\Services\DueMessageDispatcher;
use App\Domain\Messaging\Services\MessagingManager;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.provider' => 'fake',
            'services.twilio.auth_token' => 'test-auth-token',
            'services.twilio.status_callback_url' => 'https://api.revieworbit.test/api/v1/webhooks/twilio/status',
            'services.twilio.inbound_webhook_url' => 'https://api.revieworbit.test/api/v1/webhooks/twilio/inbound',
            'services.twilio.tracking_base_url' => 'https://api.revieworbit.test',
        ]);
    }

    public function test_configuration_is_tenant_scoped_and_uses_an_approved_sender(): void
    {
        [$ownerA, $businessA] = $this->fixture('Business A');
        [$ownerB, $businessB] = $this->fixture('Business B');

        $this->actingAs($ownerA)->putJson('/api/v1/messaging-configuration', [
            'twilio_subaccount_sid' => 'AC'.str_repeat('a', 32),
            'twilio_messaging_service_sid' => 'MG'.str_repeat('b', 32),
            'sms_sender' => '+12025550123',
            'whatsapp_sender' => null,
            'sms_enabled' => true,
            'whatsapp_enabled' => false,
        ], ['X-Business-ID' => $businessA->id])->assertOk()->assertJsonPath('data.status', 'draft');

        $this->postJson('/api/v1/messaging-configuration/verify', [], ['X-Business-ID' => $businessA->id])
            ->assertOk()->assertJsonPath('data.configuration.status', 'active');
        $this->actingAs($ownerB)->getJson('/api/v1/messaging-configuration', ['X-Business-ID' => $businessB->id])
            ->assertOk()->assertJsonPath('data.configuration', null);
        $this->assertDatabaseHas('messaging_configurations', ['business_id' => $businessA->id, 'sms_sender' => '+12025550123']);
        $this->assertDatabaseMissing('messaging_configurations', ['business_id' => $businessB->id]);
    }

    public function test_due_sms_is_recorded_through_the_fake_provider_without_exposing_the_phone(): void
    {
        [, $business, , $customer, , , $dispatch] = $this->fixture();
        $this->configuration($business, sms: true);

        $delivery = app(MessagingManager::class)->send($dispatch);

        $this->assertSame('sent', $delivery->status);
        $this->assertSame('sms', $delivery->channel);
        $this->assertStringStartsWith('SMFAKE', $delivery->provider_message_sid);
        $this->assertSame(hash('sha256', $customer->phone_e164), $delivery->to_hash);
        $this->assertSame('0123', $delivery->to_last_four);
        $this->assertDatabaseCount('message_deliveries', 1);
        $this->assertDatabaseCount('review_links', 1);
        $this->assertArrayNotHasKey('phone_e164', $delivery->getAttributes());

        $sameDelivery = app(MessagingManager::class)->send($dispatch);
        $this->assertSame($delivery->id, $sameDelivery->id);
        $this->assertDatabaseCount('message_deliveries', 1);
    }

    public function test_mms_generates_recipient_media_and_links_it_to_the_delivery(): void
    {
        Storage::fake('local');
        [, $business, , $customer, $template, , $dispatch] = $this->fixture(channel: 'sms');
        $customer->update(['first_name' => 'Christopher Maximilian Alexander']);
        $this->configuration($business, sms: true);
        $background = UploadedFile::fake()->image('board.jpg', 1080, 1080);
        $path = $background->store('businesses/'.$business->id.'/media-templates', 'local');
        $media = MediaTemplate::create([
            'business_id' => $business->id, 'name' => 'Welcome board', 'disk' => 'local',
            'background_image_path' => $path, 'width' => 1080, 'height' => 1080,
            'text_configuration' => [
                'text' => '{{customer_first_name}}', 'color' => '#17201b', 'font_size' => 72, 'min_font_size' => 20,
                'font_family' => 'Poppins', 'align' => 'center', 'max_lines' => 2,
                'x' => 22, 'y' => 38, 'width' => 56, 'height' => 24,
                'top_left_x' => 24, 'top_left_y' => 36, 'top_right_x' => 79, 'top_right_y' => 40,
                'bottom_right_x' => 76, 'bottom_right_y' => 65, 'bottom_left_x' => 21, 'bottom_left_y' => 61,
            ],
        ]);
        $template->update(['include_media' => true, 'media_template_id' => $media->id]);

        $delivery = app(MessagingManager::class)->send($dispatch);

        $this->assertSame('sms', $delivery->channel);
        $this->assertNotNull($delivery->generated_media_id);
        $generated = $delivery->generatedMedia;
        $this->assertSame('ready', $generated->status);
        $this->assertNotNull($generated->access_token_hash);
        Storage::disk('local')->assertExists($generated->path);
    }

    public function test_whatsapp_requires_its_own_consent_and_an_approved_content_template(): void
    {
        [, $business, , $customer, $template, , $dispatch] = $this->fixture(channel: 'whatsapp');
        $this->configuration($business, whatsapp: true);

        try {
            app(MessagingManager::class)->send($dispatch);
            $this->fail('WhatsApp sending should require WhatsApp-specific consent.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('whatsapp consent', $exception->getMessage());
        }

        $customer->consents()->create(['business_id' => $business->id, 'channel' => 'whatsapp', 'status' => 'granted', 'source' => 'written', 'recorded_at' => now()]);
        try {
            app(MessagingManager::class)->send($dispatch);
            $this->fail('WhatsApp sending should require an approved content template.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Content Template SID', $exception->getMessage());
        }

        $template->update(['provider_template_sid' => 'HX'.str_repeat('c', 32)]);
        $delivery = app(MessagingManager::class)->send($dispatch);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('whatsapp', $delivery->channel);
    }

    public function test_signed_status_callback_updates_only_the_matching_delivery(): void
    {
        [, $business, , , , , $dispatch] = $this->fixture();
        $this->configuration($business, sms: true);
        $delivery = app(MessagingManager::class)->send($dispatch);
        $url = config('services.twilio.status_callback_url');
        $payload = ['MessageSid' => $delivery->provider_message_sid, 'MessageStatus' => 'delivered'];

        $this->post('/api/v1/webhooks/twilio/status', $payload, ['X-Twilio-Signature' => 'invalid'])->assertForbidden();
        $this->post('/api/v1/webhooks/twilio/status', $payload, ['X-Twilio-Signature' => $this->signature($url, $payload)])->assertNoContent();

        $this->assertDatabaseHas('message_deliveries', ['id' => $delivery->id, 'status' => 'delivered']);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_signed_whatsapp_stop_and_start_are_channel_specific(): void
    {
        [, $business, , $customer] = $this->fixture();
        $configuration = $this->configuration($business, whatsapp: true);
        $url = config('services.twilio.inbound_webhook_url');
        $stop = [
            'MessagingServiceSid' => $configuration->twilio_messaging_service_sid,
            'From' => 'whatsapp:'.$customer->phone_e164,
            'To' => 'whatsapp:'.$configuration->whatsapp_sender,
            'Body' => 'STOP',
            'OptOutType' => 'STOP',
        ];

        $this->post('/api/v1/webhooks/twilio/inbound', $stop, ['X-Twilio-Signature' => $this->signature($url, $stop)])->assertNoContent();
        $this->assertDatabaseHas('suppression_entries', ['business_id' => $business->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'reason' => 'opt_out', 'released_at' => null]);
        $this->assertDatabaseMissing('suppression_entries', ['business_id' => $business->id, 'customer_id' => $customer->id, 'channel' => 'sms']);

        $start = [...$stop, 'Body' => 'START', 'OptOutType' => 'START'];
        $this->post('/api/v1/webhooks/twilio/inbound', $start, ['X-Twilio-Signature' => $this->signature($url, $start)])->assertNoContent();
        $this->assertDatabaseHas('customer_consents', ['business_id' => $business->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp', 'status' => 'granted']);
        $this->assertNotNull($customer->suppressions()->where('channel', 'whatsapp')->first()->released_at);
    }

    public function test_review_link_records_a_click_and_redirects_without_claiming_a_review(): void
    {
        [, $business, $location, $customer, , , , $visit] = $this->fixture();
        $token = Str::random(64);
        $link = ReviewLink::create([
            'business_id' => $business->id,
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'visit_id' => $visit->id,
            'token_hash' => hash('sha256', $token),
            'destination_url' => $location->google_review_url,
            'expires_at' => now()->addDay(),
        ]);

        $this->get('/r/'.$token)->assertRedirect($location->google_review_url);
        $this->assertSame(1, $link->fresh()->click_count);
        $this->assertNotNull($link->fresh()->first_clicked_at);
    }

    public function test_scheduler_queues_only_due_dispatches_for_active_messaging_workspaces(): void
    {
        Queue::fake();
        [, $activeBusiness, , , , , $activeDispatch] = $this->fixture('Active Business');
        [, , , , , , $inactiveDispatch] = $this->fixture('Inactive Business');
        $this->configuration($activeBusiness, sms: true);

        $count = app(DueMessageDispatcher::class)->dispatch();

        $this->assertSame(1, $count);
        Queue::assertPushed(SendAutomationDispatch::class, fn (SendAutomationDispatch $job) => $job->dispatchId === $activeDispatch->id);
        Queue::assertNotPushed(SendAutomationDispatch::class, fn (SendAutomationDispatch $job) => $job->dispatchId === $inactiveDispatch->id);
    }

    private function fixture(string $name = 'AL Barber Shop', string $channel = 'sms'): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'default_timezone' => 'America/New_York']);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);
        $location = Location::create(['business_id' => $business->id, 'name' => 'Main Street', 'timezone' => 'America/New_York', 'google_review_url' => 'https://example.invalid/review']);
        $customer = Customer::create(['business_id' => $business->id, 'first_name' => 'Umer', 'phone_e164' => '+12025550123', 'phone_hash' => hash('sha256', '+12025550123')]);
        $customer->consents()->create(['business_id' => $business->id, 'channel' => 'sms', 'status' => 'granted', 'source' => 'written', 'recorded_at' => now()->subDay()]);
        $template = MessageTemplate::create(['business_id' => $business->id, 'name' => 'Review request', 'channel' => $channel, 'body' => 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}: {{review_link}}', 'status' => 'active']);
        $rule = AutomationRule::create(['business_id' => $business->id, 'location_id' => $location->id, 'message_template_id' => $template->id, 'name' => 'Completed visit', 'status' => 'active', 'delay_minutes' => 0, 'frequency_limit_days' => 30]);
        $visit = Visit::create(['business_id' => $business->id, 'location_id' => $location->id, 'customer_id' => $customer->id, 'source' => 'manual', 'type' => 'service', 'status' => 'completed', 'completed_at' => now()->subMinute()]);
        $dispatch = AutomationDispatch::create(['business_id' => $business->id, 'visit_id' => $visit->id, 'automation_rule_id' => $rule->id, 'sequence_number' => 0, 'decision' => 'scheduled', 'scheduled_for' => now()->subSecond()]);

        return [$owner, $business, $location, $customer, $template, $rule, $dispatch, $visit];
    }

    private function configuration(Business $business, bool $sms = false, bool $whatsapp = false): MessagingConfiguration
    {
        return MessagingConfiguration::create([
            'business_id' => $business->id,
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_subaccount_sid' => 'AC'.str_repeat('a', 32),
            'twilio_messaging_service_sid' => 'MG'.str_repeat('b', 32),
            'sms_sender' => $sms ? '+12025550111' : null,
            'whatsapp_sender' => $whatsapp ? '+12025550112' : null,
            'sms_enabled' => $sms,
            'whatsapp_enabled' => $whatsapp,
            'verified_at' => now(),
        ]);
    }

    private function signature(string $url, array $payload): string
    {
        ksort($payload, SORT_STRING);
        $data = $url;
        foreach ($payload as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, config('services.twilio.auth_token'), true));
    }
}
