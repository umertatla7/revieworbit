<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestonesThreeToFiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_authenticated_owner_and_business(): void
    {
        $response = $this->withHeader('Origin', 'https://revieworbit.test')->postJson('/api/v1/auth/register', [
            'name' => 'Umer Tatla',
            'email' => 'umer@example.com',
            'password' => 'ReviewOrbit123!',
            'password_confirmation' => 'ReviewOrbit123!',
            'business_name' => 'AL Barber Shop',
            'industry' => 'beauty_wellness',
            'business_phone' => '+12025550123',
            'location_name' => 'Main Street',
            'address_line1' => '100 Main Street',
            'city' => 'New York',
            'region' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'timezone' => 'America/New_York',
        ]);

        $response->assertCreated()->assertJsonPath('data.businesses.0.role', 'owner');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('businesses', ['name' => 'AL Barber Shop']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.created']);
    }

    public function test_a_user_cannot_read_or_mutate_another_business_customer(): void
    {
        [$ownerA, $businessA] = $this->ownerAndBusiness('Business A');
        [, $businessB] = $this->ownerAndBusiness('Business B');
        $customerB = Customer::create([
            'business_id' => $businessB->id,
            'first_name' => 'Other',
            'phone_e164' => '+12025550111',
            'phone_hash' => hash('sha256', '+12025550111'),
        ]);

        $headers = ['X-Business-ID' => $businessA->id];
        $this->actingAs($ownerA)->getJson('/api/v1/customers/'.$customerB->id, $headers)->assertNotFound();
        $this->actingAs($ownerA)->patchJson('/api/v1/customers/'.$customerB->id, ['first_name' => 'Stolen'], $headers)->assertNotFound();
        $this->actingAs($ownerA)->getJson('/api/v1/customers', ['X-Business-ID' => $businessB->id])->assertNotFound();
    }

    public function test_customer_consent_and_suppression_are_explicit_and_audited(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('AL Barber Shop');
        $headers = ['X-Business-ID' => $business->id];

        $customerId = $this->actingAs($owner)->postJson('/api/v1/customers', [
            'first_name' => 'Umer',
            'email' => 'umer@example.com',
            'phone' => '+12025550123',
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/customers/'.$customerId.'/consents', ['status' => 'granted', 'source' => 'written'], $headers)->assertCreated();
        $this->postJson('/api/v1/customers/'.$customerId.'/suppressions', ['reason' => 'manual'], $headers)->assertCreated();
        $this->deleteJson('/api/v1/customers/'.$customerId.'/suppressions', ['channel' => 'sms'], $headers)->assertOk();

        $this->assertDatabaseHas('customer_consents', ['customer_id' => $customerId, 'status' => 'granted', 'source' => 'written']);
        $this->assertDatabaseHas('suppression_entries', ['customer_id' => $customerId, 'reason' => 'manual']);
        $this->assertNotNull($business->customers()->findOrFail($customerId)->suppressions()->firstOrFail()->released_at);
        $this->assertSame(4, AuditLog::where('business_id', $business->id)->count());

        $this->getJson('/api/v1/customers?sms=consented', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/customers/'.$customerId.'/consents', ['status' => 'revoked', 'source' => 'written'], $headers)->assertCreated();
        $this->getJson('/api/v1/customers?sms=consented', $headers)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/customers?sms=not_recorded', $headers)->assertJsonPath('meta.total', 1);
    }

    public function test_viewers_can_read_but_cannot_create_customers(): void
    {
        [, $business] = $this->ownerAndBusiness('AL Barber Shop');
        $viewer = User::factory()->create();
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $viewer->id, 'role' => BusinessRole::Viewer]);
        $headers = ['X-Business-ID' => $business->id];

        $this->actingAs($viewer)->getJson('/api/v1/customers', $headers)->assertOk();
        $this->postJson('/api/v1/customers', ['first_name' => 'Umer', 'phone' => '+12025550123'], $headers)->assertForbidden();
    }

    public function test_an_invitation_can_only_be_accepted_by_its_intended_email(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('AL Barber Shop');
        $headers = ['X-Business-ID' => $business->id];
        $token = $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'manager@example.com', 'role' => 'manager'], $headers)
            ->assertCreated()->json('data.token');

        $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
        $this->actingAs($wrongUser)->postJson('/api/v1/invitations/'.$token.'/accept')->assertForbidden();

        $manager = User::factory()->create(['email' => 'manager@example.com']);
        $this->actingAs($manager)->postJson('/api/v1/invitations/'.$token.'/accept')
            ->assertOk()->assertJsonPath('data.role', 'manager');
        $this->assertDatabaseHas('business_users', ['business_id' => $business->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->assertDatabaseHas('business_invitations', ['business_id' => $business->id, 'status' => 'accepted']);
    }

    public function test_template_requires_review_link_and_reports_segment_estimate(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('AL Barber Shop');
        $headers = ['X-Business-ID' => $business->id];

        $this->actingAs($owner)->postJson('/api/v1/templates', ['name' => 'Unsafe', 'body' => 'Hello {{customer_first_name}}'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $body = 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}. Share your honest feedback: {{review_link}}';
        $this->postJson('/api/v1/templates', ['name' => 'Standard', 'body' => $body, 'status' => 'active'], $headers)->assertCreated();
        $this->postJson('/api/v1/templates/preview', ['body' => $body], $headers)
            ->assertOk()
            ->assertJsonPath('data.estimate.encoding', 'GSM-7')
            ->assertJsonFragment(['review_link']);
    }

    public function test_basic_plan_allows_one_review_link_from_any_supported_provider(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('Flexible Review Link');
        $headers = ['X-Business-ID' => $business->id];

        $this->actingAs($owner)->postJson('/api/v1/locations', [
            'name' => 'Main location',
            'timezone' => 'America/New_York',
            'review_destinations' => [[
                'provider' => 'trustpilot',
                'url' => 'https://www.trustpilot.com/review/example.com',
                'is_primary' => true,
            ]],
        ], $headers)->assertCreated()->assertJsonPath('data.review_destinations.0.provider', 'trustpilot');

        $this->postJson('/api/v1/locations', [
            'name' => 'Second location',
            'timezone' => 'America/New_York',
            'review_destinations' => [['provider' => 'google', 'url' => 'https://g.page/r/example/review']],
        ], $headers)->assertUnprocessable();
    }

    public function test_basic_plan_allows_five_active_templates_and_archives_deletions(): void
    {
        [$owner, $business] = $this->ownerAndBusiness('Template Limits');
        $headers = ['X-Business-ID' => $business->id];
        $body = 'Hi {{customer_first_name}}, please share honest feedback: {{review_link}}';
        $templateId = null;

        foreach (range(1, 5) as $number) {
            $templateId = $this->actingAs($owner)->postJson('/api/v1/templates', [
                'name' => 'Template '.$number,
                'body' => $body,
                'status' => 'active',
            ], $headers)->assertCreated()->json('data.id');
        }

        $this->postJson('/api/v1/templates', ['name' => 'Template 6', 'body' => $body], $headers)->assertUnprocessable();
        $this->deleteJson('/api/v1/templates/'.$templateId, [], $headers)->assertNoContent();
        $this->assertDatabaseHas('message_templates', ['id' => $templateId, 'status' => 'archived']);
        $this->postJson('/api/v1/templates', ['name' => 'Replacement', 'body' => $body], $headers)->assertCreated();
    }

    private function ownerAndBusiness(string $name): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => BusinessRole::Owner]);

        return [$owner, $business];
    }
}
