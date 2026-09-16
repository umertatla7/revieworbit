<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as StripeRequest;
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

    public function test_plan_creation_enforces_inclusive_messaging_without_overage_fees(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/v1/admin/plans', [
            'code' => 'inclusive',
            'name' => 'Inclusive',
            'review_providers' => ['google'],
            'included_message_credits' => 500,
            'overage_price_minor' => 99,
            'allow_overage' => true,
            'estimated_sms_provider_cost_minor' => 4,
            'estimated_mms_provider_cost_minor' => 8,
        ])->assertCreated();

        $response
            ->assertJsonPath('data.overage_price_minor', 0)
            ->assertJsonPath('data.allow_overage', false)
            ->assertJsonPath('data.sms_credit_units', 1)
            ->assertJsonPath('data.mms_credit_units', 1)
            ->assertJsonPath('data.estimated_sms_provider_cost_minor', 0)
            ->assertJsonPath('data.estimated_mms_provider_cost_minor', 0);
    }

    public function test_super_admin_can_load_usage_without_unrelated_model_relationships(): void
    {
        [, $business] = $this->owner('Usage');

        $this->actingAs($this->admin())->getJson('/api/v1/admin/usage')
            ->assertOk()
            ->assertJsonPath('data.0.business_id', $business->id)
            ->assertJsonPath('data.0.messages', 0);
    }

    public function test_owner_can_start_stripe_checkout_but_another_tenant_cannot_access_billing(): void
    {
        [$ownerA, $businessA] = $this->owner('A');
        [$ownerB, $businessB] = $this->owner('B');
        $plan = SubscriptionPlan::where('code', 'momentum')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_growth_month']);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified']);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_test_a']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['client_secret' => 'cs_test_secret_checkout']),
        ]);

        $headersA = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->postJson('/api/v1/billing/checkout', ['plan_id' => $plan->id, 'interval' => 'month'], $headersA)
            ->assertOk()->assertJsonPath('data.client_secret', 'cs_test_secret_checkout')->assertJsonPath('data.publishable_key', 'pk_test_x');
        Http::assertSent(fn (StripeRequest $request): bool => str_ends_with($request->url(), '/v1/checkout/sessions')
            && data_get($request->data(), 'ui_mode') === 'embedded'
            && data_get($request->data(), 'payment_method_collection') === 'always');
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $businessA->id, 'stripe_customer_id' => 'cus_test_a']);

        $this->actingAs($ownerB)->getJson('/api/v1/billing', ['X-Business-ID' => $businessA->id])->assertNotFound();
        $this->getJson('/api/v1/billing', ['X-Business-ID' => $businessB->id])->assertOk()->assertJsonPath('data.current_plan_code', 'launch');
    }

    public function test_public_plans_are_available_for_signup_without_exposing_stripe_ids(): void
    {
        SubscriptionPlan::where('code', 'launch')->update(['stripe_product_id' => 'prod_private', 'stripe_monthly_price_id' => 'price_private']);

        $this->getJson('/api/v1/plans')->assertOk()
            ->assertJsonPath('data.0.code', 'launch')
            ->assertJsonMissing(['stripe_product_id' => 'prod_private'])
            ->assertJsonMissing(['stripe_monthly_price_id' => 'price_private']);
    }

    public function test_owner_changes_an_existing_subscription_in_app_with_proration(): void
    {
        [$owner, $business] = $this->owner('Plan change');
        $plan = SubscriptionPlan::where('code', 'expansion')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_pro_month']);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified']);
        BusinessSubscription::create(['business_id' => $business->id, 'stripe_customer_id' => 'cus_change', 'stripe_subscription_id' => 'sub_change', 'status' => 'active']);
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_change' => Http::sequence()
                ->push(['id' => 'sub_change', 'items' => ['data' => [['id' => 'si_current', 'price' => ['id' => 'price_old']]]]])
                ->push([
                    'id' => 'sub_change', 'status' => 'active',
                    'items' => ['data' => [[
                        'id' => 'si_current',
                        'price' => ['id' => 'price_pro_month', 'recurring' => ['interval' => 'month']],
                    ]]],
                ]),
        ]);

        $this->actingAs($owner)->postJson('/api/v1/billing/checkout', ['plan_id' => $plan->id, 'interval' => 'month'], ['X-Business-ID' => $business->id])
            ->assertOk()->assertJsonPath('data.subscription.plan.code', 'expansion');
        Http::assertSent(fn (StripeRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/subscriptions/sub_change')
            && data_get($request->data(), 'items.0.price') === 'price_pro_month'
            && data_get($request->data(), 'proration_behavior') === 'create_prorations');
    }

    public function test_plan_sync_sends_stripe_compatible_boolean_values_and_publishes_both_prices(): void
    {
        $admin = $this->admin();
        $plan = SubscriptionPlan::where('code', 'momentum')->firstOrFail();
        $plan->update(['stripe_product_id' => null, 'stripe_monthly_price_id' => null, 'stripe_annual_price_id' => null]);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified']);
        $priceNumber = 0;
        Http::fake(function (StripeRequest $request) use (&$priceNumber) {
            if (str_ends_with($request->url(), '/v1/products')) {
                return Http::response(['id' => 'prod_growth']);
            }
            if (str_ends_with($request->url(), '/v1/prices')) {
                $priceNumber++;

                return Http::response(['id' => $priceNumber === 1 ? 'price_growth_month' : 'price_growth_year']);
            }
            if (str_ends_with($request->url(), '/v1/billing_portal/configurations')) {
                $this->assertSame('true', data_get($request->data(), 'features.invoice_history.enabled'));
                $this->assertSame('true', data_get($request->data(), 'features.payment_method_update.enabled'));

                return Http::response(['id' => 'bpc_test']);
            }

            return Http::response(['error' => ['message' => 'Unexpected Stripe request']], 400);
        });

        $this->actingAs($admin)->postJson("/api/v1/admin/plans/{$plan->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('data.stripe_product_id', 'prod_growth')
            ->assertJsonPath('data.stripe_monthly_price_id', 'price_growth_month')
            ->assertJsonPath('data.stripe_annual_price_id', 'price_growth_year');
        $this->assertDatabaseHas('platform_stripe_settings', ['portal_configuration_id' => 'bpc_test']);
    }

    public function test_owner_can_open_a_dedicated_payment_method_update_flow(): void
    {
        [$owner, $business] = $this->owner('Payment');
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified', 'portal_configuration_id' => 'bpc_test']);
        BusinessSubscription::create(['business_id' => $business->id, 'stripe_customer_id' => 'cus_payment', 'status' => 'incomplete']);
        Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/test'])]);

        $this->actingAs($owner)->postJson('/api/v1/billing/portal', ['flow' => 'payment_method'], ['X-Business-ID' => $business->id])
            ->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/test');
        Http::assertSent(function (StripeRequest $request): bool {
            return str_ends_with($request->url(), '/v1/billing_portal/sessions')
                && data_get($request->data(), 'flow_data.type') === 'payment_method_update'
                && data_get($request->data(), 'customer') === 'cus_payment';
        });
    }

    public function test_embedded_payment_method_setup_preserves_the_existing_subscription_and_sets_the_default(): void
    {
        [$owner, $business] = $this->owner('Embedded payment');
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_signing', 'mode' => 'test', 'status' => 'verified']);
        BusinessSubscription::create(['business_id' => $business->id, 'stripe_customer_id' => 'cus_embedded', 'stripe_subscription_id' => 'sub_keep', 'status' => 'active']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['client_secret' => 'cs_test_secret_setup']),
            'api.stripe.com/v1/setup_intents/seti_embedded' => Http::response(['id' => 'seti_embedded', 'customer' => 'cus_embedded', 'payment_method' => 'pm_new', 'status' => 'succeeded']),
            'api.stripe.com/v1/customers/cus_embedded' => Http::response(['id' => 'cus_embedded']),
        ]);

        $this->actingAs($owner)->postJson('/api/v1/billing/payment-method', [], ['X-Business-ID' => $business->id])
            ->assertOk()->assertJsonPath('data.client_secret', 'cs_test_secret_setup');

        $timestamp = time();
        $payload = json_encode([
            'id' => 'evt_setup_completed', 'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => [
                'mode' => 'setup', 'customer' => 'cus_embedded', 'subscription' => null,
                'setup_intent' => 'seti_embedded', 'metadata' => ['business_id' => $business->id, 'purpose' => 'default_payment_method'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_signing');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars([
            'Stripe-Signature' => "t={$timestamp},v1={$signature}", 'Content-Type' => 'application/json',
        ]), $payload)->assertOk();

        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $business->id, 'stripe_subscription_id' => 'sub_keep']);
        Http::assertSent(fn (StripeRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/customers/cus_embedded')
            && data_get($request->data(), 'invoice_settings.default_payment_method') === 'pm_new');
    }

    public function test_admin_support_can_open_an_audited_payment_method_flow_without_an_existing_stripe_customer(): void
    {
        $admin = $this->admin();
        [, $business] = $this->owner('Assisted payment');
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified', 'portal_configuration_id' => 'bpc_test']);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_assisted']),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/assisted']),
        ]);
        $token = $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/support-sessions", [
            'reason' => 'Customer requested billing assistance by phone.',
        ])->assertCreated()->json('data.token');
        $headers = ['X-Business-ID' => $business->id, 'X-Support-Session' => $token];

        $this->actingAs($admin)->getJson('/api/v1/billing', $headers)
            ->assertOk()->assertJsonPath('data.can_manage_billing', true)->assertJsonPath('data.managed_by_support', true);
        $this->actingAs($admin)->postJson('/api/v1/billing/portal', ['flow' => 'payment_method'], $headers)
            ->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/assisted');
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $business->id, 'stripe_customer_id' => 'cus_assisted']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'actor_user_id' => $admin->id, 'action' => 'billing.portal_opened']);
    }

    public function test_admin_plan_assignment_links_the_business_subscription_record(): void
    {
        $admin = $this->admin();
        [, $business] = $this->owner('Assigned plan');
        $plan = SubscriptionPlan::where('code', 'expansion')->firstOrFail();

        $this->actingAs($admin)->patchJson("/api/v1/admin/businesses/{$business->id}", ['plan_code' => 'expansion'])
            ->assertOk()->assertJsonPath('data.plan_code', 'expansion');

        $this->assertDatabaseHas('business_subscriptions', [
            'business_id' => $business->id,
            'subscription_plan_id' => $plan->id,
        ]);
    }

    public function test_verified_idempotent_webhook_updates_only_the_mapped_business_subscription(): void
    {
        [, $business] = $this->owner('Webhook');
        $plan = SubscriptionPlan::where('code', 'expansion')->firstOrFail();
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
        $this->assertSame('expansion', $business->fresh()->plan_code);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $business->id, 'stripe_subscription_id' => 'sub_test', 'status' => 'active']);
    }

    public function test_billing_page_returns_live_masked_payment_and_invoice_details_from_stripe(): void
    {
        [$owner, $business] = $this->owner('Live billing');
        $plan = SubscriptionPlan::where('code', 'momentum')->firstOrFail();
        $plan->update(['stripe_monthly_price_id' => 'price_growth_month']);
        PlatformStripeSetting::create(['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x', 'mode' => 'test', 'status' => 'verified']);
        BusinessSubscription::create(['business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'stripe_customer_id' => 'cus_live', 'stripe_subscription_id' => 'sub_live', 'status' => 'active']);
        Http::fake([
            'api.stripe.com/v1/customers/cus_live*' => Http::response(['id' => 'cus_live', 'invoice_settings' => ['default_payment_method' => 'pm_live']]),
            'api.stripe.com/v1/payment_methods*' => Http::response(['data' => [['id' => 'pm_live', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]]]]),
            'api.stripe.com/v1/invoices*' => Http::response(['data' => [['id' => 'in_live', 'number' => 'RO-001', 'status' => 'paid', 'amount_due' => 4900, 'amount_paid' => 4900, 'currency' => 'usd', 'created' => time()]]]),
            'api.stripe.com/v1/subscriptions/sub_live*' => Http::response(['id' => 'sub_live', 'status' => 'active', 'customer' => 'cus_live', 'items' => ['data' => [['price' => ['id' => 'price_growth_month', 'recurring' => ['interval' => 'month']], 'current_period_end' => time() + 2592000]]]]),
        ]);

        $this->actingAs($owner)->getJson('/api/v1/billing', ['X-Business-ID' => $business->id])
            ->assertOk()
            ->assertJsonPath('data.has_stripe_customer', true)
            ->assertJsonPath('data.can_manage_billing', true)
            ->assertJsonPath('data.subscription.plan.code', 'momentum')
            ->assertJsonPath('data.payment_methods.0.last4', '4242')
            ->assertJsonPath('data.payment_methods.0.is_default', true)
            ->assertJsonPath('data.invoices.0.number', 'RO-001')
            ->assertJsonMissing(['stripe_customer_id' => 'cus_live'])
            ->assertJsonMissing(['stripe_subscription_id' => 'sub_live']);
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
