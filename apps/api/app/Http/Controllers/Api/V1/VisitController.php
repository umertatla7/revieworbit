<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Automations\Services\AutomationEvaluator;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $visits = Visit::where('business_id', $request->attributes->get('business')->id)
            ->with(['customer', 'location', 'dispatches.rule'])
            ->latest('completed_at')
            ->paginate(50);

        return response()->json(['data' => $visits->items(), 'meta' => ['total' => $visits->total()]]);
    }

    public function store(Request $request, AutomationEvaluator $evaluator, Auditor $auditor): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $data = $request->validate([
            'location_id' => ['required', 'string'],
            'customer_id' => ['required', 'string'],
            'automation_rule_id' => ['nullable', 'string'],
            'completed_at' => ['required', 'date', 'before_or_equal:now'],
            'type' => ['required', Rule::in(['purchase', 'appointment', 'service', 'payment', 'other'])],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'consent_source' => ['nullable', Rule::in(['written', 'verbal', 'web_form', 'provider'])],
        ]);
        Location::where('business_id', $businessId)->findOrFail($data['location_id']);
        $customer = Customer::where('business_id', $businessId)->findOrFail($data['customer_id']);
        if (! empty($data['consent_source'])) {
            $customer->consents()->create(['business_id' => $businessId, 'channel' => 'sms', 'status' => 'granted', 'source' => $data['consent_source'], 'recorded_at' => now(), 'evidence' => ['method' => 'manual_visit']]);
        }
        $visit = Visit::create([
            'business_id' => $businessId,
            'location_id' => $data['location_id'],
            'customer_id' => $customer->id,
            'source' => 'manual',
            'type' => $data['type'],
            'status' => 'completed',
            'amount' => $data['amount'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'completed_at' => $data['completed_at'],
        ]);
        $customer->update(['last_visit_at' => $visit->completed_at]);
        $rules = isset($data['automation_rule_id'])
            ? AutomationRule::where('business_id', $businessId)->whereKey($data['automation_rule_id'])->get()
            : AutomationRule::where('business_id', $businessId)->where('status', 'active')->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $visit->location_id))->get();
        $dispatches = $rules->map(fn (AutomationRule $rule) => $evaluator->evaluate($visit, $rule));
        $auditor->record($request, 'visit.created', $visit, ['source' => 'manual']);

        return response()->json(['data' => [...$visit->load(['customer', 'location'])->toArray(), 'automation_dispatches' => $dispatches]], 201);
    }
}
