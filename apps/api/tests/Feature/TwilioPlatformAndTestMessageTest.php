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
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/Messages.json')
            && str_starts_with((string) $request['Body'], '[ReviewOrbit test]')
            && $request['To'] === $customerA->phone_e164);
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
            'name' => 'Review test',
            'channel' => 'sms',
            'body' => 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}: {{review_link}}',
            'status' => 'active',
        ]);
        MessagingConfiguration::create([
            'business_id' => $business->id,
            'provider' => 'twilio',
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
