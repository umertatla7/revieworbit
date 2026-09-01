<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Tenancy\Models\BusinessInvitation;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Services\PlanEntitlements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function show(Request $request, PlanEntitlements $entitlements): JsonResponse
    {
        $business = $request->attributes->get('business');

        return response()->json(['data' => [
            ...$business->toArray(),
            'locations' => $business->locations()->with('reviewDestinations')->orderBy('name')->get(),
            'entitlements' => $entitlements->for($business),
        ]]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'default_timezone' => ['sometimes', 'timezone'],
            'default_country' => ['sometimes', 'string', 'size:2'],
            'brand_settings' => ['sometimes', 'array'],
        ]);
        $business->update($data);
        $auditor->record($request, 'business.updated', $business, array_keys($data));

        return response()->json(['data' => $business->fresh()]);
    }

    public function storeLocation(Request $request, Auditor $auditor, PlanEntitlements $entitlements): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($entitlements->for($business)['can_add_location'], 422, 'Your current plan has reached its location limit. Upgrade your plan to add another location.');
        $data = $this->validatedLocation($request, $business, false, $entitlements);
        $destinations = $data['review_destinations'] ?? [];
        unset($data['review_destinations']);
        $location = DB::transaction(function () use ($business, $data, $destinations): Location {
            $location = $business->locations()->create([...$data, 'google_review_url' => collect($destinations)->firstWhere('provider', 'google')['url'] ?? null]);
            $this->syncDestinations($location, $destinations);

            return $location;
        });
        $auditor->record($request, 'location.created', $location, ['name' => $location->name]);

        return response()->json(['data' => $location->load('reviewDestinations')], 201);
    }

    public function updateLocation(Request $request, string $location, Auditor $auditor, PlanEntitlements $entitlements): JsonResponse
    {
        $business = $request->attributes->get('business');
        $model = Location::where('business_id', $business->id)->findOrFail($location);
        $data = $this->validatedLocation($request, $business, true, $entitlements);
        $destinations = $data['review_destinations'] ?? null;
        unset($data['review_destinations']);
        DB::transaction(function () use ($model, $data, $destinations): void {
            if (is_array($destinations)) {
                $data['google_review_url'] = collect($destinations)->firstWhere('provider', 'google')['url'] ?? null;
            }
            $model->update($data);
            if (is_array($destinations)) {
                $this->syncDestinations($model, $destinations);
            }
        });
        $auditor->record($request, 'location.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh()->load('reviewDestinations')]);
    }

    public function invite(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'viewer'])],
        ]);
        $plainToken = Str::random(64);
        $invitation = BusinessInvitation::create([
            'business_id' => $business->id,
            'invited_by_user_id' => $request->user()->id,
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(7),
        ]);
        $auditor->record($request, 'invitation.created', $invitation, ['role' => $data['role']]);

        return response()->json(['data' => ['id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role->value, 'expires_at' => $invitation->expires_at, 'token' => $plainToken]], 201);
    }

    public function acceptInvitation(Request $request, string $token, Auditor $auditor): JsonResponse
    {
        $invitation = BusinessInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();
        abort_unless(hash_equals(Str::lower($invitation->email), Str::lower($request->user()->email)), 403);

        DB::transaction(function () use ($invitation, $request): void {
            BusinessUser::updateOrCreate(
                ['business_id' => $invitation->business_id, 'user_id' => $request->user()->id],
                ['role' => $invitation->role, 'status' => 'active']
            );
            $invitation->update(['status' => 'accepted', 'accepted_by_user_id' => $request->user()->id, 'accepted_at' => now()]);
        });
        $auditor->record($request, 'invitation.accepted', $invitation, ['role' => $invitation->role->value]);

        return response()->json(['data' => ['business_id' => $invitation->business_id, 'role' => $invitation->role->value]]);
    }

    private function validatedLocation(Request $request, $business, bool $partial, PlanEntitlements $entitlements): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'timezone' => [$required, 'timezone'],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:180'],
            'address.line2' => ['nullable', 'string', 'max:180'],
            'address.city' => ['nullable', 'string', 'max:120'],
            'address.region' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:24'],
            'address.country' => ['nullable', 'string', 'size:2'],
            'google_review_url' => ['nullable', 'url:https', 'max:2000'],
            'review_destinations' => ['sometimes', 'array', 'min:1'],
            'review_destinations.*.provider' => ['required', Rule::in(['google', 'trustpilot', 'facebook', 'yelp', 'other']), 'distinct'],
            'review_destinations.*.url' => ['required', 'url:https', 'max:2000'],
            'review_destinations.*.is_primary' => ['sometimes', 'boolean'],
        ]);
        if (! isset($data['review_destinations']) && filled($data['google_review_url'] ?? null)) {
            $data['review_destinations'] = [['provider' => 'google', 'url' => $data['google_review_url'], 'is_primary' => true]];
        }
        unset($data['google_review_url']);
        if (! $partial && empty($data['review_destinations'])) {
            throw ValidationException::withMessages(['review_destinations' => ['Add at least one review destination.']]);
        }
        $allowed = $entitlements->for($business)['review_providers'];
        foreach ($data['review_destinations'] ?? [] as $destination) {
            if (! in_array($destination['provider'], $allowed, true)) {
                throw ValidationException::withMessages(['review_destinations' => [ucfirst($destination['provider']).' review links require a plan upgrade.']]);
            }
        }
        if (isset($data['review_destinations'])) {
            $entitlementData = $entitlements->for($business);
            $existingOutsideLocation = $business->locations()
                ->when($partial, fn ($query) => $query->where('id', '!=', $request->route('location')))
                ->withCount(['reviewDestinations' => fn ($query) => $query->where('status', 'active')])
                ->get()
                ->sum('review_destinations_count');
            if ($existingOutsideLocation + count($data['review_destinations']) > $entitlementData['review_destination_limit']) {
                throw ValidationException::withMessages(['review_destinations' => ['Your current plan has reached its review-link limit. Upgrade your plan or remove another review destination.']]);
            }
        }

        return $data;
    }

    private function syncDestinations(Location $location, array $destinations): void
    {
        $providers = collect($destinations)->pluck('provider');
        $location->reviewDestinations()->whereNotIn('provider', $providers)->delete();
        foreach ($destinations as $index => $destination) {
            $location->reviewDestinations()->updateOrCreate(
                ['provider' => $destination['provider']],
                ['business_id' => $location->business_id, 'url' => $destination['url'], 'status' => 'active', 'is_primary' => $destination['is_primary'] ?? $index === 0],
            );
        }
    }
}
