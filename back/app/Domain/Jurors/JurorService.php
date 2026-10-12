<?php

namespace App\Domain\Jurors;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Jurors\Enums\AssignmentStatus;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\People\PersonService;
use App\Domain\Results\ResultPublicationService;
use App\Domain\Teams\Enums\TeamStatus;
use App\Models\Event;
use App\Models\Juror;
use App\Models\JurorTeamAssignment;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jurados e atribuições explícitas. Não cria User (conta é do módulo de Usuários) e nunca
 * infere equipes por empresa, desafio, representante, categoria externa, turma ou setor.
 */
class JurorService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PersonService $people,
    ) {}

    /**
     * @param  array{person_id?: int, person?: array<string, mixed>, company_id?: ?int, notes?: ?string}  $data
     */
    public function create(Event $event, array $data): Juror
    {
        return DB::transaction(function () use ($event, $data) {
            $person = isset($data['person_id'])
                ? Person::query()->findOrFail($data['person_id'])
                : $this->people->create($data['person']);

            $juror = Juror::query()->create([
                'event_id' => $event->id,
                'person_id' => $person->id,
                'company_id' => $data['company_id'] ?? null,
                'status' => JurorStatus::Active->value,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->record(
                AuditAction::JUROR_CREATED,
                'jurors',
                "{$person->full_name} cadastrado(a) como jurado(a) do evento {$event->name}.",
                entity: $juror,
                after: $this->snapshot($juror),
            );

            return $juror;
        });
    }

    /**
     * @param  array{company_id?: ?int, notes?: ?string}  $data
     */
    public function update(Juror $juror, array $data): Juror
    {
        return DB::transaction(function () use ($juror, $data) {
            $before = $this->snapshot($juror);
            $juror->fill($data);

            if (! $juror->isDirty()) {
                return $juror;
            }

            $juror->save();

            $this->audit->record(AuditAction::JUROR_UPDATED, 'jurors', "Jurado(a) {$juror->person->full_name} alterado(a).", entity: $juror, before: $before, after: $this->snapshot($juror));

            return $juror;
        });
    }

    /**
     * Inativar não revoga atribuições: elas continuam esperadas no progresso até o Administrador revogar.
     */
    public function changeStatus(Juror $juror, JurorStatus $status): Juror
    {
        return DB::transaction(function () use ($juror, $status) {
            if ($juror->status === $status) {
                return $juror;
            }

            $before = $this->snapshot($juror);
            $juror->forceFill(['status' => $status])->save();

            $this->audit->record(
                AuditAction::JUROR_STATUS_CHANGED,
                'jurors',
                "Jurado(a) {$juror->person->full_name}: status alterado de {$before['status']} para {$status->value}.",
                entity: $juror,
                before: $before,
                after: $this->snapshot($juror),
            );

            return $juror;
        });
    }

    /**
     * Define a lista final de equipes do jurado: novas são criadas/reativadas, retiradas são revogadas,
     * mantidas não mudam. Uma transação e um único log.
     *
     * @param  list<int>  $teamIds
     * @return Collection<int, JurorTeamAssignment>
     */
    public function syncAssignments(Juror $juror, array $teamIds, User $actor): Collection
    {
        return DB::transaction(function () use ($juror, $teamIds, $actor) {
            $juror = Juror::query()->with('person')->lockForUpdate()->findOrFail($juror->id);
            $desired = array_values(array_unique(array_map('intval', $teamIds)));

            /** @var Collection<int, JurorTeamAssignment> $current */
            $current = JurorTeamAssignment::query()->where('juror_id', $juror->id)->lockForUpdate()->get()->keyBy('team_id');
            $activeBefore = $current->filter->isActive()->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

            $toActivate = array_values(array_diff($desired, $activeBefore));
            $toRevoke = array_values(array_diff($activeBefore, $desired));

            if ($toActivate === [] && $toRevoke === []) {
                return $this->assignmentsOf($juror);
            }

            // Lock do evento depois das atribuições (mesma ordem da avaliação): serializa com a publicação.
            DB::table('events')->where('id', $juror->event_id)->lockForUpdate()->first();
            ResultPublicationService::ensureNotPublished($juror->event_id, 'team_ids', 'alterar atribuições jurado ↔ equipe');

            if ($toActivate !== []) {
                $this->ensureCanReceive($juror, $toActivate);
            }

            $now = now();

            foreach ($toActivate as $teamId) {
                JurorTeamAssignment::query()->updateOrCreate(
                    ['juror_id' => $juror->id, 'team_id' => $teamId],
                    [
                        'event_id' => $juror->event_id,
                        'status' => AssignmentStatus::Active,
                        'assigned_by_user_id' => $actor->id,
                        'assigned_at' => $now,
                        'revoked_by_user_id' => null,
                        'revoked_at' => null,
                    ],
                );
            }

            foreach ($toRevoke as $teamId) {
                $current[$teamId]->forceFill([
                    'status' => AssignmentStatus::Revoked,
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => $now,
                ])->save();
            }

            $activeAfter = JurorTeamAssignment::query()->where('juror_id', $juror->id)->where('status', AssignmentStatus::Active->value)
                ->pluck('team_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

            $this->audit->record(
                AuditAction::JUROR_ASSIGNMENTS_CHANGED,
                'jurors',
                "Equipes do(a) jurado(a) {$juror->person->full_name} alteradas.",
                entity: $juror,
                before: ['team_ids' => $activeBefore],
                after: ['team_ids' => $activeAfter, 'assigned' => $toActivate, 'revoked' => $toRevoke],
            );

            return $this->assignmentsOf($juror);
        });
    }

    /**
     * @param  list<int>  $teamIds
     */
    private function ensureCanReceive(Juror $juror, array $teamIds): void
    {
        if (! $juror->isActive()) {
            throw ValidationException::withMessages(['team_ids' => 'Jurado inativo não recebe nova atribuição.']);
        }

        $valid = Team::query()
            ->whereIn('id', $teamIds)
            ->where('event_id', $juror->event_id)
            ->where('status', TeamStatus::Active->value)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($valid !== count($teamIds)) {
            throw ValidationException::withMessages(['team_ids' => 'Equipe inexistente, inativa ou de outro evento.']);
        }
    }

    /**
     * @return Collection<int, JurorTeamAssignment>
     */
    private function assignmentsOf(Juror $juror): Collection
    {
        return JurorTeamAssignment::query()->where('juror_id', $juror->id)->with('team')->orderBy('team_id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Juror $juror): array
    {
        return [
            'event_id' => $juror->event_id,
            'person_id' => $juror->person_id,
            'company_id' => $juror->company_id,
            'status' => $juror->status?->value,
            'notes' => $juror->notes,
        ];
    }
}
