<?php

namespace Tests\Feature\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Teams\Enums\TeamStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Users\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingEligibility;
use App\Models\VotingSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Sessão de votação: pertence ao evento, nome único sem diferença de caixa, configuração explícita só em
 * DRAFT, abertura com pré-condições, uma OPEN por evento, fechamento definitivo e cancelamento.
 */
class VotingSessionTest extends DatabaseTestCase
{
    use VotingScenario;

    private Event $event;

    private User $adminUser;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->create();
        $this->teamA = Team::factory()->for($this->event)->create(['name' => 'Equipe A']);
        $this->teamB = Team::factory()->for($this->event)->create(['name' => 'Equipe B']);
        $this->adminUser = $this->admin();
    }

    private function createViaApi(string $name = 'Escolha do Público'): VotingSession
    {
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/voting-sessions", ['name' => $name])
            ->assertCreated()->json('data.id');

        return VotingSession::query()->findOrFail($id);
    }

    public function test_admin_creates_a_draft_session_that_belongs_to_the_event(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/events/{$this->event->id}/voting-sessions", ['name' => 'Escolha do Público'])
            ->assertCreated()
            ->assertJsonPath('data.event_id', $this->event->id)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.opened_at', null)
            ->assertJsonPath('data.candidates_count', 0)
            ->assertJsonPath('data.eligible_voters_count', 0);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::VOTING_SESSION_CREATED, 'actor_user_id' => $this->adminUser->id]);
    }

    public function test_status_cannot_be_set_on_creation(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/events/{$this->event->id}/voting-sessions", ['name' => 'Sessão', 'status' => 'OPEN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_name_is_unique_per_event_case_insensitive(): void
    {
        $this->createViaApi('Escolha do Público');

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/events/{$this->event->id}/voting-sessions", ['name' => '  ESCOLHA DO PÚBLICO '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Outro evento pode repetir o nome.
        $other = Event::factory()->create();
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$other->id}/voting-sessions", ['name' => 'Escolha do Público'])->assertCreated();

        // O índice do banco também barra (sem a validação da API).
        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->insert(['event_id' => $this->event->id, 'name' => 'escolha do público', 'status' => 'DRAFT', 'created_by_user_id' => $this->adminUser->id]);
    }

    public function test_database_accepts_only_valid_statuses(): void
    {
        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->insert(['event_id' => $this->event->id, 'name' => 'X', 'status' => 'PAUSED', 'created_by_user_id' => $this->adminUser->id]);
    }

    public function test_there_is_no_delete_endpoint_and_the_database_refuses_deletes(): void
    {
        $session = $this->createViaApi();

        $this->actingAs($this->adminUser)->deleteJson("/api/v1/voting-sessions/{$session->id}")->assertStatus(405);

        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->where('id', $session->id)->delete();
    }

    public function test_only_administrator_manages_sessions(): void
    {
        $session = $this->createViaApi();

        foreach ([RoleCode::Consultant, RoleCode::Juror, RoleCode::Voter] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/voting-sessions")->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/events/{$this->event->id}/voting-sessions", ['name' => 'Outra'])->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/voting-sessions/{$session->id}/open")->assertForbidden();
            $this->actingAs($user)->putJson("/api/v1/voting-sessions/{$session->id}/teams", ['team_ids' => [$this->teamA->id]])->assertForbidden();
        }
    }

    public function test_listing_filters_by_status_and_search_and_paginates(): void
    {
        $this->createViaApi('Teste de som');
        $this->draftSession($this->event, [$this->teamA], [$this->voter()], 'Oficial');
        $this->openSession($this->event, [$this->teamA], [$this->voter()], 'Ensaio');

        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/voting-sessions?status=OPEN")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ensaio')->assertJsonStructure(['meta' => ['current_page', 'total']]);

        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/voting-sessions?search=ofic")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Oficial');
    }

    public function test_candidates_must_be_active_teams_of_the_same_event_without_repetition(): void
    {
        $session = $this->createViaApi();
        $otherEventTeam = Team::factory()->for(Event::factory())->create();
        $inactive = Team::factory()->for($this->event)->create(['status' => TeamStatus::Inactive]);
        $url = "/api/v1/voting-sessions/{$session->id}/teams";

        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$otherEventTeam->id]])->assertUnprocessable()->assertJsonValidationErrors('team_ids.0');
        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$inactive->id]])->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$this->teamA->id, $this->teamA->id]])->assertUnprocessable()->assertJsonValidationErrors('team_ids.0');

        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$this->teamA->id, $this->teamB->id]])
            ->assertOk()->assertJsonPath('data.candidates_count', 2)->assertJsonPath('data.candidates.0.name', 'Equipe A');

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_CANDIDATES_CHANGED)->count());

        // Banco: candidata repetida, de outro evento ou inativa é recusada.
        foreach ([$this->teamA->id, $otherEventTeam->id, $inactive->id] as $teamId) {
            try {
                DB::transaction(fn () => DB::table('voting_session_teams')->insert(['event_id' => $this->event->id, 'voting_session_id' => $session->id, 'team_id' => $teamId]));
                $this->fail("Candidata {$teamId} deveria ser recusada pelo banco.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_candidate_list_sync_adds_and_removes_with_a_single_log(): void
    {
        $session = $this->createViaApi();
        $url = "/api/v1/voting-sessions/{$session->id}/teams";

        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$this->teamA->id]])->assertOk();
        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$this->teamB->id]])->assertOk()->assertJsonPath('data.candidates.0.id', $this->teamB->id);
        $this->actingAs($this->adminUser)->putJson($url, ['team_ids' => [$this->teamB->id]])->assertOk(); // sem mudança: sem log

        $logs = AuditLog::query()->where('action', AuditAction::VOTING_CANDIDATES_CHANGED)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->teamB->id], $logs[1]->after_data['team_ids']);
        $this->assertSame([$this->teamB->id], $logs[1]->after_data['added']);
        $this->assertSame([$this->teamA->id], $logs[1]->after_data['removed']);
    }

    public function test_eligible_voter_must_be_an_active_voter_account(): void
    {
        $session = $this->createViaApi();
        $url = "/api/v1/voting-sessions/{$session->id}/eligibilities";
        $inactiveVoter = $this->voter();
        $inactiveVoter->forceFill(['status' => UserStatus::Inactive])->save();

        foreach ([$this->userWithRole(RoleCode::Juror), $this->userWithRole(RoleCode::Consultant), $this->adminUser, $inactiveVoter] as $user) {
            $this->actingAs($this->adminUser)->putJson($url, ['user_ids' => [$user->id]])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        }

        $voter = $this->voter();
        $usersBefore = User::query()->count();

        $this->actingAs($this->adminUser)->putJson($url, ['user_ids' => [$voter->id]])->assertOk()->assertJsonPath('data.eligible_voters_count', 1);

        // Não cria conta nem pessoa.
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_ELIGIBILITY_CHANGED)->count());
    }

    public function test_eligibility_is_unique_per_session_and_user(): void
    {
        $voter = $this->voter();
        $session = $this->draftSession($this->event, [$this->teamA], [$voter]);

        $this->actingAs($this->adminUser)->putJson("/api/v1/voting-sessions/{$session->id}/eligibilities", ['user_ids' => [$voter->id, $voter->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids.0');

        $this->expectException(QueryException::class);
        DB::table('voting_eligibilities')->insert(['event_id' => $this->event->id, 'voting_session_id' => $session->id, 'user_id' => $voter->id, 'created_by_user_id' => $this->adminUser->id]);
    }

    public function test_database_refuses_eligibility_for_non_voter_accounts(): void
    {
        $session = $this->createViaApi();

        $this->expectException(QueryException::class);
        DB::table('voting_eligibilities')->insert(['event_id' => $this->event->id, 'voting_session_id' => $session->id, 'user_id' => $this->adminUser->id, 'created_by_user_id' => $this->adminUser->id]);
    }

    public function test_opening_without_candidates_is_rejected(): void
    {
        $session = $this->draftSession($this->event, [], [$this->voter()]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/open")
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->assertSame('DRAFT', $session->fresh()->status->value);
    }

    public function test_opening_without_eligible_voters_is_rejected(): void
    {
        $session = $this->draftSession($this->event, [$this->teamA], []);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/open")
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    }

    public function test_opening_revalidates_teams_and_voters(): void
    {
        $voter = $this->voter();
        $session = $this->draftSession($this->event, [$this->teamA, $this->teamB], [$voter]);
        $url = "/api/v1/voting-sessions/{$session->id}/open";

        $this->teamB->forceFill(['status' => TeamStatus::Inactive])->save();
        $this->actingAs($this->adminUser)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('team_ids');

        $this->teamB->forceFill(['status' => TeamStatus::Active])->save();
        $voter->forceFill(['status' => UserStatus::Inactive])->save();
        $this->actingAs($this->adminUser)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('user_ids');

        $voter->forceFill(['status' => UserStatus::Active])->save();
        $voter->forceFill(['role_id' => Role::forCode(RoleCode::Consultant)->id])->save();
        $this->actingAs($this->adminUser)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    }

    public function test_open_sets_status_and_opened_at_and_is_audited(): void
    {
        $session = $this->draftSession($this->event, [$this->teamA], [$this->voter(), $this->voter()]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/open")
            ->assertOk()->assertJsonPath('data.status', 'OPEN');

        $this->assertNotNull($session->fresh()->opened_at);
        $log = AuditLog::query()->where('action', AuditAction::VOTING_SESSION_OPENED)->sole();
        $this->assertSame(1, $log->after_data['candidates']);
        $this->assertSame(2, $log->after_data['eligible_voters']);
    }

    public function test_only_one_open_session_per_event(): void
    {
        $this->openSession($this->event, [$this->teamA], [$this->voter()], 'Primeira');
        $second = $this->draftSession($this->event, [$this->teamA], [$this->voter()], 'Segunda');

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$second->id}/open")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // Outro evento pode ter a sua aberta.
        $otherEvent = Event::factory()->create();
        $this->openSession($otherEvent, [Team::factory()->for($otherEvent)->create()], [$this->voter()]);

        // O índice parcial também barra direto no banco.
        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->where('id', $second->id)->update(['status' => 'OPEN', 'opened_at' => now()]);
    }

    public function test_configuration_is_locked_after_opening(): void
    {
        $voter = $this->voter();
        $session = $this->openSession($this->event, [$this->teamA], [$voter]);

        $this->actingAs($this->adminUser)->putJson("/api/v1/voting-sessions/{$session->id}/teams", ['team_ids' => [$this->teamA->id, $this->teamB->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->actingAs($this->adminUser)->putJson("/api/v1/voting-sessions/{$session->id}/eligibilities", ['user_ids' => [$voter->id, $this->voter()->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->actingAs($this->adminUser)->patchJson("/api/v1/voting-sessions/{$session->id}", ['name' => 'Outro nome'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        // Banco: nada entra nem sai depois da abertura.
        foreach ([
            fn () => DB::table('voting_session_teams')->insert(['event_id' => $this->event->id, 'voting_session_id' => $session->id, 'team_id' => $this->teamB->id]),
            fn () => DB::table('voting_session_teams')->where('voting_session_id', $session->id)->delete(),
            fn () => VotingEligibility::query()->where('voting_session_id', $session->id)->delete(),
            fn () => DB::table('voting_sessions')->where('id', $session->id)->update(['name' => 'Renomeada']),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('O banco deveria recusar a alteração com a sessão aberta.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, DB::table('voting_session_teams')->where('voting_session_id', $session->id)->count());
    }

    public function test_draft_can_be_renamed_and_reconfigured(): void
    {
        $session = $this->draftSession($this->event, [$this->teamA], [$this->voter()]);

        $this->actingAs($this->adminUser)->patchJson("/api/v1/voting-sessions/{$session->id}", ['name' => 'Novo nome'])
            ->assertOk()->assertJsonPath('data.name', 'Novo nome');
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::VOTING_SESSION_UPDATED]);
    }

    public function test_open_to_closed_and_closed_never_reopens(): void
    {
        $session = $this->openSession($this->event, [$this->teamA], [$this->voter()]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/close")
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertNotNull($session->fresh()->closed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::VOTING_SESSION_CLOSED]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/open")->assertUnprocessable();
        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/close")->assertUnprocessable();
        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/cancel")->assertUnprocessable();

        // Banco: CLOSED é definitivo.
        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->where('id', $session->id)->update(['status' => 'OPEN', 'closed_at' => null]);
    }

    public function test_draft_cannot_be_closed_directly(): void
    {
        $session = $this->draftSession($this->event, [$this->teamA], [$this->voter()]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/close")->assertUnprocessable();
    }

    public function test_draft_can_be_cancelled(): void
    {
        $session = $this->draftSession($this->event, [$this->teamA], [$this->voter()]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::VOTING_SESSION_CANCELLED]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/open")->assertUnprocessable();
    }

    public function test_open_session_without_votes_can_be_cancelled_only_with_a_reason(): void
    {
        $session = $this->openSession($this->event, [$this->teamA], [$this->voter()]);
        $url = "/api/v1/voting-sessions/{$session->id}/cancel";

        $this->actingAs($this->adminUser)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->adminUser)->postJson($url, ['reason' => 'Sessão aberta por engano.'])
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancel_reason', 'Sessão aberta por engano.');

        $log = AuditLog::query()->where('action', AuditAction::VOTING_SESSION_CANCELLED)->sole();
        $this->assertSame('OPEN', $log->before_data['status']);
        $this->assertSame('Sessão aberta por engano.', $log->after_data['reason']);
    }

    public function test_session_with_votes_cannot_be_cancelled(): void
    {
        $voter = $this->voter();
        $session = $this->openSession($this->event, [$this->teamA], [$voter]);
        $this->castVote($session, $voter, $this->teamA);

        $this->actingAs($this->adminUser)->postJson("/api/v1/voting-sessions/{$session->id}/cancel", ['reason' => 'Tentativa'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->expectException(QueryException::class);
        DB::table('voting_sessions')->where('id', $session->id)->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
    }

    public function test_repeating_a_vote_means_a_new_session_and_the_old_one_is_preserved(): void
    {
        $voter = $this->voter();
        $first = $this->closedSession($this->event, [$this->teamA, $this->teamB], [$voter], [[$voter, $this->teamA]], 'Primeira');
        $second = $this->openSession($this->event, [$this->teamA, $this->teamB], [$voter], 'Segunda');
        $this->castVote($second, $voter, $this->teamB);

        $this->assertSame('CLOSED', $first->fresh()->status->value);
        $this->assertSame(1, DB::table('public_votes')->where('voting_session_id', $first->id)->where('team_id', $this->teamA->id)->count());
        $this->assertSame(1, DB::table('public_votes')->where('voting_session_id', $second->id)->where('team_id', $this->teamB->id)->count());
    }
}
