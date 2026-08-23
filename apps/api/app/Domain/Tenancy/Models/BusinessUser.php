<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\BusinessRole;
use App\Domain\Tenancy\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'user_id', 'role', 'status'])]
class BusinessUser extends Model
{
    use HasFactory, HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
            'status' => RecordStatus::class,
        ];
    }
}
