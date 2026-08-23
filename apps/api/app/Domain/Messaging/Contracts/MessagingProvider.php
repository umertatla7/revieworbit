<?php

namespace App\Domain\Messaging\Contracts;

use App\Domain\Messaging\Models\MessagingConfiguration;

interface MessagingProvider
{
    public function send(MessagingConfiguration $configuration, array $message): array;

    public function verify(MessagingConfiguration $configuration): array;
}
