<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Models\PlatformStripeSetting;
use App\Domain\Billing\Services\StripeGateway;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $subscription = BusinessSubscription::with('plan')->where('business_id', $business->id)->first();
        $plans = SubscriptionPlan::where('status', 'active')->orderBy('sort_order')->orderBy('monthly_price_minor')->get();
        $invoices = BillingInvoice::where('business_id', $business->id)->latest()->limit(12)->get();

        return response()->json(['data' => [
            'stripe_ready' => PlatformStripeSetting::where('status', 'verified')->whereNotNull('webhook_secret')->exists(),
            'current_plan_code' => $business->plan_code,
            'subscription' => $subscription,
            'plans' => $plans,
            'invoices' => $invoices,
        ]]);
    }

    public function checkout(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $this->ensureCustomerOwner($request);
        $data = $request->validate(['plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('status', 'active')], 'interval' => ['required', Rule::in(['month', 'year'])]]);
        $business = $request->attributes->get('business');
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $existing = BusinessSubscription::where('business_id', $business->id)->whereNotNull('stripe_subscription_id')->whereIn('status', ['trialing', 'active', 'past_due'])->first();
        $url = $existing ? $stripe->portal($business, $plan, $data['interval']) : $stripe->checkout($business, $plan, $data['interval']);
        $auditor->record($request, $existing ? 'billing.plan_change_started' : 'billing.checkout_started', $business, ['plan_code' => $plan->code, 'interval' => $data['interval']]);

        return response()->json(['data' => ['url' => $url]]);
    }

    public function portal(Request $request, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $this->ensureCustomerOwner($request);
        $business = $request->attributes->get('business');
        $url = $stripe->portal($business);
        $auditor->record($request, 'billing.portal_opened', $business);

        return response()->json(['data' => ['url' => $url]]);
    }

    private function ensureCustomerOwner(Request $request): void
    {
        abort_if($request->attributes->get('support_access') === true, 403, 'For customer privacy, administrators cannot open a customer billing session.');
        abort_unless(($request->attributes->get('membership')?->role?->value ?? $request->attributes->get('membership')?->role) === 'owner', 403, 'Only the business owner can manage billing.');
    }
}
