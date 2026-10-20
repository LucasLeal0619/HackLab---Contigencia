<?php

namespace App\Domain\Auth\Enums;

enum AccessGrantStatus: string
{
    case Pending = 'PENDING';
    case Consumed = 'CONSUMED';
    case Revoked = 'REVOKED';
}
