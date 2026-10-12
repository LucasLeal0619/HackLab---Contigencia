<?php

namespace Tests\Feature\Voting;

use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Sigilo na aplicação: nenhuma API administrativa devolve eleitor → equipe; durante OPEN ninguém recebe
 * contagem por equipe; a auditoria nunca registra a escolha.
 */
class VotingPrivacyTest extends DatabaseTestCase
{
    use VotingScenario;

    private Event $event;

    private Team $teamA;

    private Team $teamB;

    /** @var list<User> */
    private array $voterList;

    private VotingSession $session;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->create();
        $this->teamA = Team::factory()->for($this->event)->create(['name' => 'Equipe A']);
        $this->teamB = Team::factory()->for($this->event)->create(['name' => 'Equipe B']);
        $this->voterList = $this->voters(4);
        $this->session = $this->openSession($this->event, [$this->teamA, $this->teamB], $this->voterList);
        $this->castVote($this->session, $this->voterList[0], $this->teamA);
        $this->castVote($this->session, $this->voterList[1], $this->teamB);
        $this->castVote($this->session, $this->voterList[2], $this->teamA);
        $this->adminUser = $this->admin();
    }

    /**
     * Nenhum payload pode conter o par (user_id, team_id) de um voto.
     */
    private function assertNoVoterChoice(string $json): void
    {
        $data = json_decode($json, true);
        $flat = json_encode($data);

        $this->assertStringNotContainsString('"team_id"', $flat, 'Resposta administrativa não deveria trazer team_id de votos.');
        $this->assertStringNotContainsString('voted_team', $flat);
        $this->assertStringNotContainsString('"choice"', $flat);
    }

    public function test_admin_session_detail_and_listing_do_not_expose_voter_to_team(): void
    {
        foreach ([
            "/api/v1/voting-sessions/{$this->session->id}",
            "/api/v1/events/{$this->event->id}/voting-sessions",
            "/api/v1/voting-sessions/{$this->session->id}/turnout",
        ] as $url) {
            $response = $this->actingAs($this->adminUser)->getJson($url)->assertOk();
            $this->assertNoVoterChoice($response->getContent());
            $this->assertStringNotContainsString('vote_count', $response->getContent());
        }
    }

    public function test_eligibility_listing_shows_only_whether_someone_voted(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/eligibilities")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['user' => ['id', 'email', 'person' => ['full_name']], 'has_voted']], 'meta' => ['total']]);

        $this->assertNoVoterChoice($response->getContent());
        $this->assertEqualsCanonicalizing([true, true, true, false], array_column($response->json('data'), 'has_voted'));

        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/eligibilities?has_voted=0")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $this->voterList[3]->id);
        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/eligibilities?has_voted=1")
            ->assertOk()->assertJsonCount(3, 'data');

        $name = $this->voterList[1]->person->full_name;
        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/eligibilities?search=".urlencode($name))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_voted', true);
    }

    public function test_turnout_during_open_has_totals_only(): void
    {
        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/turnout")
            ->assertOk()
            ->assertExactJson(['data' => [
                'voting_session_id' => $this->session->id,
                'status' => 'OPEN',
                'eligible_voters' => 4,
                'votes_cast' => 3,
                'pending_voters' => 1,
                'turnout_percent' => 75,
            ]]);
    }

    public function test_per_team_results_during_open_are_refused_even_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/results")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session')
            ->assertJsonMissingPath('data');

        // Nem a prévia de resultados traz contagem enquanto a sessão está aberta.
        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/preview?voting_session_id={$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.popular', null)
            ->assertJsonPath('data.turnout.votes_cast', 3);
    }

    public function test_other_profiles_cannot_see_turnout_or_results(): void
    {
        $this->closeSession($this->session);

        foreach ([$this->voterList[0], $this->userWithRole(RoleCode::Consultant), $this->userWithRole(RoleCode::Juror)] as $user) {
            $this->actingAs($user)->getJson("/api/v1/voting-sessions/{$this->session->id}/turnout")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/voting-sessions/{$this->session->id}/results")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/voting-sessions/{$this->session->id}/eligibilities")->assertForbidden();
        }
    }

    public function test_closed_results_are_aggregated_without_voter_identities(): void
    {
        $this->closeSession($this->session);

        $response = $this->actingAs($this->adminUser)->getJson("/api/v1/voting-sessions/{$this->session->id}/results")->assertOk();

        foreach ($this->voterList as $voter) {
            $this->assertStringNotContainsString($voter->email, $response->getContent());
            $this->assertStringNotContainsString($voter->person->full_name, $response->getContent());
        }
        $this->assertStringNotContainsString('user_id', $response->getContent());
    }

    public function test_audit_logs_never_contain_the_voter_choice(): void
    {
        $this->closeSession($this->session);

        $this->assertSame(0, AuditLog::query()->where('action', 'ilike', '%VOTE_CAST%')->count());

        foreach (AuditLog::query()->get() as $log) {
            $payload = json_encode([$log->before_data, $log->after_data, $log->description]);

            $this->assertStringNotContainsString('"team_id"', $payload, "Log {$log->action} não deveria conter team_id de voto.");

            foreach ($this->voterList as $voter) {
                $this->assertFalse(
                    $log->actor_user_id === $voter->id,
                    "O voto de {$voter->id} gerou log ({$log->action}).",
                );
            }
        }

        // Ações administrativas foram auditadas.
        $this->assertEqualsCanonicalizing(
            ['VOTING_SESSION_CREATED', 'VOTING_CANDIDATES_CHANGED', 'VOTING_ELIGIBILITY_CHANGED', 'VOTING_SESSION_OPENED', 'VOTING_SESSION_CLOSED'],
            AuditLog::query()->where('module', 'voting')->pluck('action')->all(),
        );
    }
}
