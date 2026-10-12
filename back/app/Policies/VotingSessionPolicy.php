<?php

namespace App\Policies;

use App\Domain\Users\Enums\PermissionCode;
use App\Domain\Users\Enums\RoleCode;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;

/**
 * Administração da votação (Administrador) e área do eleitor.
 *
 * Votar = votes.cast + conta ACTIVE + perfil VOTER + elegibilidade explícita na sessão. Perfil VOTER sozinho
 * não basta. O status OPEN é verificado sob lock pelo PublicVoteService (422), não aqui.
 */
class VotingSessionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::VotingSessionsView);
    }

    public function view(User $actor, VotingSession $session): bool
    {
        return $actor->hasPermission(PermissionCode::VotingSessionsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::VotingSessionsManage);
    }

    public function manage(User $actor, VotingSession $session): bool
    {
        return $actor->hasPermission(PermissionCode::VotingSessionsManage);
    }

    public function manageEligibility(User $actor, VotingSession $session): bool
    {
        return $actor->hasPermission(PermissionCode::VotingEligibilityManage);
    }

    /**
     * Participação (sempre) e contagem por equipe (só CLOSED, regra no VotingAggregates).
     */
    public function viewAggregates(User $actor, VotingSession $session): bool
    {
        return $actor->hasPermission(PermissionCode::VotingAggregatesView);
    }

    /**
     * Área "minhas votações": lista só as sessões em que o usuário é elegível.
     */
    public function participateAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionCode::VotesCast) && $actor->hasRole(RoleCode::Voter);
    }

    /**
     * Cédula, voto e "meu voto".
     */
    public function vote(User $actor, VotingSession $session): bool
    {
        return $this->participateAny($actor)
            && $actor->isActive()
            && VotingEligibility::query()->where('voting_session_id', $session->id)->where('user_id', $actor->id)->exists();
    }
}
