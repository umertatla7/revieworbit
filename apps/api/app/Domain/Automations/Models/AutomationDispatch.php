<?php

namespace App\Domain\Automations\Models;

use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['business_id', 'visit_id', 'automation_rule_id', 'sequence_number', 'decision', 'reason_code', 'scheduled_for', 'decision_context'])]
class AutomationDispatch extends Model
{
    use HasUlids;

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(MessageDelivery::class);
    }

    protected function casts(): array
    {
        return ['sequence_number' => 'integer', 'scheduled_for' => 'datetime', 'decision_context' => 'array'];
    }
}
