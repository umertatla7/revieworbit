<?php

namespace App\Domain\Tenancy\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case PlatformManager = 'platform_manager';
}
