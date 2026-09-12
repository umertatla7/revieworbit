<?php

namespace App\Domain\Integrations\Jobs;

use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Integrations\Services\ToastOrderSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncToastOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $connectionId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ToastOrderSync $sync): void
    {
        $connection = ToastRestaurantConnection::find($this->connectionId);
        if ($connection?->status === 'connected') {
            $sync->sync($connection);
        }
    }
}
