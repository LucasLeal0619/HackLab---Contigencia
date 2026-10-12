<?php

namespace App\Domain\Integrations\Enums;

enum SyncTrigger: string
{
    case Manual = 'MANUAL';
    case Scheduled = 'SCHEDULED';
    case Reconciliation = 'RECONCILIATION';
}
