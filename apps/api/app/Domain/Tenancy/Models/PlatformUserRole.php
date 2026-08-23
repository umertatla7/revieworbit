<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'role'])]
class PlatformUserRole extends Model
{
    use HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['role' => PlatformRole::class];
    }
}
