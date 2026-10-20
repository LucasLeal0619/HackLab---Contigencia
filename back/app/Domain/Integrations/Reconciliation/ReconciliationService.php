<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AccountAccessGrantService;
use App\Domain\Auth\Enums\AccessGrantSource;
use App\Domain\Companies\CompanyRepresentativeService;
use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\ReconciliationStatus;
use App\Domain\Jurors\JurorService;
use App\Domain\Users\Enums\RoleCode;
use App\Models\AccountAccessGrant;
use App\Models\Company;
use App\Models\CompanyRepresentative;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\IntegrationReconciliation;
use App\Models\Juror;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ações do Administrador sobre os candidatos (Complemento 11.2). Cada ação:
 * - confere que a Person é candidata confiável da fila e está pendente (ou ignorada, para restaurar);
 * - serializa por Person (advisory lock), com os uniques do banco como última barreira;
 * - reaproveita o service de domínio (JurorService, CompanyRepresentativeService, AccountAccessGrantService),
 *   que mantém o próprio log de auditoria; aqui só o log da reconciliação, com referência ao resultado.
 *
 * Nada aqui chama a Even3.
 */
class ReconciliationService
{
    private const LOCK_CLASS = 91005;

    public function __construct(
        private readonly ReconciliationCandidates $candidates,
        private readonly JurorService $jurors,
        private readonly CompanyRepresentativeService $representatives,
        private readonly AccountAccessGrantService $grants,
        private readonly AuditLogger $audit,
    ) {}

    public static function integrationOf(Event $event): EventIntegration
    {
        return EventIntegration::even3For($event->id)
            ?? throw new NotFoundHttpException('Este evento não tem integração Even3 configurada.');
    }

    /**
     * "Cadastrar como Jurado": cria o Juror pelo JurorService. Não cria conta (primeiro acesso depois).
     *
     * @param  array{company_id?: ?int, notes?: ?string}  $data
     */
    public function confirmJuror(Event $event, Person $person, array $data, ?User $actor): Juror
    {
        $integration = self::integrationOf($event);

        try {
            return DB::transaction(function () use ($event, $integration, $person, $data, $actor) {
                $this->lockPerson($person);
                $this->ensurePending($integration, ReconciliationKind::Juror, $person);

                $juror = $this->jurors->create($event, ['person_id' => $person->id] + $data);
                $this->resolve($integration, ReconciliationKind::Juror, $person, IntegrationReconciliation::RESOLUTION_JUROR_CREATED, 'juror', $juror->id, $actor, $data['notes'] ?? null);

                return $juror->load('person', 'company');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['person' => 'Esta pessoa já é jurado(a) deste evento.']);
        }
    }

    /**
     * Vincula a pessoa como representante de uma empresa existente do mesmo evento.
     *
     * @param  array{company_id: int, title?: ?string, is_primary?: bool}  $data
     */
    public function linkCompanyGuest(Event $event, Person $person, array $data, User $actor): CompanyRepresentative
    {
        $integration = self::integrationOf($event);
        $company = Company::query()->where('event_id', $event->id)->find($data['company_id'])
            ?? throw ValidationException::withMessages(['company_id' => 'Empresa inexistente ou de outro evento.']);

        return DB::transaction(function () use ($integration, $person, $company, $data, $actor) {
            $this->lockPerson($person);
            $this->ensurePending($integration, ReconciliationKind::CompanyGuest, $person);

            $representative = $this->representatives->add($company, [
                'person_id' => $person->id,
                'title' => $data['title'] ?? null,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ]);
            $this->resolve($integration, ReconciliationKind::CompanyGuest, $person, IntegrationReconciliation::RESOLUTION_REPRESENTATIVE_LINKED, 'company_representative', $representative->id, $actor);

            return $representative->load('person', 'company');
        });
    }

    /**
     * "Liberar acesso" para um inscrito como Organização: cria a liberação (sem User, sem senha).
     */
    public function grantOrganizationAccess(Event $event, Person $person, RoleCode $role, ?int $sectorId, User $actor): AccountAccessGrant
    {
        $integration = self::integrationOf($event);

        return DB::transaction(function () use ($event, $integration, $person, $role, $sectorId, $actor) {
            $this->lockPerson($person);
            $this->ensurePending($integration, ReconciliationKind::Organization, $person);

            return $this->grants->grant($event, $person, $role, $sectorId, $actor, AccessGrantSource::Even3Organization);
        });
    }

    public function ignore(Event $event, ReconciliationKind $kind, Person $person, ?string $note, User $actor): IntegrationReconciliation
    {
        $integration = self::integrationOf($event);

        return DB::transaction(function () use ($integration, $kind, $person, $note, $actor) {
            $this->lockPerson($person);
            $this->ensurePending($integration, $kind, $person);

            $decision = IntegrationReconciliation::query()->updateOrCreate(
                ['event_integration_id' => $integration->id, 'person_id' => $person->id, 'kind' => $kind],
                [
                    'event_id' => $integration->event_id,
                    'status' => ReconciliationStatus::Ignored,
                    'resolution' => IntegrationReconciliation::RESOLUTION_IGNORED,
                    'target_type' => null,
                    'target_id' => null,
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => now(),
                    'note' => $note,
                ],
            );

            $this->audit->record(
                AuditAction::EVEN3_RECONCILIATION_IGNORED,
                'integrations',
                "Candidato {$this->kindLabel($kind)} ignorado: {$person->full_name}.",
                entity: $decision,
                after: ['kind' => $kind->value, 'person_id' => $person->id, 'note' => $note],
                actor: $actor,
                event: $integration->event_id,
            );

            return $decision;
        });
    }

    /**
     * Desfaz a decisão de ignorar: o candidato volta à revisão. Não mexe em entidade interna.
     */
    public function restore(Event $event, ReconciliationKind $kind, Person $person, User $actor): void
    {
        $integration = self::integrationOf($event);

        DB::transaction(function () use ($integration, $kind, $person, $actor) {
            $this->lockPerson($person);

            $decision = IntegrationReconciliation::query()->where('event_integration_id', $integration->id)
                ->where('person_id', $person->id)->where('kind', $kind->value)
                ->where('status', ReconciliationStatus::Ignored->value)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['person' => 'Este candidato não está ignorado.']);

            $before = ['kind' => $kind->value, 'person_id' => $person->id, 'note' => $decision->note, 'ignored_at' => $decision->resolved_at?->toIso8601String()];
            $decision->delete();

            $this->audit->record(
                AuditAction::EVEN3_RECONCILIATION_RESTORED,
                'integrations',
                "Candidato {$this->kindLabel($kind)} restaurado para revisão: {$person->full_name}.",
                entity: $integration,
                before: $before,
                actor: $actor,
                event: $integration->event_id,
            );
        });
    }

