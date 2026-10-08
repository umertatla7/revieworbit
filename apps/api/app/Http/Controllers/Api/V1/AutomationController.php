<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Automations\Models\AutomationRun;
use App\Domain\Automations\Services\ContactAutomationLauncher;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Services\PlanEntitlements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $rules = AutomationRule::where('business_id', $businessId)
            ->with(['location', 'messageTemplate.reviewDestination', 'followUps.messageTemplate.reviewDestination', 'runs' => fn ($query) => $query->withCount([
                'dispatches as pending_first_count' => fn ($dispatches) => $dispatches->where('sequence_number', 0)->where('decision', 'scheduled')->whereDoesntHave('delivery'),
            ])->latest()->limit(3)])
            ->latest()
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request, Auditor $auditor, PlanEntitlements $entitlements): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $limits = $entitlements->for($request->attributes->get('business'));
        abort_unless($limits['can_add_automation'], 422, 'Your current plan has reached its automation limit.');
        $data = $this->validated($request);
        $data['trigger_type'] ??= 'visit.completed';
        if ($data['trigger_type'] === 'contacts.manual') {
            $data['delay_minutes'] = 0;
        }
        $this->validateStepLimit($data, $limits['automation_step_limit']);
        $this->validateStepTiming($data);
        $this->validateTenantReferences($businessId, $data);
        $preferences = $request->attributes->get('business')->messaging_preferences ?? [];
        $data['quiet_hours_start'] ??= $preferences['quiet_hours_start'] ?? '20:00';
        $data['quiet_hours_end'] ??= $preferences['quiet_hours_end'] ?? '09:00';
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

    public function update(Request $request, string $automation, Auditor $auditor, PlanEntitlements $entitlements): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $rule = AutomationRule::where('business_id', $businessId)->findOrFail($automation);
        $data = $this->validated($request, true);
        if (($data['trigger_type'] ?? $rule->trigger_type) === 'contacts.manual') {
            $data['delay_minutes'] = 0;
        }
        $this->validateStepLimit($data, $entitlements->for($request->attributes->get('business'))['automation_step_limit']);
        $this->validateStepTiming($data, $rule->delay_minutes);
        $this->validateTenantReferences($businessId, [
            ...$rule->only(['location_id', 'message_template_id', 'trigger_type', 'status']),
            ...$data,
        ]);
        $followUps = $data['follow_ups'] ?? null;
        unset($data['follow_ups']);
        DB::transaction(function () use ($rule, $data, $followUps, $businessId): void {
            $rule->update($data);
            if (is_array($followUps)) {
                $rule->followUps()->delete();
                foreach ($followUps as $index => $followUp) {
                    MessageTemplate::where('business_id', $businessId)->findOrFail($followUp['message_template_id']);
                    $rule->followUps()->create([...$followUp, 'sequence_number' => $index + 1]);
                }
            }
        });
        $auditor->record($request, 'automation.updated', $rule, array_keys($data));

        return response()->json(['data' => $rule->fresh(['location', 'messageTemplate', 'followUps'])]);
    }

    public function history(Request $request): JsonResponse
    {
        $dispatches = AutomationDispatch::where('business_id', $request->attributes->get('business')->id)
            ->with(['visit.customer', 'visit.location', 'customer', 'run', 'rule'])
            ->latest()
            ->paginate(50);

        return response()->json(['data' => $dispatches->items(), 'meta' => ['total' => $dispatches->total()]]);
    }

    public function audience(Request $request, string $automation, ContactAutomationLauncher $launcher): JsonResponse
    {
        $rule = AutomationRule::where('business_id', $request->attributes->get('business')->id)->findOrFail($automation);

        return response()->json(['data' => $launcher->preview($rule)]);
    }

    public function launch(Request $request, string $automation, ContactAutomationLauncher $launcher, Auditor $auditor): JsonResponse
    {
        $rule = AutomationRule::where('business_id', $request->attributes->get('business')->id)->findOrFail($automation);
        $run = $launcher->launch($rule, $request->user()->id);
        $auditor->record($request, 'automation.contact_list_launched', $run, [
            'automation_rule_id' => $rule->id,
            'scheduled_count' => $run->scheduled_count,
            'skipped_count' => $run->skipped_count,
        ]);

        return response()->json(['data' => $run], 202);
    }

    public function sendPendingNow(Request $request, string $automation, string $run, ContactAutomationLauncher $launcher, Auditor $auditor): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $rule = AutomationRule::where('business_id', $businessId)->findOrFail($automation);
        $model = AutomationRun::where('business_id', $businessId)->where('automation_rule_id', $rule->id)->findOrFail($run);
        $count = $launcher->sendPendingNow($rule, $model);
        $auditor->record($request, 'automation.pending_first_messages_queued', $model, ['queued_count' => $count]);

        return response()->json(['data' => ['queued_count' => $count]], 202);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'location_id' => ['nullable', 'string'],
            'message_template_id' => [$required, 'string'],
            'trigger_type' => ['sometimes', Rule::in(['visit.completed', 'contacts.manual'])],
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
        if (($data['trigger_type'] ?? null) === 'contacts.manual' && empty($data['location_id'])) {
            throw ValidationException::withMessages(['location_id' => ['Choose the location whose review link this contact campaign will use.']]);
        }
        if (isset($data['message_template_id'])) {
            $template = MessageTemplate::where('business_id', $businessId)->findOrFail($data['message_template_id']);
            if (! empty($data['location_id']) && $template->location_id !== $data['location_id']) {
                throw ValidationException::withMessages(['message_template_id' => ['Select a template configured for the automation location.']]);
            }
            if (($data['status'] ?? 'active') === 'active' && $template->status !== 'active') {
                throw ValidationException::withMessages(['message_template_id' => ['Activate the selected message template before activating this automation.']]);
            }
        }
        if (! empty($data['location_id'])) {
            Location::where('business_id', $businessId)->findOrFail($data['location_id']);
        }
        foreach ($data['follow_ups'] ?? [] as $followUp) {
            $template = MessageTemplate::where('business_id', $businessId)->findOrFail($followUp['message_template_id']);
            if (! empty($data['location_id']) && $template->location_id !== $data['location_id']) {
                throw ValidationException::withMessages(['follow_ups' => ['Every follow-up template must belong to the automation location.']]);
            }
            if (($data['status'] ?? 'active') === 'active' && $template->status !== 'active') {
                throw ValidationException::withMessages(['follow_ups' => ['Activate every follow-up template before activating this automation.']]);
            }
        }
    }

    private function validateStepLimit(array $data, int $limit): void
    {
        if (1 + count($data['follow_ups'] ?? []) > $limit) {
            throw ValidationException::withMessages(['follow_ups' => ["Your plan allows {$limit} message step(s) per automation."]]);
        }
    }

    private function validateStepTiming(array $data, ?int $existingInitialDelay = null): void
    {
        $previous = (int) ($data['delay_minutes'] ?? $existingInitialDelay ?? 0);
        foreach ($data['follow_ups'] ?? [] as $index => $followUp) {
            $delay = (int) $followUp['delay_minutes'];
            if ($delay <= $previous) {
                throw ValidationException::withMessages([
                    "follow_ups.{$index}.delay_minutes" => ['Each follow-up must be scheduled later than the message before it.'],
                ]);
            }
            $previous = $delay;
        }
    }
}
