<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Messaging\Services\MessagingManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAutomationDispatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $dispatchId) {}

    public function uniqueId(): string
    {
        return $this->dispatchId;
    }

    public function handle(MessagingManager $manager): void
    {
        $dispatch = AutomationDispatch::find($this->dispatchId);
        if ($dispatch?->decision === 'scheduled' && $dispatch->scheduled_for?->lte(now())) {
            $dispatch->loadMissing(['customer', 'visit.customer', 'run']);
            $customer = $dispatch->customer ?? $dispatch->visit?->customer;
            if ($customer?->review_request_status === 'review_confirmed') {
                $dispatch->update(['decision' => 'cancelled', 'reason_code' => 'review_already_confirmed']);
                $this->completeRunWhenFinished($dispatch);

                return;
            }
            if ($dispatch->sequence_number > 0 && ($dispatch->decision_context['cancel_after_click'] ?? false)) {
                $clicked = AutomationDispatch::query()
                    ->where('automation_rule_id', $dispatch->automation_rule_id)
                    ->where('sequence_number', '<', $dispatch->sequence_number)
                    ->when(
                        $dispatch->automation_run_id,
                        fn ($query) => $query->where('automation_run_id', $dispatch->automation_run_id)->where('customer_id', $dispatch->customer_id),
                        fn ($query) => $query->where('visit_id', $dispatch->visit_id),
                    )
                    ->whereHas('delivery.reviewLink', fn ($query) => $query->whereNotNull('first_clicked_at'))
                    ->exists();
                if ($clicked) {
                    $dispatch->update(['decision' => 'cancelled', 'reason_code' => 'review_link_clicked']);
                    $this->completeRunWhenFinished($dispatch);

                    return;
                }
            }
            $manager->send($dispatch);
            $this->completeRunWhenFinished($dispatch);
        }
    }

    private function completeRunWhenFinished(AutomationDispatch $dispatch): void
    {
        if ($dispatch->run && ! $dispatch->run->dispatches()->where('decision', 'scheduled')->whereDoesntHave('delivery')->exists()) {
            $dispatch->run->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}
