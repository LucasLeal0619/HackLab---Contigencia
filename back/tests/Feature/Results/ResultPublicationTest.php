<?php

namespace Tests\Feature\Results;

use App\Domain\Audit\AuditAction;
use App\Domain\Evaluations\EvaluationService;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Domain\Voting\VotingSessionService;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\ResultPublication;
use App\Models\Sector;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Publicação oficial: snapshot imutável e versionado, exige votação CLOSED do evento e avaliações completas;
 * trava o resultado técnico enquanto PUBLISHED; correção = revogar (com motivo) e publicar a próxima versão.
 *
 * Cenário técnico (c1 0–10 peso 3; c2 1–5 peso 2):
 * - Equipe 1: A (8, 4) = 78% e B (5, 2) = 40% → 59%;
 * - Equipe 2: A (10, 5) = 100%;
 * - Equipe 3: sem jurado (sem posição técnica).
 * Votação: 5 elegíveis, 4 votos — Equipe 3 = 3, Equipe 1 = 1 (o público pode discordar dos jurados).
 */
class ResultPublicationTest extends DatabaseTestCase
{
    use JuryScenario;
    use VotingScenario;

    private VotingSession $session;

    /** @var list<User> */
    private array $voterList;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->assign($this->jurorB, [$this->team1]);

        $this->submitAs($this->userA, $this->jurorA, $this->team1, 8, 4, 'Comentário sigiloso do jurado A');
        $this->submitAs($this->userA, $this->jurorA, $this->team2, 10, 5);
        $this->submitAs($this->userB, $this->jurorB, $this->team1, 5, 2, 'Comentário sigiloso do jurado B');

