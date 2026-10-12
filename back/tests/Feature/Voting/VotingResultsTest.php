<?php

namespace Tests\Feature\Voting;

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Escolha do Público: só após CLOSED. percentual = votos da equipe / votos válidos; participação = votos / elegíveis.
 * Ranking por competição (1, 1, 3), sem desempate artificial.
 */
class VotingResultsTest extends DatabaseTestCase
{
    use VotingScenario;

    private Event $event;

    private Team $teamA;

    private Team $teamB;

    private Team $teamC;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->create();
        // Criadas fora de ordem alfabética: a apresentação ordena por nome só dentro da mesma posição.
        $this->teamC = Team::factory()->for($this->event)->create(['name' => 'Equipe C']);
        $this->teamB = Team::factory()->for($this->event)->create(['name' => 'Equipe B']);
        $this->teamA = Team::factory()->for($this->event)->create(['name' => 'Equipe A']);
        $this->adminUser = $this->admin();
    }

    /**
     * @param  array<string, int>  $votesByTeam  nome => quantidade
     */
    private function sessionWith(int $eligible, array $votesByTeam): VotingSession
    {
        $teams = ['A' => $this->teamA, 'B' => $this->teamB, 'C' => $this->teamC];
        $voters = $this->voters($eligible);
        $votes = [];
        $i = 0;

        foreach ($votesByTeam as $key => $count) {
            for ($n = 0; $n < $count; $n++) {
                $votes[] = [$voters[$i++], $teams[$key]];
            }
        }

        return $this->closedSession($this->event, array_values($teams), $voters, $votes);
    }

    private function results(VotingSession $session): array
    {
        return $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$session->id}/results")->assertOk()->json('data');
    }

    public function test_exact_counts_percentages_and_turnout(): void
    {
        // 10 elegíveis, 8 votos: A=4, B=3, C=1.
        $data = $this->results($this->sessionWith(10, ['A' => 4, 'B' => 3, 'C' => 1]));

        $this->assertSame(8, $data['total_votes']);
        $this->assertSame(['Equipe A', 'Equipe B', 'Equipe C'], array_column(array_column($data['teams'], 'team'), 'name'));
        $this->assertSame([4, 3, 1], array_column($data['teams'], 'vote_count'));
        $this->assertSame([50, 37.5, 12.5], array_column($data['teams'], 'percentage'));
        $this->assertSame([1, 2, 3], array_column($data['teams'], 'rank'));
        $this->assertSame([false, false, false], array_column($data['teams'], 'tied'));
        $this->assertFalse($data['has_ties']);

        $this->assertSame(10, $data['turnout']['eligible_voters']);
        $this->assertSame(8, $data['turnout']['votes_cast']);
        $this->assertSame(80, $data['turnout']['turnout_percent']);
    }

    public function test_full_turnout(): void
    {
        $data = $this->results($this->sessionWith(4, ['A' => 1, 'B' => 3]));

        $this->assertSame(100, $data['turnout']['turnout_percent']);
        $this->assertSame([75, 25, 0], array_column($data['teams'], 'percentage'));
        $this->assertSame(['Equipe B', 'Equipe A', 'Equipe C'], array_column(array_column($data['teams'], 'team'), 'name'));
    }

    public function test_zero_votes(): void
    {
        $data = $this->results($this->sessionWith(5, []));

        $this->assertSame(0, $data['total_votes']);
        $this->assertSame([0, 0, 0], array_column($data['teams'], 'vote_count'));
        $this->assertSame([0, 0, 0], array_column($data['teams'], 'percentage'));
        $this->assertSame(0, $data['turnout']['turnout_percent']);
        // Sem votos, todas empatadas (nenhum critério artificial as separa).
        $this->assertSame([1, 1, 1], array_column($data['teams'], 'rank'));
    }

    public function test_first_place_tie_is_preserved_as_1_1_3(): void
    {
        $data = $this->results($this->sessionWith(7, ['C' => 3, 'A' => 3, 'B' => 1]));

        $this->assertSame([1, 1, 3], array_column($data['teams'], 'rank'));
        $this->assertSame([true, true, false], array_column($data['teams'], 'tied'));
        // Mesma posição: ordem alfabética só para apresentação.
        $this->assertSame(['Equipe A', 'Equipe C', 'Equipe B'], array_column(array_column($data['teams'], 'team'), 'name'));
        $this->assertTrue($data['has_ties']);
        $this->assertSame([42.86, 42.86, 14.29], array_column($data['teams'], 'percentage'));
    }

    public function test_tie_is_not_broken_by_vote_time_or_registration_order(): void
    {
        // B recebe os primeiros votos e foi criada antes de A; ainda assim é empate.
        $data = $this->results($this->sessionWith(4, ['B' => 2, 'A' => 2]));

        $this->assertSame([1, 1, 3], array_column($data['teams'], 'rank'));
        $this->assertSame(['Equipe A', 'Equipe B', 'Equipe C'], array_column(array_column($data['teams'], 'team'), 'name'));
    }

    public function test_results_only_after_closed(): void
    {
        $voters = $this->voters(2);
        $draft = $this->draftSession($this->event, [$this->teamA], $voters, 'Rascunho');
        $open = $this->openSession($this->event, [$this->teamA], $voters, 'Aberta');

        foreach ([$draft, $open] as $session) {
            $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$session->id}/results")->assertUnprocessable();
        }

        $this->closeSession($open);
        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$open->id}/results")->assertOk();
    }
}
