<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
