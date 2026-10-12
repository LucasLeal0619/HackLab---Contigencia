<?php

namespace App\Domain\Integrations\Enums;

enum IntegrationStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}
