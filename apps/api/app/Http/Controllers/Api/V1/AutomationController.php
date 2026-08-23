<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Location;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $rules = AutomationRule::where('business_id', $businessId)->with(['location', 'messageTemplate', 'followUps'])->latest()->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request, Auditor $auditor): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $data = $this->validated($request);
        $this->validateTenantReferences($businessId, $data);
        $followUps = $data['follow_ups'] ?? [];
        unset($data['follow_ups']);
        $rule = AutomationRule::create([...$data, 'business_id' => $businessId]);
        foreach ($followUps as $index => $followUp) {
            MessageTemplate::where('business_id', $businessId)->findOrFail($followUp['message_template_id']);
            $rule->followUps()->create([...$followUp, 'sequence_number' => $index + 1]);
        }
        $auditor->record($request, 'automation.created', $rule, ['name' => $rule->name]);

        return response()->json(['data' => $rule->load(['location', 'messageTemplate', 'followUps'])], 201);
    }

    public function update(Request $request, string $automation, Auditor $auditor): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $rule = AutomationRule::where('business_id', $businessId)->findOrFail($automation);
        $data = $this->validated($request, true);
        $this->validateTenantReferences($businessId, $data);
        unset($data['follow_ups']);
        $rule->update($data);
        $auditor->record($request, 'automation.updated', $rule, array_keys($data));

        return response()->json(['data' => $rule->fresh(['location', 'messageTemplate', 'followUps'])]);
    }

    public function history(Request $request): JsonResponse
    {
        $dispatches = AutomationDispatch::where('business_id', $request->attributes->get('business')->id)
            ->with(['visit.customer', 'visit.location', 'rule'])
            ->latest()
            ->paginate(50);

        return response()->json(['data' => $dispatches->items(), 'meta' => ['total' => $dispatches->total()]]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'location_id' => ['nullable', 'string'],
            'message_template_id' => [$required, 'string'],
            'trigger_type' => ['sometimes', Rule::in(['visit.completed'])],
            'delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'frequency_limit_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'cancel_follow_up_after_click' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'disabled'])],
            'follow_ups' => ['sometimes', 'array', 'max:3'],
            'follow_ups.*.message_template_id' => ['required', 'string'],
            'follow_ups.*.delay_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'follow_ups.*.cancel_after_click' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateTenantReferences(string $businessId, array $data): void
    {
        if (isset($data['message_template_id'])) {
            MessageTemplate::where('business_id', $businessId)->findOrFail($data['message_template_id']);
        }
        if (! empty($data['location_id'])) {
            Location::where('business_id', $businessId)->findOrFail($data['location_id']);
        }
    }
}
