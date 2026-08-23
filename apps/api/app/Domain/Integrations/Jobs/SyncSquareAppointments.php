<?php

namespace App\Domain\Integrations\Jobs;

use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Services\SquareAppointmentSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSquareAppointments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $integrationId) {}

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function handle(SquareAppointmentSync $sync): void
    {
        $integration = PosIntegration::where('provider', 'square')->find($this->integrationId);

        if ($integration?->status === 'connected') {
            $sync->sync($integration);
        }
    }
}
