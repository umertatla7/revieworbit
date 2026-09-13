<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Customers\Models\Customer;
use App\Domain\Integrations\Models\PosIntegration;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'legal_name', 'industry', 'slug', 'status', 'plan_code', 'default_timezone', 'default_country', 'primary_email', 'phone', 'website_url', 'account_notes', 'logo_path', 'brand_settings', 'onboarding_status', 'onboarding_step', 'operation_mode', 'messaging_preferences', 'consent_confirmed_at', 'onboarding_completed_at'])]
#[Hidden(['account_notes'])]
class Business extends Model
{
    use HasFactory, HasUlids;

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function posIntegrations(): HasMany
    {
        return $this->hasMany(PosIntegration::class);
    }

    public function messagingConfiguration(): HasOne
    {
        return $this->hasOne(MessagingConfiguration::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class);
    }

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'brand_settings' => 'array',
            'messaging_preferences' => 'array',
            'consent_confirmed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }
}
