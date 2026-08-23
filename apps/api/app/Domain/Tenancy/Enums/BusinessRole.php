<?php

namespace App\Domain\Tenancy\Enums;

enum BusinessRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Viewer = 'viewer';
}
