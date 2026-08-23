<?php

namespace App\Domain\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'admin_user_id', 'token_hash', 'reason', 'expires_at', 'ended_at'])]
#[Hidden(['token_hash'])]
class AdminSupportSession extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
