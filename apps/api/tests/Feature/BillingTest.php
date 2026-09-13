<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_configures_stripe_without_secrets_being_returned_or_stored_in_plaintext(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->putJson('/api/v1/admin/stripe', [
            'publishable_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_1234567890abcdef',
            'webhook_secret' => 'whsec_1234567890abcdef',
            'mode' => 'test',
        ])->assertOk()->assertJsonMissing(['secret_key' => 'sk_test_1234567890abcdef']);

        $response->assertJsonPath('data.secret_key_configured', true)->assertJsonPath('data.webhook_secret_configured', true);
        $raw = \DB::table('platform_stripe_settings')->first();
        $this->assertStringNotContainsString('sk_test_', $raw->secret_key);
        $this->assertStringNotContainsString('whsec_', $raw->webhook_secret);
    }

    public function test_only_super_admin_can_create_a_configurable_plan(): void
    {
        $manager = User::factory()->create();
        PlatformUserRole::create(['user_id' => $manager->id, 'role' => PlatformRole::PlatformManager]);
        $payload = ['code' => 'starter', 'name' => 'Starter', 'review_providers' => ['google'], 'status' => 'draft'];
        $this->actingAs($manager)->postJson('/api/v1/admin/plans', $payload)->assertForbidden();

        $this->actingAs($this->admin())->postJson('/api/v1/admin/plans', $payload)
            ->assertCreated()->assertJsonPath('data.code', 'starter');
    }

    public function test_owner_can_start_stripe_checkout_but_another_tenant_cannot_access_billing(): void
    {
        [$ownerA, $businessA] = $this->owner('A');
        [$ownerB, $businessB] = $this->owner('B');
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_growth_month']);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified']);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_test_a']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/test']),
        ]);

        $headersA = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->postJson('/api/v1/billing/checkout', ['plan_id' => $plan->id, 'interval' => 'month'], $headersA)
            ->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/test');
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $businessA->id, 'stripe_customer_id' => 'cus_test_a']);

        $this->actingAs($ownerB)->getJson('/api/v1/billing', ['X-Business-ID' => $businessA->id])->assertNotFound();
        $this->getJson('/api/v1/billing', ['X-Business-ID' => $businessB->id])->assertOk()->assertJsonPath('data.current_plan_code', 'basic');
    }

    public function test_verified_idempotent_webhook_updates_only_the_mapped_business_subscription(): void
    {
        [, $business] = $this->owner('Webhook');
        $plan = SubscriptionPlan::where('code', 'pro')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_pro_month']);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_signing', 'mode' => 'test', 'status' => 'verified']);
        $timestamp = time();
        $payload = json_encode([
            'id' => 'evt_subscription_updated', 'type' => 'customer.subscription.updated', 'livemode' => false,
            'data' => ['object' => [
                'id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'active',
                'metadata' => ['business_id' => $business->id],
                'items' => ['data' => [['price' => ['id' => 'price_pro_month', 'recurring' => ['interval' => 'month']]]]],
                'current_period_start' => $timestamp, 'current_period_end' => $timestamp + 2592000,
            ]],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_signing');
        $headers = ['Stripe-Signature' => "t={$timestamp},v1={$signature}", 'Content-Type' => 'application/json'];

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $payload)->assertOk();
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $payload)->assertOk();
        $this->assertSame('pro', $business->fresh()->plan_code);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $business->id, 'stripe_subscription_id' => 'sub_test', 'status' => 'active']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        PlatformUserRole::create(['user_id' => $user->id, 'role' => PlatformRole::SuperAdmin]);

        return $user;
    }

    private function owner(string $suffix): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => "Business {$suffix}", 'slug' => 'business-'.strtolower($suffix).'-'.Str::lower(Str::random(5))]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);

        return [$owner, $business];
    }
}
