<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Auth\Enums\AccessGrantStatus;
use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\ReconciliationStatus;
use App\Models\AccountAccessGrant;
use App\Models\CompanyRepresentative;
use App\Models\EventIntegration;
use App\Models\IntegrationReconciliation;
use App\Models\Juror;
use App\Models\Person;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lista paginada de uma fila de reconciliação: Person + entradas confiáveis + estado interno atual.
 * O estado interno de cada página é carregado em lote (uma consulta por tipo, sem N+1).
 */
class ReconciliationQueue
{
    public const VIEWS = ['pending', 'ignored', 'all'];

    public function __construct(private readonly ReconciliationCandidates $candidates) {}

    public function paginate(EventIntegration $integration, ReconciliationKind $kind, string $view, ?string $search, int $perPage): LengthAwarePaginator
    {
        $page = $this->candidates->people($integration, $kind, $view)
            ->when(filled($search), fn ($q) => $q->where(fn ($w) => $w
                ->where('people.full_name', 'ilike', '%'.$search.'%')
                ->orWhere('people.email', 'ilike', '%'.$search.'%')))
            ->orderBy('people.full_name')->orderBy('people.id')
            ->paginate($perPage, ['people.id', 'people.full_name', 'people.email']);

        $ids = $page->getCollection()->pluck('id')->all();
        $context = $this->context($integration, $kind, $ids);

        if ($kind === ReconciliationKind::Organization) {
            // Conta de outra Person com o mesmo e-mail também bloqueia o primeiro acesso.
            $emails = $page->getCollection()->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->values()->all();
            $context['usersByEmail'] = $emails === [] ? collect() : User::query()->with('role', 'sector')
                ->whereIn(DB::raw('lower(email)'), $emails)->get()->keyBy(fn (User $u) => mb_strtolower($u->email));
        }

        $page->setCollection($page->getCollection()->map(fn (Person $person) => $this->item($kind, $person, $context)));

        return $page;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, Collection>
     */
    private function context(EventIntegration $integration, ReconciliationKind $kind, array $ids): array
    {
        $context = [
            'tickets' => ReconciliationCandidates::trustedRegistrations($integration, $kind->category())
                ->whereIn('r.person_id', $ids)->select('r.person_id', 't.external_name')->distinct()->get()
                ->groupBy('person_id')->map(fn ($rows) => $rows->pluck('external_name')->sort()->values()->all()),
            'decisions' => IntegrationReconciliation::query()->where('event_integration_id', $integration->id)
                ->where('kind', $kind->value)->whereIn('person_id', $ids)->get()->keyBy('person_id'),
        ];

        match ($kind) {
            ReconciliationKind::Juror => $context['jurors'] = Juror::query()->where('event_id', $integration->event_id)
                ->whereIn('person_id', $ids)->get()->keyBy('person_id'),
            ReconciliationKind::CompanyGuest => $context['representatives'] = CompanyRepresentative::query()->with('company:id,name,status')
                ->whereIn('person_id', $ids)->whereHas('company', fn ($q) => $q->where('event_id', $integration->event_id))
                ->get()->groupBy('person_id'),
            ReconciliationKind::Organization => [
                $context['users'] = User::query()->with('role', 'sector')->whereIn('person_id', $ids)->get()->keyBy('person_id'),
                $context['grants'] = AccountAccessGrant::query()->with('role', 'sector')->whereIn('person_id', $ids)
                    ->where('status', AccessGrantStatus::Pending->value)->get()->keyBy('person_id'),
            ],
        };

        return $context;
    }

    /**
     * @param  array<string, Collection>  $context
     * @return array<string, mixed>
     */
    private function item(ReconciliationKind $kind, Person $person, array $context): array
    {
        $decision = $context['decisions']->get($person->id);
        $internal = match ($kind) {
            ReconciliationKind::Juror => $this->jurorState($context['jurors']->get($person->id)),
            ReconciliationKind::CompanyGuest => $this->representativeState($context['representatives']->get($person->id)),
            ReconciliationKind::Organization => $this->accessState(
                $context['users']->get($person->id) ?? ($person->email ? $context['usersByEmail']->get(mb_strtolower($person->email)) : null),
                $context['grants']->get($person->id),
            ),
        };

        return [
            'person' => ['id' => $person->id, 'full_name' => $person->full_name, 'email' => $person->email],
            'tickets' => $context['tickets']->get($person->id, []),
            'state' => match (true) {
                $decision?->status === ReconciliationStatus::Ignored => 'IGNORED',
                $internal['done'] => 'DONE',
                default => 'PENDING',
            },
            'internal' => $internal['data'],
            'decision' => $decision === null ? null : [
                'status' => $decision->status->value,
                'resolution' => $decision->resolution,
                'resolved_at' => $decision->resolved_at?->toIso8601String(),
                'note' => $decision->note,
            ],
        ];
    }

    /**
     * @return array{done: bool, data: array<string, mixed>}
     */
    private function jurorState(?Juror $juror): array
    {
        return ['done' => $juror !== null, 'data' => ['juror' => $juror === null ? null : ['id' => $juror->id, 'status' => $juror->status->value]]];
    }

    /**
     * @param  Collection<int, CompanyRepresentative>|null  $representatives
     * @return array{done: bool, data: array<string, mixed>}
     */
    private function representativeState(?Collection $representatives): array
    {
        $rows = ($representatives ?? collect())->map(fn (CompanyRepresentative $r) => [
            'id' => $r->id,
            'company' => ['id' => $r->company->id, 'name' => $r->company->name],
            'active' => $r->active,
            'is_primary' => $r->is_primary,
        ])->values()->all();

        return ['done' => collect($rows)->contains(fn ($r) => $r['active']), 'data' => ['representatives' => $rows]];
    }

    /**
     * @return array{done: bool, data: array<string, mixed>}
     */
    private function accessState(?User $user, ?AccountAccessGrant $grant): array
    {
        return [
            'done' => $user !== null || $grant !== null,
            'data' => [
                'user' => $user === null ? null : ['id' => $user->id, 'role' => $user->role->code->value, 'status' => $user->status->value, 'sector' => $user->sector?->name],
                'grant' => $grant === null ? null : ['id' => $grant->id, 'role' => $grant->role->code->value, 'sector' => $grant->sector === null ? null : ['id' => $grant->sector->id, 'name' => $grant->sector->name], 'created_at' => $grant->created_at?->toIso8601String()],
            ],
        ];
    }
}
