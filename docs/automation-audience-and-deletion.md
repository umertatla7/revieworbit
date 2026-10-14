# Manual automation audiences and deletion

Manual audience preview and launch accept `audience_mode`: `new` (default) or `all`.
New means never previously enrolled in this automation, including cancelled or failed
enrollments. Select all eligible to retry those contacts. All mode still enforces consent,
suppression, frequency, pending dispatch and plan checks. Launch recalculates eligibility
under the rule lock; preview is not a reservation. The chosen mode is audited.

DELETE /api/v1/automations/{id} requires owner/manager or authorized platform support.
Deletion archives the tenant-scoped rule, cancels unsent scheduled dispatches and pending
runs, and releases its plan allowance. Sent messages and historical dispatches are retained.
Messages already handed to the provider cannot be recalled. Archived rules cannot be edited
or launched and are omitted from the automation list.

## Bulk customer deletion

The customer list supports individual checkboxes and selecting the current page. Selection
clears on pagination/filter reload. Confirmation displays the selected count.
POST /api/v1/customers/bulk-delete accepts 1–100 distinct customer_ids. All must be active
list records within the authenticated business and authorized for deletion; otherwise the
entire request fails without changes. Each deletion is audited. Customers are archived,
pending sends cancelled, and message/consent/opt-out history retained.
