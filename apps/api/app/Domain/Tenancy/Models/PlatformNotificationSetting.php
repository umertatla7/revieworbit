<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PlatformNotificationSetting extends Model
{
    use HasUlids;

    protected $fillable = ['registration_email'];
}
