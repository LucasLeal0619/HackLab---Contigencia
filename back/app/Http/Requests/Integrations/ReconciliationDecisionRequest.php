<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ignorar/restaurar um candidato: exige a permissão da fila (integrations.manage + domínio).
 */
class ReconciliationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(self::abilityFor($this->kind()), EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:1000']];
    }

    public function kind(): ReconciliationKind
    {
        return ReconciliationKind::fromQueue((string) $this->route('queue'));
    }

    public static function abilityFor(ReconciliationKind $kind): string
    {
        return match ($kind) {
            ReconciliationKind::Juror => 'reconcileJurors',
            ReconciliationKind::CompanyGuest => 'reconcileCompanyGuests',
            ReconciliationKind::Organization => 'grantAccess',
        };
    }
}
