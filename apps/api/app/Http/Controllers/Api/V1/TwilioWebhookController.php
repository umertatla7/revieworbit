<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\TestMessageDelivery;
use App\Domain\Messaging\Services\TwilioSignatureValidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TwilioWebhookController extends Controller
{
    public function status(Request $request, TwilioSignatureValidator $validator): Response
    {
        abort_unless($validator->valid(config('services.twilio.status_callback_url'), $request->all(), $request->header('X-Twilio-Signature')), 403, 'Invalid Twilio signature.');
        $delivery = MessageDelivery::where('provider_message_sid', $request->input('MessageSid'))->first()
            ?? TestMessageDelivery::where('provider_message_sid', $request->input('MessageSid'))->first();
        if (! $delivery) {
            return response('', 204);
        }

        $status = strtolower((string) $request->input('MessageStatus', 'unknown'));
        $attributes = [
            'status' => $status,
            'provider_error_code' => $request->input('ErrorCode') ?: null,
        ];
        if ($status === 'delivered') {
            $attributes['delivered_at'] = now();
        } elseif (in_array($status, ['failed', 'undelivered'], true)) {
            $attributes['failed_at'] = now();
        } elseif ($status === 'sent') {
            $attributes['sent_at'] = now();
        }
        $delivery->update($attributes);

        return response('', 204);
    }

    public function inbound(Request $request, TwilioSignatureValidator $validator, Auditor $auditor): Response
    {
        abort_unless($validator->valid(config('services.twilio.inbound_webhook_url'), $request->all(), $request->header('X-Twilio-Signature')), 403, 'Invalid Twilio signature.');
        $serviceSid = $request->input('MessagingServiceSid');
        $to = $this->phone((string) $request->input('To'));
        $configuration = MessagingConfiguration::query()
            ->where('status', 'active')
            ->where(function ($query) use ($serviceSid, $to): void {
                if ($serviceSid) {
                    $query->where('twilio_messaging_service_sid', $serviceSid);
                } else {
                    $query->where('sms_sender', $to)->orWhere('whatsapp_sender', $to);
                }
            })->first();
        if (! $configuration) {
            return response('', 204);
        }

        $from = $this->phone((string) $request->input('From'));
        $channel = str_starts_with((string) $request->input('From'), 'whatsapp:') ? 'whatsapp' : 'sms';
        $customer = Customer::where('business_id', $configuration->business_id)->where('phone_hash', hash('sha256', $from))->first();
        if (! $customer) {
            return response('', 204);
        }

        $type = strtoupper((string) ($request->input('OptOutType') ?: $request->input('Body')));
        if (in_array($type, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            $suppression = $customer->suppressions()->firstOrCreate(
                ['business_id' => $configuration->business_id, 'channel' => $channel, 'released_at' => null],
                ['phone_e164' => $from, 'reason' => 'opt_out', 'source' => 'twilio', 'suppressed_at' => now()],
            );
            $auditor->record($request, 'messaging.opt_out.received', $suppression, ['channel' => $channel]);
        } elseif (in_array($type, ['START', 'UNSTOP'], true)) {
            $customer->suppressions()->where('channel', $channel)->whereNull('released_at')->update(['released_at' => now()]);
            $consent = $customer->consents()->create(['business_id' => $configuration->business_id, 'channel' => $channel, 'status' => 'granted', 'source' => 'provider', 'recorded_at' => now(), 'consented_at' => now(), 'evidence' => ['provider' => 'twilio', 'event' => 'START']]);
            $auditor->record($request, 'messaging.opt_in.received', $consent, ['channel' => $channel]);
        }

        return response('', 204);
    }

    private function phone(string $value): string
    {
        return str_replace('whatsapp:', '', $value);
    }
}
