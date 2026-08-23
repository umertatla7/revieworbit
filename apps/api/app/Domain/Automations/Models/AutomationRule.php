<?php

namespace App\Domain\Automations\Models;

use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'location_id', 'message_template_id', 'name', 'trigger_type', 'delay_minutes', 'quiet_hours_start', 'quiet_hours_end', 'frequency_limit_days', 'cancel_follow_up_after_click', 'status'])]
class AutomationRule extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(AutomationFollowUp::class)->orderBy('sequence_number');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(AutomationDispatch::class);
    }

    protected function casts(): array
    {
        return ['delay_minutes' => 'integer', 'frequency_limit_days' => 'integer', 'cancel_follow_up_after_click' => 'boolean'];
    }
}
