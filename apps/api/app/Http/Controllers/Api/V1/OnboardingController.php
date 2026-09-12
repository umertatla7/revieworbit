<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business')->load(['locations', 'messageTemplates', 'posIntegrations']);

        return response()->json(['data' => $this->payload($business)]);
    }

    public function update(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'default_timezone' => ['sometimes', 'timezone'],
            'default_country' => ['sometimes', 'string', 'size:2'],
            'brand_settings' => ['sometimes', 'array'],
            'messaging_preferences' => ['sometimes', 'array'],
            'messaging_preferences.channel' => ['sometimes', Rule::in(['sms', 'mms'])],
            'messaging_preferences.quiet_hours_start' => ['sometimes', 'date_format:H:i'],
            'messaging_preferences.quiet_hours_end' => ['sometimes', 'date_format:H:i'],
            'consent_confirmed' => ['sometimes', 'boolean'],
            'operation_mode' => ['sometimes', Rule::in(['manual', 'generic', 'square', 'toast'])],
            'onboarding_step' => ['sometimes', 'integer', 'between:1,10'],
        ]);
        if (array_key_exists('consent_confirmed', $data)) {
            $data['consent_confirmed_at'] = $data['consent_confirmed'] ? now() : null;
            unset($data['consent_confirmed']);
        }
        if (isset($data['default_country'])) {
            $data['default_country'] = strtoupper($data['default_country']);
        }
        $business->update($data);
        $auditor->record($request, 'onboarding.updated', $business, array_keys($data));

        return response()->json(['data' => $this->payload($business->fresh()->load(['locations', 'messageTemplates', 'posIntegrations']))]);
    }

    public function complete(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business')->load(['locations', 'messageTemplates', 'posIntegrations']);
        $missing = [];
        if (! $business->locations->contains(fn ($location): bool => filled($location->google_review_url))) {
            $missing[] = 'a location with a Google review URL';
        }
        if ($business->messageTemplates->isEmpty()) {
            $missing[] = 'a message template';
        }
        if (! $business->consent_confirmed_at) {
            $missing[] = 'consent confirmation';
        }
        if ($business->operation_mode !== 'manual' && $business->posIntegrations->isEmpty()) {
            $missing[] = 'a POS integration';
        }
        if ($missing) {
            throw ValidationException::withMessages(['onboarding' => ['Complete '.implode(', ', $missing).'.']]);
        }
        $business->update(['onboarding_status' => 'completed', 'onboarding_step' => 10, 'onboarding_completed_at' => now()]);
        $auditor->record($request, 'onboarding.completed', $business);

        return response()->json(['data' => $this->payload($business->fresh()->load(['locations', 'messageTemplates', 'posIntegrations']))]);
    }

    private function payload($business): array
    {
        $checks = [
            'business_details' => filled($business->name) && filled($business->default_timezone),
            'primary_location' => $business->locations->isNotEmpty(),
            'google_review_url' => $business->locations->contains(fn ($location): bool => filled($location->google_review_url)),
            'message_template' => $business->messageTemplates->isNotEmpty(),
            'messaging_preferences' => filled($business->messaging_preferences),
            'consent_confirmation' => filled($business->consent_confirmed_at),
            'integration' => $business->operation_mode === 'manual' || $business->posIntegrations->isNotEmpty(),
        ];

        return [
            'business' => $business,
            'checks' => $checks,
            'completed_count' => collect($checks)->filter()->count(),
            'total_count' => count($checks),
        ];
    }
}
