<?php

namespace Tests\Feature;

use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Customers\Models\Customer;
use App\Domain\Integrations\Models\IntegrationApiKey;
use App\Domain\Media\Jobs\GeneratePersonalizedMedia;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MilestoneSixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_completed_visit_records_a_quiet_hour_safe_dispatch(): void
    {
        Carbon::setTestNow('2026-08-05 01:00:00'); // 21:00 previous day in New York
        [$owner, $business, $location, $customer, , $rule] = $this->fixture();

        $response = $this->actingAs($owner)->postJson('/api/v1/visits', [
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'automation_rule_id' => $rule->id,
            'completed_at' => now()->subMinute()->toIso8601String(),
            'type' => 'service',
            'amount' => 45,
            'currency' => 'USD',
        ], ['X-Business-ID' => $business->id]);

        $response->assertCreated()->assertJsonPath('data.automation_dispatches.0.decision', 'scheduled');
        $this->assertDatabaseHas('automation_dispatches', ['automation_rule_id' => $rule->id, 'decision' => 'scheduled']);
        $scheduled = Carbon::parse($response->json('data.automation_dispatches.0.scheduled_for'));
        $this->assertSame('09:00', $scheduled->timezone('America/New_York')->format('H:i'));
    }

    public function test_suppression_produces_a_clear_skip_reason(): void
    {
        [$owner, $business, $location, $customer, , $rule] = $this->fixture();
        $customer->suppressions()->create(['business_id' => $business->id, 'channel' => 'sms', 'reason' => 'opt_out', 'suppressed_at' => now()]);

        $response = $this->actingAs($owner)->postJson('/api/v1/visits', [
            'location_id' => $location->id, 'customer_id' => $customer->id, 'automation_rule_id' => $rule->id,
            'completed_at' => now()->subMinute()->toIso8601String(), 'type' => 'service',
        ], ['X-Business-ID' => $business->id]);

        $response->assertCreated()->assertJsonPath('data.automation_dispatches.0.reason_code', 'customer_suppressed');
    }

    public function test_frequency_limit_skips_a_second_recent_visit(): void
    {
        [$owner, $business, $location, $customer, , $rule] = $this->fixture();
        $headers = ['X-Business-ID' => $business->id];
        $payload = ['location_id' => $location->id, 'customer_id' => $customer->id, 'automation_rule_id' => $rule->id, 'completed_at' => now()->subMinutes(2)->toIso8601String(), 'type' => 'service'];
        $this->actingAs($owner)->postJson('/api/v1/visits', $payload, $headers)->assertCreated()->assertJsonPath('data.automation_dispatches.0.decision', 'scheduled');

        $payload['completed_at'] = now()->subMinute()->toIso8601String();
        $this->postJson('/api/v1/visits', $payload, $headers)->assertCreated()->assertJsonPath('data.automation_dispatches.0.reason_code', 'frequency_limited');
    }

    public function test_confirmed_reviewer_is_skipped_on_every_future_visit(): void
    {
        [$owner, $business, $location, $customer, , $rule] = $this->fixture();
        $customer->update([
            'review_request_status' => 'review_confirmed',
            'review_confirmed_at' => now()->subWeeks(3),
            'review_confirmation_source' => 'customer_confirmed',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/visits', [
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'automation_rule_id' => $rule->id,
            'completed_at' => now()->subMinute()->toIso8601String(),
            'type' => 'service',
        ], ['X-Business-ID' => $business->id])
            ->assertCreated()
            ->assertJsonPath('data.automation_dispatches.0.reason_code', 'review_already_confirmed');
    }

    public function test_automation_uses_business_delivery_hours_and_rejects_conflicting_follow_up_times(): void
    {
        [$owner, $business, $location, , $template, $rule] = $this->fixture();
        $rule->delete();
        $template->update(['location_id' => $location->id]);
        $business->update([
            'plan_code' => 'momentum',
            'messaging_preferences' => ['quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:30'],
        ]);
        $headers = ['X-Business-ID' => $business->id];
        $payload = [
            'name' => 'Simple review journey',
            'location_id' => $location->id,
            'message_template_id' => $template->id,
            'delay_minutes' => 60,
            'frequency_limit_days' => 30,
            'status' => 'active',
            'follow_ups' => [[
                'message_template_id' => $template->id,
                'delay_minutes' => 2880,
                'cancel_after_click' => true,
            ]],
        ];

        $this->actingAs($owner)->postJson('/api/v1/automations', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.quiet_hours_start', '21:00')
            ->assertJsonPath('data.quiet_hours_end', '08:30');

        $payload['name'] = 'Conflicting journey';
        $payload['follow_ups'][0]['delay_minutes'] = 30;
        $this->postJson('/api/v1/automations', $payload, $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('follow_ups.0.delay_minutes');
    }

    public function test_manual_visit_rejects_cross_tenant_location_and_customer_ids(): void
    {
        [$ownerA, $businessA] = $this->fixture('Business A');
        [, , $locationB, $customerB, , $ruleB] = $this->fixture('Business B');

        $this->actingAs($ownerA)->postJson('/api/v1/visits', [
            'location_id' => $locationB->id, 'customer_id' => $customerB->id, 'automation_rule_id' => $ruleB->id,
            'completed_at' => now()->subMinute()->toIso8601String(), 'type' => 'service',
        ], ['X-Business-ID' => $businessA->id])->assertNotFound();
    }

    public function test_generic_api_is_authenticated_normalized_and_idempotent(): void
    {
        [, $business, $location] = $this->fixture();
        $token = 'ro_test_example.'.Str::random(48);
        IntegrationApiKey::create([
            'business_id' => $business->id, 'name' => 'Test API', 'key_prefix' => 'examplekey12',
            'token_hash' => hash('sha256', $token), 'hmac_secret_encrypted' => Str::random(64), 'abilities' => ['visits:write'],
        ]);
        $location->update(['external_reference' => 'location_1']);
        $payload = $this->genericPayload();
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'event-123-attempt-1'];

        $this->postJson('/api/v1/integrations/generic/events', $payload, $headers)->assertAccepted()->assertJsonPath('data.created', true);
        $this->postJson('/api/v1/integrations/generic/events', $payload, $headers)->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseHas('customer_external_identities', ['business_id' => $business->id, 'provider' => 'custom', 'external_customer_id' => 'customer_50']);
    }

    public function test_generic_webhook_rejects_an_invalid_signature(): void
    {
        [, $business] = $this->fixture();
        IntegrationApiKey::create(['business_id' => $business->id, 'name' => 'Webhook', 'key_prefix' => 'examplekey12', 'token_hash' => hash('sha256', Str::random(64)), 'hmac_secret_encrypted' => Str::random(64), 'abilities' => ['visits:write']]);

        $this->postJson('/api/v1/webhooks/generic', $this->genericPayload(), [
            'X-ReviewOrbit-Key' => 'examplekey12', 'X-ReviewOrbit-Timestamp' => (string) now()->timestamp, 'X-ReviewOrbit-Signature' => str_repeat('0', 64),
        ])->assertUnauthorized();
    }

    public function test_generic_webhook_accepts_a_valid_raw_body_signature(): void
    {
        [, $business, $location] = $this->fixture();
        $location->update(['external_reference' => 'location_1']);
        $secret = Str::random(64);
        IntegrationApiKey::create(['business_id' => $business->id, 'name' => 'Webhook', 'key_prefix' => 'signedkey123', 'token_hash' => hash('sha256', Str::random(64)), 'hmac_secret_encrypted' => $secret, 'abilities' => ['visits:write']]);
        $raw = json_encode($this->genericPayload(), JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $this->call('POST', '/api/v1/webhooks/generic', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REVIEWORBIT_KEY' => 'signedkey123',
            'HTTP_X_REVIEWORBIT_TIMESTAMP' => $timestamp,
            'HTTP_X_REVIEWORBIT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ], $raw)->assertAccepted();
        $this->assertDatabaseHas('visits', ['business_id' => $business->id, 'source' => 'custom', 'external_visit_id' => 'event_123']);
    }

    public function test_csv_import_handles_quoted_values_and_returns_a_result_report(): void
    {
        [$owner, $business] = $this->fixture();
        $file = UploadedFile::fake()->createWithContent('customers.csv', "first_name,last_name,email,phone\n\"Ava, Marie\",Jones,ava@example.com,+12025550188\nInvalid,Phone,,not-a-phone\n");

        $response = $this->actingAs($owner)->post('/api/v1/customers/import-csv', ['file' => $file, 'preview' => '0'], ['X-Business-ID' => $business->id]);

        $response->assertAccepted()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 1);
        $this->assertDatabaseHas('customers', ['business_id' => $business->id, 'first_name' => 'Ava, Marie']);
    }

    public function test_personalized_media_is_private_and_queued(): void
    {
        Storage::fake('local');
        Queue::fake();
        [$owner, $business, , $customer] = $this->fixture();
        $business->update(['plan_code' => 'momentum']);
        $headers = ['X-Business-ID' => $business->id];
        $response = $this->actingAs($owner)->post('/api/v1/media-templates', [
            'name' => 'Thank you card', 'background' => UploadedFile::fake()->image('background.png', 800, 600),
            'text' => 'Thank you, {{customer_first_name}}!', 'color' => '#ffffff', 'font_size' => 48,
            'min_font_size' => 16, 'font_family' => 'Montserrat', 'max_lines' => 2, 'placement_mode' => 'manual',
            'x' => 25, 'y' => 38, 'placement_width' => 50, 'placement_height' => 26,
            'top_left_x' => 25, 'top_left_y' => 38, 'top_right_x' => 76, 'top_right_y' => 36,
            'bottom_right_x' => 74, 'bottom_right_y' => 62, 'bottom_left_x' => 24, 'bottom_left_y' => 65,
        ], $headers)->assertCreated()->assertJsonPath('data.text_configuration.font_family', 'Montserrat');

        $this->actingAs($owner)->post('/api/v1/media-templates', [
            'name' => 'Thank you card', 'background' => UploadedFile::fake()->image('duplicate.png', 800, 600),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/media-templates/'.$response->json('data.id').'/generate', ['customer_id' => $customer->id], $headers)->assertAccepted();
        $this->postJson('/api/v1/templates', [
            'name' => 'Review request with image',
            'channel' => 'sms',
            'body' => 'Hi {{customer_first_name}} {{review_link}}',
            'include_media' => true,
            'media_template_id' => $response->json('data.id'),
            'status' => 'active',
        ], $headers)->assertCreated()->assertJsonPath('data.channel', 'sms')->assertJsonPath('data.include_media', true);
        Queue::assertPushed(GeneratePersonalizedMedia::class);
        Storage::disk('local')->assertExists($response->json('data.background_image_path'));
    }

    private function fixture(string $name = 'AL Barber Shop'): array
    {
        $suffix = Str::slug($name).'-'.Str::lower(Str::random(5));
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => $suffix, 'default_timezone' => 'America/New_York']);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);
        $location = Location::create(['business_id' => $business->id, 'name' => 'Main Street', 'timezone' => 'America/New_York', 'external_reference' => $suffix]);
        $customer = Customer::create(['business_id' => $business->id, 'first_name' => 'Umer', 'phone_e164' => '+12025550123', 'phone_hash' => hash('sha256', '+12025550123')]);
        $customer->consents()->create(['business_id' => $business->id, 'channel' => 'sms', 'status' => 'granted', 'source' => 'written', 'recorded_at' => now()->subDay()]);
        $template = MessageTemplate::create(['business_id' => $business->id, 'name' => 'Review request', 'channel' => 'sms', 'body' => 'Hi {{customer_first_name}} {{review_link}}', 'status' => 'active']);
        $rule = AutomationRule::create(['business_id' => $business->id, 'location_id' => $location->id, 'message_template_id' => $template->id, 'name' => 'Completed visit', 'status' => 'active', 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '09:00', 'frequency_limit_days' => 30]);

        return [$owner, $business, $location, $customer, $template, $rule];
    }

    private function genericPayload(): array
    {
        return [
            'source' => 'custom', 'event_type' => 'visit.completed', 'external_event_id' => 'event_123', 'external_location_id' => 'location_1',
            'customer' => ['external_id' => 'customer_50', 'first_name' => 'Umer', 'last_name' => null, 'phone' => '+12025550100', 'email' => 'umer@example.com', 'consent' => ['status' => 'opted_in', 'source' => 'pos_checkout', 'consented_at' => now()->subMinutes(5)->toIso8601String()]],
            'transaction' => ['external_id' => 'payment_456', 'amount' => 45, 'currency' => 'USD', 'status' => 'completed', 'completed_at' => now()->subMinute()->toIso8601String()],
        ];
    }
}
