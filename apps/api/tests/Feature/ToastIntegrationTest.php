<?php

namespace Tests\Feature;

use App\Domain\Integrations\Jobs\ProcessToastWebhook;
use App\Domain\Integrations\Jobs\SyncToastOrders;
use App\Domain\Integrations\Models\IntegrationWebhookEvent;
use App\Domain\Integrations\Models\PlatformToastSetting;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Integrations\Services\ToastConnector;
use App\Domain\Integrations\Services\ToastOrderImporter;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ToastIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.toast', ['environment' => 'sandbox', 'history_days' => 30, 'connection_code_days' => 30]);
    }

    public function test_super_admin_saves_encrypted_partner_secrets_and_verifies_machine_credentials(): void
    {
        $admin = User::factory()->create();
        PlatformUserRole::create(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);
        Http::fake(['toast-api.example.test/authentication/v1/authentication/login' => Http::response([
            'token' => ['accessToken' => 'machine-token', 'expiresIn' => 3600],
        ])]);

        $this->actingAs($admin)->putJson('/api/v1/admin/toast', $this->settingsPayload())
            ->assertOk()->assertJsonPath('data.client_secret_configured', true)
            ->assertJsonMissingPath('data.client_secret');
        $this->assertNotSame('toast-client-secret-value', DB::table('platform_toast_settings')->value('client_secret'));

        $this->postJson('/api/v1/admin/toast/verify', ['environment' => 'sandbox'])
            ->assertOk()->assertJsonPath('data.configuration.status', 'verified')
            ->assertJsonPath('data.configuration.ready_for_connections', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.toast.credentials_verified', 'actor_user_id' => $admin->id]);
    }

    public function test_owner_creates_a_tenant_scoped_location_code_without_receiving_partner_credentials(): void
    {
        $this->readySetting();
        [$ownerA, $businessA, $locationA] = $this->businessFixture('Toast A');
        [, $businessB, $locationB] = $this->businessFixture('Toast B');

        $response = $this->actingAs($ownerA)->postJson('/api/v1/pos-integrations/toast/connect', ['location_id' => $locationA->id], ['X-Business-ID' => $businessA->id]);
        $response->assertCreated()->assertJsonPath('data.location.id', $locationA->id)
            ->assertJsonMissingPath('data.client_secret')->assertJsonStructure(['data' => ['connection_code', 'marketplace_url']]);
        $this->assertStringStartsWith('RO-', $response->json('data.connection_code'));
        $this->postJson('/api/v1/pos-integrations/toast/connect', ['location_id' => $locationB->id], ['X-Business-ID' => $businessA->id])->assertNotFound();
        $this->assertDatabaseHas('toast_connection_requests', ['business_id' => $businessA->id, 'location_id' => $locationA->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('toast_connection_requests', ['business_id' => $businessB->id]);
    }

    public function test_signed_partner_webhook_maps_to_the_correct_location_and_duplicate_is_idempotent(): void
    {
        Queue::fake();
        $this->readySetting();
        [$owner, $business, $location] = $this->businessFixture('Partner Restaurant');
        $code = $this->actingAs($owner)->postJson('/api/v1/pos-integrations/toast/connect', ['location_id' => $location->id], ['X-Business-ID' => $business->id])->json('data.connection_code');
        $payload = [
            'timestamp' => now()->toIso8601String(), 'eventCategory' => 'PARTNERS', 'eventType' => 'PARTNER_ADDED',
            'guid' => (string) Str::uuid(), 'details' => ['restaurantGuid' => (string) Str::uuid(), 'restaurantName' => 'Toast Cafe', 'externalRestaurantRef' => $code],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = base64_encode(hash_hmac('sha256', $raw.$payload['timestamp'], 'partner-signing-secret-value', true));

        $this->call('POST', '/api/v1/webhooks/toast/sandbox/partners', [], [], [], ['HTTP_TOAST_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $raw)->assertAccepted();
        $this->call('POST', '/api/v1/webhooks/toast/sandbox/partners', [], [], [], ['HTTP_TOAST_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $raw)->assertAccepted();
        $this->assertDatabaseCount('integration_webhook_events', 1);
        $event = IntegrationWebhookEvent::firstOrFail();
        (new ProcessToastWebhook($event->id))->handle(app(ToastConnector::class), app(ToastOrderImporter::class));
        $this->assertDatabaseHas('toast_restaurant_connections', ['business_id' => $business->id, 'location_id' => $location->id, 'status' => 'connected']);
        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'operation_mode' => 'toast']);
        Queue::assertPushed(SyncToastOrders::class);
    }

    public function test_invalid_signature_is_rejected_before_persistence(): void
    {
        $this->readySetting();
        $payload = ['timestamp' => now()->toIso8601String(), 'eventType' => 'ORDER_UPDATED', 'guid' => (string) Str::uuid(), 'details' => []];
        $this->call('POST', '/api/v1/webhooks/toast/sandbox/orders', [], [], [], ['HTTP_TOAST_SIGNATURE' => 'invalid', 'CONTENT_TYPE' => 'application/json'], json_encode($payload))->assertUnauthorized();
        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    public function test_completed_toast_order_creates_one_visit_without_creating_consent(): void
    {
        [$owner, $business, $location] = $this->businessFixture('Order Restaurant');
        $this->readySetting();
        $integration = PosIntegration::create(['business_id' => $business->id, 'created_by_user_id' => $owner->id, 'provider' => 'toast', 'name' => 'Toast POS', 'environment' => 'sandbox', 'status' => 'connected']);
        $connection = ToastRestaurantConnection::create(['business_id' => $business->id, 'integration_id' => $integration->id, 'location_id' => $location->id, 'environment' => 'sandbox', 'restaurant_guid' => (string) Str::uuid(), 'status' => 'connected']);
        $orderGuid = (string) Str::uuid();
        Http::fake([
            'toast-api.example.test/authentication/v1/authentication/login' => Http::response(['token' => ['accessToken' => 'machine-token', 'expiresIn' => 3600]]),
            "toast-api.example.test/orders/v2/orders/{$orderGuid}" => Http::response([
                'guid' => $orderGuid, 'checks' => [[
                    'guid' => (string) Str::uuid(), 'closedDate' => now()->subHour()->toIso8601String(), 'amount' => 45.5,
                    'customer' => ['guid' => (string) Str::uuid(), 'firstName' => 'Jordan', 'lastName' => 'Guest', 'phone' => '(202) 555-0147'],
                ]],
            ]),
        ]);
        $payload = ['_meta' => ['environment' => 'sandbox', 'restaurant_guid' => $connection->restaurant_guid], 'details' => ['restaurantGuid' => $connection->restaurant_guid, 'order' => ['guid' => $orderGuid, 'checks' => [['closedDate' => now()->subHour()->toIso8601String()]]]]];
        $event = IntegrationWebhookEvent::create(['provider' => 'toast', 'category' => 'orders', 'event_type' => 'order_updated', 'external_event_id' => (string) Str::uuid(), 'payload_fingerprint' => hash('sha256', 'order'), 'payload' => $payload, 'status' => 'received', 'verified_at' => now()]);

        (new ProcessToastWebhook($event->id))->handle(app(ToastConnector::class), app(ToastOrderImporter::class));
        $this->assertDatabaseHas('visits', ['business_id' => $business->id, 'source' => 'toast', 'external_order_id' => $orderGuid, 'status' => 'completed']);
        $this->assertDatabaseHas('customers', ['business_id' => $business->id, 'first_name' => 'Jordan', 'phone_e164' => '+12025550147']);
        $this->assertDatabaseCount('customer_consents', 0);
        $this->assertDatabaseCount('automation_dispatches', 0);
    }

    public function test_manual_toast_sync_is_tenant_scoped(): void
    {
        [$ownerA, $businessA] = $this->businessFixture('Tenant A');
        [$ownerB, $businessB, $locationB] = $this->businessFixture('Tenant B');
        $integrationB = PosIntegration::create(['business_id' => $businessB->id, 'created_by_user_id' => $ownerB->id, 'provider' => 'toast', 'name' => 'Toast POS', 'environment' => 'sandbox', 'status' => 'connected']);
        $connectionB = ToastRestaurantConnection::create(['business_id' => $businessB->id, 'integration_id' => $integrationB->id, 'location_id' => $locationB->id, 'environment' => 'sandbox', 'restaurant_guid' => (string) Str::uuid(), 'status' => 'connected']);
        $this->actingAs($ownerA)->postJson('/api/v1/pos-integrations/toast/connections/'.$connectionB->id.'/sync', [], ['X-Business-ID' => $businessA->id])->assertNotFound();
    }

    private function readySetting(): PlatformToastSetting
    {
        return PlatformToastSetting::create([
            'environment' => 'sandbox', 'api_base_url' => 'https://toast-api.example.test', 'client_id' => 'toast-client-id',
            'client_secret' => 'toast-client-secret-value', 'partner_webhook_secret' => 'partner-signing-secret-value',
            'orders_webhook_secret' => 'orders-signing-secret-value', 'marketplace_url' => 'https://www.toasttab.com/integrations/revieworbit',
            'status' => 'verified', 'verified_at' => now(),
        ]);
    }

    private function settingsPayload(): array
    {
        return ['environment' => 'sandbox', 'api_base_url' => 'https://toast-api.example.test', 'client_id' => 'toast-client-id',
            'client_secret' => 'toast-client-secret-value', 'partner_webhook_secret' => 'partner-signing-secret-value',
            'orders_webhook_secret' => 'orders-signing-secret-value', 'marketplace_url' => 'https://www.toasttab.com/integrations/revieworbit'];
    }

    private function businessFixture(string $name): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'default_timezone' => 'America/New_York']);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);
        $location = Location::create(['business_id' => $business->id, 'name' => 'Main Location', 'timezone' => 'America/New_York', 'status' => 'active']);

        return [$owner, $business, $location];
    }
}
