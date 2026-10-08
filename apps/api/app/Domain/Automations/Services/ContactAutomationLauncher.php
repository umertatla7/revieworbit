<?php

namespace App\Domain\Automations\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Automations\Models\AutomationRun;
use App\Domain\Customers\Models\Customer;
use App\Domain\Messaging\Jobs\SendAutomationDispatch;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Tenancy\Enums\RecordStatus;
use App\Domain\Tenancy\Services\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactAutomationLauncher
{
    public function __construct(private readonly PlanEntitlements $entitlements) {}

    public function preview(AutomationRule $rule, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $rule->loadMissing(['business.messagingConfiguration', 'location', 'messageTemplate', 'followUps.messageTemplate']);
        $this->assertLaunchable($rule);
        [$eligible, $skipped] = $this->classify($rule, $now);
        $limits = $this->entitlements->for($rule->business);
        $alreadyCounted = MessageDelivery::query()
            ->where('business_id', $rule->business_id)
            ->whereIn('customer_id', $eligible->pluck('id'))
            ->where('created_at', '>=', $now->startOfMonth())
            ->distinct()
            ->pluck('customer_id');
        $newCustomers = $eligible->pluck('id')->diff($alreadyCounted)->count();

        return [
            'total_contacts' => $eligible->count() + array_sum($skipped),
            'eligible_contacts' => $eligible->count(),
            'new_plan_customers' => $newCustomers,
            'customers_remaining_this_month' => $limits['customers_remaining_this_month'],
            'skipped_contacts' => array_sum($skipped),
            'skipped_summary' => $skipped,
            'first_message_at' => $eligible->isEmpty()
                ? null
                : $now->toIso8601String(),
        ];
    }

    public function launch(AutomationRule $rule, string $requestedByUserId, ?CarbonImmutable $now = null): AutomationRun
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($rule, $requestedByUserId, $now): AutomationRun {
            $rule = AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $rule->loadMissing(['business.messagingConfiguration', 'location', 'messageTemplate', 'followUps.messageTemplate']);
            $this->assertLaunchable($rule);
            [$eligible, $skipped] = $this->classify($rule, $now);
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['audience' => ['No contacts are currently eligible. Add SMS consent or review the skipped-contact reasons.']]);
            }

            $limits = $this->entitlements->for($rule->business);
            if ($limits['monthly_customer_limit'] !== null) {
                $alreadyCounted = MessageDelivery::query()
                    ->where('business_id', $rule->business_id)
                    ->whereIn('customer_id', $eligible->pluck('id'))
                    ->where('created_at', '>=', $now->startOfMonth())
                    ->distinct()
                    ->pluck('customer_id');
                $newCustomers = $eligible->pluck('id')->diff($alreadyCounted)->count();
                if ($newCustomers > $limits['customers_remaining_this_month']) {
                    throw ValidationException::withMessages([
                        'audience' => ["This launch needs {$newCustomers} new monthly customers, but the plan has {$limits['customers_remaining_this_month']} remaining."],
                    ]);
                }
            }

            $steps = collect([[
                'sequence_number' => 0,
                'delay_minutes' => 0,
                'follow_up_id' => null,
                'cancel_after_click' => false,
            ]])->concat($rule->followUps->where('status', 'active')->map(fn ($followUp): array => [
                'sequence_number' => $followUp->sequence_number,
                'delay_minutes' => $followUp->delay_minutes,
                'follow_up_id' => $followUp->id,
                'cancel_after_click' => $followUp->cancel_after_click,
            ]));
            $run = AutomationRun::create([
                'business_id' => $rule->business_id,
                'automation_rule_id' => $rule->id,
                'requested_by_user_id' => $requestedByUserId,
                'status' => 'scheduled',
                'audience_count' => $eligible->count() + array_sum($skipped),
                'scheduled_count' => $eligible->count(),
                'skipped_count' => array_sum($skipped),
                'skipped_summary' => $skipped,
                'started_at' => $now,
            ]);

            foreach ($eligible as $customer) {
                foreach ($steps as $step) {
                    $dispatch = AutomationDispatch::create([
                        'business_id' => $rule->business_id,
                        'visit_id' => null,
                        'automation_run_id' => $run->id,
                        'customer_id' => $customer->id,
                        'automation_rule_id' => $rule->id,
                        'sequence_number' => $step['sequence_number'],
                        'decision' => 'scheduled',
                        'scheduled_for' => $step['sequence_number'] === 0
                            ? $now
                            : $this->outsideQuietHours($now->addMinutes($step['delay_minutes']), $rule),
                        'decision_context' => [
                            'trigger_type' => 'contacts.manual',
                            'follow_up_id' => $step['follow_up_id'],
                            'cancel_after_click' => $step['cancel_after_click'],
                        ],
                    ]);
                    if ($step['sequence_number'] === 0) {
                        SendAutomationDispatch::dispatch($dispatch->id)->onQueue('messaging')->afterCommit();
                    }
                }
            }

            return $run->fresh(['rule', 'dispatches']);
        });
    }

    public function sendPendingNow(AutomationRule $rule, AutomationRun $run): int
    {
        $rule->loadMissing(['business.messagingConfiguration', 'location', 'messageTemplate', 'followUps.messageTemplate']);
        $this->assertLaunchable($rule);
        abort_unless($run->business_id === $rule->business_id && $run->automation_rule_id === $rule->id, 404);

        return DB::transaction(function () use ($run): int {
            $pending = $run->dispatches()->where('sequence_number', 0)->where('decision', 'scheduled')
                ->whereDoesntHave('delivery')->lockForUpdate()->get();
            foreach ($pending as $dispatch) {
                $dispatch->update(['scheduled_for' => now()]);
                SendAutomationDispatch::dispatch($dispatch->id)->onQueue('messaging')->afterCommit();
            }

            return $pending->count();
        });
    }

    /** @return array{0: Collection<int, Customer>, 1: array<string, int>} */
    private function classify(AutomationRule $rule, CarbonImmutable $now): array
    {
        $frequencyStart = $now->subDays($rule->frequency_limit_days);
        $customers = Customer::query()
            ->where('business_id', $rule->business_id)
            ->with([
                'consents' => fn ($query) => $query->where('channel', 'sms')->latest('recorded_at'),
                'suppressions' => fn ($query) => $query->where('channel', 'sms')->whereNull('released_at'),
                'messageDeliveries' => fn ($query) => $query->when(
                    $rule->frequency_limit_days > 0,
                    fn ($delivery) => $delivery->where('created_at', '>=', $frequencyStart),
                    fn ($delivery) => $delivery->whereRaw('1 = 0'),
                ),
            ])
            ->orderBy('id')
            ->get();
        $pendingCustomerIds = AutomationDispatch::query()
            ->where('business_id', $rule->business_id)
            ->whereNotNull('customer_id')
            ->where('decision', 'scheduled')
            ->whereDoesntHave('delivery')
            ->pluck('customer_id')
            ->flip();
        $eligible = collect();
        $skipped = [
            'inactive' => 0,
            'phone_missing' => 0,
            'consent_missing' => 0,
            'suppressed' => 0,
            'review_already_confirmed' => 0,
            'frequency_limited' => 0,
            'already_scheduled' => 0,
        ];

        foreach ($customers as $customer) {
            $reason = match (true) {
                $customer->status !== 'active' => 'inactive',
                ! $customer->phone_e164 => 'phone_missing',
                $customer->review_request_status === 'review_confirmed' => 'review_already_confirmed',
                $customer->suppressions->isNotEmpty() => 'suppressed',
                ! $this->hasConsent($customer, $now) => 'consent_missing',
                $pendingCustomerIds->has($customer->id) => 'already_scheduled',
                $rule->frequency_limit_days > 0 && $customer->messageDeliveries->isNotEmpty() => 'frequency_limited',
                default => null,
            };
            if ($reason === null) {
                $eligible->push($customer);
            } else {
                $skipped[$reason]++;
            }
        }

        return [$eligible, array_filter($skipped)];
    }

    private function hasConsent(Customer $customer, CarbonImmutable $now): bool
    {
        $consent = $customer->consents->first();

        return $consent?->status === 'granted' && (! $consent->expires_at || $consent->expires_at->isAfter($now));
    }

    private function assertLaunchable(AutomationRule $rule): void
    {
        if ($rule->business->status !== RecordStatus::Active) {
            throw ValidationException::withMessages(['business' => ['The business must be active before launching messages.']]);
        }
        if ($rule->trigger_type !== 'contacts.manual') {
            throw ValidationException::withMessages(['automation' => ['Only a contact-list automation can be launched manually.']]);
        }
        if ($rule->status !== 'active') {
            throw ValidationException::withMessages(['automation' => ['Activate this automation before launching it.']]);
        }
        $locationStatus = $rule->location?->status instanceof RecordStatus ? $rule->location->status->value : $rule->location?->status;
        if (! $rule->location || $locationStatus !== 'active') {
            throw ValidationException::withMessages(['location_id' => ['A contact-list automation requires one active location.']]);
        }
        if ($rule->messageTemplate->status !== 'active' || $rule->messageTemplate->location_id !== $rule->location_id) {
            throw ValidationException::withMessages(['message_template_id' => ['Select an active template for this automation location.']]);
        }
        if ($rule->business->messagingConfiguration?->status !== 'active' || ! $rule->business->messagingConfiguration?->sms_enabled) {
            throw ValidationException::withMessages(['configuration' => ['Verify and enable SMS before launching this automation.']]);
        }
    }

    private function outsideQuietHours(CarbonImmutable $utc, AutomationRule $rule): CarbonImmutable
    {
        $start = $rule->quiet_hours_start;
        $end = $rule->quiet_hours_end;
        if ($start === null || $end === null || $start === $end) {
            return $utc;
        }
        $local = $utc->setTimezone($rule->location->timezone);
        $startToday = $local->setTimeFromTimeString($start);
        $endToday = $local->setTimeFromTimeString($end);
        if ($start < $end && $local->betweenIncluded($startToday, $endToday)) {
            return $endToday->utc();
        }
        if ($start > $end) {
            if ($local->greaterThanOrEqualTo($startToday)) {
                return $endToday->addDay()->utc();
            }
            if ($local->lessThan($endToday)) {
                return $endToday->utc();
            }
        }

        return $utc;
    }
}
