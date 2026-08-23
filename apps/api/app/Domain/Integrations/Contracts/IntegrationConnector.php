<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\PosIntegration;

interface IntegrationConnector
{
    public function authorizationUrl(string $environment, string $state): string;

    public function exchangeAuthorizationCode(string $environment, string $code): array;

    public function disconnect(PosIntegration $integration): void;
}
