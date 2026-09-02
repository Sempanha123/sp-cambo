<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Disabled = 'DISABLED';
}
