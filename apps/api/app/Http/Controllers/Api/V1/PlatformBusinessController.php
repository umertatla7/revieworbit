<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Tenancy\Models\AdminSupportSession;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Services\BusinessProvisioner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformBusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Business::query()
            ->withCount(['locations', 'customers', 'posIntegrations'])
            ->with(['memberships' => fn ($query) => $query->where('role', 'owner')->where('status', 'active')->with('user:id,name,email')])
            ->latest();

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (Business $business): array => $this->payload($business))]);
    }

    public function show(string $business): JsonResponse
    {
        $model = Business::withCount(['locations', 'customers', 'posIntegrations'])
            ->with(['locations', 'posIntegrations', 'memberships.user:id,name,email'])
            ->findOrFail($business);

        return response()->json(['data' => $this->payload($model, true)]);
    }

    public function store(Request $request, Auditor $auditor, BusinessProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'industry' => ['required', Rule::in(['automotive', 'beauty_wellness', 'dental', 'healthcare', 'home_services', 'hospitality', 'professional_services', 'restaurant', 'retail', 'other'])],
            'business_email' => ['required', 'email', 'max:255'],
            'business_phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'location_name' => ['required', 'string', 'max:160'],
            'location_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'address_line1' => ['required', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'region' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:24'],
            'country' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'timezone'],
            'google_review_url' => ['nullable', 'url:http,https', 'max:2048'],
            'operation_mode' => ['required', Rule::in(['manual', 'generic', 'square'])],
            'preferred_channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'quiet_hours_start' => ['required', 'date_format:H:i'],
            'quiet_hours_end' => ['required', 'date_format:H:i'],
            'account_notes' => ['nullable', 'string', 'max:2000'],
            'send_owner_setup_email' => ['required', 'boolean'],
        ]);
        $result = $provisioner->provision($data);
        $setupEmailStatus = $data['send_owner_setup_email'] ? 'existing_owner' : 'not_requested';
        if ($data['send_owner_setup_email'] && $result['owner_created']) {
            try {
                $status = PasswordBroker::sendResetLink(['email' => $result['owner']->email]);
                $setupEmailStatus = $status === PasswordBroker::RESET_LINK_SENT ? 'sent' : 'failed';
            } catch (\Throwable) {
                $setupEmailStatus = 'failed';
            }
        }
        $business = $result['business']->load(['locations', 'memberships.user']);
        $auditor->record($request, 'platform.business.provisioned', $business, [
            'industry' => $business->industry,
            'operation_mode' => $business->operation_mode,
            'owner_created' => $result['owner_created'],
            'setup_email_status' => $setupEmailStatus,
        ]);

        return response()->json(['data' => $this->payload($business, true), 'meta' => ['owner_setup_email_status' => $setupEmailStatus]], 201);
    }

    public function update(Request $request, string $business, Auditor $auditor): JsonResponse
    {
        $model = Business::findOrFail($business);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'plan_code' => ['sometimes', Rule::in(['basic', 'growth', 'pro'])],
            'default_timezone' => ['sometimes', 'timezone'],
            'default_country' => ['sometimes', 'string', 'size:2'],
            'onboarding_status' => ['sometimes', Rule::in(['in_progress', 'completed'])],
            'onboarding_step' => ['sometimes', 'integer', 'between:1,10'],
            'operation_mode' => ['sometimes', Rule::in(['manual', 'generic', 'square'])],
        ]);
        if (isset($data['default_country'])) {
            $data['default_country'] = strtoupper($data['default_country']);
        }
        $model->update($data);
        $auditor->record($request, 'platform.business.updated', $model, array_keys($data));

        return response()->json(['data' => $this->payload($model->fresh())]);
    }

    public function startSupportSession(Request $request, string $business, Auditor $auditor): JsonResponse
    {
        $model = Business::findOrFail($business);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $plainToken = 'support_'.Str::random(64);
        $session = AdminSupportSession::create([
            'business_id' => $model->id,
            'admin_user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $plainToken),
            'reason' => $data['reason'],
            'expires_at' => now()->addHour(),
        ]);
        $auditor->record($request, 'platform.support_session.started', $session, ['reason' => $data['reason'], 'expires_at' => $session->expires_at->toIso8601String()]);

        return response()->json(['data' => [
            'id' => $session->id,
            'business_id' => $model->id,
            'business_name' => $model->name,
            'token' => $plainToken,
            'expires_at' => $session->expires_at,
        ]], 201);
    }

    private function payload(Business $business, bool $detailed = false): array
    {
        $data = [
            'id' => $business->id,
            'name' => $business->name,
            'legal_name' => $business->legal_name,
            'industry' => $business->industry,
            'slug' => $business->slug,
            'status' => $business->status instanceof \BackedEnum ? $business->status->value : $business->status,
            'plan_code' => $business->plan_code,
            'default_timezone' => $business->default_timezone,
            'default_country' => $business->default_country,
            'primary_email' => $business->primary_email,
            'phone' => $business->phone,
            'website_url' => $business->website_url,
            'onboarding_status' => $business->onboarding_status,
            'onboarding_step' => $business->onboarding_step,
            'operation_mode' => $business->operation_mode,
            'locations_count' => $business->locations_count ?? $business->locations()->count(),
            'customers_count' => $business->customers_count ?? $business->customers()->count(),
            'pos_integrations_count' => $business->pos_integrations_count ?? $business->posIntegrations()->count(),
            'owners' => $business->memberships->where('role.value', 'owner')->map(fn ($membership): array => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ])->values(),
            'created_at' => $business->created_at,
        ];

        if ($detailed) {
            $data['account_notes'] = $business->account_notes;
            $data['locations'] = $business->locations;
            $data['pos_integrations'] = $business->posIntegrations;
            $data['memberships'] = $business->memberships->map(fn ($membership): array => [
                'id' => $membership->id,
                'role' => $membership->role instanceof \BackedEnum ? $membership->role->value : $membership->role,
                'status' => $membership->status instanceof \BackedEnum ? $membership->status->value : $membership->status,
                'user' => $membership->user,
            ])->values();
        }

        return $data;
    }
}
