<?php

namespace Tests\Feature\Voting;

use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Voting\VotingSessionService;
use App\Models\Event;
use App\Models\PublicVote;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Voto: só User ACTIVE + VOTER + elegível, só com a sessão OPEN, só em candidata.
 * Definitivo (sem troca, exclusão ou segundo voto), retry idêntico idempotente.
 */
class PublicVoteTest extends DatabaseTestCase
{
    use VotingScenario;

    private Event $event;

    private Team $teamA;

    private Team $teamB;

    private Team $outsider;

    private User $voterA;

    private User $voterB;

    private VotingSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->create();
        $this->teamA = Team::factory()->for($this->event)->create(['name' => 'Equipe A']);
        $this->teamB = Team::factory()->for($this->event)->create(['name' => 'Equipe B']);
        $this->outsider = Team::factory()->for($this->event)->create(['name' => 'Equipe fora da votação']);
        [$this->voterA, $this->voterB] = $this->voters(2);
        $this->session = $this->openSession($this->event, [$this->teamA, $this->teamB], [$this->voterA, $this->voterB]);
    }

    private function vote(User $user, Team $team, ?VotingSession $session = null)
    {
        $session ??= $this->session;

        return $this->actingAs($user)->postJson("/api/v1/voting-sessions/{$session->id}/vote", ['team_id' => $team->id]);
    }

    public function test_eligible_voter_votes_once(): void
    {
        $this->vote($this->voterA, $this->teamA)
            ->assertCreated()
            ->assertJsonPath('data.team.id', $this->teamA->id)
            ->assertJsonPath('data.final', true);

        $vote = PublicVote::query()->sole();
        $this->assertSame($this->event->id, $vote->event_id);
        $this->assertSame($this->session->id, $vote->voting_session_id);
        $this->assertNotNull($vote->cast_at);
    }

    public function test_non_eligible_voter_is_forbidden(): void
    {
        $this->vote($this->voter(), $this->teamA)->assertForbidden();
        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_other_profiles_cannot_vote(): void
    {
        foreach ([RoleCode::Administrator, RoleCode::Manager, RoleCode::Editor, RoleCode::Consultant, RoleCode::Juror] as $role) {
            $user = in_array($role, [RoleCode::Manager, RoleCode::Editor], true)
                ? $this->sectorUser($role, Sector::factory()->for($this->event)->create())
                : $this->userWithRole($role);

            $this->vote($user, $this->teamA)->assertForbidden();
        }

        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_voter_who_lost_the_voter_role_cannot_vote_even_if_eligible(): void
    {
        $this->voterA->forceFill(['role_id' => Role::forCode(RoleCode::Consultant)->id])->save();

        $this->vote($this->voterA, $this->teamA)->assertForbidden();
    }

    public function test_inactive_account_cannot_vote(): void
    {
        $this->voterA->forceFill(['status' => UserStatus::Inactive])->save();

        $this->vote($this->voterA, $this->teamA)->assertForbidden();
        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_draft_closed_and_cancelled_sessions_do_not_receive_votes(): void
    {
        $draft = $this->draftSession($this->event, [$this->teamA], [$this->voterA], 'Rascunho');
        $this->vote($this->voterA, $this->teamA, $draft)->assertUnprocessable()->assertJsonValidationErrors('session');

        $cancelled = $this->draftSession($this->event, [$this->teamA], [$this->voterA], 'Cancelada');
        app(VotingSessionService::class)->cancel($cancelled, null);
        $this->vote($this->voterA, $this->teamA, $cancelled)->assertUnprocessable();

        $this->closeSession($this->session);
        $this->vote($this->voterA, $this->teamA)->assertUnprocessable()->assertJsonValidationErrors('session');

        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_team_outside_the_session_is_rejected(): void
    {
        $this->vote($this->voterA, $this->outsider)->assertUnprocessable()->assertJsonValidationErrors('team_id');

        $otherEventTeam = Team::factory()->for(Event::factory())->create();
        $this->vote($this->voterA, $otherEventTeam)->assertUnprocessable()->assertJsonValidationErrors('team_id');

        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_second_vote_for_another_team_is_rejected(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();

        $this->vote($this->voterA, $this->teamB)
            ->assertUnprocessable()
            ->assertJsonPath('errors.team_id.0', 'Você já votou nesta sessão. O voto é definitivo e não pode ser alterado.');

        $this->assertSame($this->teamA->id, PublicVote::query()->sole()->team_id);
    }

    public function test_identical_retry_is_idempotent(): void
    {
        $first = $this->vote($this->voterA, $this->teamA)->assertCreated()->json('data');
        $retry = $this->vote($this->voterA, $this->teamA)->assertOk()->json('data');

        $this->assertSame($first, $retry);
        $this->assertSame(1, PublicVote::query()->count());
    }

    public function test_there_is_no_endpoint_to_change_or_delete_a_vote(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();
        $url = "/api/v1/voting-sessions/{$this->session->id}/vote";

        $this->actingAs($this->voterA)->patchJson($url, ['team_id' => $this->teamB->id])->assertStatus(405);
        $this->actingAs($this->voterA)->putJson($url, ['team_id' => $this->teamB->id])->assertStatus(405);
        $this->actingAs($this->voterA)->deleteJson($url)->assertStatus(405);
    }

    public function test_database_blocks_direct_update_of_a_vote(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('voto é definitivo');
        DB::table('public_votes')->update(['team_id' => $this->teamB->id]);
    }

    public function test_database_blocks_direct_delete_of_a_vote(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('voto é definitivo');
        DB::table('public_votes')->delete();
    }

    public function test_model_refuses_update_and_delete(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();
        $vote = PublicVote::query()->sole();

        $this->expectException(LogicException::class);
        $vote->delete();
    }

    public function test_database_refuses_votes_outside_the_rules(): void
    {
        $base = ['event_id' => $this->event->id, 'voting_session_id' => $this->session->id, 'cast_at' => now()];
        $notEligible = $this->voter();

        foreach ([
            'equipe não candidata' => $base + ['user_id' => $this->voterA->id, 'team_id' => $this->outsider->id],
            'usuário não elegível' => $base + ['user_id' => $notEligible->id, 'team_id' => $this->teamA->id],
        ] as $case => $row) {
            try {
                DB::transaction(fn () => DB::table('public_votes')->insert($row));
                $this->fail("O banco deveria recusar: {$case}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Duplicado do mesmo usuário na mesma sessão.
        DB::table('public_votes')->insert($base + ['user_id' => $this->voterA->id, 'team_id' => $this->teamA->id]);
        try {
            DB::transaction(fn () => DB::table('public_votes')->insert($base + ['user_id' => $this->voterA->id, 'team_id' => $this->teamB->id]));
            $this->fail('O banco deveria recusar o segundo voto.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('public_votes_voting_session_id_user_id_unique', $e->getMessage());
        }

        // Sessão fechada não recebe voto nem direto no banco.
        $this->closeSession($this->session);
        $this->expectException(QueryException::class);
        DB::table('public_votes')->insert($base + ['user_id' => $this->voterB->id, 'team_id' => $this->teamA->id]);
    }

    public function test_vote_remains_valid_after_the_voter_is_inactivated(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();

        $this->voterA->forceFill(['status' => UserStatus::Inactive])->save();
        $this->closeSession($this->session);

        $this->assertSame(1, PublicVote::query()->count());
        $this->actingAs($this->admin())->getJson("/api/v1/voting-sessions/{$this->session->id}/results")
            ->assertOk()->assertJsonPath('data.total_votes', 1);
    }

    public function test_voter_area_lists_only_eligible_sessions_without_counts(): void
    {
        $this->vote($this->voterA, $this->teamA)->assertCreated();
        $this->draftSession($this->event, [$this->teamA], [$this->voterB], 'Só para B');

        $this->actingAs($this->voterA)->getJson("/api/v1/events/{$this->event->id}/my-voting-sessions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->session->id)
            ->assertJsonPath('data.0.status', 'OPEN')
            ->assertJsonPath('data.0.has_voted', true)
            ->assertJsonMissingPath('data.0.votes')
            ->assertJsonMissingPath('data.0.vote_count');

        $this->actingAs($this->voterB)->getJson("/api/v1/events/{$this->event->id}/my-voting-sessions")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.has_voted', false);

        $this->actingAs($this->admin())->getJson("/api/v1/events/{$this->event->id}/my-voting-sessions")->assertForbidden();
    }

    public function test_ballot_shows_candidates_with_challenge_and_company_but_no_counts(): void
    {
        $response = $this->actingAs($this->voterA)->getJson("/api/v1/voting-sessions/{$this->session->id}/ballot")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'OPEN')
            ->assertJsonPath('data.can_vote', true)
            ->assertJsonPath('data.has_voted', false)
            ->assertJsonCount(2, 'data.candidates')
            ->assertJsonPath('data.candidates.0.team.name', 'Equipe A')
            ->assertJsonStructure(['data' => ['candidates' => [['team', 'challenge', 'company']]]]);

        $this->assertStringNotContainsString('vote_count', $response->getContent());

        $this->vote($this->voterA, $this->teamA)->assertCreated();
        $this->actingAs($this->voterA)->getJson("/api/v1/voting-sessions/{$this->session->id}/ballot")
            ->assertJsonPath('data.can_vote', false)->assertJsonPath('data.has_voted', true);

        $this->actingAs($this->voter())->getJson("/api/v1/voting-sessions/{$this->session->id}/ballot")->assertForbidden();
    }

    public function test_my_vote_shows_only_the_own_choice(): void
    {
        $this->actingAs($this->voterA)->getJson("/api/v1/voting-sessions/{$this->session->id}/my-vote")->assertOk()->assertJsonPath('data', null);

        $this->vote($this->voterA, $this->teamB)->assertCreated();

        $this->actingAs($this->voterA)->getJson("/api/v1/voting-sessions/{$this->session->id}/my-vote")
            ->assertOk()->assertJsonPath('data.team.id', $this->teamB->id);
        $this->actingAs($this->voterB)->getJson("/api/v1/voting-sessions/{$this->session->id}/my-vote")
            ->assertOk()->assertJsonPath('data', null);
    }
}
