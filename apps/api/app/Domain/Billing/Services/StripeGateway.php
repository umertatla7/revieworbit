<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class StripeGateway
{
    public function configuration(): PlatformStripeSetting
    {
        $setting = PlatformStripeSetting::query()->latest()->first();
        if (! $setting?->secret_key || $setting->status !== 'verified') {
            throw ValidationException::withMessages(['billing' => ['Stripe is not ready yet. Please contact ReviewOrbit support.']]);
        }

        return $setting;
    }

    public function verify(PlatformStripeSetting $setting): array
    {
        return $this->request($setting)->get('/v1/account')->throw()->json();
    }

    public function syncPlan(SubscriptionPlan $plan): SubscriptionPlan
    {
        $setting = $this->configuration();
        $product = $plan->stripe_product_id
            ? $this->request($setting)->post('/v1/products/'.$plan->stripe_product_id, $this->productData($plan))->throw()->json()
            : $this->request($setting)->post('/v1/products', $this->productData($plan))->throw()->json();

        $monthly = $this->ensurePrice($setting, $plan->stripe_monthly_price_id, $product['id'], $plan->monthly_price_minor, $plan->currency, 'month', $plan);
        $annual = $this->ensurePrice($setting, $plan->stripe_annual_price_id, $product['id'], $plan->annual_price_minor, $plan->currency, 'year', $plan);
        $plan->update(['stripe_product_id' => $product['id'], 'stripe_monthly_price_id' => $monthly, 'stripe_annual_price_id' => $annual]);
        $this->syncPortalConfiguration($setting);

        return $plan->fresh();
    }

    public function checkout(Business $business, SubscriptionPlan $plan, string $interval): string
    {
        $setting = $this->configuration();
        $subscription = BusinessSubscription::firstOrCreate(['business_id' => $business->id]);
        $customerId = $subscription->stripe_customer_id;
        if (! $customerId) {
            $customer = $this->request($setting)->post('/v1/customers', [
                'name' => $business->name,
                'email' => $business->primary_email,
                'metadata' => ['business_id' => $business->id],
            ])->throw()->json();
            $customerId = $customer['id'];
            $subscription->update(['stripe_customer_id' => $customerId]);
        }

        $priceId = $interval === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id;
        if (! $priceId) {
            throw ValidationException::withMessages(['plan' => ['This plan is not available for checkout yet.']]);
        }
        $web = rtrim((string) config('services.frontend.url'), '/');
        $subscriptionData = ['metadata' => ['business_id' => $business->id, 'plan_id' => $plan->id]];
        if (! $subscription->trial_started_at && ! $subscription->stripe_subscription_id && $plan->trial_days > 0) {
            $subscriptionData['trial_period_days'] = $plan->trial_days;
        }
        $session = $this->request($setting)->post('/v1/checkout/sessions', [
            'mode' => 'subscription', 'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $web.'/dashboard/billing?checkout=success',
            'cancel_url' => $web.'/dashboard/billing?checkout=cancelled',
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
            'client_reference_id' => $business->id,
            'metadata' => ['business_id' => $business->id, 'plan_id' => $plan->id],
            'subscription_data' => $subscriptionData,
        ])->throw()->json();

        return $session['url'];
    }

    public function portal(Business $business, ?SubscriptionPlan $plan = null, ?string $interval = null): string
    {
        $setting = $this->configuration();
        $subscription = BusinessSubscription::where('business_id', $business->id)->first();
        if (! $subscription?->stripe_customer_id) {
            throw ValidationException::withMessages(['billing' => ['Start a subscription before opening billing management.']]);
        }
        $web = rtrim((string) config('services.frontend.url'), '/');
        $payload = ['customer' => $subscription->stripe_customer_id, 'return_url' => $web.'/dashboard/billing'];
        if ($setting->portal_configuration_id) {
            $payload['configuration'] = $setting->portal_configuration_id;
        }
        if ($plan && $subscription->stripe_subscription_id) {
            $price = $interval === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id;
            if (! $price) {
                throw ValidationException::withMessages(['plan' => ['This billing interval is not available.']]);
            }
            $payload['flow_data'] = [
                'type' => 'subscription_update_confirm',
                'subscription_update_confirm' => [
                    'subscription' => $subscription->stripe_subscription_id,
                    'items' => [['price' => $price, 'quantity' => 1]],
                ],
                'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $web.'/dashboard/billing?plan=updated']],
            ];
        }

        return $this->request($setting)->post('/v1/billing_portal/sessions', $payload)->throw()->json('url');
    }

    private function ensurePrice(PlatformStripeSetting $setting, ?string $currentId, string $productId, int $amount, string $currency, string $interval, SubscriptionPlan $plan): string
    {
        if ($currentId) {
            $current = $this->request($setting)->get('/v1/prices/'.$currentId)->throw()->json();
            if ((int) $current['unit_amount'] === $amount && strtolower($current['currency']) === strtolower($currency) && data_get($current, 'recurring.interval') === $interval && $current['active']) {
                return $currentId;
            }
        }
        $price = $this->request($setting)->post('/v1/prices', [
            'product' => $productId, 'unit_amount' => $amount, 'currency' => strtolower($currency),
            'recurring' => ['interval' => $interval],
            'nickname' => $plan->name.' '.ucfirst($interval).'ly',
            'metadata' => ['revieworbit_plan_id' => $plan->id, 'revieworbit_plan_code' => $plan->code],
        ])->throw()->json();
        if ($currentId) {
            $this->request($setting)->post('/v1/prices/'.$currentId, ['active' => 'false'])->throw();
        }

        return $price['id'];
    }

    private function productData(SubscriptionPlan $plan): array
    {
        return ['name' => $plan->name, 'description' => $plan->description, 'metadata' => ['revieworbit_plan_id' => $plan->id, 'revieworbit_plan_code' => $plan->code]];
    }

    private function syncPortalConfiguration(PlatformStripeSetting $setting): void
    {
        $products = SubscriptionPlan::where('status', 'active')->whereNotNull('stripe_product_id')->get()
            ->map(fn (SubscriptionPlan $plan): array => ['product' => $plan->stripe_product_id, 'prices' => array_values(array_filter([$plan->stripe_monthly_price_id, $plan->stripe_annual_price_id]))])
            ->filter(fn (array $product): bool => $product['prices'] !== [])->values()->all();
        $payload = [
            'business_profile' => ['headline' => 'Manage your ReviewOrbit subscription'],
            'features' => [
                'customer_update' => ['enabled' => true, 'allowed_updates' => ['email', 'address', 'tax_id']],
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
                'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end', 'cancellation_reason' => ['enabled' => true, 'options' => ['too_expensive', 'missing_features', 'switched_service', 'unused', 'other']]],
                'subscription_update' => ['enabled' => true, 'default_allowed_updates' => ['price'], 'products' => $products],
            ],
        ];
        $response = $setting->portal_configuration_id
            ? $this->request($setting)->post('/v1/billing_portal/configurations/'.$setting->portal_configuration_id, $payload)
            : $this->request($setting)->post('/v1/billing_portal/configurations', $payload);
        $configuration = $response->throw()->json();
        if (! $setting->portal_configuration_id) {
            $setting->update(['portal_configuration_id' => $configuration['id']]);
        }
    }

    private function request(PlatformStripeSetting $setting): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com')->withToken($setting->secret_key)
            ->withHeaders(['Stripe-Version' => config('services.stripe.api_version')])
            ->asForm()->acceptJson()->timeout(15)->retry(2, 200, throw: false);
    }
}
