<?php

namespace App\Domain\Integrations\Enums;

/**
 * Status da inscrição externa. Cancelamento só por informação explícita (webhook).
 */
enum RegistrationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';
    case Unknown = 'UNKNOWN';
}
