<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'host',
    'port',
    'encryption',
    'username',
    'password',
    'from_address',
    'from_name',
    'enabled',
    'status',
    'updated_by_user_id',
    'verified_at',
    'last_tested_at',
    'last_error',
])]
class PlatformMailSetting extends Model
{
    use HasUlids;

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'enabled' => 'boolean',
            'verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }
}
