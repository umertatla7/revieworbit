<?php

namespace App\Domain\Automations\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Tenancy\Enums\RecordStatus;
use App\Domain\Visits\Models\Visit;
use Carbon\CarbonImmutable;

class AutomationEvaluator
{
    public function evaluate(Visit $visit, AutomationRule $rule, ?CarbonImmutable $now = null): AutomationDispatch
    {
        $visit->loadMissing(['business', 'location', 'customer.consents', 'customer.suppressions']);
        $rule->loadMissing('messageTemplate');
        $now ??= CarbonImmutable::now('UTC');
        $reason = $this->ineligibleReason($visit, $rule, $now);

        if ($reason !== null) {
            return AutomationDispatch::firstOrCreate(
                ['visit_id' => $visit->id, 'automation_rule_id' => $rule->id, 'sequence_number' => 0],
                ['business_id' => $visit->business_id, 'decision' => 'skipped', 'reason_code' => $reason, 'decision_context' => ['evaluated_at' => $now->toIso8601String()]]
            );
        }

        $candidate = CarbonImmutable::instance($visit->completed_at)->addMinutes($rule->delay_minutes);
        if ($candidate->lessThan($now)) {
            $candidate = $now;
        }
        $scheduledFor = $this->outsideQuietHours($candidate, $visit->location->timezone, $rule->quiet_hours_start, $rule->quiet_hours_end);
        $dispatch = AutomationDispatch::firstOrCreate(
            ['visit_id' => $visit->id, 'automation_rule_id' => $rule->id, 'sequence_number' => 0],
            ['business_id' => $visit->business_id, 'decision' => 'scheduled', 'scheduled_for' => $scheduledFor, 'decision_context' => ['timezone' => $visit->location->timezone]]
        );

        foreach ($rule->followUps()->where('status', 'active')->get() as $followUp) {
            $followUpCandidate = CarbonImmutable::instance($visit->completed_at)->addMinutes($followUp->delay_minutes);
            if ($followUpCandidate->lessThan($now)) {
                $followUpCandidate = $now;
            }
            $followUpScheduledFor = $this->outsideQuietHours($followUpCandidate, $visit->location->timezone, $rule->quiet_hours_start, $rule->quiet_hours_end);
            AutomationDispatch::firstOrCreate(
                ['visit_id' => $visit->id, 'automation_rule_id' => $rule->id, 'sequence_number' => $followUp->sequence_number],
                ['business_id' => $visit->business_id, 'decision' => 'scheduled', 'scheduled_for' => $followUpScheduledFor, 'decision_context' => ['follow_up_id' => $followUp->id, 'cancel_after_click' => $followUp->cancel_after_click]]
            );
        }

        return $dispatch;
    }

    private function ineligibleReason(Visit $visit, AutomationRule $rule, CarbonImmutable $now): ?string
    {
        $businessStatus = $visit->business->status instanceof RecordStatus ? $visit->business->status->value : $visit->business->status;
        $locationStatus = $visit->location->status instanceof RecordStatus ? $visit->location->status->value : $visit->location->status;
        if ($businessStatus !== 'active') {
            return 'business_inactive';
        }
        if ($locationStatus !== 'active') {
            return 'location_inactive';
        }
        if ($rule->status !== 'active') {
            return 'rule_inactive';
        }
        if ($rule->location_id !== null && $rule->location_id !== $visit->location_id) {
            return 'location_mismatch';
        }
        if ($visit->status !== 'completed') {
            return 'visit_not_completed';
        }
        if ($visit->customer === null) {
            return 'customer_missing';
        }
        if ($visit->customer->phone_e164 === null) {
            return 'phone_missing';
        }

        $channel = $rule->messageTemplate->channel === 'mms' ? 'sms' : $rule->messageTemplate->channel;
        $consent = $visit->customer->consents->sortByDesc('recorded_at')->firstWhere('channel', $channel);
        if ($consent === null || $consent->status !== 'granted' || ($consent->expires_at && $consent->expires_at->isBefore($now))) {
            return 'consent_missing';
        }
        if ($visit->customer->suppressions->contains(fn ($entry): bool => $entry->channel === $channel && $entry->released_at === null)) {
            return 'customer_suppressed';
        }
        if (AutomationDispatch::where('visit_id', $visit->id)->where('automation_rule_id', $rule->id)->exists()) {
            return 'duplicate_dispatch';
        }

        $frequencyStart = $now->subDays($rule->frequency_limit_days);
        $recent = AutomationDispatch::query()
            ->where('business_id', $visit->business_id)
            ->where('decision', 'scheduled')
            ->where('created_at', '>=', $frequencyStart)
            ->whereHas('visit', fn ($query) => $query->where('customer_id', $visit->customer_id))
            ->exists();

        return $recent ? 'frequency_limited' : null;
    }

    private function outsideQuietHours(CarbonImmutable $utc, string $timezone, ?string $start, ?string $end): CarbonImmutable
    {
        if ($start === null || $end === null || $start === $end) {
            return $utc;
        }
        $local = $utc->setTimezone($timezone);
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
