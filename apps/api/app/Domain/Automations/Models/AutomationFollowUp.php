<?php

namespace App\Domain\Automations\Models;

use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['automation_rule_id', 'message_template_id', 'sequence_number', 'delay_minutes', 'cancel_after_click', 'status'])]
class AutomationFollowUp extends Model
{
    use HasUlids;

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    protected function casts(): array
    {
        return ['sequence_number' => 'integer', 'delay_minutes' => 'integer', 'cancel_after_click' => 'boolean'];
    }
}
