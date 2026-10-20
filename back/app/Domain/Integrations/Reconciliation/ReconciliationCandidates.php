<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\ReconciliationStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\EventIntegration;
use App\Models\Person;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Candidatos de reconciliação, sempre por Person (várias inscrições da mesma pessoa = um candidato).
 *
 * Candidato: inscrição CONFIRMED, sem identity_mismatch, desta integração, em entrada cuja
 * internal_category é a da fila (nunca pelo texto do nome da entrada).
 *
 * Pendência é derivada: candidato sem a entidade interna da fila e sem decisão IGNORED.
 *  - JUROR: sem Juror no evento (ativo ou inativo);
 *  - ORGANIZATION: sem User (pela Person ou pelo e-mail) e sem liberação de acesso PENDING;
 *  - COMPANY_GUEST: sem representação ativa em empresa do evento.
 *
 * Só consultas (nada é gravado). Não chama a Even3.
 */
class ReconciliationCandidates
{
    /**
     * Subconsulta com os person_id candidatos da fila.
     */
    public static function candidateIds(EventIntegration $integration, TicketCategory $category): QueryBuilder
    {
        return self::trustedRegistrations($integration, $category)->select('r.person_id')->distinct();
    }

    /**
     * Inscrições confiáveis de uma categoria nesta integração.
     */
    public static function trustedRegistrations(EventIntegration $integration, TicketCategory $category): QueryBuilder
    {
        return DB::table('event_registrations as r')
            ->join('external_ticket_types as t', 't.id', '=', 'r.external_ticket_type_id')
            ->where('r.event_integration_id', $integration->id)
            ->where('r.event_id', $integration->event_id)
            ->where('r.status', RegistrationStatus::Confirmed->value)
            ->where('r.identity_mismatch', false)
            ->where('t.internal_category', $category->value);
    }

    /**
     * Persons candidatas da fila, com filtro de visão: pending | ignored | all.
     */
    public function people(EventIntegration $integration, ReconciliationKind $kind, string $view = 'pending'): Builder
    {
        $query = Person::query()->whereIn('people.id', self::candidateIds($integration, $kind->category()));

        return match ($view) {
            'ignored' => $query->whereExists($this->ignoredDecision($integration, $kind)),
            'all' => $query,
            default => $this->pendingOnly($query, $integration, $kind),
        };
    }

    public function pendingCount(EventIntegration $integration, ReconciliationKind $kind): int
    {
        return $this->people($integration, $kind)->count();
    }

    public function ignoredCount(EventIntegration $integration, ReconciliationKind $kind): int
    {
        return $this->people($integration, $kind, 'ignored')->count();
    }

    public function isCandidate(EventIntegration $integration, ReconciliationKind $kind, int $personId): bool
    {
        return self::trustedRegistrations($integration, $kind->category())->where('r.person_id', $personId)->exists();
    }

    public function isPending(EventIntegration $integration, ReconciliationKind $kind, int $personId): bool
    {
        return $this->people($integration, $kind)->where('people.id', $personId)->exists();
    }

    public function isIgnored(EventIntegration $integration, ReconciliationKind $kind, int $personId): bool
    {
        return Person::query()->whereKey($personId)->whereExists($this->ignoredDecision($integration, $kind))->exists();
    }

    public function hasInternalEntity(EventIntegration $integration, ReconciliationKind $kind, int $personId): bool
    {
        return Person::query()->whereKey($personId)->whereExists($this->internalEntity($integration, $kind))->exists();
    }

    /**
     * Participantes: o que a materialização criaria (CONFIRMED + PARTICIPANT, ainda sem Participant no evento).
     * Mesmo critério do ParticipantMaterializer, para o número bater com o resultado do botão.
     */
    public function participantsToMaterialize(EventIntegration $integration): int
    {
        return (int) DB::table('event_registrations as r')
            ->join('external_ticket_types as t', 't.id', '=', 'r.external_ticket_type_id')
            ->where('r.event_integration_id', $integration->id)
            ->where('r.event_id', $integration->event_id)
            ->where('r.status', RegistrationStatus::Confirmed->value)
            ->where('t.internal_category', TicketCategory::Participant->value)
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('participants as p')
                ->whereColumn('p.person_id', 'r.person_id')
                ->whereColumn('p.event_id', 'r.event_id'))
            ->distinct()
            ->count('r.person_id');
    }

    /**
     * Inscritos do público (confiáveis) ainda sem conta.
     */
    public function publicWithoutAccess(EventIntegration $integration): int
    {
        return Person::query()
            ->whereIn('people.id', self::candidateIds($integration, TicketCategory::Public))
            ->whereNotExists($this->accountOf())
            ->count();
    }

    private function pendingOnly(Builder $query, EventIntegration $integration, ReconciliationKind $kind): Builder
    {
        return $query->whereNotExists($this->internalEntity($integration, $kind))
            ->whereNotExists($this->ignoredDecision($integration, $kind));
    }

    private function ignoredDecision(EventIntegration $integration, ReconciliationKind $kind): Closure
    {
        return fn (QueryBuilder $q) => $q->from('integration_reconciliations as ir')
            ->whereColumn('ir.person_id', 'people.id')
            ->where('ir.event_integration_id', $integration->id)
            ->where('ir.kind', $kind->value)
            ->where('ir.status', ReconciliationStatus::Ignored->value);
    }

    /**
     * A entidade interna que tira o candidato da fila.
     */
    private function internalEntity(EventIntegration $integration, ReconciliationKind $kind): Closure
    {
        return match ($kind) {
            ReconciliationKind::Juror => fn (QueryBuilder $q) => $q->from('jurors as j')
                ->whereColumn('j.person_id', 'people.id')
                ->where('j.event_id', $integration->event_id),
            ReconciliationKind::CompanyGuest => fn (QueryBuilder $q) => $q->from('company_representatives as cr')
                ->join('companies as c', 'c.id', '=', 'cr.company_id')
                ->whereColumn('cr.person_id', 'people.id')
                ->where('c.event_id', $integration->event_id)
                ->where('cr.active', true),
            ReconciliationKind::Organization => fn (QueryBuilder $q) => $q->selectRaw('1')
                ->whereExists($this->accountOf())
                ->orWhereExists(fn (QueryBuilder $g) => $g->from('account_access_grants as g')
                    ->whereColumn('g.person_id', 'people.id')
                    ->where('g.status', AccessGrantStatus::Pending->value)),
        };
    }

    /**
     * Conta da Person: pela própria Person ou por um User com o mesmo e-mail (o primeiro acesso recusaria).
     */
    private function accountOf(): Closure
    {
        return fn (QueryBuilder $q) => $q->from('users as u')
            ->where(fn (QueryBuilder $w) => $w->whereColumn('u.person_id', 'people.id')
                ->orWhereRaw('people.email is not null and lower(u.email) = lower(people.email)'));
    }
}
