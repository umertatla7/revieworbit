<?php

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'location_id', 'customer_id', 'visit_id', 'token_hash', 'destination_url', 'click_count', 'first_clicked_at', 'last_clicked_at', 'expires_at'])]
class ReviewLink extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
