<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Customers\Models\Customer;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\ReviewLink;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\LocationReviewDestination;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use App\Domain\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestBusinessSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = config('revieworbit.seed.test_business_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('Set TEST_BUSINESS_PASSWORD to at least 12 characters before running TestBusinessSeeder.');
        }

        foreach ($this->businesses() as $businessIndex => $fixture) {
            DB::transaction(function () use ($fixture, $businessIndex, $password): void {
                $owner = User::updateOrCreate(['email' => $fixture['email']], [
                    'name' => $fixture['owner'],
                    'password' => $password,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
                $business = Business::updateOrCreate(['slug' => $fixture['slug']], [
                    'name' => $fixture['name'],
                    'legal_name' => $fixture['name'].' LLC',
                    'industry' => $fixture['industry'],
                    'status' => 'active',
                    'plan_code' => $fixture['plan'],
                    'default_timezone' => $fixture['timezone'],
                    'default_country' => 'US',
                    'primary_email' => $fixture['email'],
                    'phone' => sprintf('+12025550%03d', 200 + $businessIndex),
                    'website_url' => 'https://'.$fixture['slug'].'.example.invalid',
                    'onboarding_status' => 'completed',
                    'onboarding_step' => 10,
                    'operation_mode' => $businessIndex % 3 === 0 ? 'manual' : 'square',
                    'messaging_preferences' => ['channel' => 'sms', 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '09:00'],
                    'consent_confirmed_at' => now()->subDays(30),
                    'onboarding_completed_at' => now()->subDays(25),
                    'account_notes' => 'Deterministic test workspace created by TestBusinessSeeder. No provider messages are sent.',
                ]);
                BusinessUser::updateOrCreate(
                    ['business_id' => $business->id, 'user_id' => $owner->id],
                    ['role' => BusinessRole::Owner, 'status' => 'active'],
                );
                BusinessSubscription::updateOrCreate(
                    ['business_id' => $business->id],
                    ['subscription_plan_id' => SubscriptionPlan::where('code', $fixture['plan'])->value('id')],
                );

                $locationCount = ['basic' => 1, 'growth' => 2, 'pro' => 3][$fixture['plan']];
                $locations = collect(range(1, $locationCount))->map(function (int $number) use ($business, $fixture): Location {
                    $location = Location::updateOrCreate(
                        ['business_id' => $business->id, 'external_reference' => 'test-location-'.$number],
                        ['name' => $number === 1 ? 'Main Location' : 'Location '.$number, 'timezone' => $fixture['timezone'], 'phone' => null, 'address' => ['line1' => (100 + $number).' Test Avenue', 'city' => 'Columbus', 'region' => 'OH', 'postal_code' => '43215', 'country' => 'US'], 'google_review_url' => 'https://example.invalid/'.$fixture['slug'].'/location-'.$number.'/review', 'status' => 'active'],
                    );
                    LocationReviewDestination::updateOrCreate(
                        ['location_id' => $location->id, 'provider' => 'google'],
                        ['business_id' => $business->id, 'url' => $location->google_review_url, 'status' => 'active', 'is_primary' => true],
                    );

                    return $location;
                });

                $templateCount = ['basic' => 1, 'growth' => 2, 'pro' => 3][$fixture['plan']];
                $templates = collect(range(1, $templateCount))->map(function (int $number) use ($business, $locations): MessageTemplate {
                    $location = $locations[($number - 1) % $locations->count()];
                    $destination = $location->reviewDestinations()->firstOrFail();

                    return MessageTemplate::updateOrCreate(
                        ['business_id' => $business->id, 'name' => $number === 1 ? 'Standard review request' : 'Follow-up template '.$number],
                        ['location_id' => $location->id, 'review_destination_id' => $destination->id, 'channel' => 'sms', 'body' => 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}. We would appreciate your honest feedback: {{review_link}}', 'status' => 'active', 'include_media' => false],
                    );
                });

                foreach (range(1, 4) as $customerIndex) {
                    $phone = sprintf('+12025550%03d', 100 + ($businessIndex * 4) + $customerIndex);
                    $customer = Customer::updateOrCreate(
                        ['business_id' => $business->id, 'phone_hash' => hash('sha256', $phone)],
                        ['first_name' => ['Ava', 'Noah', 'Mia', 'Liam'][$customerIndex - 1], 'last_name' => 'Tester '.($businessIndex + 1), 'email' => 'contact'.$customerIndex.'.'.$fixture['slug'].'@example.test', 'phone_e164' => $phone, 'status' => 'active', 'source' => $business->operation_mode === 'square' ? 'square' : 'manual'],
                    );
                    $customer->consents()->updateOrCreate(
                        ['business_id' => $business->id, 'channel' => 'sms'],
                        ['status' => 'granted', 'source' => 'fixture', 'recorded_at' => now()->subDays(45), 'evidence' => ['fixture' => true]],
                    );

                    foreach (range(1, 2) as $visitIndex) {
                        $location = $locations[($customerIndex + $visitIndex - 2) % $locations->count()];
                        $template = $templates[($customerIndex + $visitIndex - 2) % $templates->count()];
                        $completedAt = now()->subDays(($businessIndex * 2) + $customerIndex + $visitIndex)->setTime(14 + $visitIndex, 15);
                        $externalId = 'fixture-'.$fixture['slug'].'-'.$customerIndex.'-'.$visitIndex;
                        $visit = Visit::updateOrCreate(
                            ['business_id' => $business->id, 'source' => $business->operation_mode === 'square' ? 'square' : 'manual', 'external_visit_id' => $externalId],
                            ['location_id' => $location->id, 'customer_id' => $customer->id, 'type' => $visitIndex === 1 ? 'appointment' : 'service', 'status' => 'completed', 'amount' => 45 + ($businessIndex * 5) + ($customerIndex * 3), 'currency' => 'USD', 'completed_at' => $completedAt, 'raw_metadata' => ['fixture' => true]],
                        );
                        $destination = $location->reviewDestinations()->firstOrFail();
                        $clickCount = ($businessIndex + $customerIndex + $visitIndex) % 3;
                        $reviewLink = ReviewLink::updateOrCreate(
                            ['token_hash' => hash('sha256', 'fixture:'.$externalId)],
                            ['business_id' => $business->id, 'location_id' => $location->id, 'review_destination_id' => $destination->id, 'customer_id' => $customer->id, 'visit_id' => $visit->id, 'destination_url' => $destination->url, 'click_count' => $clickCount, 'first_clicked_at' => $clickCount ? $completedAt->copy()->addHours(3) : null, 'last_clicked_at' => $clickCount ? $completedAt->copy()->addHours(3 + $clickCount) : null, 'expires_at' => now()->addDays(60)],
                        );
                        $status = ($businessIndex + $customerIndex + $visitIndex) % 7 === 0 ? 'failed' : 'delivered';
                        MessageDelivery::updateOrCreate(
                            ['business_id' => $business->id, 'visit_id' => $visit->id, 'delivery_type' => 'fixture'],
                            ['location_id' => $location->id, 'customer_id' => $customer->id, 'message_template_id' => $template->id, 'review_link_id' => $reviewLink->id, 'provider' => 'fixture', 'channel' => 'sms', 'body_snapshot' => 'Hi '.$customer->first_name.', thank you for visiting '.$business->name.'. We would appreciate your honest feedback.', 'billable_credits' => 1, 'estimated_cost_minor' => 1, 'provider_cost_minor' => 1, 'provider_currency' => 'USD', 'to_hash' => hash('sha256', $phone), 'to_last_four' => substr($phone, -4), 'status' => $status, 'queued_at' => $completedAt->copy()->addHour(), 'sent_at' => $completedAt->copy()->addHour()->addMinute(), 'delivered_at' => $status === 'delivered' ? $completedAt->copy()->addHour()->addMinutes(2) : null, 'failed_at' => $status === 'failed' ? $completedAt->copy()->addHour()->addMinutes(2) : null, 'failure_message' => $status === 'failed' ? 'Fixture delivery failure for analytics testing.' : null],
                        );
                    }
                    $customer->forceFill(['last_visit_at' => $customer->visits()->max('completed_at')])->save();
                }
            });
        }

        $this->command?->info('Created or refreshed 10 test businesses, 40 contacts, and 80 visits without contacting a messaging provider.');
    }

    /** @return array<int, array<string, string>> */
    private function businesses(): array
    {
        return [
            ['name' => 'Northstar Dental', 'slug' => 'test-northstar-dental', 'owner' => 'Avery Morgan', 'email' => 'owner.basic01@revieworbit.test', 'plan' => 'basic', 'industry' => 'dental', 'timezone' => 'America/New_York'],
            ['name' => 'Maple Auto Care', 'slug' => 'test-maple-auto-care', 'owner' => 'Jordan Lee', 'email' => 'owner.basic02@revieworbit.test', 'plan' => 'basic', 'industry' => 'automotive', 'timezone' => 'America/Chicago'],
            ['name' => 'Willow Wellness', 'slug' => 'test-willow-wellness', 'owner' => 'Taylor Brooks', 'email' => 'owner.basic03@revieworbit.test', 'plan' => 'basic', 'industry' => 'beauty_wellness', 'timezone' => 'America/Denver'],
            ['name' => 'Harbor Home Services', 'slug' => 'test-harbor-home-services', 'owner' => 'Casey Davis', 'email' => 'owner.basic04@revieworbit.test', 'plan' => 'basic', 'industry' => 'home_services', 'timezone' => 'America/Los_Angeles'],
            ['name' => 'Cedar & Stone Salon', 'slug' => 'test-cedar-stone-salon', 'owner' => 'Riley Parker', 'email' => 'owner.growth01@revieworbit.test', 'plan' => 'growth', 'industry' => 'beauty_wellness', 'timezone' => 'America/New_York'],
            ['name' => 'Summit Family Clinic', 'slug' => 'test-summit-family-clinic', 'owner' => 'Morgan Reed', 'email' => 'owner.growth02@revieworbit.test', 'plan' => 'growth', 'industry' => 'healthcare', 'timezone' => 'America/Chicago'],
            ['name' => 'Riverbend Café', 'slug' => 'test-riverbend-cafe', 'owner' => 'Cameron Hayes', 'email' => 'owner.growth03@revieworbit.test', 'plan' => 'growth', 'industry' => 'restaurant', 'timezone' => 'America/Denver'],
            ['name' => 'Brightline Hospitality', 'slug' => 'test-brightline-hospitality', 'owner' => 'Quinn Foster', 'email' => 'owner.pro01@revieworbit.test', 'plan' => 'pro', 'industry' => 'hospitality', 'timezone' => 'America/New_York'],
            ['name' => 'Oakridge Retail Group', 'slug' => 'test-oakridge-retail', 'owner' => 'Skyler Bennett', 'email' => 'owner.pro02@revieworbit.test', 'plan' => 'pro', 'industry' => 'retail', 'timezone' => 'America/Chicago'],
            ['name' => 'Atlas Professional Partners', 'slug' => 'test-atlas-professional', 'owner' => 'Emerson Ward', 'email' => 'owner.pro03@revieworbit.test', 'plan' => 'pro', 'industry' => 'professional_services', 'timezone' => 'America/Los_Angeles'],
        ];
    }
}
