<?php

namespace App\Domain\Integrations\Jobs;

use App\Domain\Integrations\Models\IntegrationWebhookEvent;
use App\Domain\Integrations\Models\ToastConnectionRequest;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Integrations\Services\ToastConnector;
use App\Domain\Integrations\Services\ToastOrderImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessToastWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public readonly string $eventId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 600, 1800, 3600];
    }

    public function handle(ToastConnector $toast, ToastOrderImporter $importer): void
    {
        $event = IntegrationWebhookEvent::find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }
        $event->update([
            'status' => 'processing',
            'attempt_number' => $event->attempt_number + ($event->status === 'received' ? 0 : 1),
            'failure_message' => null,
        ]);
        try {
            $event->category === 'partners' ? $this->processPartner($event) : $this->processOrder($event, $toast, $importer);
            $event->update(['status' => 'processed', 'processed_at' => now(), 'failure_message' => null]);
        } catch (\Throwable $exception) {
            $event->update(['status' => 'failed', 'failure_message' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    private function processPartner(IntegrationWebhookEvent $event): void
    {
        $details = is_array($event->payload['details'] ?? null) ? $event->payload['details'] : [];
        $restaurantGuid = $details['restaurantGuid'] ?? $details['restaurantGUID'] ?? $details['guid'] ?? null;
        $environment = $event->payload['_meta']['environment'] ?? 'sandbox';
        if (! is_string($restaurantGuid)) {
            throw new RuntimeException('Toast partner event has no restaurant GUID.');
        }

        if (str_contains($event->event_type, 'removed')) {
            $connection = ToastRestaurantConnection::where('environment', $environment)->where('restaurant_guid', $restaurantGuid)->first();
            if (! $connection) {
                return;
            }
            DB::transaction(function () use ($connection, $event): void {
                $connection->update(['status' => 'disconnected', 'disconnected_at' => now()]);
                $event->update(['business_id' => $connection->business_id]);
                if (! $connection->integration->toastRestaurants()->where('status', 'connected')->exists()) {
                    $connection->integration->update(['status' => 'disconnected', 'disconnected_at' => now()]);
                }
            });

            return;
        }

        $externalRef = $details['externalRestaurantRef'] ?? null;
        $existing = ToastRestaurantConnection::where('environment', $environment)->where('restaurant_guid', $restaurantGuid)->first();
        $request = is_string($externalRef)
            ? ToastConnectionRequest::where('connection_code_hash', hash('sha256', $externalRef))->where('status', 'pending')->where('expires_at', '>', now())->first()
            : null;
        if (! $existing && $request) {
            $existing = ToastRestaurantConnection::where('business_id', $request->business_id)
                ->where('location_id', $request->location_id)
                ->where('environment', $environment)
                ->where('status', '!=', 'connected')
                ->first();
        }
        if (! $existing && ! $request) {
            throw new RuntimeException('Toast location code was not found or has expired.');
        }

        DB::transaction(function () use ($existing, $request, $details, $restaurantGuid, $environment, $externalRef, $event): void {
            $connection = $existing ?? ToastRestaurantConnection::create([
                'business_id' => $request->business_id,
                'integration_id' => $request->integration_id,
                'location_id' => $request->location_id,
                'environment' => $environment,
                'restaurant_guid' => $restaurantGuid,
            ]);
            if ($request && $connection->business_id !== $request->business_id) {
                throw new RuntimeException('Toast restaurant is already assigned to another workspace.');
            }
            $connection->update([
                'management_group_guid' => $details['managementGroupGuid'] ?? $connection->management_group_guid,
                'restaurant_name' => $details['restaurantName'] ?? $details['name'] ?? $connection->restaurant_name,
                'location_name' => $details['locationName'] ?? $connection->location_name,
                'external_group_ref' => $details['externalGroupRef'] ?? $connection->external_group_ref,
                'external_restaurant_ref' => $externalRef ?? $connection->external_restaurant_ref,
                'restaurant_guid' => $restaurantGuid,
                'status' => 'connected',
                'connected_at' => $connection->connected_at ?? now(),
                'disconnected_at' => null,
            ]);
            $connection->integration->update(['status' => 'connected', 'connected_at' => $connection->integration->connected_at ?? now(), 'disconnected_at' => null, 'sync_error' => null]);
            $connection->business()->update(['operation_mode' => 'toast']);
            $request?->update(['status' => 'connected', 'connected_at' => now()]);
            $event->update(['business_id' => $connection->business_id]);
            SyncToastOrders::dispatch($connection->id)->afterCommit();
        });
    }

    private function processOrder(IntegrationWebhookEvent $event, ToastConnector $toast, ToastOrderImporter $importer): void
    {
        $details = is_array($event->payload['details'] ?? null) ? $event->payload['details'] : [];
        $restaurantGuid = $details['restaurantGuid'] ?? $event->payload['_meta']['restaurant_guid'] ?? null;
        $order = is_array($details['order'] ?? null) ? $details['order'] : $details;
        $orderGuid = $order['guid'] ?? $details['orderGuid'] ?? null;
        $environment = $event->payload['_meta']['environment'] ?? 'sandbox';
        $connection = ToastRestaurantConnection::where('environment', $environment)
            ->where('restaurant_guid', $restaurantGuid)->where('status', 'connected')->first();
        if (! $connection) {
            throw new RuntimeException('Toast order belongs to an unconnected restaurant.');
        }
        $event->update(['business_id' => $connection->business_id]);
        if (($order['voided'] ?? false) || ($order['deleted'] ?? false)) {
            $importer->import($connection, $order);

            return;
        }
        $containsOrder = is_array($details['order'] ?? null);
        $hasClosedCheck = collect($order['checks'] ?? [])->contains(fn ($check): bool => is_array($check) && ! empty($check['closedDate']));
        if ($containsOrder && empty($order['closedDate']) && ! $hasClosedCheck) {
            return;
        }
        if (! is_string($orderGuid)) {
            throw new RuntimeException('Toast order event has no order GUID.');
        }
        $importer->import($connection, $toast->get($connection, '/orders/v2/orders/'.$orderGuid));
    }
}
