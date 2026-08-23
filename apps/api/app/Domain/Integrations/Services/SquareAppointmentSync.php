<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Models\CustomerExternalIdentity;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Integrations\Models\SquareAppointment;
use App\Domain\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class SquareAppointmentSync
{
    public function __construct(private readonly SquareConnector $square) {}

    public function sync(PosIntegration $integration): array
    {
        abort_unless($integration->provider === 'square' && $integration->status === 'connected', 422, 'Square is not connected.');

        try {
            $now = CarbonImmutable::now('UTC');
            $from = $now->subDays((int) config('services.square.history_days'));
            $until = $now->addDays((int) config('services.square.upcoming_days'));
            $locations = $this->syncLocations($integration);
            $bookings = $this->allBookings($integration, $from, $until);
            $customers = $this->customers($integration, $bookings);
            $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'past' => 0, 'upcoming' => 0, 'missing_contact' => 0];

            foreach ($bookings as $booking) {
                if (! isset($booking['id'], $booking['start_at'])) {
                    continue;
                }

                $externalCustomerId = $booking['customer_id'] ?? null;
                $customer = $externalCustomerId ? $this->upsertCustomer($integration, $externalCustomerId, $customers[$externalCustomerId] ?? []) : null;
                $startsAt = CarbonImmutable::parse($booking['start_at'])->utc();
                $duration = collect($booking['appointment_segments'] ?? [])->sum(fn (array $segment): int => (int) ($segment['duration_minutes'] ?? 0));
                $appointment = SquareAppointment::updateOrCreate(
                    [
                        'business_id' => $integration->business_id,
                        'integration_id' => $integration->id,
                        'external_booking_id' => $booking['id'],
                    ],
                    [
                        'location_id' => $locations[$booking['location_id'] ?? ''] ?? null,
                        'customer_id' => $customer?->id,
                        'external_location_id' => $booking['location_id'] ?? null,
                        'external_customer_id' => $externalCustomerId,
                        'status' => strtolower((string) ($booking['status'] ?? 'unknown')),
                        'starts_at' => $startsAt,
                        'ends_at' => $duration > 0 ? $startsAt->addMinutes($duration) : null,
                        'service_summary' => collect($booking['appointment_segments'] ?? [])->map(fn (array $segment): array => Arr::only($segment, ['service_variation_id', 'team_member_id', 'duration_minutes']))->values()->all(),
                        'provider_updated_at' => $booking['updated_at'] ?? null,
                        'synced_at' => now(),
                    ],
                );

                $counts['total']++;
                $counts[$appointment->wasRecentlyCreated ? 'created' : 'updated']++;
                $counts[$startsAt->isPast() ? 'past' : 'upcoming']++;
                if (! $customer?->phone_e164 && ! $customer?->email) {
                    $counts['missing_contact']++;
                }
            }

            $summary = [
                ...$counts,
                'locations' => count($locations),
                'range_start' => $from->toIso8601String(),
                'range_end' => $until->toIso8601String(),
            ];
            $integration->update([
                'last_synced_at' => now(),
                'last_health_check_at' => now(),
                'sync_error' => null,
                'sync_summary' => $summary,
            ]);

            return $summary;
        } catch (Throwable $exception) {
            $integration->update([
                'last_health_check_at' => now(),
                'sync_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }
    }

    private function syncLocations(PosIntegration $integration): array
    {
        $response = $this->square->get($integration, '/v2/locations');
        $mapped = [];

        foreach ($response['locations'] ?? [] as $squareLocation) {
            if (! isset($squareLocation['id'])) {
                continue;
            }

            $externalReference = 'square:'.$squareLocation['id'];
            $location = Location::updateOrCreate(
                ['business_id' => $integration->business_id, 'external_reference' => $externalReference],
                [
                    'name' => $squareLocation['name'] ?? $squareLocation['business_name'] ?? 'Square location',
                    'address' => Arr::only($squareLocation['address'] ?? [], ['address_line_1', 'address_line_2', 'locality', 'administrative_district_level_1', 'postal_code', 'country']),
                    'timezone' => $squareLocation['timezone'] ?? $integration->business->default_timezone,
                    'phone' => $squareLocation['phone_number'] ?? null,
                    'status' => ($squareLocation['status'] ?? 'ACTIVE') === 'ACTIVE' ? 'active' : 'inactive',
                ],
            );
            $mapped[$squareLocation['id']] = $location->id;
        }

        $settings = $integration->settings ?? [];
        $settings['square_locations'] = collect($response['locations'] ?? [])->map(fn (array $location): array => Arr::only($location, ['id', 'name', 'status', 'timezone']))->values()->all();
        $integration->update(['settings' => $settings]);

        return $mapped;
    }

    private function allBookings(PosIntegration $integration, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $bookings = [];
        $cursor = null;

        do {
            $query = array_filter([
                'limit' => 100,
                'cursor' => $cursor,
                'start_at_min' => $from->toIso8601String(),
                'start_at_max' => $until->toIso8601String(),
            ]);
            $response = $this->square->get($integration, '/v2/bookings', $query);
            array_push($bookings, ...($response['bookings'] ?? []));
            $cursor = $response['cursor'] ?? null;
        } while ($cursor);

        return $bookings;
    }

    private function customers(PosIntegration $integration, array $bookings): array
    {
        $ids = collect($bookings)->pluck('customer_id')->filter()->unique()->values();
        $customers = [];

        foreach ($ids->chunk(100) as $chunk) {
            $response = $this->square->post($integration, '/v2/customers/bulk-retrieve', ['customer_ids' => $chunk->all()]);
            foreach ($response['responses'] ?? [] as $id => $result) {
                if (isset($result['customer'])) {
                    $customers[$id] = $result['customer'];
                }
            }
        }

        return $customers;
    }

    private function upsertCustomer(PosIntegration $integration, string $externalId, array $profile): Customer
    {
        return DB::transaction(function () use ($integration, $externalId, $profile): Customer {
            $identity = CustomerExternalIdentity::with('customer')
                ->where('business_id', $integration->business_id)
                ->where('provider', 'square')
                ->where('external_customer_id', $externalId)
                ->first();

            $phone = $this->normalizePhone($profile['phone_number'] ?? null);
            $customer = $identity?->customer;
            if (! $customer && $phone) {
                $customer = Customer::where('business_id', $integration->business_id)
                    ->where('phone_hash', hash('sha256', $phone))
                    ->first();
            }

            $attributes = [
                'first_name' => trim((string) ($profile['given_name'] ?? '')) ?: 'Square customer',
                'last_name' => trim((string) ($profile['family_name'] ?? '')) ?: null,
                'email' => $profile['email_address'] ?? null,
                'phone_e164' => $phone,
                'phone_hash' => $phone ? hash('sha256', $phone) : null,
                'source' => $customer?->source ?? 'square',
            ];
            if ($customer) {
                $customer->update($attributes);
            } else {
                $customer = Customer::create(['business_id' => $integration->business_id, ...$attributes]);
            }

            CustomerExternalIdentity::firstOrCreate(
                ['business_id' => $integration->business_id, 'provider' => 'square', 'external_customer_id' => $externalId],
                ['customer_id' => $customer->id],
            );

            return $customer;
        });
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits ? '+'.$digits : null;
    }
}
