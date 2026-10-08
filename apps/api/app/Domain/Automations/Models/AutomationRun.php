<?php

namespace App\Domain\Automations\Models;

use App\Domain\Tenancy\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'automation_rule_id', 'requested_by_user_id', 'status', 'audience_count', 'scheduled_count', 'skipped_count', 'skipped_summary', 'started_at', 'completed_at'])]
class AutomationRun extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(AutomationDispatch::class);
    }

    protected function casts(): array
    {
        return [
            'audience_count' => 'integer',
            'scheduled_count' => 'integer',
            'skipped_count' => 'integer',
            'skipped_summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
