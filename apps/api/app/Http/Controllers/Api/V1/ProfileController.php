<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'has_avatar' => filled($user->avatar_path),
            'avatar_url' => filled($user->avatar_path) ? url('/api/v1/profile/avatar') : null,
        ]]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);
        $data['email'] = Str::lower($data['email']);
        if ($data['email'] !== $user->email) {
            $data['email_verified_at'] = null;
        }
        $user->forceFill($data)->save();
        $auditor->record($request, 'profile.updated', $request->attributes->get('business'), array_keys($data));

        return $this->show($request);
    }

    public function avatar(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=128,min_height=128,max_width=4000,max_height=4000'],
        ]);
        $user = $request->user();
        $oldPath = $user->avatar_path;
        $path = $data['avatar']->store('avatars/'.$user->id, 'local');
        $user->forceFill(['avatar_path' => $path])->save();
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }
        $auditor->record($request, 'profile.avatar_updated', $request->attributes->get('business'));

        return $this->show($request);
    }

    public function serveAvatar(Request $request): StreamedResponse
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function password(Request $request, Auditor $auditor): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        $sessions = DB::table('sessions')->where('user_id', $user->id);
        if ($request->hasSession()) {
            $sessions->where('id', '!=', $request->session()->getId());
        }
        $sessions->delete();
        $auditor->record($request, 'profile.password_changed', $request->attributes->get('business'), ['other_sessions_revoked' => true]);

        return response()->json(['message' => 'Password changed. Other signed-in devices were logged out.']);
    }
}
