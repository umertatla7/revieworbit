<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Auditor
{
    public function record(Request $request, string $action, Model $target, array $changes = []): void
    {
        AuditLog::create([
            'business_id' => $target instanceof Business ? $target->getKey() : $target->getAttribute('business_id'),
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'target_type' => $target->getMorphClass(),
            'target_id' => (string) $target->getKey(),
            'changes' => $changes ?: null,
            'request_id' => $request->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