    private function lockPerson(Person $person): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_CLASS, $person->id]);
    }

    private function ensurePending(EventIntegration $integration, ReconciliationKind $kind, Person $person): void
    {
        if (! $this->candidates->isCandidate($integration, $kind, $person->id)) {
            throw ValidationException::withMessages(['person' => 'Esta pessoa não é candidata desta fila (inscrição confirmada, sem conflito e mapeada).']);
        }

        if ($this->candidates->isIgnored($integration, $kind, $person->id)) {
            throw ValidationException::withMessages(['person' => 'Este candidato está ignorado. Restaure-o para revisão antes.']);
        }

        if ($this->candidates->hasInternalEntity($integration, $kind, $person->id)) {
            throw ValidationException::withMessages(['person' => match ($kind) {
                ReconciliationKind::Juror => 'Esta pessoa já é jurado(a) deste evento. Use a tela de Jurados.',
                ReconciliationKind::CompanyGuest => 'Esta pessoa já representa uma empresa deste evento.',
                ReconciliationKind::Organization => 'Esta pessoa já tem acesso ou liberação de acesso pendente.',
            }]);
        }
    }

    private function resolve(EventIntegration $integration, ReconciliationKind $kind, Person $person, string $resolution, string $targetType, int $targetId, ?User $actor, ?string $note = null): void
    {
        $decision = IntegrationReconciliation::query()->updateOrCreate(
            ['event_integration_id' => $integration->id, 'person_id' => $person->id, 'kind' => $kind],
            [
                'event_id' => $integration->event_id,
                'status' => ReconciliationStatus::Resolved,
                'resolution' => $resolution,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'resolved_by_user_id' => $actor?->id,
                'resolved_at' => now(),
                'note' => $note,
            ],
        );

        $this->audit->record(
            AuditAction::EVEN3_RECONCILIATION_RESOLVED,
            'integrations',
            "Candidato {$this->kindLabel($kind)} reconciliado: {$person->full_name}.",
            entity: $decision,
            after: ['kind' => $kind->value, 'person_id' => $person->id, 'resolution' => $resolution, 'target_type' => $targetType, 'target_id' => $targetId],
            actor: $actor,
            event: $integration->event_id,
        );
    }

    private function kindLabel(ReconciliationKind $kind): string
    {
        return match ($kind) {
            ReconciliationKind::Juror => 'a Jurado',
            ReconciliationKind::Organization => 'da Organização',
            ReconciliationKind::CompanyGuest => 'de Empresa/Convidado',
        };
    }
}
