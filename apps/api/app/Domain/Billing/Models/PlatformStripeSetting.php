<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['publishable_key', 'secret_key', 'webhook_secret', 'mode', 'status', 'account_id', 'account_name', 'portal_configuration_id', 'updated_by_user_id', 'verified_at', 'last_health_check_at', 'last_error'])]
class PlatformStripeSetting extends Model
{
    use HasUlids;

    protected $hidden = ['secret_key', 'webhook_secret'];

    protected function casts(): array
    {
        return ['secret_key' => 'encrypted', 'webhook_secret' => 'encrypted', 'verified_at' => 'datetime', 'last_health_check_at' => 'datetime'];
    }
}
