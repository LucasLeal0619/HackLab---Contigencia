<?php

namespace App\Domain\Integrations\Enums;

enum AttendanceKind: string
{
    case Event = 'EVENT';
    case Activity = 'ACTIVITY';
}
