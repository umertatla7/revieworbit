<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Billing\Services\CustomPlanManager;
use App\Domain\Billing\Services\CustomPlanQuoteCalculator;
use App\Domain\Billing\Services\StripeGateway;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function plans(): JsonResponse
    {
        return response()->json(['data' => SubscriptionPlan::where('status', 'active')->where('is_public', true)
            ->orderBy('sort_order')->orderBy('monthly_price_minor')->get()
            ->makeHidden([
                'business_id', 'stripe_product_id', 'stripe_monthly_price_id', 'stripe_annual_price_id',
                'overage_price_minor', 'included_message_credits', 'sms_credit_units', 'mms_credit_units', 'whatsapp_credit_units',
                'estimated_sms_provider_cost_minor', 'estimated_mms_provider_cost_minor',
                'estimated_whatsapp_provider_cost_minor', 'allow_overage',
            ])]);
    }

    public function publicCustomQuote(Request $request, CustomPlanQuoteCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'locations' => ['required', 'integer', 'min:1', 'max:1000'],
            'monthly_messages' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $messages = (int) $data['monthly_messages'];
        $quote = $calculator->calculate([
            'monthly_customers' => max(1, (int) ceil($messages / 2)),
            'locations' => (int) $data['locations'],
            'messages_per_customer' => 2,
            'monthly_messages' => $messages,
            'sms_segments_per_message' => 1,
            'mms_percent' => 0,
            'support_level' => 'standard',
        ]);

        return response()->json(['data' => $this->customerQuotePayload($quote)]);
    }

    public function show(Request $request, StripeGateway $stripe): JsonResponse
    {
        $business = $request->attributes->get('business');
        $subscriptionModel = BusinessSubscription::with('plan')->where('business_id', $business->id)->first();
        $subscription = $subscriptionModel ? $this->subscriptionPayload($subscriptionModel) : null;
        $plans = SubscriptionPlan::where('status', 'active')
            ->where(function ($query) use ($business, $subscriptionModel): void {
                $query->where('is_public', true);
                $query->orWhere('business_id', $business->id);
                if ($subscriptionModel?->subscription_plan_id) {
                    $query->orWhere('id', $subscriptionModel->subscription_plan_id);
                }
            })
            ->orderBy('sort_order')->orderBy('monthly_price_minor')->get()
            ->makeHidden([
                'business_id',
                'overage_price_minor', 'included_message_credits', 'sms_credit_units', 'mms_credit_units', 'whatsapp_credit_units',
                'estimated_sms_provider_cost_minor', 'estimated_mms_provider_cost_minor',
                'estimated_whatsapp_provider_cost_minor', 'allow_overage',
            ]);
        $invoices = BillingInvoice::where('business_id', $business->id)->latest()->limit(12)->get()
            ->map(fn (BillingInvoice $invoice): array => $this->invoicePayload($invoice))->all();
        $paymentMethods = [];
        $stripeError = null;
        if ($subscriptionModel?->stripe_customer_id && PlatformStripeSetting::where('status', 'verified')->exists()) {
            try {
                $snapshot = $stripe->customerBillingSnapshot($subscriptionModel);
                $subscription = $snapshot['subscription'] ?: $subscription;
                $paymentMethods = $snapshot['payment_methods'];
                if ($snapshot['invoices'] !== []) {
                    $invoices = $snapshot['invoices'];
                }
            } catch (\Throwable) {
                $stripeError = 'Live billing details are temporarily unavailable. Your saved subscription state is shown.';
            }
        }

        $role = $request->attributes->get('membership')?->role;
        $isOwner = ($role?->value ?? $role) === 'owner';
        $isSupport = $request->attributes->get('support_access') === true;

        return response()->json(['data' => [
            'stripe_ready' => PlatformStripeSetting::where('status', 'verified')->whereNotNull('webhook_secret')->exists(),
            'has_stripe_customer' => (bool) $subscriptionModel?->stripe_customer_id,
            'can_manage_billing' => $isOwner || $isSupport,
            'managed_by_support' => $isSupport,
            'trial_eligible' => ! $subscriptionModel?->trial_used_at && ! $subscriptionModel?->trial_started_at && ! $subscriptionModel?->stripe_subscription_id,
            'current_plan_code' => $business->plan_code,
            'subscription' => $subscription,
            'plans' => $plans,
            'invoices' => $invoices,
            'payment_methods' => $paymentMethods,
            'stripe_error' => $stripeError,
        ]]);
    }

    private function subscriptionPayload(BusinessSubscription $subscription): array
    {
        return [
            'status' => $subscription->status,
            'billing_interval' => $subscription->billing_interval,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'plan' => $subscription->plan?->makeHidden([
                'overage_price_minor', 'included_message_credits', 'sms_credit_units', 'mms_credit_units', 'whatsapp_credit_units',
                'estimated_sms_provider_cost_minor', 'estimated_mms_provider_cost_minor',
                'estimated_whatsapp_provider_cost_minor', 'allow_overage',
            ]),
        ];
    }

    private function invoicePayload(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'amount_due_minor' => $invoice->amount_due_minor,
            'amount_paid_minor' => $invoice->amount_paid_minor,
            'currency' => strtoupper($invoice->currency),
            'hosted_invoice_url' => $invoice->hosted_invoice_url,
            'invoice_pdf_url' => $invoice->invoice_pdf_url,
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }

    public function checkout(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $this->ensureBillingManager($request);
        $data = $request->validate(['plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('status', 'active')], 'interval' => ['required', Rule::in(['month', 'year'])]]);
        $business = $request->attributes->get('business');
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $isAssignedPrivatePlan = ! $plan->is_public && $plan->business_id === $business->id;
        abort_unless(($plan->is_self_serve || $isAssignedPrivatePlan) && $plan->monthly_price_minor > 0, 422, 'This plan requires a conversation with our team.');
        $existing = BusinessSubscription::where('business_id', $business->id)->whereNotNull('stripe_subscription_id')->whereIn('status', ['trialing', 'active', 'past_due'])->first();
        if ($existing) {
            $changed = $stripe->changeSubscription($business, $plan, $data['interval']);
            if (data_get($changed, 'plan.id') === $plan->id) {
                $existing->update([
                    'subscription_plan_id' => $plan->id,
                    'stripe_price_id' => $data['interval'] === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id,
                    'billing_interval' => $data['interval'],
                ]);
                $business->update(['plan_code' => $plan->code]);
            }
            $session = ['subscription' => $changed];
        } else {
            $session = $stripe->checkout($business, $plan, $data['interval']);
        }
        $auditor->record($request, $existing ? 'billing.plan_change_started' : 'billing.checkout_started', $business, ['plan_code' => $plan->code, 'interval' => $data['interval']]);

        return response()->json(['data' => $session]);
    }

    public function paymentMethod(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $this->ensureBillingManager($request);
        $business = $request->attributes->get('business');
        $session = $stripe->paymentMethodSetup($business);
        $auditor->record($request, 'billing.payment_method_setup_started', $business);

        return response()->json(['data' => $session]);
    }

    public function customQuote(Request $request, CustomPlanQuoteCalculator $calculator): JsonResponse
    {
        $this->ensureBillingManager($request);
        $quote = $calculator->calculate($this->customQuoteData($request));

        return response()->json(['data' => $this->customerQuotePayload($quote)]);
    }

    public function customPlan(Request $request, CustomPlanQuoteCalculator $calculator, CustomPlanManager $manager, Auditor $auditor): JsonResponse
    {
        $this->ensureBillingManager($request);
        $business = $request->attributes->get('business');
        $quote = $calculator->calculate($this->customQuoteData($request));
        $trialDays = BusinessSubscription::where('business_id', $business->id)
            ->where(fn ($query) => $query->whereNotNull('trial_used_at')->orWhereNotNull('trial_started_at')->orWhereNotNull('stripe_subscription_id'))
            ->exists() ? 0 : 7;
        $plan = $manager->configure($business, $quote, $trialDays);
        $auditor->record($request, 'billing.custom_plan.configured', $business, [
            'plan_id' => $plan->id,
            'monthly_customers' => $quote['usage']['monthly_customers'],
            'messages' => $quote['usage']['messages'],
            'locations' => $quote['usage']['locations'],
            'monthly_price_minor' => $quote['monthly_price_minor'],
        ]);

        return response()->json(['data' => $plan->makeHidden([
            'business_id', 'stripe_product_id', 'overage_price_minor', 'included_message_credits',
            'sms_credit_units', 'mms_credit_units', 'whatsapp_credit_units', 'estimated_sms_provider_cost_minor',
            'estimated_mms_provider_cost_minor', 'estimated_whatsapp_provider_cost_minor', 'allow_overage',
        ])]);
    }

    public function portal(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $this->ensureBillingManager($request);
        $data = $request->validate(['flow' => ['sometimes', Rule::in(['payment_method'])]]);
        $business = $request->attributes->get('business');
        $flow = $data['flow'] ?? null;
        $url = $stripe->portal($business, flow: $flow);
        $auditor->record($request, 'billing.portal_opened', $business, ['flow' => $flow ?? 'general']);

        return response()->json(['data' => ['url' => $url]]);
    }

    private function ensureBillingManager(Request $request): void
    {
        if ($request->attributes->get('support_access') === true) {
            return;
        }

        abort_unless(($request->attributes->get('membership')?->role?->value ?? $request->attributes->get('membership')?->role) === 'owner', 403, 'Only the business owner can manage billing.');
    }

    /** @return array{monthly_customers:int,locations:int,messages_per_customer:int,monthly_messages:?int,sms_segments_per_message:int,mms_percent:int,support_level:string} */
    private function customQuoteData(Request $request): array
    {
        return $request->validate([
            'monthly_customers' => ['required', 'integer', 'min:1', 'max:100000'],
            'locations' => ['required', 'integer', 'min:1', 'max:1000'],
            'messages_per_customer' => ['required', 'integer', 'min:1', 'max:10'],
            'monthly_messages' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'sms_segments_per_message' => ['required', 'integer', 'min:1', 'max:10'],
            'mms_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'support_level' => ['required', Rule::in(['standard', 'priority', 'dedicated'])],
        ]);
    }

    /** @param array<string, mixed> $quote */
    private function customerQuotePayload(array $quote): array
    {
        return [
            'currency' => $quote['currency'],
            'monthly_price_minor' => $quote['monthly_price_minor'],
            'annual_price_minor' => $quote['annual_price_minor'],
            'annual_discount_months' => $quote['annual_discount_months'] ?? 2,
            'usage' => $quote['usage'],
            'recommended_allowances' => $quote['recommended_allowances'],
        ];
    }
}
