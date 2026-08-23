<?php

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'actor_user_id', 'action', 'target_type', 'target_id', 'changes', 'request_id', 'created_at'])]
class AuditLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
