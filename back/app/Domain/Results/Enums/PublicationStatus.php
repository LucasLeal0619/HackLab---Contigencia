<?php

namespace App\Domain\Results\Enums;

enum PublicationStatus: string
{
    case Published = 'PUBLISHED';
    case Revoked = 'REVOKED';
}
