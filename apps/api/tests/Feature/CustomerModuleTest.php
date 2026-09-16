<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_plan_allows_one_location_and_google_only(): void
    {
        [$owner, $business] = $this->workspace('Basic Shop');
        $headers = ['X-Business-ID' => $business->id];

        $location = $this->actingAs($owner)->postJson('/api/v1/locations', $this->locationPayload(), $headers)
            ->assertCreated()
            ->assertJsonPath('data.review_destinations.0.provider', 'google');
        $this->assertDatabaseHas('location_review_destinations', ['business_id' => $business->id, 'location_id' => $location->json('data.id'), 'provider' => 'google']);

        $this->postJson('/api/v1/locations', [...$this->locationPayload(), 'name' => 'Second'], $headers)
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Your current plan has reached its location limit. Upgrade your plan to add another location.']);
    }

    public function test_plan_review_provider_entitlements_are_enforced_server_side(): void
    {
        [$owner, $business] = $this->workspace('Review Shop');
        $headers = ['X-Business-ID' => $business->id];
        $payload = $this->locationPayload();
        $payload['review_destinations'][] = ['provider' => 'trustpilot', 'url' => 'https://www.trustpilot.com/review/example.test'];

        $this->actingAs($owner)->postJson('/api/v1/locations', $payload, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('review_destinations');

        $business->update(['plan_code' => 'expansion']);
        $this->postJson('/api/v1/locations', $payload, $headers)->assertCreated();
        $this->getJson('/api/v1/business', $headers)
            ->assertOk()->assertJsonPath('data.entitlements.location_limit', 2)
            ->assertJsonPath('data.entitlements.review_providers.1', 'trustpilot');
    }

    public function test_location_edit_is_tenant_scoped_and_syncs_review_destinations(): void
    {
        [$ownerA, $businessA] = $this->workspace('Business A', 'expansion');
        [, $businessB] = $this->workspace('Business B', 'expansion');
        $locationB = Location::create(['business_id' => $businessB->id, 'name' => 'Private', 'timezone' => 'UTC']);
        $headers = ['X-Business-ID' => $businessA->id];

        $this->actingAs($ownerA)->patchJson('/api/v1/locations/'.$locationB->id, ['name' => 'Stolen'], $headers)->assertNotFound();
        $locationA = $this->postJson('/api/v1/locations', $this->locationPayload(), $headers)->assertCreated()->json('data');
        $this->patchJson('/api/v1/locations/'.$locationA['id'], [
            'name' => 'Downtown Updated',
            'review_destinations' => [
                ['provider' => 'google', 'url' => 'https://g.page/r/new/review'],
                ['provider' => 'trustpilot', 'url' => 'https://www.trustpilot.com/review/example.test'],
            ],
        ], $headers)->assertOk()->assertJsonCount(2, 'data.review_destinations');
        $this->assertDatabaseHas('locations', ['id' => $locationA['id'], 'name' => 'Downtown Updated', 'google_review_url' => 'https://g.page/r/new/review']);
    }

    private function workspace(string $name, string $plan = 'launch'): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'plan_code' => $plan]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);

        return [$owner, $business];
    }

    private function locationPayload(): array
    {
        return [
            'name' => 'Downtown',
            'timezone' => 'America/New_York',
            'phone' => '+12025550123',
            'address' => ['line1' => '100 Main St', 'city' => 'New York', 'region' => 'NY', 'postal_code' => '10001', 'country' => 'US'],
            'review_destinations' => [['provider' => 'google', 'url' => 'https://g.page/r/example/review', 'is_primary' => true]],
        ];
    }
}
