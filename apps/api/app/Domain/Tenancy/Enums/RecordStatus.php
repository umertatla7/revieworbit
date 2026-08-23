<?php

namespace App\Domain\Tenancy\Enums;

enum RecordStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
}
