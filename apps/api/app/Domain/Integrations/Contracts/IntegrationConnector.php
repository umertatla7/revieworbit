<?php

namespace App\Domain\Integrations\Contracts;

interface IntegrationConnector
{
    public function configured(?string $environment = null): bool;
}
