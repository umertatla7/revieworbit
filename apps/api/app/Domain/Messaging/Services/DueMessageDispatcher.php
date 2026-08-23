<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Messaging\Jobs\SendAutomationDispatch;

class DueMessageDispatcher
{
    public function dispatch(): int
    {
        $count = 0;
        AutomationDispatch::query()
            ->where('decision', 'scheduled')
            ->where('scheduled_for', '<=', now())
            ->whereHas('visit.business.messagingConfiguration', fn ($query) => $query->where('status', 'active'))
            ->whereDoesntHave('delivery')
            ->orderBy('scheduled_for')
            ->limit(500)
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                SendAutomationDispatch::dispatch($id)->onQueue('messaging');
                $count++;
            });

        return $count;
    }
}
