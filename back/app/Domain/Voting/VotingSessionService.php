<?php

namespace App\Domain\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Teams\Enums\TeamStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Voting\Enums\VotingSessionStatus;
use App\Models\Event;
use App\Models\PublicVote;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida da sessão de votação: configuração (só em DRAFT), abertura, fechamento e cancelamento.
 *
 * Toda decisão relê a sessão sob lock (nunca confia no model carregado pela rota):
 * - configuração, fechar e cancelar: sessão FOR UPDATE (fechar espera os votos em andamento, que
 *   seguram a sessão FOR SHARE; votos que chegam depois já veem CLOSED);
 * - abrir: evento FOR UPDATE (serializa aberturas do mesmo evento) → sessão FOR UPDATE.
 */
class VotingSessionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Event $event, string $name, User $actor): VotingSession
    {
        try {
            return DB::transaction(function () use ($event, $name, $actor) {
                $session = new VotingSession(['event_id' => $event->id, 'name' => $name, 'created_by_user_id' => $actor->id]);
                $session->forceFill(['status' => VotingSessionStatus::Draft])->save();

                $this->audit->record(
                    AuditAction::VOTING_SESSION_CREATED,
                    'voting',
                    "Sessão de votação \"{$name}\" criada no evento {$event->name}.",
                    entity: $session,
                    after: $this->snapshot($session),
                );

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe uma sessão de votação com este nome neste evento.']);
        }
    }

    /**
     * Só o nome, e só em DRAFT.
     */
    public function rename(VotingSession $session, string $name): VotingSession
    {
        try {
            return DB::transaction(function () use ($session, $name) {
                $session = $this->lock($session);
                $this->ensureDraft($session, 'name');

                if ($session->name === $name) {
                    return $session;
                }

                $before = $this->snapshot($session);
                $session->forceFill(['name' => $name])->save();

                $this->audit->record(AuditAction::VOTING_SESSION_UPDATED, 'voting', "Sessão de votação {$session->id} renomeada.", entity: $session, before: $before, after: $this->snapshot($session));

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe uma sessão de votação com este nome neste evento.']);
        }
    }

    /**
     * Lista final de equipes candidatas (explícita). Só em DRAFT; equipes ACTIVE do mesmo evento. Um único log.
     *
     * @param  list<int>  $teamIds
     */
    public function syncTeams(VotingSession $session, array $teamIds): VotingSession
    {
        return DB::transaction(function () use ($session, $teamIds) {
            $session = $this->lock($session);
            $this->ensureDraft($session, 'team_ids');

            $desired = array_values(array_unique(array_map('intval', $teamIds)));
            $current = $this->candidateIds($session);

            $toAdd = array_values(array_diff($desired, $current));
            $toRemove = array_values(array_diff($current, $desired));

            if ($toAdd === [] && $toRemove === []) {
                return $session;
            }

            if ($toAdd !== []) {
                $valid = Team::query()->whereIn('id', $toAdd)->where('event_id', $session->event_id)
                    ->where('status', TeamStatus::Active->value)->sharedLock()->pluck('id')->count();

                if ($valid !== count($toAdd)) {
                    throw ValidationException::withMessages(['team_ids' => 'Equipe inexistente, inativa ou de outro evento.']);
                }
            }

            DB::table('voting_session_teams')->where('voting_session_id', $session->id)->whereIn('team_id', $toRemove)->delete();
            DB::table('voting_session_teams')->insert(array_map(fn (int $teamId) => [
                'event_id' => $session->event_id,
                'voting_session_id' => $session->id,
                'team_id' => $teamId,
            ], $toAdd));

            $this->audit->record(
                AuditAction::VOTING_CANDIDATES_CHANGED,
                'voting',
                "Equipes candidatas da sessão \"{$session->name}\" alteradas.",
                entity: $session,
                before: ['team_ids' => $current],
                after: ['team_ids' => $this->candidateIds($session), 'added' => $toAdd, 'removed' => $toRemove],
            );

            return $session;
        });
    }

    /**
     * Lista final de eleitores elegíveis (explícita). Só em DRAFT; só User ACTIVE com perfil VOTER.
     * Não cria conta. Um único log (com IDs de usuário; nunca há escolha de voto aqui).
     *
     * @param  list<int>  $userIds
     */
    public function syncEligibilities(VotingSession $session, array $userIds, User $actor): VotingSession
    {
        return DB::transaction(function () use ($session, $userIds, $actor) {
            $session = $this->lock($session);
            $this->ensureDraft($session, 'user_ids');

            $desired = array_values(array_unique(array_map('intval', $userIds)));
            $current = $this->eligibleIds($session);

            $toAdd = array_values(array_diff($desired, $current));
            $toRemove = array_values(array_diff($current, $desired));

            if ($toAdd === [] && $toRemove === []) {
                return $session;
            }

            if ($toAdd !== []) {
                $valid = $this->activeVoters($toAdd)->sharedLock()->pluck('users.id')->count();

                if ($valid !== count($toAdd)) {
                    throw ValidationException::withMessages(['user_ids' => 'Só usuários ativos com perfil Votante podem ser elegíveis.']);
                }
            }

            VotingEligibility::query()->where('voting_session_id', $session->id)->whereIn('user_id', $toRemove)->delete();

            $now = now();
            VotingEligibility::query()->insert(array_map(fn (int $userId) => [
                'event_id' => $session->event_id,
                'voting_session_id' => $session->id,
                'user_id' => $userId,
                'created_by_user_id' => $actor->id,
                'created_at' => $now,
            ], $toAdd));

            $after = $this->eligibleIds($session);

            $this->audit->record(
                AuditAction::VOTING_ELIGIBILITY_CHANGED,
                'voting',
                "Eleitores elegíveis da sessão \"{$session->name}\" alterados (".count($after).' no total).',
                entity: $session,
                before: ['user_ids' => $current],
                after: ['user_ids' => $after, 'added' => $toAdd, 'removed' => $toRemove],
            );

            return $session;
        });
    }

    public function open(VotingSession $session): VotingSession
    {
        try {
            return DB::transaction(function () use ($session) {
                // Serializa aberturas do mesmo evento antes de olhar se já existe outra OPEN.
                DB::table('events')->where('id', $session->event_id)->lockForUpdate()->first();
                $session = $this->lock($session);

                if (! $session->isDraft()) {
                    throw ValidationException::withMessages(['status' => "Só sessão em DRAFT pode ser aberta (atual: {$session->status->value})."]);
                }

                $otherOpen = VotingSession::query()->where('event_id', $session->event_id)->whereKeyNot($session->id)
                    ->where('status', VotingSessionStatus::Open->value)->value('name');

                if ($otherOpen !== null) {
                    throw ValidationException::withMessages(['status' => "Já existe uma votação aberta neste evento (\"{$otherOpen}\"). Feche-a antes."]);
                }

                $this->ensureReadyToOpen($session);

                $session->forceFill(['status' => VotingSessionStatus::Open, 'opened_at' => now()])->save();

                $this->audit->record(
                    AuditAction::VOTING_SESSION_OPENED,
                    'voting',
                    "Sessão de votação \"{$session->name}\" aberta.",
                    entity: $session,
                    before: ['status' => VotingSessionStatus::Draft->value],
                    after: $this->snapshot($session) + [
                        'candidates' => count($this->candidateIds($session)),
                        'eligible_voters' => count($this->eligibleIds($session)),
                    ],
                );

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida entre aberturas: o índice parcial (1 OPEN por evento) barrou a segunda.
            throw ValidationException::withMessages(['status' => 'Já existe uma votação aberta neste evento.']);
        }
    }

    /**
     * OPEN → CLOSED. Definitivo: sessão fechada não reabre (repetir votação = nova sessão).
     */
    public function close(VotingSession $session): VotingSession
    {
        return DB::transaction(function () use ($session) {
            $session = $this->lock($session);

            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['status' => "Só sessão aberta pode ser fechada (atual: {$session->status->value})."]);
            }

            $session->forceFill(['status' => VotingSessionStatus::Closed, 'closed_at' => now()])->save();

            $this->audit->record(
                AuditAction::VOTING_SESSION_CLOSED,
                'voting',
                "Sessão de votação \"{$session->name}\" fechada.",
                entity: $session,
                before: ['status' => VotingSessionStatus::Open->value],
                after: $this->snapshot($session) + [
                    'eligible_voters' => count($this->eligibleIds($session)),
                    'votes_cast' => PublicVote::query()->where('voting_session_id', $session->id)->count(),
                ],
            );

            return $session;
        });
    }

    /**
     * DRAFT → CANCELLED (motivo opcional). OPEN → CANCELLED só sem nenhum voto e com motivo.
     */
    public function cancel(VotingSession $session, ?string $reason): VotingSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $session = $this->lock($session);
            $previous = $session->status;

            if (! in_array($previous, [VotingSessionStatus::Draft, VotingSessionStatus::Open], true)) {
                throw ValidationException::withMessages(['status' => "Sessão {$previous->value} não pode ser cancelada."]);
            }

            if ($previous === VotingSessionStatus::Open) {
                if ($reason === null || $reason === '') {
                    throw ValidationException::withMessages(['reason' => 'Informe o motivo para cancelar uma votação aberta.']);
                }

                if (PublicVote::query()->where('voting_session_id', $session->id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'A votação já recebeu votos: não pode ser cancelada. Feche-a e, se necessário, crie outra sessão.']);
                }
            }

            $session->forceFill(['status' => VotingSessionStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            $this->audit->record(
                AuditAction::VOTING_SESSION_CANCELLED,
                'voting',
                "Sessão de votação \"{$session->name}\" cancelada (estava {$previous->value}).",
                entity: $session,
                before: ['status' => $previous->value],
                after: $this->snapshot($session) + ['reason' => $reason],
            );

            return $session;
        });
    }

    private function ensureReadyToOpen(VotingSession $session): void
    {
        $teamIds = $this->candidateIds($session);
        $userIds = $this->eligibleIds($session);

        if ($teamIds === []) {
            throw ValidationException::withMessages(['team_ids' => 'Inclua ao menos uma equipe candidata antes de abrir.']);
        }

        if ($userIds === []) {
            throw ValidationException::withMessages(['user_ids' => 'Inclua ao menos um eleitor elegível antes de abrir.']);
        }

        $inactiveTeams = Team::query()->whereIn('id', $teamIds)->where('status', '<>', TeamStatus::Active->value)
            ->sharedLock()->orderBy('name')->pluck('name');

        if ($inactiveTeams->isNotEmpty()) {
            throw ValidationException::withMessages(['team_ids' => 'Equipes candidatas inativas: '.$inactiveTeams->implode(', ').'. Retire-as antes de abrir.']);
        }

        $validVoters = $this->activeVoters($userIds)->sharedLock()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $invalid = array_values(array_diff($userIds, $validVoters));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['user_ids' => 'Eleitores que não estão mais ativos com perfil Votante (IDs '.implode(', ', $invalid).'). Retire-os antes de abrir.']);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function activeVoters(array $userIds): Builder
    {
        return User::query()
            ->whereIn('users.id', $userIds)
            ->where('users.status', UserStatus::Active->value)
            ->whereHas('role', fn ($q) => $q->where('code', RoleCode::Voter->value));
    }

    private function ensureDraft(VotingSession $session, string $field): void
    {
        if (! $session->isDraft()) {
            throw ValidationException::withMessages([$field => "Sessão {$session->status->value}: a configuração fica travada depois da abertura."]);
        }
    }

    private function lock(VotingSession $session): VotingSession
    {
        return VotingSession::query()->lockForUpdate()->findOrFail($session->id);
    }

    /**
     * @return list<int>
     */
    private function candidateIds(VotingSession $session): array
    {
        return DB::table('voting_session_teams')->where('voting_session_id', $session->id)->orderBy('team_id')->pluck('team_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function eligibleIds(VotingSession $session): array
    {
        return VotingEligibility::query()->where('voting_session_id', $session->id)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(VotingSession $session): array
    {
        return [
            'event_id' => $session->event_id,
            'name' => $session->name,
            'status' => $session->status?->value,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
        ];
    }
}
