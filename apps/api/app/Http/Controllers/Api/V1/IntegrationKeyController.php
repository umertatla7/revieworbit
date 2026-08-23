<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Integrations\Models\IntegrationApiKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = IntegrationApiKey::where('business_id', $request->attributes->get('business')->id)
            ->get(['id', 'name', 'key_prefix', 'abilities', 'last_used_at', 'expires_at', 'revoked_at', 'created_at']);

        return response()->json(['data' => $keys]);
    }

    public function store(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $prefix = Str::lower(Str::random(12));
        $plainToken = 'ro_test_'.$prefix.'.'.Str::random(48);
        $hmacSecret = Str::random(64);
        $key = IntegrationApiKey::create([
            'business_id' => $request->attributes->get('business')->id,
            'name' => $data['name'],
            'key_prefix' => $prefix,
            'token_hash' => hash('sha256', $plainToken),
            'hmac_secret_encrypted' => $hmacSecret,
            'abilities' => ['visits:write'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $auditor->record($request, 'integration_key.created', $key, ['name' => $key->name]);

        return response()->json(['data' => ['id' => $key->id, 'name' => $key->name, 'key_prefix' => $prefix, 'api_key' => $plainToken, 'hmac_secret' => $hmacSecret]], 201);
    }

    public function revoke(Request $request, string $key, Auditor $auditor): JsonResponse
    {
        $model = IntegrationApiKey::where('business_id', $request->attributes->get('business')->id)->findOrFail($key);
        $model->update(['revoked_at' => now()]);
        $auditor->record($request, 'integration_key.revoked', $model);

        return response()->json(null, 204);
    }
}
