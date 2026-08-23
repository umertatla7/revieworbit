<?php

namespace App\Http\Controllers;

use App\Domain\Messaging\Models\ReviewLink;
use Illuminate\Http\RedirectResponse;

class ReviewLinkController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        $link = ReviewLink::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->firstOrFail();
        $link->increment('click_count');
        $link->update(['first_clicked_at' => $link->first_clicked_at ?? now(), 'last_clicked_at' => now()]);

        return redirect()->away($link->destination_url);
    }
}
