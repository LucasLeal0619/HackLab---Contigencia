<?php

namespace App\Domain\Auth\Enums;

/**
 * Origem da liberação de acesso. Outras fontes administrativas entram aqui (e no check do banco).
 */
enum AccessGrantSource: string
{
    case Even3Organization = 'EVEN3_ORGANIZATION';
}
