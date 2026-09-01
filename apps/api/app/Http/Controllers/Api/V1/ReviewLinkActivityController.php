<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\ReviewLink;
use App\Domain\Messaging\Services\ManualReviewMessageSender;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewLinkActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $query = ReviewLink::where('business_id', $businessId)
            ->with(['customer:id,first_name,last_name', 'location:id,name', 'destination:id,provider', 'visit:id,completed_at,source', 'deliveries.template:id,name'])
            ->latest();
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->string('location_id'));
        }
        $links = $query->paginate(50);
        $sent = MessageDelivery::where('business_id', $businessId)->whereIn('status', ['queued', 'sent', 'delivered'])->count();
        $clicked = ReviewLink::where('business_id', $businessId)->whereNotNull('first_clicked_at')->count();
        $total = ReviewLink::where('business_id', $businessId)->count();

        return response()->json(['data' => $links->items(), 'meta' => [
            'total' => $total,
            'messages_sent' => $sent,
            'links_clicked' => $clicked,
            'click_rate' => $total ? round(($clicked / $total) * 100, 1) : 0,
        ]]);
    }

    public function resend(Request $request, string $reviewLink, ManualReviewMessageSender $sender, Auditor $auditor): JsonResponse
    {
        $link = ReviewLink::where('business_id', $request->attributes->get('business')->id)->findOrFail($reviewLink);
        $data = $request->validate(['body' => ['required', 'string', 'min:10', 'max:1500']]);
        $delivery = $sender->send($link, $data['body'], $request->user()->id);
        $auditor->record($request, 'review_link.message_resent', $delivery, ['source_review_link_id' => $link->id, 'channel' => $delivery->channel]);

        return response()->json(['data' => $delivery], 202);
    }
}
