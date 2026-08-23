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
            $manager->send($dispatch);
        }
    }
}
