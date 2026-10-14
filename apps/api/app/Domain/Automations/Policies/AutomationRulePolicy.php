<?php

namespace App\Domain\Automations\Policies;

use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Tenancy\Models\BusinessUser;
use App\Models\User;

class AutomationRulePolicy
{
    public function delete(User $user, AutomationRule $rule): bool
    {
        $request = request();
        if ($request->attributes->get('business')?->id !== $rule->business_id) {
            return false;
        }
        if ($request->attributes->get('support_access') === true) {
            return $request->attributes->get('support_session')?->admin_user_id === $user->id
                && $user->platformRoles()->whereIn('role', ['super_admin', 'platform_manager'])->exists();
        }

        return BusinessUser::where('business_id', $rule->business_id)->where('user_id', $user->id)
            ->where('status', 'active')->whereIn('role', ['owner', 'manager'])->exists();
    }
}
