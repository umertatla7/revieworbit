<?php

namespace App\Domain\Customers\Services;

use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;

class DeleteCustomer
{
    public function handle(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer->update(['status' => 'archived']);
            AutomationDispatch::where('business_id', $customer->business_id)
                ->where('decision', 'scheduled')->whereDoesntHave('delivery')
                ->where(fn ($query) => $query->where('customer_id', $customer->id)
                    ->orWhereHas('visit', fn ($visit) => $visit->where('customer_id', $customer->id)))
                ->update(['decision' => 'cancelled', 'reason_code' => 'customer_deleted']);
        });
    }
}
