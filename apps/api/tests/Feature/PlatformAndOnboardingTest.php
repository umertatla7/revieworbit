<?php

namespace Tests\Feature;

use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\AdminSupportSession;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAndOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_return_json_instead_of_a_missing_login_route(): void
    {
        $this->getJson('/api/v1/business', ['X-Business-ID' => (string) Str::ulid()])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_customer_signup_returns_customer_workspace_context_and_onboarding_progress(): void
    {
        $response = $this->withHeader('Origin', 'https://revieworbit.test')->postJson('/api/v1/auth/register', [
            'name' => 'Ava Owner',
            'email' => 'ava@example.com',
            'password' => 'ReviewOrbit123!',
            'password_confirmation' => 'ReviewOrbit123!',
            'business_name' => 'Ava Studio',
            'industry' => 'beauty_wellness',
            'business_phone' => '+13125550123',
            'website_url' => 'https://ava.example.com',
            'location_name' => 'Main Studio',
            'address_line1' => '100 Main Street',
            'city' => 'Chicago',
            'region' => 'IL',
            'postal_code' => '60601',
            'country' => 'US',
            'timezone' => 'America/Chicago',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_platform_admin', false)
            ->assertJsonPath('data.platform_roles', [])
            ->assertJsonPath('data.businesses.0.role', 'owner');
        $businessId = $response->json('data.businesses.0.id');
        $this->getJson('/api/v1/onboarding', ['X-Business-ID' => $businessId])
            ->assertOk()
            ->assertJsonPath('data.business.onboarding_status', 'in_progress')
            ->assertJsonPath('data.checks.integration', true);
        $this->assertDatabaseHas('businesses', ['id' => $businessId, 'industry' => 'beauty_wellness', 'phone' => '+13125550123']);
        $this->assertDatabaseHas('locations', ['business_id' => $businessId, 'name' => 'Main Studio']);
    }

    public function test_customer_signup_assigns_the_selected_plan_and_keeps_location_setup_short(): void
    {
        $plan = SubscriptionPlan::where('code', 'momentum')->firstOrFail();
        $response = $this->withHeader('Origin', 'https://revieworbit.test')->postJson('/api/v1/auth/register', [
            'name' => 'Jamie Owner', 'email' => 'jamie@example.com',
            'password' => 'ReviewOrbit123!', 'password_confirmation' => 'ReviewOrbit123!',
            'business_name' => 'Jamie Studio', 'industry' => 'beauty_wellness',
            'business_phone' => '+13125550199', 'country' => 'US',
            'timezone' => 'America/Chicago', 'plan_id' => $plan->id,
        ])->assertCreated();

        $businessId = $response->json('data.businesses.0.id');
        $this->assertDatabaseHas('businesses', ['id' => $businessId, 'plan_code' => 'momentum']);
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $businessId, 'subscription_plan_id' => $plan->id]);
        $this->assertDatabaseHas('locations', ['business_id' => $businessId, 'name' => 'Main location']);
    }

    public function test_business_owner_cannot_access_platform_customer_directory(): void
    {
        [$owner] = $this->ownerAndBusiness('Customer Business');

        $this->actingAs($owner)->getJson('/api/v1/admin/businesses')->assertForbidden();
    }

    public function test_platform_admin_provisions_a_complete_customer_owner_and_primary_location_atomically(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/businesses', $this->provisioningPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Harbor Dental')
            ->assertJsonPath('data.industry', 'dental')
            ->assertJsonPath('data.owners.0.email', 'ava@harbordental.test')
            ->assertJsonPath('data.locations.0.name', 'Downtown Clinic')
            ->assertJsonPath('meta.owner_setup_email_status', 'sent');
        $businessId = $response->json('data.id');
        $owner = User::where('email', 'ava@harbordental.test')->firstOrFail();
        $this->assertDatabaseHas('business_users', ['business_id' => $businessId, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->assertDatabaseHas('locations', ['business_id' => $businessId, 'phone' => '+12025550124']);
        $this->assertSame('125 Harbor Avenue', Business::findOrFail($businessId)->locations()->first()->address['line1']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $businessId, 'action' => 'platform.business.provisioned']);
        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_admin_provisioning_reuses_an_existing_user_without_changing_their_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        $owner = User::factory()->create(['email' => 'ava@harbordental.test', 'password' => 'ExistingPassword123!']);
        $originalPassword = $owner->password;

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/businesses', [
            ...$this->provisioningPayload(),
            'send_owner_setup_email' => false,
        ])->assertCreated();

        $response->assertJsonPath('meta.owner_setup_email_status', 'not_requested');

        $this->assertSame($originalPassword, $owner->fresh()->password);
        $this->assertDatabaseHas('business_users', ['business_id' => $response->json('data.id'), 'user_id' => $owner->id, 'role' => 'owner']);
        Notification::assertNothingSent();
    }

    public function test_super_admin_uses_an_audited_support_session_to_manage_another_tenant(): void
    {
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        [, $business] = $this->ownerAndBusiness('Supported Business');

        $this->actingAs($admin)->patchJson('/api/v1/business', ['name' => 'Unauthorized'], ['X-Business-ID' => $business->id])->assertNotFound();
        $token = $this->postJson('/api/v1/admin/businesses/'.$business->id.'/support-sessions', [
            'reason' => 'Customer requested full onboarding assistance.',
        ])->assertCreated()->json('data.token');

        $this->patchJson('/api/v1/business', ['name' => 'Supported Business Updated'], [
            'X-Business-ID' => $business->id,
            'X-Support-Session' => $token,
        ])->assertOk()->assertJsonPath('data.name', 'Supported Business Updated');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'platform.support_session.started']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business.updated']);
    }

    public function test_super_admin_can_reset_a_tenant_owner_password_and_revoke_existing_access(): void
    {
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        [$owner, $business] = $this->ownerAndBusiness('Password Reset Business');
        $owner->createToken('existing-mobile-session');
        DB::table('sessions')->insert([
            'id' => 'existing-browser-session',
            'user_id' => $owner->id,
            'payload' => 'fixture',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/admin/businesses/'.$business->id.'/owners/'.$owner->id.'/password', [
            'password' => 'NewPassword2026!',
            'password_confirmation' => 'NewPassword2026!',
        ])->assertOk()->assertJsonPath('message', 'The owner password was reset and existing sessions were signed out.');

        $this->assertTrue(Hash::check('NewPassword2026!', $owner->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'actor_user_id' => $admin->id,
            'action' => 'platform.business_owner.password_reset',
        ]);
    }

    public function test_owner_password_reset_is_super_admin_only_and_tenant_scoped(): void
    {
        $manager = User::factory()->create();
        PlatformUserRole::create(['user_id' => $manager->id, 'role' => PlatformRole::PlatformManager]);
        [, $businessA] = $this->ownerAndBusiness('Password Business A');
        [$ownerB] = $this->ownerAndBusiness('Password Business B');
        $payload = ['password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'];

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/businesses/'.$businessA->id.'/owners/'.$ownerB->id.'/password', $payload)
            ->assertForbidden();

        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        $this->actingAs($admin)
            ->postJson('/api/v1/admin/businesses/'.$businessA->id.'/owners/'.$ownerB->id.'/password', $payload)
            ->assertNotFound();
    }

    public function test_expired_or_cross_tenant_support_session_is_rejected(): void
    {
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        [, $businessA] = $this->ownerAndBusiness('Business A');
        [, $businessB] = $this->ownerAndBusiness('Business B');
        $plainToken = 'support_'.Str::random(64);
        AdminSupportSession::create([
            'business_id' => $businessA->id,
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $plainToken),
            'reason' => 'Expired customer support verification session.',
            'expires_at' => now()->subMinute(),
        ]);

        $headers = ['X-Business-ID' => $businessB->id, 'X-Support-Session' => $plainToken];
        $this->actingAs($admin)->getJson('/api/v1/business', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The admin support session has expired. Start a new support session.');
    }

    public function test_pos_connections_are_tenant_scoped_and_do_not_store_browser_secrets(): void
    {
        [$ownerA, $businessA] = $this->ownerAndBusiness('Business A');
        [, $businessB] = $this->ownerAndBusiness('Business B');
        $connectionB = PosIntegration::create([
            'business_id' => $businessB->id,
            'provider' => 'generic',
            'name' => 'Private POS',
            'status' => 'awaiting_credentials',
        ]);

        $headers = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->getJson('/api/v1/pos-integrations', $headers)
            ->assertOk()->assertJsonMissing(['id' => $connectionB->id]);
        $this->patchJson('/api/v1/pos-integrations/'.$connectionB->id, ['status' => 'connected'], $headers)->assertNotFound();
        $this->postJson('/api/v1/pos-integrations', [
            'provider' => 'generic', 'name' => 'Website POS', 'environment' => 'sandbox',
        ], $headers)->assertCreated()->assertJsonMissingPath('data.credentials');
    }

    private function ownerAndBusiness(string $name): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);

        return [$owner, $business];
    }

    private function provisioningPayload(): array
    {
        return [
            'business_name' => 'Harbor Dental',
            'legal_name' => 'Harbor Dental Group LLC',
            'industry' => 'dental',
            'business_email' => 'hello@harbordental.test',
            'business_phone' => '+12025550123',
            'website_url' => 'https://harbordental.test',
            'owner_name' => 'Ava Morgan',
            'owner_email' => 'ava@harbordental.test',
            'owner_phone' => '+12025550125',
            'location_name' => 'Downtown Clinic',
            'location_phone' => '+12025550124',
            'address_line1' => '125 Harbor Avenue',
            'address_line2' => 'Suite 200',
            'city' => 'Boston',
            'region' => 'MA',
            'postal_code' => '02110',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'google_review_url' => 'https://g.page/r/example/review',
            'operation_mode' => 'square',
            'preferred_channel' => 'sms',
            'quiet_hours_start' => '20:00',
            'quiet_hours_end' => '09:00',
            'account_notes' => 'Customer requested concierge Square setup.',
            'plan_code' => 'launch',
            'send_owner_setup_email' => true,
        ];
    }
}
