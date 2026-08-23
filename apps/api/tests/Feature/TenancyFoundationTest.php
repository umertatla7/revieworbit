<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\RecordStatus;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenancyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_memberships_and_locations_use_ulid_tenant_keys(): void
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'AL Barber Shop',
            'slug' => 'al-barber-shop',
            'default_timezone' => 'America/New_York',
            'default_country' => 'US',
        ]);

        $membership = BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessRole::Owner,
            'status' => RecordStatus::Active,
        ]);

        $location = Location::create([
            'business_id' => $business->id,
            'name' => 'Main Street Location',
            'timezone' => 'America/New_York',
            'google_review_url' => 'https://example.invalid/al-barber-shop/review',
        ]);

        $this->assertTrue(Str::isUlid($user->id));
        $this->assertTrue(Str::isUlid($business->id));
        $this->assertTrue($user->businesses->contains($business));
        $this->assertTrue($business->locations->contains($location));
        $this->assertSame(BusinessRole::Owner, $membership->role);
    }

    public function test_a_user_cannot_have_duplicate_memberships_for_one_business(): void
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'AL Barber Shop',
            'slug' => 'al-barber-shop',
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessRole::Owner,
        ]);

        $this->expectException(QueryException::class);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessRole::Viewer,
        ]);
    }
}
