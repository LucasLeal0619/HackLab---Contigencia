<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Models\EventIntegration;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * O que a sincronização aplica sozinha (decisão de 20/10/2026, um evento só): Jurados confirmados e nome/datas
 * do evento. Usa as mesmas ações das telas (ReconciliationService, EventMetadataReconciler), com as mesmas regras
 * e a mesma auditoria. Nunca cria conta, perfil ou empresa.
 */
class Even3AutoApply
{
    public function __construct(
        private readonly ReconciliationCandidates $candidates,
        private readonly ReconciliationService $reconciliation,
        private readonly EventMetadataReconciler $metadata,
    ) {}

    /**
     * Inscritos confirmados como JUROR (sem conflito, não ignorados, ainda sem Juror no evento) → Juror ativo.
     *
     * @return array{created: int, skipped: list<int>}
     */
    public function jurors(EventIntegration $integration, ?User $actor): array
    {
        $event = $integration->event()->firstOrFail();
        $created = 0;
        $skipped = [];

        $people = $this->candidates->people($integration, ReconciliationKind::Juror)->orderBy('people.id')->get();
        foreach ($people as $person) {
            try {
                $this->reconciliation->confirmJuror($event, $person, [], $actor);
                $created++;
            } catch (ValidationException) {
                // Mudou no meio do caminho (outro processo criou, conflito): segue para o próximo.
                $skipped[] = $person->id;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Nome e datas diferentes do retrato da Even3 → evento do HackLab. Se as datas deixariam dias cadastrados
     * fora do período, aplica só o nome e devolve o aviso.
     *
     * @return array{applied: list<string>, warning: ?string}
     */
    public function eventMetadata(EventIntegration $integration, ?User $actor): array
    {
        $event = $integration->event()->firstOrFail();
        $fields = collect($this->metadata->compare($event, $integration))->where('different', true)->pluck('field')->values()->all();

        if ($fields === []) {
            return ['applied' => [], 'warning' => null];
        }

        try {
            $this->metadata->apply($event, $integration, $fields, $actor);

            return ['applied' => $fields, 'warning' => null];
        } catch (ValidationException $e) {
            $warning = 'Datas da Even3 não aplicadas: '.collect($e->errors())->flatten()->first();
            $nameOnly = in_array('name', $fields, true) ? ['name'] : [];

            if ($nameOnly !== []) {
                try {
                    $this->metadata->apply($event->fresh(), $integration, $nameOnly, $actor);
                } catch (ValidationException) {
                    $nameOnly = [];
                }
            }

            return ['applied' => $nameOnly, 'warning' => $warning];
        }
    }
}
