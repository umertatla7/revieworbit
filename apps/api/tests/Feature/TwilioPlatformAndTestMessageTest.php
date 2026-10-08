<?php

namespace Tests\Feature;

use App\Domain\Customers\Models\Customer;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\PlatformTwilioSetting;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\LocationReviewDestination;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TwilioPlatformAndTestMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_store_encrypted_platform_credentials_and_verify_them(): void
    {
        $superAdmin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $superAdmin->id, 'role' => PlatformRole::SuperAdmin]);
        $manager = User::factory()->create();
        PlatformUserRole::create(['user_id' => $manager->id, 'role' => PlatformRole::PlatformManager]);
        $accountSid = 'AC'.str_repeat('a', 32);
        $authToken = 'super-secret-twilio-auth-token';

        $this->actingAs($manager)->putJson('/api/v1/admin/twilio', [
            'account_sid' => $accountSid,
            'auth_token' => $authToken,
            'mode' => 'trial',
        ])->assertForbidden();

        $response = $this->actingAs($superAdmin)->putJson('/api/v1/admin/twilio', [
            'account_sid' => $accountSid,
            'auth_token' => $authToken,
            'mode' => 'trial',
        ])->assertOk()
            ->assertJsonPath('data.account_sid', $accountSid)
            ->assertJsonPath('data.auth_token_configured', true)
            ->assertJsonMissingPath('data.auth_token');

        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame('platform.twilio.credentials_updated', $response->json('data.activity.0.action'));
        $this->assertNotSame($authToken, DB::table('platform_twilio_settings')->value('auth_token'));
        $this->assertSame($authToken, PlatformTwilioSetting::firstOrFail()->auth_token);

        Http::fake([
            "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}.json" => Http::response([
                'sid' => $accountSid,
                'friendly_name' => 'ReviewOrbit Trial',
                'status' => 'active',
                'type' => 'Trial',
            ]),
        ]);

        $this->postJson('/api/v1/admin/twilio/verify')
            ->assertOk()
            ->assertJsonPath('data.configuration.status', 'verified')
            ->assertJsonPath('data.account.name', 'ReviewOrbit Trial');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.twilio.credentials_verified', 'actor_user_id' => $superAdmin->id]);
    }

    public function test_trial_template_test_requires_tenant_scoped_customer_consent_and_verified_recipient_confirmation(): void
    {
        $accountSid = 'AC'.str_repeat('a', 32);
        PlatformTwilioSetting::create([
            'account_sid' => $accountSid,
            'auth_token' => 'super-secret-twilio-auth-token',
            'mode' => 'trial',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        [$ownerA, $businessA, $customerA, $templateA] = $this->businessFixture('Business A', '+12025550123', true);
        [, $businessB, $customerB] = $this->businessFixture('Business B', '+12025550124', true);

        Http::fake([
            "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json" => Http::response([
                'sid' => 'SM'.str_repeat('c', 32),
                'status' => 'queued',
            ]),
        ]);

        $headers = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->postJson("/api/v1/templates/{$templateA->id}/test", [
            'customer_id' => $customerA->id,
            'trial_recipient_verified' => false,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('trial_recipient_verified');
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $businessA->id,
            'action' => 'template.test_message_failed',
        ]);

        $this->postJson("/api/v1/templates/{$templateA->id}/test", [
            'customer_id' => $customerB->id,
            'trial_recipient_verified' => true,
        ], $headers)->assertNotFound();

        $customerA->consents()->delete();
        $this->postJson("/api/v1/templates/{$templateA->id}/test", [
            'customer_id' => $customerA->id,
            'trial_recipient_verified' => true,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $customerA->consents()->create([
            'business_id' => $businessA->id,
            'channel' => 'sms',
            'status' => 'granted',
            'source' => 'written',
            'recorded_at' => now(),
        ]);
        $this->postJson("/api/v1/templates/{$templateA->id}/test", [
            'customer_id' => $customerA->id,
            'trial_recipient_verified' => true,
        ], $headers)->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.recipient_last_four', '0123');

        $this->assertDatabaseHas('test_message_deliveries', [
            'business_id' => $businessA->id,
            'customer_id' => $customerA->id,
            'message_template_id' => $templateA->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('test_message_deliveries', ['business_id' => $businessB->id]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $businessA->id, 'action' => 'template.test_message_sent']);
        $this->getJson('/api/v1/message-deliveries', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.is_test', true)
            ->assertJsonPath('data.0.status', 'queued')
            ->assertJsonPath('data.0.to_last_four', '0123');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/Messages.json')
            && str_starts_with((string) $request['Body'], '[Business A test via B Review]')
            && str_contains((string) $request['Body'], 'https://www.google.com/maps?cid=123')
            && ! str_contains((string) $request['Body'], '/dashboard/templates')
            && $request['To'] === $customerA->phone_e164);
    }

    public function test_business_owner_can_select_verified_platform_shared_sender_without_receiving_platform_credentials(): void
    {
        $accountSid = 'AC'.str_repeat('a', 32);
        $serviceSid = 'MG'.str_repeat('b', 32);
        PlatformTwilioSetting::create([
            'account_sid' => $accountSid,
            'auth_token' => 'super-secret-twilio-auth-token',
            'shared_messaging_service_sid' => $serviceSid,
            'shared_sms_sender' => '+12025550111',
            'shared_sender_enabled' => true,
            'shared_sender_compliance_confirmed_at' => now(),
            'mode' => 'production',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        [$owner, $business] = $this->businessFixture('Shared Sender Business', '+12025550123', true);

        $headers = ['X-Business-ID' => $business->id];
        $this->actingAs($owner)->putJson('/api/v1/messaging-configuration', [
            'sender_mode' => 'platform_shared',
            'twilio_subaccount_sid' => null,
            'twilio_auth_token' => null,
            'twilio_messaging_service_sid' => null,
            'sms_sender' => null,
            'sms_enabled' => true,
        ], $headers)->assertOk()
            ->assertJsonPath('data.sender_mode', 'platform_shared')
            ->assertJsonPath('data.twilio_auth_token_configured', false)
            ->assertJsonMissingPath('data.twilio_auth_token');

        Http::fake([
            "https://messaging.twilio.com/v1/Services/{$serviceSid}" => Http::response([
                'sid' => $serviceSid,
                'account_sid' => $accountSid,
                'friendly_name' => 'B Review shared sender',
            ]),
            "https://messaging.twilio.com/v1/Services/{$serviceSid}/PhoneNumbers*" => Http::response([
                'phone_numbers' => [['phone_number' => '+12025550111']],
            ]),
        ]);

        $this->postJson('/api/v1/messaging-configuration/verify', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.configuration.status', 'active')
            ->assertJsonPath('data.provider.sender_mode', 'platform_shared');
    }

    public function test_customer_owned_auth_token_is_encrypted_write_only_and_tenant_scoped(): void
    {
        [$ownerA, $businessA] = $this->businessFixture('Customer-owned A', '+12025550123', true);
        [$ownerB, $businessB] = $this->businessFixture('Customer-owned B', '+12025550124', true);
        $token = 'customer-owned-twilio-auth-token';

        $this->actingAs($ownerA)->putJson('/api/v1/messaging-configuration', [
            'sender_mode' => 'customer_owned',
            'twilio_subaccount_sid' => 'AC'.str_repeat('c', 32),
            'twilio_auth_token' => $token,
            'twilio_messaging_service_sid' => 'MG'.str_repeat('d', 32),
            'sms_sender' => '+12025550125',
            'sms_enabled' => true,
        ], ['X-Business-ID' => $businessA->id])->assertOk()
            ->assertJsonPath('data.twilio_auth_token_configured', true)
            ->assertJsonMissingPath('data.twilio_auth_token');

        $this->assertNotSame($token, DB::table('messaging_configurations')->where('business_id', $businessA->id)->value('twilio_auth_token'));
        $this->assertSame($token, MessagingConfiguration::where('business_id', $businessA->id)->firstOrFail()->twilio_auth_token);
        $this->actingAs($ownerB)->getJson('/api/v1/messaging-configuration', ['X-Business-ID' => $businessB->id])
            ->assertOk()
            ->assertJsonPath('data.configuration.sender_mode', 'platform_dedicated')
            ->assertJsonMissingPath('data.configuration.twilio_auth_token');
    }

    private function businessFixture(string $name, string $phone, bool $withConsent): array
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'default_timezone' => 'America/New_York',
        ]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);
        $location = Location::create([
            'business_id' => $business->id,
            'name' => 'Main location',
            'timezone' => 'America/New_York',
            'status' => 'active',
        ]);
        $destination = LocationReviewDestination::create([
            'business_id' => $business->id,
            'location_id' => $location->id,
            'provider' => 'google',
            'url' => 'https://www.google.com/maps?cid=123',
            'status' => 'active',
            'is_primary' => true,
        ]);
        $customer = Customer::create([
            'business_id' => $business->id,
            'first_name' => 'Test',
            'phone_e164' => $phone,
            'phone_hash' => hash('sha256', $phone),
        ]);
        if ($withConsent) {
            $customer->consents()->create([
                'business_id' => $business->id,
                'channel' => 'sms',
                'status' => 'granted',
                'source' => 'written',
                'recorded_at' => now(),
            ]);
        }
        $template = MessageTemplate::create([
            'business_id' => $business->id,
            'location_id' => $location->id,
            'review_destination_id' => $destination->id,
            'name' => 'Review test',
            'channel' => 'sms',
            'body' => 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}: {{review_link}}',
            'status' => 'active',
        ]);
        MessagingConfiguration::create([
            'business_id' => $business->id,
            'provider' => 'twilio',
            'sender_mode' => 'platform_dedicated',
            'status' => 'active',
            'twilio_subaccount_sid' => 'AC'.str_repeat('a', 32),
            'twilio_messaging_service_sid' => 'MG'.str_repeat('b', 32),
            'sms_sender' => '+12025550111',
            'sms_enabled' => true,
            'whatsapp_enabled' => false,
            'verified_at' => now(),
        ]);

        return [$owner, $business, $customer, $template];
    }
}
