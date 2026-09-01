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
            if ($dispatch->sequence_number > 0 && ($dispatch->decision_context['cancel_after_click'] ?? false)) {
                $clicked = AutomationDispatch::query()
                    ->where('visit_id', $dispatch->visit_id)
                    ->where('automation_rule_id', $dispatch->automation_rule_id)
                    ->where('sequence_number', '<', $dispatch->sequence_number)
                    ->whereHas('delivery.reviewLink', fn ($query) => $query->whereNotNull('first_clicked_at'))
                    ->exists();
                if ($clicked) {
                    $dispatch->update(['decision' => 'cancelled', 'reason_code' => 'review_link_clicked']);

                    return;
                }
            }
            $manager->send($dispatch);
        }
    }
}
