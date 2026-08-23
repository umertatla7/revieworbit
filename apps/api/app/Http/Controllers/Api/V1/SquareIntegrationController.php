<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Integrations\Jobs\SyncSquareAppointments;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Models\SquareAppointment;
use App\Domain\Integrations\Models\SquareOAuthState;
use App\Domain\Integrations\Services\SquareAppointmentSync;
use App\Domain\Integrations\Services\SquareConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SquareIntegrationController extends Controller
{
    public function authorize(Request $request, SquareConnector $square, Auditor $auditor): JsonResponse
    {
        abort_unless($square->configured(), 422, 'Square application credentials must be configured by a platform administrator before connecting an account.');
        $business = $request->attributes->get('business');
        $data = $request->validate(['environment' => ['nullable', Rule::in(['sandbox', 'production'])]]);
        $environment = $data['environment'] ?? config('services.square.environment');
        $integration = PosIntegration::firstOrCreate(
            ['business_id' => $business->id, 'provider' => 'square', 'name' => 'Square Appointments'],
            ['created_by_user_id' => $request->user()->id, 'environment' => $environment, 'status' => 'setup_required'],
        );

        abort_if($integration->status === 'connected', 409, 'Square is already connected. Disconnect it before authorizing a different account.');
        $integration->update(['environment' => $environment, 'sync_error' => null]);
        SquareOAuthState::where('integration_id', $integration->id)->whereNull('consumed_at')->delete();
        $plainState = Str::random(80);
        SquareOAuthState::create([
            'business_id' => $business->id,
            'integration_id' => $integration->id,
            'initiated_by_user_id' => $request->user()->id,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addMinutes(10),
        ]);
        $auditor->record($request, 'square.authorization.started', $integration, ['environment' => $environment]);

        return response()->json(['data' => [
            'authorization_url' => $square->authorizationUrl($environment, $plainState),
            'integration_id' => $integration->id,
            'expires_at' => now()->addMinutes(10),
        ]]);
    }

    public function callback(Request $request, SquareConnector $square, Auditor $auditor): RedirectResponse
    {
        $stateValue = (string) $request->query('state');
        $state = SquareOAuthState::with('integration')
            ->where('state_hash', hash('sha256', $stateValue))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $state || ! $request->filled('code') || $request->filled('error')) {
            return $this->webRedirect('error', $request->query('error_description', 'Square authorization was cancelled or expired.'));
        }

        try {
            $token = $square->exchangeAuthorizationCode($state->integration->environment, (string) $request->query('code'));
            DB::transaction(function () use ($state, $token): void {
                $state->update(['consumed_at' => now()]);
                $state->integration->update([
                    'status' => 'connected',
                    'external_merchant_id' => $token['merchant_id'],
                    'access_token_encrypted' => $token['access_token'],
                    'refresh_token_encrypted' => $token['refresh_token'] ?? null,
                    'token_expires_at' => $token['expires_at'] ?? null,
                    'connected_at' => now(),
                    'disconnected_at' => null,
                    'sync_error' => null,
                ]);
                $state->integration->business()->update(['operation_mode' => 'square']);
            });
            $auditor->record($request, 'square.connected', $state->integration, ['environment' => $state->integration->environment]);
            SyncSquareAppointments::dispatch($state->integration->id);

            return $this->webRedirect('connected', 'Square is connected. The initial appointment import has started.');
        } catch (Throwable $exception) {
            report($exception);
            $state->update(['consumed_at' => now()]);

            return $this->webRedirect('error', 'Square could not be connected. Please try again.');
        }
    }

    public function sync(Request $request, string $integration, SquareAppointmentSync $sync, Auditor $auditor): JsonResponse
    {
        $model = $this->tenantIntegration($request, $integration);
        $summary = $sync->sync($model);
        $auditor->record($request, 'square.appointments.synced', $model, $summary);

        return response()->json(['data' => ['integration' => $model->fresh(), 'summary' => $summary]]);
    }

    public function appointments(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $data = $request->validate([
            'timing' => ['nullable', Rule::in(['past', 'upcoming'])],
            'status' => ['nullable', 'string', 'max:50'],
        ]);
        $query = SquareAppointment::where('business_id', $businessId)->with(['customer:id,first_name,last_name,email,phone_e164', 'location:id,name,timezone']);
        if (($data['timing'] ?? null) === 'past') {
            $query->where('starts_at', '<', now());
        } elseif (($data['timing'] ?? null) === 'upcoming') {
            $query->where('starts_at', '>=', now());
        }
        if ($data['status'] ?? null) {
            $query->where('status', $data['status']);
        }
        $appointments = $query->orderBy('starts_at')->paginate(50);

        return response()->json(['data' => $appointments->items(), 'meta' => ['total' => $appointments->total()]]);
    }

    public function disconnect(Request $request, string $integration, SquareConnector $square, Auditor $auditor): JsonResponse
    {
        $model = $this->tenantIntegration($request, $integration);
        $square->disconnect($model);
        $model->update([
            'status' => 'disconnected',
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
            'token_expires_at' => null,
            'disconnected_at' => now(),
        ]);
        $auditor->record($request, 'square.disconnected', $model);

        return response()->json(['data' => $model->fresh()]);
    }

    private function tenantIntegration(Request $request, string $integration): PosIntegration
    {
        return PosIntegration::where('business_id', $request->attributes->get('business')->id)
            ->where('provider', 'square')
            ->findOrFail($integration);
    }

    private function webRedirect(string $status, string $message): RedirectResponse
    {
        return redirect()->away(rtrim(config('services.square.web_url'), '/').'/dashboard/integrations?'.http_build_query([
            'square' => $status,
            'message' => $message,
        ]));
    }
}
