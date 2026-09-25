<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Billing\Services\CustomPlanManager;
use App\Domain\Billing\Services\CustomPlanQuoteCalculator;
use App\Domain\Billing\Services\StripeGateway;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => SubscriptionPlan::orderBy('sort_order')->orderBy('monthly_price_minor')->get()]);
    }

    public function store(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $this->validated($request, true);
        $data = $this->withAnnualPrice($data);
        $data = array_merge($data, $this->inclusiveMessagingDefaults());
        $model = SubscriptionPlan::create($data);
        $auditor->record($request, 'subscription_plan.created', $model, ['code' => $model->code]);

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, string $plan, Auditor $auditor): JsonResponse
    {
        $model = SubscriptionPlan::findOrFail($plan);
        $data = $this->validated($request);
        $data = $this->withAnnualPrice($data, $model);
        $data = array_merge($data, $this->inclusiveMessagingDefaults());
        $model->update($data);
        $auditor->record($request, 'subscription_plan.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh()]);
    }

    public function syncStripe(Request $request, string $plan, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $model = SubscriptionPlan::findOrFail($plan);
        abort_unless($model->monthly_price_minor > 0, 422, 'A paid plan is required before creating Stripe prices.');
        $model = $stripe->syncPlan($model);
        $auditor->record($request, 'subscription_plan.stripe_synced', $model, ['stripe_product_id' => $model->stripe_product_id]);

        return response()->json(['data' => $model]);
    }

    public function quote(Request $request, CustomPlanQuoteCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'monthly_customers' => ['required', 'integer', 'min:1', 'max:100000'],
            'locations' => ['required', 'integer', 'min:1', 'max:1000'],
            'messages_per_customer' => ['required', 'integer', 'min:1', 'max:10'],
            'monthly_messages' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'sms_segments_per_message' => ['required', 'integer', 'min:1', 'max:10'],
            'mms_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'support_level' => ['required', Rule::in(['standard', 'priority', 'dedicated'])],
        ]);

        return response()->json(['data' => $calculator->calculate($data)]);
    }

    public function assignCustom(Request $request, CustomPlanQuoteCalculator $calculator, CustomPlanManager $manager, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $data = $this->quoteData($request, true);
        $business = Business::findOrFail($data['business_id']);
        $quote = $calculator->calculate($data);
        $plan = $manager->configure($business, $quote, 0);
        $subscription = BusinessSubscription::firstOrCreate(['business_id' => $business->id]);
        $changed = null;

        if ($subscription->stripe_subscription_id && in_array($subscription->status, ['trialing', 'active', 'past_due'], true)) {
            $interval = $subscription->billing_interval === 'year' ? 'year' : 'month';
            $changed = $stripe->changeSubscription($business, $plan, $interval);
            $subscription->update([
                'subscription_plan_id' => $plan->id,
                'stripe_price_id' => $interval === 'year' ? $plan->stripe_annual_price_id : $plan->stripe_monthly_price_id,
                'billing_interval' => $interval,
            ]);
        } else {
            $subscription->update(['subscription_plan_id' => $plan->id]);
        }
        $business->update(['plan_code' => $plan->code]);
        $auditor->record($request, 'subscription_plan.custom_assigned', $business, [
            'plan_id' => $plan->id,
            'monthly_price_minor' => $plan->monthly_price_minor,
            'stripe_subscription_changed' => $changed !== null,
        ]);

        return response()->json(['data' => ['plan' => $plan, 'business' => ['id' => $business->id, 'name' => $business->name], 'subscription' => $changed]], 201);
    }

    /** @return array<string, mixed> */
    private function quoteData(Request $request, bool $withBusiness = false): array
    {
        return $request->validate([
            'business_id' => [$withBusiness ? 'required' : 'sometimes', 'ulid', Rule::exists('businesses', 'id')],
            'monthly_customers' => ['required', 'integer', 'min:1', 'max:100000'],
            'locations' => ['required', 'integer', 'min:1', 'max:1000'],
            'messages_per_customer' => ['required', 'integer', 'min:1', 'max:10'],
            'monthly_messages' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'sms_segments_per_message' => ['required', 'integer', 'min:1', 'max:10'],
            'mms_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'support_level' => ['required', Rule::in(['standard', 'priority', 'dedicated'])],
        ]);
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/', Rule::unique('subscription_plans', 'code')->ignore($request->route('plan'))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_minor' => ['sometimes', 'integer', 'min:0'],
            'annual_price_minor' => ['sometimes', 'integer', 'min:0'],
            'annual_discount_months' => ['sometimes', 'integer', 'min:0', 'max:11'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'trial_message_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'badge' => ['nullable', 'string', 'max:40'],
            'cta_label' => ['sometimes', 'string', 'max:60'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_self_serve' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'features' => ['sometimes', 'array', 'max:30'],
            'features.*' => ['string', 'max:120'],
            'location_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'template_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'automation_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'automation_step_limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'media_template_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'review_destination_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'included_message_credits' => ['sometimes', 'integer', 'min:0'],
            'monthly_customer_limit' => ['nullable', 'integer', 'min:1'],
            'sms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'mms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'whatsapp_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'review_providers' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'review_providers.*' => [Rule::in(['google', 'trustpilot', 'facebook', 'yelp', 'other'])],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function withAnnualPrice(array $data, ?SubscriptionPlan $plan = null): array
    {
        $monthlyPrice = (int) ($data['monthly_price_minor'] ?? $plan?->monthly_price_minor ?? 0);
        $discountMonths = (int) ($data['annual_discount_months'] ?? $plan?->annual_discount_months ?? 2);
        $data['annual_discount_months'] = $discountMonths;
        $data['annual_price_minor'] = $monthlyPrice * (12 - $discountMonths);

        return $data;
    }

    /**
     * Messaging is included in the subscription package. These values are
     * server-owned so the plan builder cannot accidentally enable usage fees.
     */
    private function inclusiveMessagingDefaults(): array
    {
        return [
            'overage_price_minor' => 0,
            'allow_overage' => false,
            'sms_credit_units' => 1,
            'mms_credit_units' => 1,
            'whatsapp_credit_units' => 1,
            'estimated_sms_provider_cost_minor' => 0,
            'estimated_mms_provider_cost_minor' => 0,
            'estimated_whatsapp_provider_cost_minor' => 0,
        ];
    }

    public function usage(Request $request): JsonResponse
    {
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfMonth();
        $businesses = Business::query()->orderBy('name')->get();
        $usage = $businesses->map(function (Business $business) use ($from, $to): array {
            $deliveries = MessageDelivery::where('business_id', $business->id)->whereBetween('created_at', [$from, $to]);
            $plan = SubscriptionPlan::where('code', $business->plan_code)->first();
            $credits = (clone $deliveries)->sum('billable_credits');
            $estimatedCost = (clone $deliveries)->sum('estimated_cost_minor');
            $failed = (clone $deliveries)->whereIn('status', ['failed', 'undelivered'])->count();
            $customers = (clone $deliveries)->distinct('customer_id')->count('customer_id');

            return [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'plan_code' => $business->plan_code,
                'messages' => (clone $deliveries)->count(),
                'customers' => $customers,
                'customer_limit' => $plan?->monthly_customer_limit,
                'delivered' => (clone $deliveries)->where('status', 'delivered')->count(),
                'failed' => $failed,
                'credits_used' => (int) $credits,
                'included_credits' => $plan?->included_message_credits ?? 0,
                'estimated_cost_minor' => (int) $estimatedCost,
                'currency' => $plan?->currency ?? 'USD',
            ];
        });

        return response()->json(['data' => $usage, 'meta' => ['from' => $from, 'to' => $to]]);
    }
}
