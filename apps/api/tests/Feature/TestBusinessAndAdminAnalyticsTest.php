<?php

namespace Tests\Feature;

use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Models\User;
use Database\Seeders\TestBusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestBusinessAndAdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_business_seeder_is_deterministic_and_never_sends_provider_messages(): void
    {
        config()->set('revieworbit.seed.test_business_password', 'FixturePassword123!');

        $this->seed(TestBusinessSeeder::class);
        $this->seed(TestBusinessSeeder::class);

        $businesses = Business::where('slug', 'like', 'test-%')->withCount(['customers', 'locations'])->get();
        $this->assertCount(10, $businesses);
        $this->assertSame(4, $businesses->where('plan_code', 'basic')->count());
        $this->assertSame(3, $businesses->where('plan_code', 'growth')->count());
        $this->assertSame(3, $businesses->where('plan_code', 'pro')->count());
        foreach ($businesses as $business) {
            $this->assertSame(4, $business->customers_count);
            $this->assertSame(8, $business->customers()->withCount('visits')->get()->sum('visits_count'));
            $this->assertSame(8, $business->hasMany(MessageDelivery::class)->where('provider', 'fixture')->count());
        }
        $this->assertDatabaseHas('subscription_plans', ['code' => 'basic', 'location_limit' => 1, 'template_limit' => 1, 'media_template_limit' => 1, 'review_destination_limit' => 1]);
        $this->assertDatabaseHas('subscription_plans', ['code' => 'growth', 'location_limit' => 5, 'template_limit' => 5, 'media_template_limit' => 5, 'review_destination_limit' => 5]);
        $this->assertDatabaseHas('subscription_plans', ['code' => 'pro', 'location_limit' => 15, 'template_limit' => 15, 'media_template_limit' => 15, 'review_destination_limit' => 15]);
        $this->assertDatabaseMissing('message_deliveries', ['provider' => 'twilio']);
    }

    public function test_platform_admin_can_view_customer_analytics_without_starting_support_mode(): void
    {
        config()->set('revieworbit.seed.test_business_password', 'FixturePassword123!');
        $this->seed(TestBusinessSeeder::class);
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        $business = Business::where('slug', 'test-northstar-dental')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/v1/admin/businesses/'.$business->id)
            ->assertOk()
            ->assertJsonPath('data.analytics.summary.messages_all_time', 8)
            ->assertJsonPath('data.analytics.summary.visits_all_time', 8)
            ->assertJsonCount(5, 'data.entitlements.available_review_providers')
            ->assertJsonPath('data.entitlements.review_destination_limit', 1)
            ->assertJsonCount(8, 'data.analytics.recent_deliveries')
            ->assertJsonCount(8, 'data.analytics.recent_visits');
    }

    public function test_business_owner_cannot_view_platform_customer_analytics(): void
    {
        config()->set('revieworbit.seed.test_business_password', 'FixturePassword123!');
        $this->seed(TestBusinessSeeder::class);
        $business = Business::where('slug', 'test-northstar-dental')->firstOrFail();
        $owner = User::where('email', 'owner.basic01@revieworbit.test')->firstOrFail();

        $this->actingAs($owner)->getJson('/api/v1/admin/businesses/'.$business->id)->assertForbidden();
    }
}
