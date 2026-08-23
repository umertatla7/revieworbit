<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Domain\Tenancy\Services\BusinessProvisioner;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, Auditor $auditor, BusinessProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'business_name' => ['required', 'string', 'max:160'],
            'industry' => ['required', Rule::in(['automotive', 'beauty_wellness', 'dental', 'healthcare', 'home_services', 'hospitality', 'professional_services', 'restaurant', 'retail', 'other'])],
            'business_phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'location_name' => ['required', 'string', 'max:160'],
            'address_line1' => ['required', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'region' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:24'],
            'country' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'timezone'],
        ]);

        $result = $provisioner->provision([
            ...$data,
            'owner_name' => $data['name'],
            'owner_email' => $data['email'],
            'owner_password' => $data['password'],
            'owner_email_verified' => true,
            'business_email' => $data['email'],
            'owner_phone' => $data['business_phone'],
            'location_phone' => $data['business_phone'],
            'operation_mode' => 'manual',
            'preferred_channel' => 'sms',
            'quiet_hours_start' => '20:00',
            'quiet_hours_end' => '09:00',
        ]);
        $user = $result['owner'];
        $business = $result['business'];

        Auth::login($user);
        $request->session()->regenerate();
        $auditor->record($request, 'business.created', $business, ['name' => $business->name]);

        return response()->json(['data' => $this->userPayload($user->fresh('businessMemberships.business'))], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => Str::lower($credentials['email']), 'password' => $credentials['password'], 'status' => 'active'], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => $this->userPayload($request->user()->load('businessMemberships.business'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        PasswordBroker::sendResetLink(['email' => Str::lower($data['email'])]);

        return response()->json(['message' => 'If the account exists, a password reset link has been sent.'], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        $status = PasswordBroker::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user()->load('businessMemberships.business'))]);
    }

    private function userPayload(User $user): array
    {
        $platformRoles = $user->platformRoles()->pluck('role')->map(fn ($role): string => $role instanceof \BackedEnum ? $role->value : (string) $role)->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'platform_roles' => $platformRoles,
            'is_platform_admin' => $platformRoles->contains(fn (string $role): bool => in_array($role, ['super_admin', 'platform_manager'], true)),
            'businesses' => $user->businessMemberships->map(fn (BusinessUser $membership): array => [
                'id' => $membership->business->id,
                'name' => $membership->business->name,
                'slug' => $membership->business->slug,
                'role' => $membership->role->value,
            ])->values(),
        ];
    }
}
