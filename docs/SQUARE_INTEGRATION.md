# Square Appointments Integration

## Implemented first step

ReviewOrbit pins Square API version `2026-07-15`. A business owner or manager opens **POS & integrations**, chooses Sandbox or production, and selects **Connect Square account**. Platform administrators can do the same on a customer's behalf only after entering an audited, one-hour support session for that tenant.

OAuth requests only `MERCHANT_PROFILE_READ`, `CUSTOMERS_READ`, `APPOINTMENTS_READ`, and `APPOINTMENTS_ALL_READ`. ReviewOrbit does not request appointment write permissions. State values are random, stored only as SHA-256 hashes, expire after ten minutes, and are consumed once. Access and refresh tokens are encrypted by Laravel's application encrypter, hidden from model serialization, refreshed on the server, and never sent to Next.js.

After authorization, an initial queued import starts. A user can also select **Sync appointments now** for an immediate reconciliation. The default range is 365 days before and after the current time and can be changed with `SQUARE_HISTORY_DAYS` and `SQUARE_UPCOMING_DAYS`. Pagination is followed until Square omits its cursor.

The importer:

- imports Square locations into tenant-owned locations using `square:{location_id}` external references;
- retrieves Square customer profiles in bulk batches of at most 100;
- resolves customers through business-scoped Square external identities or a tenant-scoped normalized phone hash;
- upserts normalized appointments idempotently by business, connection, and Square booking ID;
- stores start/end time, booking status, location, customer, and minimal service/team identifiers;
- reports previous, upcoming, missing-contact, and location counts; and
- retains imported history when a seller disconnects.

Square data never creates messaging consent. A booking is scheduling context, not proof that a visit occurred, and therefore does not create a `visit`, evaluate an automation, or schedule a message.

## Local configuration

Create Sandbox and production applications in the Square Developer Console. Configure the exact OAuth redirect URL for each environment. For Herd, the callback is normally:

```text
https://api.revieworbit.test/api/v1/integrations/square/callback
```

Set these server-only values in `apps/api/.env`:

```dotenv
WEB_URL=https://revieworbit.test
SQUARE_ENVIRONMENT=sandbox
SQUARE_API_VERSION=2026-07-15
SQUARE_APPLICATION_ID=your_sandbox_application_id
SQUARE_APPLICATION_SECRET=your_sandbox_application_secret
SQUARE_REDIRECT_URI=https://api.revieworbit.test/api/v1/integrations/square/callback
SQUARE_HISTORY_DAYS=365
SQUARE_UPCOMING_DAYS=365
```

Then run `php artisan config:clear` and `php artisan migrate`. Do not add secrets to Git. Production uses the production application ID/secret and the same HTTPS callback pattern.

Square seller-level calendar visibility requires `APPOINTMENTS_READ` plus `APPOINTMENTS_ALL_READ`. Availability can depend on the seller's Square Appointments capabilities and plan; ReviewOrbit surfaces Square API errors as connection sync errors rather than silently importing a partial calendar.

## Next Square milestone

The next slice adds `booking.created` and `booking.updated` webhook intake, exact raw-body HMAC-SHA256 validation using the configured notification URL and signature key, durable event storage, idempotent queued processing, and scheduled reconciliation. Completion-trigger semantics remain separate: an appointment webhook alone will never claim that a service was completed.

## Official references

- [Square OAuth API](https://developer.squareup.com/reference/square/oauth-api)
- [List Bookings](https://developer.squareup.com/reference/square/bookings-api/list-bookings)
- [Bookings API permissions and limitations](https://developer.squareup.com/docs/bookings-api/what-it-is)
- [List Locations](https://developer.squareup.com/reference/square/locations-api/ListLocations)
- [Bulk Retrieve Customers](https://developer.squareup.com/reference/square/customers-api/BulkRetrieveCustomers)
- [Square webhook validation](https://developer.squareup.com/docs/webhooks/step3validate)
