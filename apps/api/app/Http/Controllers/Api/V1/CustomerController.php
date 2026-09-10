<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $customers = Customer::query()
            ->where('business_id', $business->id)
            ->with([
                'consents' => fn ($query) => $query->latest('recorded_at'),
                'suppressions' => fn ($query) => $query->whereNull('released_at'),
                'visits' => fn ($query) => $query->with('location:id,name')->latest('completed_at')->limit(1),
                'reviewLinks' => fn ($query) => $query->with(['location:id,name', 'destination:id,provider'])->latest()->limit(1),
            ])
            ->withCount(['visits', 'reviewLinks', 'reviewLinks as clicked_review_links_count' => fn ($query) => $query->whereNotNull('first_clicked_at')])
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(fn ($nested) => $nested->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('source')->toString(), fn ($query, string $source) => $query->where('source', $source))
            ->when($request->string('visit')->toString(), function ($query, string $filter): void {
                match ($filter) {
                    'has_visits' => $query->has('visits'),
                    'no_visits' => $query->doesntHave('visits'),
                    'recent_30' => $query->whereHas('visits', fn ($visit) => $visit->where('completed_at', '>=', now()->subDays(30))),
                    'inactive_90' => $query->whereDoesntHave('visits', fn ($visit) => $visit->where('completed_at', '>=', now()->subDays(90))),
                    default => null,
                };
            })
            ->when($request->string('link')->toString(), function ($query, string $filter): void {
                match ($filter) {
                    'clicked' => $query->whereHas('reviewLinks', fn ($link) => $link->whereNotNull('first_clicked_at')),
                    'not_clicked' => $query->whereHas('reviewLinks')
                        ->whereDoesntHave('reviewLinks', fn ($link) => $link->whereNotNull('first_clicked_at')),
                    'no_link' => $query->doesntHave('reviewLinks'),
                    default => null,
                };
            })
            ->when($request->string('sms')->toString(), function ($query, string $filter): void {
                match ($filter) {
                    'consented' => $query->whereHas('latestSmsConsent', fn ($consent) => $consent->where('status', 'granted'))
                        ->whereDoesntHave('suppressions', fn ($suppression) => $suppression->where('channel', 'sms')->whereNull('released_at')),
                    'suppressed' => $query->whereHas('suppressions', fn ($suppression) => $suppression->where('channel', 'sms')->whereNull('released_at')),
                    'not_recorded' => $query->whereDoesntHave('latestSmsConsent', fn ($consent) => $consent->where('status', 'granted')),
                    default => null,
                };
            })
            ->latest()
            ->paginate(25);

        return response()->json(['data' => $customers->items(), 'meta' => ['current_page' => $customers->currentPage(), 'last_page' => $customers->lastPage(), 'total' => $customers->total()]]);
    }

    public function store(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $this->validated($request);
        $customer = $this->createCustomer($business->id, $data);
        $auditor->record($request, 'customer.created', $customer, ['source' => $customer->source]);

        return response()->json(['data' => $customer->load(['consents', 'suppressions'])], 201);
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        return response()->json(['data' => $this->scoped($request, $customer)->load([
            'consents' => fn ($query) => $query->latest('recorded_at'),
            'suppressions' => fn ($query) => $query->latest('suppressed_at'),
            'visits' => fn ($query) => $query->with(['location:id,name', 'reviewLinks.destination:id,provider', 'messageDeliveries.template:id,name'])->latest('completed_at'),
            'reviewLinks' => fn ($query) => $query->with(['location:id,name', 'destination:id,provider', 'deliveries.template:id,name'])->latest(),
            'messageDeliveries' => fn ($query) => $query->with(['template:id,name', 'reviewLink:id,first_clicked_at,click_count'])->latest(),
        ])]);
    }

    public function update(Request $request, string $customer, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $customer);
        $data = $this->validated($request, true);
        if (array_key_exists('phone', $data)) {
            $data = [...$data, ...$this->phoneFields($data['phone'])];
            unset($data['phone']);
        }
        $model->update($data);
        $auditor->record($request, 'customer.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh(['consents', 'suppressions'])]);
    }

    public function consent(Request $request, string $customer, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $customer);
        $data = $request->validate([
            'channel' => ['sometimes', Rule::in(['sms', 'whatsapp'])],
            'status' => ['required', Rule::in(['granted', 'revoked'])],
            'source' => ['required', Rule::in(['written', 'verbal', 'web_form', 'import', 'provider'])],
            'disclosure_version' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'array'],
        ]);
        $consent = $model->consents()->create([...$data, 'business_id' => $model->business_id, 'channel' => $data['channel'] ?? 'sms', 'recorded_at' => now(), 'consented_at' => $data['status'] === 'granted' ? now() : null, 'revoked_at' => $data['status'] === 'revoked' ? now() : null]);
        $auditor->record($request, 'customer.consent_recorded', $consent, ['status' => $data['status'], 'source' => $data['source']]);

        return response()->json(['data' => $consent], 201);
    }

    public function suppress(Request $request, string $customer, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $customer);
        $data = $request->validate(['channel' => ['sometimes', Rule::in(['sms', 'whatsapp'])], 'reason' => ['required', Rule::in(['opt_out', 'complaint', 'invalid_number', 'manual'])]]);
        $entry = $model->suppressions()->firstOrCreate(
            ['business_id' => $model->business_id, 'channel' => $data['channel'] ?? 'sms', 'released_at' => null],
            ['phone_e164' => $model->phone_e164, 'reason' => $data['reason'], 'source' => 'manual', 'suppressed_at' => now()]
        );
        $auditor->record($request, 'customer.suppressed', $entry, ['reason' => $entry->reason]);

        return response()->json(['data' => $entry], 201);
    }

    public function releaseSuppression(Request $request, string $customer, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $customer);
        $data = $request->validate(['channel' => ['sometimes', Rule::in(['sms', 'whatsapp'])]]);
        $entry = $model->suppressions()
            ->where('channel', $data['channel'] ?? 'sms')
            ->whereNull('released_at')
            ->latest('suppressed_at')
            ->firstOrFail();
        $entry->update(['released_at' => now()]);
        $auditor->record($request, 'customer.suppression_released', $entry, ['channel' => $entry->channel]);

        return response()->json(['data' => $entry->fresh()]);
    }

    public function import(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $data = $request->validate(['customers' => ['required', 'array', 'min:1', 'max:500'], 'customers.*.first_name' => ['required', 'string', 'max:100'], 'customers.*.last_name' => ['nullable', 'string', 'max:100'], 'customers.*.email' => ['nullable', 'email'], 'customers.*.phone' => ['required', 'string']]);
        $created = 0;
        $skipped = [];
        foreach ($data['customers'] as $index => $row) {
            try {
                $this->createCustomer($business->id, [...$row, 'source' => 'import']);
                $created++;
            } catch (ValidationException|UniqueConstraintViolationException $exception) {
                $skipped[] = ['row' => $index + 1, 'reason' => 'Invalid or duplicate customer'];
            }
        }

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]], 202);
    }

    public function importCsv(Request $request, Auditor $auditor): JsonResponse
    {
        $business = $request->attributes->get('business');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'preview' => ['sometimes', 'boolean']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        abort_unless(is_resource($handle), 422, 'The CSV file could not be read.');
        $headers = array_map(fn ($value): string => strtolower(trim((string) $value)), fgetcsv($handle, null, ',', '"', '') ?: []);
        $required = ['first_name', 'phone'];
        if (array_diff($required, $headers) !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => ['CSV headers must include first_name and phone. Optional headers are last_name and email.']]);
        }

        $rows = [];
        $errors = [];
        $line = 1;
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($line > 501) {
                $errors[] = ['row' => $line, 'reason' => 'The import is limited to 500 customers.'];
                break;
            }
            if (count($values) !== count($headers)) {
                $errors[] = ['row' => $line, 'reason' => 'Column count does not match the header.'];

                continue;
            }
            $row = array_combine($headers, $values);
            $validator = Validator::make($row, ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email'], 'phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/']]);
            if ($validator->fails()) {
                $errors[] = ['row' => $line, 'reason' => $validator->errors()->first()];

                continue;
            }
            $rows[] = $validator->validated();
        }
        fclose($handle);

        if ($request->boolean('preview', true)) {
            return response()->json(['data' => ['valid_rows' => count($rows), 'preview' => array_slice($rows, 0, 10), 'errors' => $errors]]);
        }

        $created = 0;
        foreach ($rows as $index => $row) {
            try {
                $this->createCustomer($business->id, [...$row, 'source' => 'import']);
                $created++;
            } catch (UniqueConstraintViolationException) {
                $errors[] = ['row' => $index + 2, 'reason' => 'Duplicate phone number for this business.'];
            }
        }

        $auditor->record($request, 'customers.csv_imported', $business, ['created' => $created, 'skipped' => count($errors)]);

        return response()->json(['data' => ['created' => $created, 'skipped' => count($errors), 'errors' => $errors]], 202);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => [$required, 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'source' => ['sometimes', Rule::in(['manual', 'import', 'square'])],
        ]);
    }

    private function createCustomer(string $businessId, array $data): Customer
    {
        $phone = $this->phoneFields($data['phone']);
        unset($data['phone']);

        return Customer::create([...$data, ...$phone, 'business_id' => $businessId]);
    }

    private function phoneFields(?string $phone): array
    {
        if ($phone === null || $phone === '') {
            return ['phone_e164' => null, 'phone_hash' => null];
        }
        $normalized = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized)) {
            throw ValidationException::withMessages(['phone' => ['Use E.164 format, for example +12025550123.']]);
        }

        return ['phone_e164' => $normalized, 'phone_hash' => hash('sha256', $normalized)];
    }

    private function scoped(Request $request, string $id): Customer
    {
        return Customer::where('business_id', $request->attributes->get('business')->id)->findOrFail($id);
    }
}
