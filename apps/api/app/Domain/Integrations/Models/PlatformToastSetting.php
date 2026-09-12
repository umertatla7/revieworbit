<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['environment', 'api_base_url', 'client_id', 'client_secret', 'partner_webhook_secret', 'orders_webhook_secret', 'marketplace_url', 'status', 'updated_by_user_id', 'verified_at', 'last_health_check_at', 'last_error'])]
class PlatformToastSetting extends Model
{
    use HasUlids;

    protected $hidden = ['client_secret', 'partner_webhook_secret', 'orders_webhook_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'partner_webhook_secret' => 'encrypted',
            'orders_webhook_secret' => 'encrypted',
            'verified_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }
}
