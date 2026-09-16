<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_profile_avatar_and_password(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'Original@Password2026']);
        $business = Business::create(['name' => 'Profile Shop', 'slug' => 'profile-shop', 'plan_code' => 'launch']);
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => BusinessRole::Owner]);
        $headers = ['X-Business-ID' => $business->id];

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'name' => 'Updated Owner', 'email' => 'updated@example.test', 'phone' => null,
        ], $headers)->assertOk()->assertJsonPath('data.name', 'Updated Owner')->assertJsonPath('data.phone', null);

        $response = $this->postJson('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ], $headers)->assertOk()->assertJsonPath('data.has_avatar', true);
        $this->assertNotNull($response->json('data.avatar_url'));
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'wrong', 'password' => 'Changed@Password2026', 'password_confirmation' => 'Changed@Password2026',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'Original@Password2026', 'password' => 'Changed@Password2026', 'password_confirmation' => 'Changed@Password2026',
        ], $headers)->assertOk();
        $this->assertTrue(Hash::check('Changed@Password2026', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'profile.password_changed']);
    }
}
