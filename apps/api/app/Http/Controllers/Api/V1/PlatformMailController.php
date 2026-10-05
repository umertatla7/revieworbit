<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Tenancy\Models\PlatformMailSetting;
use App\Domain\Tenancy\Services\PlatformMailConfigurator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlatformMailController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(PlatformMailSetting::query()->latest()->first())]);
    }

    public function update(Request $request, Auditor $auditor, PlatformMailConfigurator $configurator): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['ssl', 'starttls'])],
            'username' => ['required', 'email:rfc', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);
        $setting = PlatformMailSetting::query()->latest()->first();
        if (! $setting && empty($data['password'])) {
            throw ValidationException::withMessages([
                'password' => ['Enter the SMTP password the first time the configuration is saved.'],
            ]);
        }

        $passwordRotated = ! empty($data['password']);
        $attributes = [
            'host' => $data['host'],
            'port' => $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'],
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'],
            'enabled' => $data['enabled'],
            'status' => 'draft',
            'verified_at' => null,
            'last_error' => null,
            'updated_by_user_id' => $request->user()->id,
        ];
        if ($passwordRotated) {
            $attributes['password'] = $data['password'];
        }

        if ($setting) {
            $setting->update($attributes);
        } else {
            $setting = PlatformMailSetting::query()->create($attributes);
        }
        $configurator->apply($setting->fresh());
        $auditor->record($request, 'platform.mail.configuration_updated', $setting, [
            'host' => $setting->host,
            'port' => $setting->port,
            'encryption' => $setting->encryption,
            'enabled' => $setting->enabled,
            'password_rotated' => $passwordRotated,
        ]);

        return response()->json(['data' => $this->payload($setting->fresh())]);
    }

    public function test(Request $request, Auditor $auditor, PlatformMailConfigurator $configurator): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'email:rfc', 'max:255'],
        ]);
        $setting = PlatformMailSetting::query()->latest()->firstOrFail();
        if (! $setting->enabled) {
            throw ValidationException::withMessages(['recipient' => ['Enable SMTP before sending a test email.']]);
        }

        if (! $configurator->apply($setting)) {
            $setting->update([
                'status' => 'error',
                'verified_at' => null,
                'last_tested_at' => now(),
                'last_error' => 'The saved SMTP configuration could not be loaded securely.',
            ]);

            return response()->json([
                'message' => 'The saved SMTP configuration could not be loaded securely.',
                'data' => $this->payload($setting->fresh()),
            ], 422);
        }
        try {
            Mail::mailer('smtp')->raw(
                'B Review successfully connected to the configured SMTP server. No action is required.',
                function ($message) use ($data): void {
                    $message->to($data['recipient'])->subject('B Review SMTP connection test');
                }
            );
            $setting->update([
                'status' => 'verified',
                'verified_at' => now(),
                'last_tested_at' => now(),
                'last_error' => null,
            ]);
            $auditor->record($request, 'platform.mail.test_succeeded', $setting, [
                'host' => $setting->host,
                'port' => $setting->port,
                'encryption' => $setting->encryption,
            ]);

            return response()->json([
                'message' => 'SMTP connected successfully and the test email was accepted for delivery.',
                'data' => $this->payload($setting->fresh()),
            ]);
        } catch (Throwable $exception) {
            $safeError = $this->safeError($exception);
            $setting->update([
                'status' => 'error',
                'verified_at' => null,
                'last_tested_at' => now(),
                'last_error' => $safeError,
            ]);
            $auditor->record($request, 'platform.mail.test_failed', $setting, [
                'host' => $setting->host,
                'port' => $setting->port,
                'encryption' => $setting->encryption,
                'reason' => $safeError,
            ]);

            return response()->json(['message' => $safeError, 'data' => $this->payload($setting->fresh())], 422);
        }
    }

    private function payload(?PlatformMailSetting $setting): array
    {
        return [
            'configured' => (bool) $setting,
            'host' => $setting?->host ?? 'smtp.hostinger.com',
            'port' => $setting?->port ?? 465,
            'encryption' => $setting?->encryption ?? 'ssl',
            'username' => $setting?->username,
            'password_configured' => (bool) $setting?->getRawOriginal('password'),
            'from_address' => $setting?->from_address,
            'from_name' => $setting?->from_name ?? 'B Review',
            'enabled' => $setting?->enabled ?? true,
            'status' => $setting?->status ?? 'not_configured',
            'verified_at' => $setting?->verified_at,
            'last_tested_at' => $setting?->last_tested_at,
            'last_error' => $setting?->last_error,
        ];
    }

    private function safeError(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'authenticat') => 'SMTP authentication failed. Check the username and password.',
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'The SMTP connection timed out. Check the host, port, encryption, and server firewall.',
            str_contains($message, 'getaddrinfo'), str_contains($message, 'name or service not known') => 'The SMTP host could not be resolved. Check the server hostname.',
            str_contains($message, 'certificate'), str_contains($message, 'crypto') => 'The SMTP encryption handshake failed. Check the encryption type and port.',
            default => 'The SMTP test failed. Check the host, port, encryption, username, and password.',
        };
    }
}
