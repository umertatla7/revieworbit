<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Enums\RecordStatus;
use App\Domain\Tenancy\Models\AdminSupportSession;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\BusinessUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $businessId = $request->header('X-Business-ID');
        abort_unless(is_string($businessId) && $businessId !== '', 400, 'X-Business-ID is required.');

        $plainToken = $request->header('X-Support-Session');
        if (is_string($plainToken) && $plainToken !== '') {
            $supportSession = AdminSupportSession::query()
                ->with('business')
                ->where('business_id', $businessId)
                ->where('admin_user_id', $request->user()->getAuthIdentifier())
                ->where('token_hash', hash('sha256', $plainToken))
                ->whereNull('ended_at')
                ->where('expires_at', '>', now())
                ->first();

            abort_unless($supportSession, 401, 'The admin support session has expired. Start a new support session.');

            abort_unless($request->user()->platformRoles()->whereIn('role', ['super_admin', 'platform_manager'])->exists(), 403);
            $request->attributes->set('business', $supportSession->business);
            $request->attributes->set('support_session', $supportSession);
            $request->attributes->set('support_access', true);

            return $this->continueForActiveBusiness($request, $next, $supportSession->business);
        }

        $membership = BusinessUser::query()
            ->with('business')
            ->where('business_id', $businessId)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', RecordStatus::Active->value)
            ->first();

        if (! $membership) {
            abort(404);
        }

        $request->attributes->set('business', $membership->business);
        $request->attributes->set('membership', $membership);

        return $this->continueForActiveBusiness($request, $next, $membership->business);
    }

    private function continueForActiveBusiness(Request $request, Closure $next, Business $business): Response
    {
        abort_unless($business->status === RecordStatus::Active, 403, 'Business is not active.');

        return $next($request);
    }
}
