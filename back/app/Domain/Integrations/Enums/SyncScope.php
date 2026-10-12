<?php

namespace App\Domain\Integrations\Enums;

enum SyncScope: string
{
    case Event = 'EVENT';
    case Tickets = 'TICKETS';
    case Attendees = 'ATTENDEES';
    case All = 'ALL';
}
