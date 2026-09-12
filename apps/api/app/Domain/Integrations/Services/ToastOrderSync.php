<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Models\ToastRestaurantConnection;
use Carbon\CarbonImmutable;
use Throwable;

class ToastOrderSync
{
    public function __construct(private readonly ToastConnector $toast, private readonly ToastOrderImporter $importer) {}

    public function sync(ToastRestaurantConnection $connection): array
    {
        abort_unless($connection->status === 'connected', 422, 'This Toast location is not connected.');
        $from = CarbonImmutable::now('UTC')->subDays(min(31, max(1, (int) config('services.toast.history_days'))));
        $until = CarbonImmutable::now('UTC');
        $summary = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'missing_contact' => 0];

        try {
            $page = 1;
            do {
                $orders = $this->toast->get($connection, '/orders/v2/ordersBulk', [
                    'startDate' => $from->toIso8601String(),
                    'endDate' => $until->toIso8601String(),
                    'page' => $page,
                    'pageSize' => 100,
                ]);
                $items = array_is_list($orders) ? $orders : ($orders['orders'] ?? []);
                foreach ($items as $order) {
                    if (! is_array($order)) {
                        continue;
                    }
                    $result = $this->importer->import($connection, $order);
                    $summary['total']++;
                    foreach (['created', 'updated', 'skipped', 'missing_contact'] as $key) {
                        $summary[$key] += $result[$key];
                    }
                }
                $page++;
            } while (count($items) === 100 && $page <= 100);

            $summary['range_start'] = $from->toIso8601String();
            $summary['range_end'] = $until->toIso8601String();
            $connection->update(['last_synced_at' => now(), 'sync_error' => null, 'sync_summary' => $summary]);
            $connection->integration->update(['last_synced_at' => now(), 'last_health_check_at' => now(), 'sync_error' => null, 'sync_summary' => $summary]);

            return $summary;
        } catch (Throwable $exception) {
            $connection->update(['sync_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $connection->integration->update(['last_health_check_at' => now(), 'sync_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }
}
