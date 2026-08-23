<?php

namespace Database\Seeders;

use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Customers\Models\Customer;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Tenancy\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = config('revieworbit.seed.password');
        if (! is_string($password) || $password === '') {
            $this->command?->warn('SEED_DEFAULT_PASSWORD is empty; development identities were not seeded.');

            return;
        }

        $admin = User::updateOrCreate(['email' => config('revieworbit.seed.admin_email')], [
            'name' => 'ReviewOrbit Admin', 'password' => $password, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        PlatformUserRole::firstOrCreate(['user_id' => $admin->id, 'role' => PlatformRole::SuperAdmin]);

        $owner = User::updateOrCreate(['email' => config('revieworbit.seed.owner_email')], [
            'name' => 'AL Barber Shop Owner', 'password' => $password, 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $business = Business::firstOrCreate(['slug' => 'al-barber-shop'], ['name' => 'AL Barber Shop', 'default_timezone' => 'America/New_York', 'default_country' => 'US']);
        $business->update(['onboarding_status' => 'completed', 'onboarding_step' => 10, 'operation_mode' => 'manual', 'messaging_preferences' => ['channel' => 'sms', 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '09:00'], 'consent_confirmed_at' => now(), 'onboarding_completed_at' => now()]);
        BusinessUser::updateOrCreate(['business_id' => $business->id, 'user_id' => $owner->id], ['role' => BusinessRole::Owner, 'status' => 'active']);
        BusinessUser::where('business_id', $business->id)->where('user_id', $admin->id)->delete();
        $location = Location::firstOrCreate(['business_id' => $business->id, 'external_reference' => 'main-street'], ['name' => 'Main Street Location', 'timezone' => 'America/New_York', 'google_review_url' => 'https://example.invalid/al-barber-shop/review', 'status' => 'active']);
        $customer = Customer::firstOrCreate(['business_id' => $business->id, 'phone_hash' => hash('sha256', '+12025550123')], ['first_name' => 'Umer', 'email' => 'umer@example.com', 'phone_e164' => '+12025550123', 'source' => 'manual']);
        $customer->consents()->firstOrCreate(['business_id' => $business->id, 'channel' => 'sms', 'status' => 'granted'], ['source' => 'written', 'recorded_at' => now(), 'evidence' => ['fixture' => true]]);
        $template = MessageTemplate::firstOrCreate(['business_id' => $business->id, 'name' => 'Standard review request'], ['channel' => 'sms', 'body' => 'Hi {{customer_first_name}}, thank you for visiting {{business_name}}. We would appreciate your honest feedback. Share your experience here: {{review_link}}', 'status' => 'active']);
        AutomationRule::firstOrCreate(['business_id' => $business->id, 'name' => 'Completed visit follow-up'], ['location_id' => $location->id, 'message_template_id' => $template->id, 'trigger_type' => 'visit.completed', 'delay_minutes' => 60, 'quiet_hours_start' => '20:00', 'quiet_hours_end' => '09:00', 'frequency_limit_days' => 30, 'status' => 'active']);
    }
}
