<?php

namespace App\Domain\Voting\Enums;

/**
 * DRAFT → OPEN → CLOSED; DRAFT (ou OPEN sem votos) → CANCELLED. CLOSED e CANCELLED são definitivos.
 */
enum VotingSessionStatus: string
{
    case Draft = 'DRAFT';
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
