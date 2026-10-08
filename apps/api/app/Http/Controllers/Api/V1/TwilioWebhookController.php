<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Automations\Models\AutomationDispatch;
use App\Domain\Customers\Models\Customer;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\MessagingConfiguration;
use App\Domain\Messaging\Models\PlatformTwilioSetting;
use App\Domain\Messaging\Models\TestMessageDelivery;
use App\Domain\Messaging\Services\TwilioConnectionResolver;
use App\Domain\Messaging\Services\TwilioSignatureValidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TwilioWebhookController extends Controller
{
    public function status(Request $request, TwilioSignatureValidator $validator, TwilioConnectionResolver $connections): Response
    {
        $delivery = MessageDelivery::where('provider_message_sid', $request->input('MessageSid'))->first()
            ?? TestMessageDelivery::where('provider_message_sid', $request->input('MessageSid'))->first();
        if (! $delivery) {
            return response('', 204);
        }
        $configuration = MessagingConfiguration::where('business_id', $delivery->business_id)->first();
        abort_unless($configuration, 403, 'Invalid Twilio signature.');
        try {
            $connection = $connections->resolve($configuration);
            $validSignature = $validator->validWithToken(config('services.twilio.status_callback_url'), $request->all(), $request->header('X-Twilio-Signature'), $connection['auth_secret']);
        } catch (\Throwable) {
            $validSignature = $configuration->sender_mode === 'platform_dedicated'
                && $validator->valid(config('services.twilio.status_callback_url'), $request->all(), $request->header('X-Twilio-Signature'));
        }
        abort_unless($validSignature, 403, 'Invalid Twilio signature.');

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

    public function inbound(Request $request, TwilioSignatureValidator $validator, TwilioConnectionResolver $connections, Auditor $auditor): Response
    {
        $serviceSid = $request->input('MessagingServiceSid');
        $to = $this->phone((string) $request->input('To'));
        $platform = PlatformTwilioSetting::query()->latest()->first();
        $configurations = MessagingConfiguration::query()
            ->where('status', 'active')
            ->where(function ($query) use ($serviceSid, $to): void {
                if ($serviceSid) {
                    $query->where('twilio_messaging_service_sid', $serviceSid);
                } else {
                    $query->where('sms_sender', $to)->orWhere('whatsapp_sender', $to);
                }
            })
            ->get();
        if ($platform?->shared_sender_enabled
            && (($serviceSid && $serviceSid === $platform->shared_messaging_service_sid) || (! $serviceSid && $to === $platform->shared_sms_sender))) {
            $configurations = $configurations->concat(
                MessagingConfiguration::where('status', 'active')->where('sender_mode', 'platform_shared')->get(),
            )->unique('id')->values();
        }
        if ($configurations->isEmpty()) {
            return response('', 204);
        }
        try {
            $connection = $connections->resolve($configurations->first());
            $validSignature = $validator->validWithToken(config('services.twilio.inbound_webhook_url'), $request->all(), $request->header('X-Twilio-Signature'), $connection['auth_secret']);
        } catch (\Throwable) {
            $validSignature = $configurations->first()->sender_mode === 'platform_dedicated'
                && $validator->valid(config('services.twilio.inbound_webhook_url'), $request->all(), $request->header('X-Twilio-Signature'));
        }
        abort_unless($validSignature, 403, 'Invalid Twilio signature.');

        $from = $this->phone((string) $request->input('From'));
        $channel = str_starts_with((string) $request->input('From'), 'whatsapp:') ? 'whatsapp' : 'sms';
        $type = strtoupper(trim((string) ($request->input('OptOutType') ?: $request->input('Body'))));
        foreach ($configurations as $configuration) {
            $customer = Customer::where('business_id', $configuration->business_id)->where('phone_hash', hash('sha256', $from))->first();
            if (! $customer) {
                continue;
            }
            if (in_array($type, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'], true)) {
                $suppression = $customer->suppressions()->firstOrCreate(
                    ['business_id' => $configuration->business_id, 'channel' => $channel, 'released_at' => null],
                    ['phone_e164' => $from, 'reason' => 'opt_out', 'source' => 'twilio', 'suppressed_at' => now()],
                );
                AutomationDispatch::where('business_id', $configuration->business_id)
                    ->where('decision', 'scheduled')->whereDoesntHave('delivery')
                    ->where(fn ($query) => $query->where('customer_id', $customer->id)
                        ->orWhereHas('visit', fn ($visit) => $visit->where('customer_id', $customer->id)))
                    ->update(['decision' => 'cancelled', 'reason_code' => 'customer_opted_out']);
                $auditor->record($request, 'messaging.opt_out.received', $suppression, ['channel' => $channel]);
            } elseif (in_array($type, ['START', 'UNSTOP'], true)) {
                $customer->suppressions()->where('channel', $channel)->whereNull('released_at')->update(['released_at' => now()]);
                $consent = $customer->consents()->create(['business_id' => $configuration->business_id, 'channel' => $channel, 'status' => 'granted', 'source' => 'provider', 'recorded_at' => now(), 'consented_at' => now(), 'evidence' => ['provider' => 'twilio', 'event' => 'START']]);
                $auditor->record($request, 'messaging.opt_in.received', $consent, ['channel' => $channel]);
            }
        }

        return response('', 204);
    }

    private function phone(string $value): string
    {
        return str_replace('whatsapp:', '', $value);
    }
}
