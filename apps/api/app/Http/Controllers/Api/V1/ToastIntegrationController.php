<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Models\ToastConnectionRequest;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Integrations\Services\ToastConnector;
use App\Domain\Integrations\Services\ToastOrderSync;
use App\Domain\Tenancy\Models\Location;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ToastIntegrationController extends Controller
{
    public function index(Request $request, ToastConnector $toast): JsonResponse
    {
        $business = $request->attributes->get('business');
        $environment = config('services.toast.environment');
        $setting = $toast->setting($environment, false);

        return response()->json(['data' => [
            'ready' => $toast->configured($environment), 'environment' => $environment,
            'marketplace_url' => $setting?->marketplace_url,
            'requests' => ToastConnectionRequest::where('business_id', $business->id)->where('status', 'pending')->where('expires_at', '>', now())
                ->with('location:id,name')->latest()->get()->map(fn ($item) => [
                    'id' => $item->id, 'location' => $item->location, 'connection_code' => $item->connection_code_encrypted, 'expires_at' => $item->expires_at,
                ]),
            'connections' => ToastRestaurantConnection::where('business_id', $business->id)->with('location:id,name')->latest()->get(),
        ]]);
    }

    public function start(Request $request, ToastConnector $toast, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $environment = config('services.toast.environment');
        abort_unless($toast->configured($environment), 422, 'A platform administrator must finish the Toast partner setup first.');
        $locationId = $request->validate(['location_id' => ['required', 'string']])['location_id'];
        $location = Location::where('business_id', $business->id)->findOrFail($locationId);
        abort_if(ToastRestaurantConnection::where('business_id', $business->id)->where('location_id', $location->id)->where('environment', $environment)->where('status', 'connected')->exists(), 409, 'This location is already connected to Toast.');

        [$integration, $connectionRequest, $code] = DB::transaction(function () use ($business, $location, $request, $environment): array {
            $integration = PosIntegration::firstOrCreate(
                ['business_id' => $business->id, 'provider' => 'toast', 'name' => 'Toast POS'],
                ['created_by_user_id' => $request->user()->id, 'environment' => $environment, 'status' => 'setup_required'],
            );
            ToastConnectionRequest::where('business_id', $business->id)->where('location_id', $location->id)->where('status', 'pending')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            do {
                $code = 'RO-'.Str::upper(Str::random(20));
            } while (ToastConnectionRequest::where('connection_code_hash', hash('sha256', $code))->exists());
            $connectionRequest = ToastConnectionRequest::create([
                'business_id' => $business->id, 'integration_id' => $integration->id, 'location_id' => $location->id,
                'initiated_by_user_id' => $request->user()->id, 'connection_code_hash' => hash('sha256', $code),
                'connection_code_encrypted' => $code, 'status' => 'pending',
                'expires_at' => now()->addDays((int) config('services.toast.connection_code_days')),
            ]);

            return [$integration, $connectionRequest, $code];
        });
        $auditor->record($request, 'toast.connection_requested', $integration, ['location_id' => $location->id, 'environment' => $environment]);

        return response()->json(['data' => [
            'request_id' => $connectionRequest->id, 'location' => $location->only(['id', 'name']),
            'connection_code' => $code, 'expires_at' => $connectionRequest->expires_at,
            'marketplace_url' => $toast->setting($environment)->marketplace_url,
        ]], 201);
    }

    public function cancel(Request $request, string $connectionRequest, Auditor $auditor): JsonResponse
    {
        $model = ToastConnectionRequest::where('business_id', $request->attributes->get('business')->id)->where('status', 'pending')->findOrFail($connectionRequest);
        $model->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $auditor->record($request, 'toast.connection_request_cancelled', $model, ['location_id' => $model->location_id]);

        return response()->json(status: 204);
    }

    public function sync(Request $request, string $connection, ToastOrderSync $sync, Auditor $auditor): JsonResponse
    {
        $model = ToastRestaurantConnection::where('business_id', $request->attributes->get('business')->id)->findOrFail($connection);
        $summary = $sync->sync($model);
        $auditor->record($request, 'toast.orders_synced', $model, $summary);

        return response()->json(['data' => ['connection' => $model->fresh(), 'summary' => $summary]]);
    }
}
