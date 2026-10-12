<?php

namespace App\Domain\Integrations\Enums;

enum AttendanceSource: string
{
    case Webhook = 'WEBHOOK';
    case Api = 'API';
}
