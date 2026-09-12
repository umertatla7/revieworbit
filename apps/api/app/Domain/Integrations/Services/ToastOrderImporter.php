<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Automations\Services\AutomationEvaluator;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerExternalIdentity;
use App\Domain\Integrations\Models\ToastRestaurantConnection;
use App\Domain\Visits\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ToastOrderImporter
{
    public function __construct(private readonly AutomationEvaluator $evaluator) {}

    public function import(ToastRestaurantConnection $connection, array $order): array
    {
        $orderGuid = $order['guid'] ?? null;
        if (! is_string($orderGuid) || $orderGuid === '') {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1, 'missing_contact' => 0];
        }

        if (($order['voided'] ?? false) || ($order['deleted'] ?? false)) {
            $visits = Visit::where('business_id', $connection->business_id)
                ->where('source', 'toast')->where('external_order_id', $orderGuid)->get();
            foreach ($visits as $visit) {
                $visit->update(['status' => 'voided']);
                AutomationDispatch::where('visit_id', $visit->id)->where('decision', 'scheduled')->update([
                    'decision' => 'skipped', 'reason_code' => 'visit_voided', 'scheduled_for' => null,
                ]);
            }

            return ['created' => 0, 'updated' => $visits->count(), 'skipped' => 0, 'missing_contact' => 0];
        }

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing_contact' => 0];
        foreach ($order['checks'] ?? [] as $check) {
            if (! is_array($check) || empty($check['guid']) || ($check['voided'] ?? false) || ($check['deleted'] ?? false)) {
                continue;
            }
            $completedAt = $check['closedDate'] ?? $order['closedDate'] ?? null;
            if (! $completedAt) {
                $counts['skipped']++;

                continue;
            }

            $customer = $this->upsertCustomer($connection, is_array($check['customer'] ?? null) ? $check['customer'] : []);
            $externalVisitId = $orderGuid.':'.$check['guid'];
            $visit = Visit::where('business_id', $connection->business_id)
                ->where('source', 'toast')->where('external_visit_id', $externalVisitId)->first();
            $created = ! $visit;
            $attributes = [
                'location_id' => $connection->location_id,
                'customer_id' => $customer?->id,
                'external_order_id' => $orderGuid,
                'external_payment_id' => collect($check['payments'] ?? [])->pluck('guid')->filter()->first(),
                'type' => 'sale',
                'status' => 'completed',
                'amount' => is_numeric($check['amount'] ?? null) ? $check['amount'] : null,
                'currency' => null,
                'completed_at' => CarbonImmutable::parse($completedAt)->utc(),
                'raw_metadata' => [
                    'provider' => 'toast',
                    'restaurant_guid' => $connection->restaurant_guid,
                    'order_guid' => $orderGuid,
                    'check_guid' => $check['guid'],
                    'source' => $order['source'] ?? null,
                ],
            ];
            $visit = $visit
                ? tap($visit)->update($attributes)
                : Visit::create(['business_id' => $connection->business_id, 'source' => 'toast', 'external_visit_id' => $externalVisitId, ...$attributes]);
            $counts[$created ? 'created' : 'updated']++;
            if (! $customer?->phone_e164 && ! $customer?->email) {
                $counts['missing_contact']++;
            }
            if ($created) {
                AutomationRule::where('business_id', $connection->business_id)
                    ->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $connection->location_id))
                    ->get()->each(fn (AutomationRule $rule) => $this->evaluator->evaluate($visit, $rule));
            }
        }

        return $counts;
    }

    private function upsertCustomer(ToastRestaurantConnection $connection, array $profile): ?Customer
    {
        $externalId = is_string($profile['guid'] ?? null) ? $profile['guid'] : null;
        $email = filter_var($profile['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
        $phone = $this->normalizePhone($profile['phone'] ?? $profile['phoneNumber'] ?? null);
        if (! $externalId && ! $email && ! $phone) {
            return null;
        }

        return DB::transaction(function () use ($connection, $profile, $externalId, $email, $phone): Customer {
            $customer = $externalId
                ? CustomerExternalIdentity::with('customer')->where('business_id', $connection->business_id)
                    ->where('provider', 'toast')->where('external_customer_id', $externalId)->first()?->customer
                : null;
            $customer ??= $phone ? Customer::where('business_id', $connection->business_id)->where('phone_hash', hash('sha256', $phone))->first() : null;
            $customer ??= $email ? Customer::where('business_id', $connection->business_id)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first() : null;

            $attributes = [
                'first_name' => trim((string) ($profile['firstName'] ?? '')) ?: ($customer?->first_name ?? 'Toast guest'),
                'last_name' => trim((string) ($profile['lastName'] ?? '')) ?: $customer?->last_name,
                'email' => $email ?? $customer?->email,
                'phone_e164' => $phone ?? $customer?->phone_e164,
                'phone_hash' => ($phone ?? $customer?->phone_e164) ? hash('sha256', $phone ?? $customer->phone_e164) : null,
                'source' => $customer?->source ?? 'toast',
            ];
            if ($customer) {
                $customer->update($attributes);
            } else {
                $customer = Customer::create(['business_id' => $connection->business_id, ...$attributes]);
            }
            if ($externalId) {
                CustomerExternalIdentity::firstOrCreate(
                    ['business_id' => $connection->business_id, 'provider' => 'toast', 'external_customer_id' => $externalId],
                    ['customer_id' => $customer->id],
                );
            }

            return $customer;
        });
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return $digits && strlen($digits) >= 8 ? '+'.$digits : null;
    }
}
