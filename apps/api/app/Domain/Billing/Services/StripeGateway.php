<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
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
        try {
            $setting = $this->configuration();
            $product = $plan->stripe_product_id
                ? $this->request($setting)->post('/v1/products/'.$plan->stripe_product_id, $this->productData($plan))->throw()->json()
                : $this->request($setting)->post('/v1/products', $this->productData($plan))->throw()->json();

            $monthly = $this->ensurePrice($setting, $plan->stripe_monthly_price_id, $product['id'], $plan->monthly_price_minor, $plan->currency, 'month', $plan);
            $annual = $this->ensurePrice($setting, $plan->stripe_annual_price_id, $product['id'], $plan->annual_price_minor, $plan->currency, 'year', $plan);
            $plan->update(['stripe_product_id' => $product['id'], 'stripe_monthly_price_id' => $monthly, 'stripe_annual_price_id' => $annual]);
            $this->syncPortalConfiguration($setting);

            return $plan->fresh();
        } catch (RequestException $exception) {
            $message = data_get($exception->response?->json(), 'error.message');
            throw ValidationException::withMessages([
                'stripe' => [$message ?: 'Stripe could not synchronize this plan. Check the Stripe configuration and try again.'],
            ]);
        }
    }

    /** @return array{client_secret: string, publishable_key: string} */
    public function checkout(Business $business, SubscriptionPlan $plan, string $interval): array
    {
        try {
            $setting = $this->configuration();
            $subscription = BusinessSubscription::firstOrCreate(['business_id' => $business->id]);
            $customerId = $this->ensureCustomer($setting, $business, $subscription);

            $priceId = $interval === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id;
            if (! $priceId) {
                throw ValidationException::withMessages(['plan' => ['This plan is not available for checkout yet.']]);
            }
            $web = rtrim((string) config('services.frontend.url'), '/');
            $subscriptionData = ['metadata' => ['business_id' => $business->id, 'plan_id' => $plan->id]];
            if (! $subscription->trial_used_at && ! $subscription->trial_started_at && ! $subscription->stripe_subscription_id && $plan->trial_days > 0) {
                $subscriptionData['trial_period_days'] = $plan->trial_days;
            }
            $session = $this->request($setting)->post('/v1/checkout/sessions', [
                'mode' => 'subscription', 'customer' => $customerId,
                'ui_mode' => 'embedded',
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'return_url' => $web.'/dashboard/billing?checkout=success&session_id={CHECKOUT_SESSION_ID}',
                'payment_method_collection' => 'always',
                'allow_promotion_codes' => 'true',
                'billing_address_collection' => 'auto',
                'client_reference_id' => $business->id,
                'metadata' => ['business_id' => $business->id, 'plan_id' => $plan->id],
                'subscription_data' => $subscriptionData,
            ])->throw()->json();

            return ['client_secret' => $session['client_secret'], 'publishable_key' => $setting->publishable_key];
        } catch (RequestException $exception) {
            $this->throwStripeValidation($exception, 'Stripe Checkout could not be opened. Please try again.');
        }
    }

    /** @return array{client_secret: string, publishable_key: string} */
    public function paymentMethodSetup(Business $business): array
    {
        try {
            $setting = $this->configuration();
            $subscription = BusinessSubscription::firstOrCreate(['business_id' => $business->id]);
            $customerId = $this->ensureCustomer($setting, $business, $subscription);
            $web = rtrim((string) config('services.frontend.url'), '/');
            $session = $this->request($setting)->post('/v1/checkout/sessions', [
                'mode' => 'setup', 'customer' => $customerId, 'ui_mode' => 'embedded',
                'payment_method_types' => ['card'],
                'return_url' => $web.'/dashboard/billing?payment=updated&session_id={CHECKOUT_SESSION_ID}',
                'client_reference_id' => $business->id,
                'metadata' => ['business_id' => $business->id, 'purpose' => 'default_payment_method'],
            ])->throw()->json();

            return ['client_secret' => $session['client_secret'], 'publishable_key' => $setting->publishable_key];
        } catch (RequestException $exception) {
            $this->throwStripeValidation($exception, 'Stripe could not open the secure card form. Please try again.');
        }
    }

    public function changeSubscription(Business $business, SubscriptionPlan $plan, string $interval): array
    {
        try {
            $setting = $this->configuration();
            $subscription = BusinessSubscription::where('business_id', $business->id)->firstOrFail();
            if (! $subscription->stripe_subscription_id) {
                throw ValidationException::withMessages(['subscription' => ['No active Stripe subscription was found.']]);
            }
            $priceId = $interval === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id;
            if (! $priceId) {
                throw ValidationException::withMessages(['plan' => ['This billing interval is not available.']]);
            }
            $remote = $this->request($setting)->get('/v1/subscriptions/'.$subscription->stripe_subscription_id)->throw()->json();
            $itemId = data_get($remote, 'items.data.0.id');
            if (! $itemId) {
                throw ValidationException::withMessages(['subscription' => ['Stripe could not identify the current subscription item.']]);
            }
            $updated = $this->request($setting)->post('/v1/subscriptions/'.$subscription->stripe_subscription_id, [
                'items' => [['id' => $itemId, 'price' => $priceId, 'quantity' => 1]],
                'proration_behavior' => 'create_prorations',
                'payment_behavior' => 'error_if_incomplete',
                'metadata' => ['business_id' => $business->id, 'plan_id' => $plan->id],
            ])->throw()->json();

            return $this->subscriptionSnapshot($updated);
        } catch (RequestException $exception) {
            $this->throwStripeValidation($exception, 'Stripe could not change the subscription. Please try again.');
        }
    }

    public function applySetupIntentDefault(string $customerId, string $setupIntentId): void
    {
        $setting = $this->configuration();
        $intent = $this->request($setting)->get('/v1/setup_intents/'.$setupIntentId)->throw()->json();
        $intentCustomer = $this->id($intent['customer'] ?? null);
        $paymentMethodId = $this->id($intent['payment_method'] ?? null);
        if ($intentCustomer !== $customerId || ! $paymentMethodId || ($intent['status'] ?? null) !== 'succeeded') {
            throw ValidationException::withMessages(['payment_method' => ['Stripe did not return a completed payment method setup.']]);
        }
        $this->request($setting)->post('/v1/customers/'.$customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ])->throw();
    }

    public function portal(Business $business, ?SubscriptionPlan $plan = null, ?string $interval = null, ?string $flow = null): string
    {
        try {
            $setting = $this->configuration();
            $subscription = BusinessSubscription::firstOrCreate(['business_id' => $business->id]);
            $customerId = $this->ensureCustomer($setting, $business, $subscription);
            $web = rtrim((string) config('services.frontend.url'), '/');
            $payload = ['customer' => $customerId, 'return_url' => $web.'/dashboard/billing'];
            if ($setting->portal_configuration_id) {
                $payload['configuration'] = $setting->portal_configuration_id;
            }
            if ($flow === 'payment_method') {
                $payload['flow_data'] = [
                    'type' => 'payment_method_update',
                    'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $web.'/dashboard/billing?payment=updated']],
                ];
            } elseif ($plan && $subscription->stripe_subscription_id) {
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
        } catch (RequestException $exception) {
            $this->throwStripeValidation($exception, 'Stripe billing management could not be opened. Please try again.');
        }
    }

    public function customerBillingSnapshot(BusinessSubscription $subscription): array
    {
        $setting = $this->configuration();
        if (! $subscription->stripe_customer_id) {
            return ['subscription' => null, 'payment_methods' => [], 'invoices' => []];
        }
        $customer = $this->request($setting)->get('/v1/customers/'.$subscription->stripe_customer_id, [
            'expand' => ['invoice_settings.default_payment_method'],
        ])->throw()->json();
        $default = data_get($customer, 'invoice_settings.default_payment_method');
        $defaultId = is_array($default) ? ($default['id'] ?? null) : $default;
        $methods = $this->request($setting)->get('/v1/payment_methods', [
            'customer' => $subscription->stripe_customer_id, 'type' => 'card', 'limit' => 10,
        ])->throw()->json('data', []);
        $invoices = $this->request($setting)->get('/v1/invoices', [
            'customer' => $subscription->stripe_customer_id, 'limit' => 12,
        ])->throw()->json('data', []);
        $remoteSubscription = $subscription->stripe_subscription_id
            ? $this->request($setting)->get('/v1/subscriptions/'.$subscription->stripe_subscription_id)->throw()->json()
            : null;

        return [
            'subscription' => $remoteSubscription ? $this->subscriptionSnapshot($remoteSubscription) : null,
            'payment_methods' => collect($methods)->map(fn (array $method): array => [
                'id' => $method['id'], 'brand' => data_get($method, 'card.brand'), 'last4' => data_get($method, 'card.last4'),
                'exp_month' => data_get($method, 'card.exp_month'), 'exp_year' => data_get($method, 'card.exp_year'),
                'is_default' => $method['id'] === $defaultId,
            ])->values()->all(),
            'invoices' => collect($invoices)->map(fn (array $invoice): array => [
                'id' => $invoice['id'], 'number' => $invoice['number'] ?? null, 'status' => $invoice['status'] ?? null,
                'amount_due_minor' => $invoice['amount_due'] ?? 0, 'amount_paid_minor' => $invoice['amount_paid'] ?? 0,
                'currency' => strtoupper($invoice['currency'] ?? 'USD'), 'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
                'invoice_pdf_url' => $invoice['invoice_pdf'] ?? null, 'created_at' => isset($invoice['created']) ? Carbon::createFromTimestampUTC($invoice['created']) : null,
            ])->values()->all(),
        ];
    }

    private function subscriptionSnapshot(array $subscription): array
    {
        $priceId = data_get($subscription, 'items.data.0.price.id');
        $plan = SubscriptionPlan::where('stripe_monthly_price_id', $priceId)->orWhere('stripe_annual_price_id', $priceId)->first();

        return [
            'status' => $subscription['status'] ?? 'unknown', 'billing_interval' => data_get($subscription, 'items.data.0.price.recurring.interval', 'month'),
            'trial_ends_at' => $this->timestamp($subscription['trial_end'] ?? null),
            'current_period_ends_at' => $this->timestamp($subscription['current_period_end'] ?? data_get($subscription, 'items.data.0.current_period_end')),
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false), 'plan' => $plan,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value ? Carbon::createFromTimestampUTC((int) $value)->toIso8601String() : null;
    }

    private function id(mixed $value): ?string
    {
        return is_array($value) ? ($value['id'] ?? null) : $value;
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
        $products = SubscriptionPlan::where('status', 'active')
            ->where('is_public', true)
            ->where('is_self_serve', true)
            ->whereNotNull('stripe_product_id')->get()
            ->map(fn (SubscriptionPlan $plan): array => ['product' => $plan->stripe_product_id, 'prices' => array_values(array_filter([$plan->stripe_monthly_price_id, $plan->stripe_annual_price_id]))])
            ->filter(fn (array $product): bool => $product['prices'] !== [])->values()->all();
        $payload = [
            'business_profile' => ['headline' => 'Manage your ReviewOrbit subscription'],
            'features' => [
                'customer_update' => ['enabled' => 'true', 'allowed_updates' => ['email', 'address', 'tax_id']],
                'invoice_history' => ['enabled' => 'true'],
                'payment_method_update' => ['enabled' => 'true'],
                'subscription_cancel' => ['enabled' => 'true', 'mode' => 'at_period_end', 'cancellation_reason' => ['enabled' => 'true', 'options' => ['too_expensive', 'missing_features', 'switched_service', 'unused', 'other']]],
                'subscription_update' => ['enabled' => 'true', 'default_allowed_updates' => ['price'], 'products' => $products],
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

    private function ensureCustomer(PlatformStripeSetting $setting, Business $business, BusinessSubscription $subscription): string
    {
        if ($subscription->stripe_customer_id) {
            return $subscription->stripe_customer_id;
        }

        $customer = $this->request($setting)->post('/v1/customers', [
            'name' => $business->name,
            'email' => $business->primary_email,
            'metadata' => ['business_id' => $business->id],
        ])->throw()->json();
        $subscription->update(['stripe_customer_id' => $customer['id']]);

        return $customer['id'];
    }

    private function throwStripeValidation(RequestException $exception, string $fallback): never
    {
        $message = data_get($exception->response?->json(), 'error.message');
        throw ValidationException::withMessages(['stripe' => [$message ?: $fallback]]);
    }
}
