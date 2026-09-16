<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessProvisioner
{
    /** @return array{business: Business, owner: User, owner_created: bool} */
    public function provision(array $data, ?User $authenticatedOwner = null): array
    {
        return DB::transaction(function () use ($data, $authenticatedOwner): array {
            $owner = $authenticatedOwner ?? User::where('email', Str::lower($data['owner_email']))->first();
            $ownerCreated = false;
            if (! $owner) {
                $owner = User::create([
                    'name' => $data['owner_name'],
                    'email' => Str::lower($data['owner_email']),
                    'phone' => $data['owner_phone'] ?? null,
                    'password' => $data['owner_password'] ?? Str::random(48),
                    'email_verified_at' => ($data['owner_email_verified'] ?? false) ? now() : null,
                ]);
                $ownerCreated = true;
            } else {
                if (! $owner->phone && ! empty($data['owner_phone'])) {
                    $owner->update(['phone' => $data['owner_phone']]);
                }
            }

            $business = Business::create([
                'name' => $data['business_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'industry' => $data['industry'],
                'slug' => $this->uniqueSlug($data['business_name']),
                'default_timezone' => $data['timezone'],
                'default_country' => strtoupper($data['country']),
                'primary_email' => Str::lower($data['business_email']),
                'phone' => $data['business_phone'],
                'website_url' => $data['website_url'] ?? null,
                'account_notes' => $data['account_notes'] ?? null,
                'operation_mode' => $data['operation_mode'],
                'plan_code' => $data['plan_code'] ?? 'launch',
                'messaging_preferences' => [
                    'channel' => $data['preferred_channel'],
                    'quiet_hours_start' => $data['quiet_hours_start'],
                    'quiet_hours_end' => $data['quiet_hours_end'],
                ],
                'onboarding_status' => 'in_progress',
                'onboarding_step' => 3,
            ]);
            BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);
            BusinessSubscription::create([
                'business_id' => $business->id,
                'subscription_plan_id' => SubscriptionPlan::where('code', $data['plan_code'] ?? $business->plan_code)->value('id'),
                'status' => 'none',
            ]);
            $location = $business->locations()->create([
                'name' => $data['location_name'],
                'timezone' => $data['timezone'],
                'phone' => $data['location_phone'] ?? $data['business_phone'],
                'google_review_url' => $data['google_review_url'] ?? null,
                'address' => [
                    'line1' => $data['address_line1'],
                    'line2' => $data['address_line2'] ?? null,
                    'city' => $data['city'],
                    'region' => $data['region'],
                    'postal_code' => $data['postal_code'],
                    'country' => strtoupper($data['country']),
                ],
            ]);
            if (! empty($data['google_review_url'])) {
                $location->reviewDestinations()->create([
                    'business_id' => $business->id,
                    'provider' => 'google',
                    'url' => $data['google_review_url'],
                    'status' => 'active',
                    'is_primary' => true,
                ]);
            }

            return ['business' => $business, 'owner' => $owner, 'owner_created' => $ownerCreated];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        for ($suffix = 2; Business::where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
