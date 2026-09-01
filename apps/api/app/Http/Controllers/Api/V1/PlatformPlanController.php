<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
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
        return response()->json(['data' => SubscriptionPlan::orderBy('monthly_price_minor')->get()]);
    }

    public function update(Request $request, string $plan, Auditor $auditor): JsonResponse
    {
        $model = SubscriptionPlan::findOrFail($plan);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'monthly_price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'location_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'template_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'automation_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'automation_step_limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'media_template_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'included_message_credits' => ['sometimes', 'integer', 'min:0'],
            'overage_price_minor' => ['sometimes', 'integer', 'min:0'],
            'sms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'mms_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'whatsapp_credit_units' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'estimated_sms_provider_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'estimated_mms_provider_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'estimated_whatsapp_provider_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'review_providers' => ['sometimes', 'array', 'min:1'],
            'review_providers.*' => [Rule::in(['google', 'trustpilot', 'facebook', 'yelp', 'other'])],
            'allow_overage' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]);
        $model->update($data);
        $auditor->record($request, 'subscription_plan.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh()]);
    }

    public function usage(Request $request): JsonResponse
    {
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfMonth();
        $businesses = Business::query()->withCount(['locations', 'templates'])->orderBy('name')->get();
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
