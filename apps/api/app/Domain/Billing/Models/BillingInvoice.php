<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'stripe_invoice_id', 'number', 'status', 'amount_due_minor', 'amount_paid_minor', 'currency', 'hosted_invoice_url', 'invoice_pdf_url', 'period_starts_at', 'period_ends_at', 'paid_at'])]
class BillingInvoice extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['period_starts_at' => 'datetime', 'period_ends_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
