<?php

namespace App\Domain\Integrations\Enums;

/**
 * Decisão explícita sobre um candidato. "Pendente" não é gravado: é derivado das inscrições.
 */
enum ReconciliationStatus: string
{
    case Resolved = 'RESOLVED';
    case Ignored = 'IGNORED';
}
