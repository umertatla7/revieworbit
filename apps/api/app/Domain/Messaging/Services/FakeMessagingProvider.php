<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\MessagingConfiguration;
use Illuminate\Support\Str;

class FakeMessagingProvider implements MessagingProvider
{
    public function send(MessagingConfiguration $configuration, array $message): array
    {
        return ['sid' => 'SMFAKE'.Str::upper(Str::random(24)), 'status' => 'sent'];
    }

    public function verify(MessagingConfiguration $configuration): array
    {
        return ['name' => 'Breviews fake provider', 'sid' => $configuration->twilio_messaging_service_sid ?? 'MGFAKE'];
    }
}
