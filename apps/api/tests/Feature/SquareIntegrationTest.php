<?php

namespace Tests\Feature;

use App\Domain\Integrations\Jobs\SyncSquareAppointments;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Models\SquareOAuthState;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SquareIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.square', [
            'environment' => 'sandbox',
            'api_version' => '2026-07-15',
            'application_id' => 'sandbox-square-app-id',
            'application_secret' => 'sandbox-square-app-secret',
            'redirect_uri' => 'https://api.revieworbit.test/api/v1/integrations/square/callback',
            'webhook_signature_key' => 'signature-key',
            'web_url' => 'https://revieworbit.test',
            'history_days' => 365,
            'upcoming_days' => 365,
        ]);
    }

    public function test_owner_can_start_least_privilege_square_oauth_without_receiving_credentials(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('Square Salon');

        $response = $this->actingAs($owner)->postJson('/api/v1/pos-integrations/square/authorize', [
            'environment' => 'sandbox',
        ], ['X-Business-ID' => $business->id]);

        $response->assertOk()
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');
        $url = $response->json('data.authorization_url');
        $this->assertStringStartsWith('https://connect.squareupsandbox.com/oauth2/authorize?', $url);
        $this->assertStringContainsString('APPOINTMENTS_READ', urldecode($url));
        $this->assertStringContainsString('APPOINTMENTS_ALL_READ', urldecode($url));
        $this->assertStringNotContainsString('APPOINTMENTS_WRITE', urldecode($url));
        $this->assertDatabaseCount('square_oauth_states', 1);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'square.authorization.started']);
    }

    public function test_authorization_fails_cleanly_when_platform_square_credentials_are_missing(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('Unconfigured Square Salon');
        config()->set('services.square.application_secret', null);

        $this->actingAs($owner)->postJson('/api/v1/pos-integrations/square/authorize', [], [
            'X-Business-ID' => $business->id,
        ])->assertUnprocessable()->assertJsonPath('message', 'Square application credentials must be configured by a platform administrator before connecting an account.');

        $this->assertDatabaseCount('pos_integrations', 0);
        $this->assertDatabaseCount('square_oauth_states', 0);
    }

    public function test_callback_exchanges_code_stores_encrypted_tokens_and_queues_initial_sync(): void
    {
        Queue::fake();
        [$owner, $business] = $this->ownerAndBusiness('Square Spa');
        $integration = PosIntegration::create([
            'business_id' => $business->id,
            'created_by_user_id' => $owner->id,
            'provider' => 'square',
            'name' => 'Square Appointments',
            'environment' => 'sandbox',
            'status' => 'setup_required',
        ]);
        $plainState = Str::random(80);
        SquareOAuthState::create([
            'business_id' => $business->id,
            'integration_id' => $integration->id,
            'initiated_by_user_id' => $owner->id,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addMinutes(10),
        ]);
        Http::fake([
            'connect.squareupsandbox.com/oauth2/token' => Http::response([
                'access_token' => 'square-access-token',
                'refresh_token' => 'square-refresh-token',
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'merchant_id' => 'MERCHANT-123',
            ]),
        ]);

        $this->get('/api/v1/integrations/square/callback?'.http_build_query(['state' => $plainState, 'code' => 'authorization-code']))
            ->assertRedirectContains('square=connected');

        $integration->refresh();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('square-access-token', $integration->access_token_encrypted);
        $this->assertSame('square-refresh-token', $integration->refresh_token_encrypted);
        $this->assertArrayNotHasKey('access_token_encrypted', $integration->toArray());
        $this->assertDatabaseMissing('pos_integrations', ['id' => $integration->id, 'access_token_encrypted' => 'square-access-token']);
        Queue::assertPushed(SyncSquareAppointments::class, fn ($job): bool => $job->integrationId === $integration->id);
    }

    public function test_manual_sync_imports_locations_customers_and_past_and_upcoming_appointments_without_consent(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('Square Barber');
        $integration = PosIntegration::create([
            'business_id' => $business->id,
            'created_by_user_id' => $owner->id,
            'provider' => 'square',
            'name' => 'Square Appointments',
            'environment' => 'sandbox',
            'status' => 'connected',
            'access_token_encrypted' => 'square-access-token',
            'refresh_token_encrypted' => 'square-refresh-token',
            'token_expires_at' => now()->addDays(20),
            'connected_at' => now(),
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/locations')) {
                return Http::response(['locations' => [[
                    'id' => 'LOCATION-1', 'name' => 'Main Square Shop', 'status' => 'ACTIVE',
                    'timezone' => 'America/New_York', 'phone_number' => '+12125550110',
                    'address' => ['address_line_1' => '10 Main St', 'locality' => 'New York', 'country' => 'US'],
                ]]]);
            }
            if (str_contains($request->url(), '/v2/bookings')) {
                return Http::response(['bookings' => [
                    $this->booking('BOOKING-PAST', now()->subDays(10)->toIso8601String()),
                    $this->booking('BOOKING-UPCOMING', now()->addDays(10)->toIso8601String()),
                ]]);
            }
            if (str_ends_with($request->url(), '/v2/customers/bulk-retrieve')) {
                return Http::response(['responses' => ['CUSTOMER-1' => ['customer' => [
                    'id' => 'CUSTOMER-1', 'given_name' => 'Sam', 'family_name' => 'Square',
                    'email_address' => 'sam@example.test', 'phone_number' => '+1 (212) 555-0199',
                ]]]]);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($owner)->postJson('/api/v1/pos-integrations/'.$integration->id.'/square/sync', [], [
            'X-Business-ID' => $business->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.past', 1)
            ->assertJsonPath('data.summary.upcoming', 1)
            ->assertJsonPath('data.summary.locations', 1);
        $this->assertDatabaseHas('locations', ['business_id' => $business->id, 'external_reference' => 'square:LOCATION-1']);
        $this->assertDatabaseHas('customers', ['business_id' => $business->id, 'first_name' => 'Sam', 'phone_e164' => '+12125550199']);
        $this->assertDatabaseHas('customer_external_identities', ['business_id' => $business->id, 'provider' => 'square', 'external_customer_id' => 'CUSTOMER-1']);
        $this->assertDatabaseHas('square_appointments', ['business_id' => $business->id, 'external_booking_id' => 'BOOKING-PAST']);
        $this->assertDatabaseHas('square_appointments', ['business_id' => $business->id, 'external_booking_id' => 'BOOKING-UPCOMING']);
        $this->assertDatabaseCount('customer_consents', 0);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'square.appointments.synced']);
    }

    public function test_square_appointments_and_sync_are_tenant_scoped(): void
    {
        [$ownerA, $businessA] = $this->ownerAndBusiness('Tenant A');
        [, $businessB] = $this->ownerAndBusiness('Tenant B');
        $integrationB = PosIntegration::create([
            'business_id' => $businessB->id,
            'provider' => 'square',
            'name' => 'Square Appointments',
            'environment' => 'sandbox',
            'status' => 'connected',
        ]);

        $headers = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->postJson('/api/v1/pos-integrations/'.$integrationB->id.'/square/sync', [], $headers)->assertNotFound();
        $this->getJson('/api/v1/square/appointments', $headers)->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function booking(string $id, string $startsAt): array
    {
        return [
            'id' => $id,
            'status' => 'ACCEPTED',
            'location_id' => 'LOCATION-1',
            'customer_id' => 'CUSTOMER-1',
            'start_at' => $startsAt,
            'updated_at' => now()->toIso8601String(),
            'appointment_segments' => [['duration_minutes' => 45, 'service_variation_id' => 'SERVICE-1', 'team_member_id' => 'TEAM-1']],
        ];
    }

    private function ownerAndBusiness(string $name): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);

        return [$owner, $business];
    }
}