        $this->voterList = $this->voters(5);
        $v = $this->voterList;
        $this->session = $this->closedSession(
            $this->event,
            [$this->team1, $this->team2, $this->team3],
            $v,
            [[$v[0], $this->team3], [$v[1], $this->team3], [$v[2], $this->team3], [$v[3], $this->team1]],
        );
    }

    private function submitAs(User $user, $juror, Team $team, float $s1, float $s2, ?string $comments = null): void
    {
        app(EvaluationService::class)->submit($this->assignmentOf($juror, $team), $user, ['comments' => $comments, 'scores' => $this->fullScores($s1, $s2)]);
    }

    private function publish(?VotingSession $session = null, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->adminUser)
            ->postJson("/api/v1/events/{$this->event->id}/results/publish", ['voting_session_id' => ($session ?? $this->session)->id]);
    }

    private function revoke(string $reason = 'Avaliação precisava ser corrigida.')
    {
        return $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/results/revoke", ['reason' => $reason]);
    }

    public function test_only_administrator_publishes(): void
    {
        $sector = Sector::factory()->for($this->event)->create();

        foreach ([
            $this->sectorUser(RoleCode::Manager, $sector),
            $this->sectorUser(RoleCode::Editor, $sector),
            $this->userWithRole(RoleCode::Consultant),
            $this->userA,
            $this->voterList[0],
        ] as $user) {
            $this->publish(actor: $user)->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/results/preview?voting_session_id={$this->session->id}")->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/events/{$this->event->id}/results/revoke", ['reason' => 'x'])->assertForbidden();
        }

        $this->assertSame(0, ResultPublication::query()->count());
    }

    public function test_voting_session_must_be_closed(): void
    {
        $voters = $this->voters(1);
        $draft = $this->draftSession($this->event, [$this->team1], $voters, 'Rascunho');
        $open = $this->openSession($this->event, [$this->team1], $voters, 'Aberta');
        $cancelled = $this->draftSession($this->event, [$this->team1], $voters, 'Cancelada');
        app(VotingSessionService::class)->cancel($cancelled, null);

        foreach ([$draft, $open, $cancelled] as $session) {
            $this->publish($session)->assertUnprocessable()->assertJsonValidationErrors('voting_session_id');
        }

        $this->assertSame(0, ResultPublication::query()->count());
    }

    public function test_voting_session_must_belong_to_the_event(): void
    {
        $otherEvent = Event::factory()->create();
        $otherTeam = Team::factory()->for($otherEvent)->create();
        $voters = $this->voters(1);
        $foreign = $this->closedSession($otherEvent, [$otherTeam], $voters, [[$voters[0], $otherTeam]], 'Outro evento');

        $this->publish($foreign)->assertUnprocessable()->assertJsonValidationErrors('voting_session_id');
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/results/publish", [])->assertUnprocessable()->assertJsonValidationErrors('voting_session_id');
    }

    public function test_incomplete_evaluations_block_publication_including_inactive_juror(): void
    {
        // Jurado sem conta recebe a Equipe 3 e não avalia; depois é inativado (atribuição continua ativa).
        $this->assign($this->jurorNoAccount, [$this->team3]);
        $this->actingAs($this->adminUser)->patchJson("/api/v1/jurors/{$this->jurorNoAccount->id}/status", ['status' => JurorStatus::Inactive->value])->assertOk();

        $this->publish()->assertUnprocessable()->assertJsonValidationErrors('evaluations');

        $preview = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/preview?voting_session_id={$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.can_publish', false)
            ->assertJsonPath('data.completeness.complete', false)
            ->assertJsonPath('data.completeness.pending_count', 1)
            ->assertJsonPath('data.completeness.pending.0.juror.status', 'INACTIVE')
            ->assertJsonPath('data.completeness.pending.0.evaluation_status', 'NOT_STARTED');
        $this->assertContains('EVALUATIONS_INCOMPLETE', array_column($preview->json('data.blockers'), 'code'));

        // Nada some sozinho: o Administrador revoga explicitamente e então publica.
        $this->assign($this->jurorNoAccount, []);
        $this->publish()->assertCreated();
    }

    public function test_publication_requires_at_least_one_valid_technical_evaluation(): void
    {
        // Avaliações SUBMITTED cujas atribuições foram revogadas não contam: a completude passaria
        // por vacuidade (nenhuma atribuição ativa), mas não há resultado técnico para publicar.
        $this->assign($this->jurorA, []);
        $this->assign($this->jurorB, []);
        $this->assertSame(3, Evaluation::query()->where('status', 'SUBMITTED')->count());

        $this->publish()
            ->assertUnprocessable()
            ->assertJsonPath('errors.evaluations.0', 'Não há nenhuma avaliação técnica válida (enviada, com atribuição ativa): o resultado oficial não pode ser publicado.');

        $preview = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/preview?voting_session_id={$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.can_publish', false)
            ->assertJsonPath('data.completeness.complete', true);
        $this->assertSame(['NO_VALID_EVALUATIONS'], array_column($preview->json('data.blockers'), 'code'));

        // Evento sem nenhuma avaliação: também não publica (sem modalidade "somente voto popular" implícita).
        $otherEvent = Event::factory()->create();
        $otherTeam = Team::factory()->for($otherEvent)->create();
        $voters = $this->voters(1);
        $popularOnly = $this->closedSession($otherEvent, [$otherTeam], $voters, [[$voters[0], $otherTeam]], 'Só voto');
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$otherEvent->id}/results/publish", ['voting_session_id' => $popularOnly->id])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluations');

        $this->assertSame(0, ResultPublication::query()->count());

        // Uma única avaliação válida já basta (sem mínimo de jurados inventado).
        // Reativar A → Equipe 2 traz de volta a avaliação enviada dela (100%).
        $this->assign($this->jurorA, [$this->team2]);
        $this->publish()->assertCreated();

        $technical = ResultPublication::query()->sole()->snapshot['technical']['teams'];
        $this->assertSame(['Equipe 2', 'Equipe 1', 'Equipe 3'], array_column(array_column($technical, 'team'), 'name'));
        $this->assertSame([1, null, null], array_column($technical, 'rank'));
        $this->assertSame([1, 0, 0], array_column($technical, 'valid_evaluations'));
    }

    public function test_draft_or_revision_requested_evaluation_also_blocks(): void
    {
        $evaluation = Evaluation::query()->where('juror_id', $this->jurorB->id)->where('team_id', $this->team1->id)->sole();
        $this->actingAs($this->adminUser)->postJson("/api/v1/evaluations/{$evaluation->id}/request-revision", ['reason' => 'Revisar'])->assertOk();

        $this->publish()->assertUnprocessable()->assertJsonValidationErrors('evaluations');
    }

    public function test_preview_shows_both_rankings_separately_with_blockers(): void
    {
        $data = $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/preview?voting_session_id={$this->session->id}")
            ->assertOk()->json('data');

        $this->assertTrue($data['can_publish']);
        $this->assertSame([], $data['blockers']);
        $this->assertSame(['Equipe 2', 'Equipe 1', 'Equipe 3'], array_column(array_column($data['technical']['teams'], 'team'), 'name'));
        $this->assertSame([1, 2, null], array_column($data['technical']['teams'], 'rank'));
        $this->assertSame(['Equipe 3', 'Equipe 1', 'Equipe 2'], array_column(array_column($data['popular']['teams'], 'team'), 'name'));
        $this->assertSame(['technical' => 'juror_evaluations', 'popular' => 'one_person_one_vote', 'combined' => false], $data['rules']);

        // Sem sessão escolhida: técnico aparece, popular não, e há bloqueio explícito.
        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/preview")
            ->assertOk()->assertJsonPath('data.popular', null)->assertJsonPath('data.blockers.0.code', 'VOTING_SESSION_REQUIRED');
    }

    public function test_snapshot_is_created_correctly(): void
    {
        $this->publish()->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'PUBLISHED');

        $publication = ResultPublication::query()->sole();
        $snapshot = $publication->snapshot;

        $this->assertSame(1, $snapshot['version']);
        $this->assertSame($this->event->id, $snapshot['event']['id']);
        $this->assertSame($this->session->id, $snapshot['voting_session']['id']);

        $technical = $snapshot['technical']['teams'];
        $this->assertSame(['Equipe 2', 'Equipe 1', 'Equipe 3'], array_column(array_column($technical, 'team'), 'name'));
        $this->assertSame([100, 59, null], array_column($technical, 'technical_score'));
        $this->assertSame([1, 2, 0], array_column($technical, 'valid_evaluations'));
        $this->assertSame([1, 2, null], array_column($technical, 'rank'));
        $this->assertSame(0, bccomp('59', $technical[1]['technical_score_exact'], 30));

        $popular = $snapshot['popular'];
        $this->assertSame(['Equipe 3', 'Equipe 1', 'Equipe 2'], array_column(array_column($popular['teams'], 'team'), 'name'));
        $this->assertSame([3, 1, 0], array_column($popular['teams'], 'vote_count'));
        $this->assertSame([75, 25, 0], array_column($popular['teams'], 'percentage'));
        $this->assertSame([1, 2, 3], array_column($popular['teams'], 'rank'));
        $this->assertSame(4, $popular['total_votes']);

        // jsonb reordena as chaves: compara sem ordem.
        $this->assertEquals(['eligible_voters' => 5, 'votes_cast' => 4, 'pending_voters' => 1, 'turnout_percent' => 80], $snapshot['turnout']);
        $this->assertFalse($snapshot['rules']['combined']);

        $log = AuditLog::query()->where('action', AuditAction::RESULTS_PUBLISHED)->sole();
        $this->assertSame(1, $log->after_data['version']);
        $this->assertSame($this->adminUser->id, $log->actor_user_id);
    }

    public function test_snapshot_has_no_voter_identities_nor_individual_juror_scores_or_comments(): void
    {
        $this->publish()->assertCreated();
        $json = json_encode(ResultPublication::query()->sole()->snapshot);

        foreach ($this->voterList as $voter) {
            $this->assertStringNotContainsString($voter->email, $json);
            $this->assertStringNotContainsString($voter->person->full_name, $json);
        }

        foreach ([$this->jurorA, $this->jurorB] as $juror) {
            $this->assertStringNotContainsString($juror->person->full_name, $json);
        }

        $this->assertStringNotContainsString('Comentário sigiloso', $json);
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString('juror_id', $json);
        $this->assertStringNotContainsString('"juror"', $json);
        $this->assertStringNotContainsString('evaluation_id', $json);
        $this->assertStringNotContainsString('"scores"', $json);
        $this->assertStringNotContainsString('"comments"', $json);
    }

    public function test_second_active_publication_is_refused(): void
    {
        $this->publish()->assertCreated();
        $this->publish()->assertUnprocessable()->assertJsonValidationErrors('publication');

        $this->assertSame(1, ResultPublication::query()->count());

        // O índice parcial (1 PUBLISHED por evento) também barra direto no banco.
        $this->expectException(QueryException::class);
        DB::table('result_publications')->insert([
            'event_id' => $this->event->id, 'voting_session_id' => $this->session->id, 'version' => 2, 'status' => 'PUBLISHED',
            'snapshot' => '{}', 'published_by_user_id' => $this->adminUser->id, 'published_at' => now(),
        ]);
    }

    public function test_publication_locks_revision_requests_and_assignment_changes(): void
    {
        $this->publish()->assertCreated();
        $evaluation = Evaluation::query()->where('juror_id', $this->jurorB->id)->where('team_id', $this->team1->id)->sole();

        $this->actingAs($this->adminUser)->postJson("/api/v1/evaluations/{$evaluation->id}/request-revision", ['reason' => 'Corrigir nota'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($this->adminUser)->putJson("/api/v1/jurors/{$this->jurorB->id}/assignments", ['team_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids');
        $this->actingAs($this->adminUser)->putJson("/api/v1/jurors/{$this->jurorNoAccount->id}/assignments", ['team_ids' => [$this->team3->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('team_ids');

        $this->assertSame('SUBMITTED', $evaluation->fresh()->status->value);

        // Banco: triggers recusam mesmo sem passar pela aplicação.
        foreach ([
            fn () => DB::table('evaluations')->where('id', $evaluation->id)->update(['status' => 'REVISION_REQUESTED']),
            fn () => DB::table('juror_team_assignments')->where('juror_id', $this->jurorB->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('O banco deveria recusar a alteração técnica com resultado publicado.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('resultado publicado', $e->getMessage());
            }
        }

        // O resultado oficial continua igual ao publicado.
        $this->actingAs($this->voterList[0])->getJson("/api/v1/events/{$this->event->id}/results/published")
            ->assertOk()->assertJsonPath('data.snapshot.technical.teams.1.technical_score', 59);
    }

    public function test_revoke_requires_a_reason(): void
    {
        $this->publish()->assertCreated();

        $this->revoke('   ')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('PUBLISHED', ResultPublication::query()->sole()->status->value);
    }

    public function test_revoke_preserves_the_version_releases_corrections_and_republishing_creates_version_2(): void
    {
        $this->publish()->assertCreated();

        $this->revoke()->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')
            ->assertJsonPath('data.revoke_reason', 'Avaliação precisava ser corrigida.')
            ->assertJsonPath('data.revoked_by_user_id', $this->adminUser->id);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::RESULTS_PUBLICATION_REVOKED]);

        // Nenhum resultado oficial agora.
        $this->actingAs($this->voterList[0])->getJson("/api/v1/events/{$this->event->id}/results/published")
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.official', false);

        // Correção liberada: revisão → jurado B reenvia (5, 2) → (10, 5): Equipe 1 = (78 + 100) / 2 = 89%.
        $evaluation = Evaluation::query()->where('juror_id', $this->jurorB->id)->where('team_id', $this->team1->id)->sole();
        $this->actingAs($this->adminUser)->postJson("/api/v1/evaluations/{$evaluation->id}/request-revision", ['reason' => 'Nota trocada'])->assertOk();
        $this->submitAs($this->userB, $this->jurorB, $this->team1, 10, 5);

        $this->publish()->assertCreated()->assertJsonPath('data.version', 2);

        $versions = ResultPublication::query()->orderBy('version')->get();
        $this->assertSame(['REVOKED', 'PUBLISHED'], $versions->map(fn ($p) => $p->status->value)->all());
        $this->assertSame(59, $versions[0]->snapshot['technical']['teams'][1]['technical_score']);
        $this->assertSame(89, $versions[1]->snapshot['technical']['teams'][1]['technical_score']);

        // Só a PUBLISHED é oficial; o histórico (Administrador) mostra as duas.
        $this->actingAs($this->userA)->getJson("/api/v1/events/{$this->event->id}/results/published")
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonMissingPath('data.revoke_reason');
        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/results/publications")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.version', 1)->assertJsonPath('data.1.status', 'REVOKED');
    }

    public function test_published_snapshot_is_immutable_in_the_database(): void
    {
        $this->publish()->assertCreated();
        $id = ResultPublication::query()->sole()->id;

        foreach ([
            fn () => DB::table('result_publications')->where('id', $id)->update(['snapshot' => '{"forjado": true}']),
            fn () => DB::table('result_publications')->where('id', $id)->update(['version' => 7]),
            fn () => DB::table('result_publications')->where('id', $id)->delete(),
            fn () => DB::table('result_publications')->where('id', $id)->update(['status' => 'REVOKED']), // sem quem/quando/motivo
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('O banco deveria recusar a alteração da publicação.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->revoke()->assertOk();

        $this->expectException(QueryException::class);
        DB::table('result_publications')->where('id', $id)->update(['status' => 'PUBLISHED', 'revoked_at' => null, 'revoked_by_user_id' => null, 'revoke_reason' => null]);
    }

    public function test_every_authenticated_profile_sees_only_the_published_snapshot(): void
    {
        $sector = Sector::factory()->for($this->event)->create();
        $profiles = [
            $this->adminUser,
            $this->sectorUser(RoleCode::Manager, $sector),
            $this->sectorUser(RoleCode::Editor, $sector),
            $this->userWithRole(RoleCode::Consultant),
            $this->userA,
            $this->voterList[0],
        ];

        // Antes da publicação: ninguém recebe ranking (nem prévia, exceto o Administrador).
        foreach ($profiles as $user) {
            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/results/published")->assertOk()->assertJsonPath('data', null);
        }

        $this->publish()->assertCreated();

        foreach ($profiles as $user) {
            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/results/published")
                ->assertOk()
                ->assertJsonPath('meta.official', true)
                ->assertJsonPath('data.version', 1)
                ->assertJsonPath('data.snapshot.popular.teams.0.team.name', 'Equipe 3');
        }

        // Ver o resultado não concede telas administrativas.
        $this->actingAs($this->voterList[0])->getJson("/api/v1/voting-sessions/{$this->session->id}/results")->assertForbidden();
        $this->actingAs($this->userA)->getJson("/api/v1/events/{$this->event->id}/technical-results")->assertForbidden();
        $this->actingAs($this->userA)->getJson("/api/v1/events/{$this->event->id}/results/publications")->assertForbidden();
    }
}
