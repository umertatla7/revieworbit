<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\BusinessRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'invited_by_user_id', 'accepted_by_user_id', 'email', 'role', 'token_hash', 'status', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class BusinessInvitation extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
