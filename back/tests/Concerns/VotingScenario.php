<?php

namespace Tests\Concerns;

use App\Domain\Users\Enums\RoleCode;
use App\Domain\Voting\PublicVoteService;
use App\Domain\Voting\VotingSessionService;
use App\Models\Event;
use App\Models\Person;
use App\Models\PublicVote;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;

/**
 * Atalhos de votação pelos Services (com as mesmas regras e auditoria da API).
 * Exige um DatabaseTestCase (perfis semeados).
 */
trait VotingScenario
{
    private ?User $votingActor = null;

    protected function votingActor(): User
    {
        return $this->votingActor ??= User::factory()->role(RoleCode::Administrator)->create();
    }

    protected function voter(?string $name = null): User
    {
        $person = Person::factory()->create($name === null ? [] : ['full_name' => $name]);

        return User::factory()->role(RoleCode::Voter)->create(['person_id' => $person->id]);
    }

    /**
     * @return list<User>
     */
    protected function voters(int $count): array
    {
        return array_map(fn (int $n) => $this->voter(sprintf('Votante %02d', $n)), range(1, $count));
    }

    /**
     * Sessão DRAFT com candidatas e elegíveis.
     *
     * @param  list<Team>  $teams
     * @param  list<User>  $voters
     */
    protected function draftSession(Event $event, array $teams, array $voters, string $name = 'Escolha do Público'): VotingSession
    {
        $service = app(VotingSessionService::class);
        $session = $service->create($event, $name, $this->votingActor());
        $service->syncTeams($session, array_map(fn (Team $t) => $t->id, $teams));
        $service->syncEligibilities($session, array_map(fn (User $u) => $u->id, $voters), $this->votingActor());

        return $session->refresh();
    }

    /**
     * @param  list<Team>  $teams
     * @param  list<User>  $voters
     */
    protected function openSession(Event $event, array $teams, array $voters, string $name = 'Escolha do Público'): VotingSession
    {
        return app(VotingSessionService::class)->open($this->draftSession($event, $teams, $voters, $name));
    }

    protected function castVote(VotingSession $session, User $voter, Team $team): PublicVote
    {
        return app(PublicVoteService::class)->cast($session, $voter, $team->id)[0];
    }

    protected function closeSession(VotingSession $session): VotingSession
    {
        return app(VotingSessionService::class)->close($session);
    }

    /**
     * Sessão fechada com os votos informados: lista de [eleitor, equipe].
     *
     * @param  list<Team>  $teams
     * @param  list<User>  $voters
     * @param  list<array{0: User, 1: Team}>  $votes
     */
    protected function closedSession(Event $event, array $teams, array $voters, array $votes, string $name = 'Escolha do Público'): VotingSession
    {
        $session = $this->openSession($event, $teams, $voters, $name);

        foreach ($votes as [$voter, $team]) {
            $this->castVote($session, $voter, $team);
        }

        return $this->closeSession($session);
    }
}
