<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Services\SquareConnector;
use App\Domain\Integrations\Services\ToastConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosIntegrationController extends Controller
{
    public function index(Request $request, SquareConnector $square, ToastConnector $toast): JsonResponse
    {
        $business = $request->attributes->get('business');

        return response()->json(['data' => [
            'connections' => PosIntegration::where('business_id', $business->id)->with('toastRestaurants.location:id,name')->latest()->get(),
            'providers' => [
                ['id' => 'generic', 'name' => 'Generic POS / API', 'availability' => 'available', 'description' => 'Connect any POS that can send completed-visit events using an API key or signed webhook.'],
                ['id' => 'square', 'name' => 'Square Appointments', 'availability' => $square->configured() ? 'available' : 'configuration required', 'configured' => $square->configured(), 'description' => 'Connect securely with Square OAuth and import locations, customers, previous appointments, and upcoming appointments.'],
                ['id' => 'toast', 'name' => 'Toast POS', 'availability' => $toast->configured() ? 'available' : 'partner setup required', 'configured' => $toast->configured(), 'description' => 'Connect each Toast restaurant location using its ReviewOrbit location code. Completed checks are imported as visits; contact data never implies messaging consent.'],
                ['id' => 'manual', 'name' => 'Manual mode', 'availability' => 'available', 'description' => 'Record completed visits from the dashboard without connecting a POS.'],
            ],
        ]]);
    }

    public function store(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'provider' => ['required', Rule::in(['generic', 'manual'])],
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['nullable', Rule::in(['sandbox', 'production'])],
            'settings' => ['nullable', 'array'],
        ]);
        $provider = $data['provider'];
        $integration = PosIntegration::create([
            'business_id' => $business->id,
            'created_by_user_id' => $request->user()->id,
            'provider' => $provider,
            'name' => $data['name'],
            'environment' => $data['environment'] ?? 'sandbox',
            'settings' => $data['settings'] ?? null,
            'status' => $provider === 'manual' ? 'connected' : 'awaiting_credentials',
            'connected_at' => $provider === 'manual' ? now() : null,
        ]);
        $business->update(['operation_mode' => $provider]);
        $auditor->record($request, 'pos_integration.created', $integration, ['provider' => $provider, 'environment' => $integration->environment]);

        return response()->json(['data' => $integration], 201);
    }

    public function update(Request $request, string $integration, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $model = PosIntegration::where('business_id', $business->id)->findOrFail($integration);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['setup_required', 'awaiting_credentials', 'connected', 'error', 'disconnected'])],
            'settings' => ['sometimes', 'array'],
        ]);
        if (($data['status'] ?? null) === 'connected') {
            $data['connected_at'] = now();
            $data['disconnected_at'] = null;
        }
        if (($data['status'] ?? null) === 'disconnected') {
            $data['disconnected_at'] = now();
        }
        $model->update($data);
        $auditor->record($request, 'pos_integration.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh()]);
    }
}
