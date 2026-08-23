<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'integration_id', 'initiated_by_user_id', 'state_hash', 'expires_at', 'consumed_at'])]
class SquareOAuthState extends Model
{
    use HasUlids;

    protected $table = 'square_oauth_states';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(PosIntegration::class, 'integration_id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
