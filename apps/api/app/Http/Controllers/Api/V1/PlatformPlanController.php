<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
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
        $data = array_merge($data, $this->inclusiveMessagingDefaults());
        $model = SubscriptionPlan::create($data);
        $auditor->record($request, 'subscription_plan.created', $model, ['code' => $model->code]);

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, string $plan, Auditor $auditor): JsonResponse
    {
        $model = SubscriptionPlan::findOrFail($plan);
        $data = $this->validated($request);
        $data = array_merge($data, $this->inclusiveMessagingDefaults());
        $model->update($data);
        $auditor->record($request, 'subscription_plan.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh()]);
    }

    public function syncStripe(Request $request, string $plan, StripeGateway $stripe, Auditor $auditor): JsonResponse
    {
        $model = SubscriptionPlan::findOrFail($plan);
        $model = $stripe->syncPlan($model);
        $auditor->record($request, 'subscription_plan.stripe_synced', $model, ['stripe_product_id' => $model->stripe_product_id]);

        return response()->json(['data' => $model]);
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/', Rule::unique('subscription_plans', 'code')->ignore($request->route('plan'))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_minor' => ['sometimes', 'integer', 'min:0'],
            'annual_price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'badge' => ['nullable', 'string', 'max:40'],
            'is_featured' => ['sometimes', 'boolean'],
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
            'sms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'mms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'whatsapp_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'review_providers' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'review_providers.*' => [Rule::in(['google', 'trustpilot', 'facebook', 'yelp', 'other'])],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
        ]);
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

            return [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'plan_code' => $business->plan_code,
                'messages' => (clone $deliveries)->count(),
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
