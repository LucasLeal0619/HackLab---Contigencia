<?php

namespace App\Domain\Voting;

use App\Domain\Users\Enums\RoleCode;
use App\Models\PublicVote;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o voto público. Voto é definitivo (sem alteração, exclusão ou segundo voto) e NÃO é auditado:
 * registrar "usuário X votou na equipe Y" em audit_logs criaria outro cruzamento identidade → escolha.
 *
 * Concorrência:
 * 1. sessão FOR SHARE: votos de usuários diferentes seguem em paralelo; o fechamento (FOR UPDATE)
 *    espera os votos em andamento e os seguintes já leem CLOSED;
 * 2. elegibilidade do usuário FOR UPDATE: duas requisições do mesmo usuário serializam aqui;
 *    a segunda vê o voto da primeira (mesma equipe → idempotente; outra equipe → 422);
 * 3. unique(voting_session_id, user_id) é a última barreira: a violação vira o mesmo resultado do item 2.
 */
class PublicVoteService
{
    /**
     * @return array{0: PublicVote, 1: bool} [voto, criado agora?]
     */
    public function cast(VotingSession $session, User $voter, int $teamId): array
    {
        try {
            return DB::transaction(function () use ($session, $voter, $teamId) {
                $session = VotingSession::query()->sharedLock()->findOrFail($session->id);
                $this->ensureVoter($session, $voter);

                $existing = $this->existingVote($session, $voter);

                if ($existing !== null) {
                    return [$this->sameOrFail($existing, $teamId), false];
                }

                if (! $session->isOpen()) {
                    throw ValidationException::withMessages(['session' => "A votação não está aberta (status: {$session->status->value})."]);
                }

                $candidate = DB::table('voting_session_teams')->where('voting_session_id', $session->id)->where('team_id', $teamId)->exists();

                if (! $candidate) {
                    throw ValidationException::withMessages(['team_id' => 'Esta equipe não é candidata nesta votação.']);
                }

                $vote = PublicVote::query()->create([
                    'event_id' => $session->event_id,
                    'voting_session_id' => $session->id,
                    'user_id' => $voter->id,
                    'team_id' => $teamId,
                    'cast_at' => now(),
                ]);

                return [$vote, true];
            });
        } catch (UniqueConstraintViolationException) {
            // Outra requisição do mesmo usuário gravou primeiro (a transação acima foi desfeita).
            $existing = $this->existingVote($session, $voter);

            if ($existing === null) {
                throw ValidationException::withMessages(['team_id' => 'Não foi possível registrar o voto. Tente novamente.']);
            }

            return [$this->sameOrFail($existing, $teamId), false];
        }
    }

    /**
     * Revalida no momento do voto (a configuração da sessão não substitui a conta continuar ativa).
     * Trava a elegibilidade: serializa requisições simultâneas do mesmo usuário.
     */
    private function ensureVoter(VotingSession $session, User $voter): void
    {
        $user = User::query()->with('role')->findOrFail($voter->id);

        if (! $user->isActive() || ! $user->hasRole(RoleCode::Voter)) {
            throw new AuthorizationException('Somente usuário ativo com perfil Votante pode votar.');
        }

        $eligible = VotingEligibility::query()->where('voting_session_id', $session->id)->where('user_id', $user->id)
            ->lockForUpdate()->first();

        if ($eligible === null) {
            throw new AuthorizationException('Você não está habilitado(a) para votar nesta sessão.');
        }
    }

    private function existingVote(VotingSession $session, User $voter): ?PublicVote
    {
        return PublicVote::query()->where('voting_session_id', $session->id)->where('user_id', $voter->id)->first();
    }

    /**
     * Retry idêntico devolve o voto existente; outra equipe é recusada (voto é definitivo).
     */
    private function sameOrFail(PublicVote $existing, int $teamId): PublicVote
    {
        if ($existing->team_id !== $teamId) {
            throw ValidationException::withMessages(['team_id' => 'Você já votou nesta sessão. O voto é definitivo e não pode ser alterado.']);
        }

        return $existing;
    }
}
