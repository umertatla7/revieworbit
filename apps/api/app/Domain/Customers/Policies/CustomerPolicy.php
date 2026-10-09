<?php

namespace App\Domain\Customers\Policies;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Models\User;

class CustomerPolicy
{
    public function delete(User $user, Customer $customer): bool
    {
        $request = request();
        if ($request->attributes->get('business')?->id !== $customer->business_id) {
            return false;
        }
        if ($request->attributes->get('support_access') === true) {
            return $request->attributes->get('support_session')?->admin_user_id === $user->id
                && $user->platformRoles()->whereIn('role', ['super_admin', 'platform_manager'])->exists();
        }

        return BusinessUser::where('business_id', $customer->business_id)->where('user_id', $user->id)
            ->where('status', 'active')->whereIn('role', ['owner', 'manager'])->exists();
    }
}
