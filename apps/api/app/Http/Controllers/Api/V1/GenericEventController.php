<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Automations\Services\AutomationEvaluator;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerExternalIdentity;
use App\Domain\Integrations\Models\IdempotencyRecord;
use App\Domain\Integrations\Models\IntegrationApiKey;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use App\Domain\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GenericEventController extends Controller
{
    public function ingest(Request $request, AutomationEvaluator $evaluator): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey) && strlen($idempotencyKey) >= 8 && strlen($idempotencyKey) <= 200, 400, 'Idempotency-Key is required.');
        $business = $request->attributes->get('business');
        $fingerprint = hash('sha256', $request->getContent());
        $keyHash = hash('sha256', $idempotencyKey);
        $existing = IdempotencyRecord::where('business_id', $business->id)->where('operation', 'generic.visit.completed')->where('key_hash', $keyHash)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Idempotency key was reused with a different payload.');

            return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
        }

        $body = $this->process($business, $request->all(), $evaluator);
        IdempotencyRecord::create(['business_id' => $business->id, 'operation' => 'generic.visit.completed', 'key_hash' => $keyHash, 'request_fingerprint' => $fingerprint, 'response_status' => 202, 'response_body' => $body, 'expires_at' => now()->addDay()]);

        return response()->json($body, 202);
    }

    public function webhook(Request $request, AutomationEvaluator $evaluator): JsonResponse
    {
        $prefix = $request->header('X-ReviewOrbit-Key');
        $timestamp = $request->header('X-ReviewOrbit-Timestamp');
        $signature = $request->header('X-ReviewOrbit-Signature');
        abort_unless(is_string($prefix) && is_string($timestamp) && ctype_digit($timestamp) && is_string($signature), 401);
        abort_unless(abs(now()->timestamp - (int) $timestamp) <= 300, 401, 'Webhook timestamp is outside the allowed window.');
        $key = IntegrationApiKey::with('business')
            ->where('key_prefix', $prefix)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        abort_unless($key !== null && $key->business->status->value === 'active' && in_array('visits:write', $key->abilities ?? [], true), 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $key->hmac_secret_encrypted);
        abort_unless(hash_equals($expected, $signature), 401, 'Webhook signature is invalid.');
        $body = $this->process($key->business, $request->json()->all(), $evaluator);

        return response()->json($body, 202);
    }

    private function process(Business $business, array $payload, AutomationEvaluator $evaluator): array
    {
        $data = Validator::make($payload, [
            'source' => ['required', 'in:custom'],
            'event_type' => ['required', 'in:visit.completed'],
            'external_event_id' => ['required', 'string', 'max:190'],
            'external_location_id' => ['required', 'string', 'max:190'],
            'customer.external_id' => ['required', 'string', 'max:190'],
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'customer.email' => ['nullable', 'email'],
            'customer.consent.status' => ['nullable', 'in:opted_in,opted_out,unknown'],
            'customer.consent.source' => ['required_if:customer.consent.status,opted_in', 'string', 'max:100'],
            'customer.consent.consented_at' => ['required_if:customer.consent.status,opted_in', 'date'],
            'transaction.external_id' => ['required', 'string', 'max:190'],
            'transaction.amount' => ['nullable', 'numeric', 'min:0'],
            'transaction.currency' => ['required', 'string', 'size:3'],
            'transaction.status' => ['required', 'in:completed'],
            'transaction.completed_at' => ['required', 'date'],
        ])->validate();

        return DB::transaction(function () use ($business, $data, $evaluator): array {
            $location = Location::where('business_id', $business->id)->where('external_reference', $data['external_location_id'])->firstOrFail();
            $identity = CustomerExternalIdentity::with('customer')->where('business_id', $business->id)->where('provider', 'custom')->where('external_customer_id', $data['customer']['external_id'])->first();
            $phoneHash = hash('sha256', $data['customer']['phone']);
            $customer = $identity?->customer ?? Customer::where('business_id', $business->id)->where('phone_hash', $phoneHash)->first();
            $customer ??= Customer::create(['business_id' => $business->id, 'first_name' => $data['customer']['first_name'], 'last_name' => $data['customer']['last_name'] ?? null, 'email' => $data['customer']['email'] ?? null, 'phone_e164' => $data['customer']['phone'], 'phone_hash' => $phoneHash, 'source' => 'custom']);
            CustomerExternalIdentity::firstOrCreate(['business_id' => $business->id, 'provider' => 'custom', 'external_customer_id' => $data['customer']['external_id']], ['customer_id' => $customer->id]);

            $consent = $data['customer']['consent'] ?? null;
            if (($consent['status'] ?? null) === 'opted_in') {
                $customer->consents()->create(['business_id' => $business->id, 'channel' => 'sms', 'status' => 'granted', 'source' => 'provider', 'recorded_at' => $consent['consented_at'], 'evidence' => ['provider' => 'custom', 'source' => $consent['source']]]);
            } elseif (($consent['status'] ?? null) === 'opted_out') {
                $customer->suppressions()->firstOrCreate(['business_id' => $business->id, 'channel' => 'sms', 'released_at' => null], ['reason' => 'opt_out', 'suppressed_at' => now()]);
            }

            $visit = Visit::firstOrCreate(
                ['business_id' => $business->id, 'source' => 'custom', 'external_visit_id' => $data['external_event_id']],
                ['location_id' => $location->id, 'customer_id' => $customer->id, 'external_payment_id' => $data['transaction']['external_id'], 'type' => 'payment', 'status' => 'completed', 'amount' => $data['transaction']['amount'] ?? null, 'currency' => strtoupper($data['transaction']['currency']), 'completed_at' => $data['transaction']['completed_at'], 'raw_metadata' => ['event_type' => $data['event_type']]]
            );
            $customer->update(['last_visit_at' => $visit->completed_at]);
            $dispatches = AutomationRule::where('business_id', $business->id)->where('status', 'active')->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $location->id))->get()->map(fn (AutomationRule $rule) => $evaluator->evaluate($visit, $rule));

            return ['data' => ['visit_id' => $visit->id, 'created' => $visit->wasRecentlyCreated, 'automation_dispatches' => $dispatches->map->only(['id', 'decision', 'reason_code', 'scheduled_for'])]];
        });
    }
}
