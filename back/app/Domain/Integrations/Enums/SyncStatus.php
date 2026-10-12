<?php

namespace App\Domain\Integrations\Enums;

enum SyncStatus: string
{
    case Running = 'RUNNING';
    case Success = 'SUCCESS';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
}
