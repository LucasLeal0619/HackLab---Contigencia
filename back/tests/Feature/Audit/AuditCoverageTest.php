<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAction;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Participant;
use App\Models\Team;
use Database\Seeders\DatabaseSeeder;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Revalidação das decisões de cobertura das Fases 3, 6 e 7: o que audita (um log semântico),
 * o que não audita (rascunho, voto, retry) e o que nunca entra no log (notas, comentários, eleitor → equipe).
 */
class AuditCoverageTest extends DatabaseTestCase
{
    use JuryScenario;
    use VotingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
    }

    public function test_public_vote_and_its_retry_never_create_audit_logs(): void
    {
        $voters = $this->voters(2);
        $session = $this->openSession($this->event, [$this->team1, $this->team2], $voters);
        $before = AuditLog::query()->count();

        $url = "/api/v1/voting-sessions/{$session->id}/vote";
        $this->actingAs($voters[0])->postJson($url, ['team_id' => $this->team1->id])->assertCreated();
        $this->actingAs($voters[0])->postJson($url, ['team_id' => $this->team1->id])->assertOk(); // retry
        $this->actingAs($voters[0])->postJson($url, ['team_id' => $this->team2->id])->assertUnprocessable(); // troca recusada
        $this->actingAs($voters[1])->postJson($url, ['team_id' => $this->team2->id])->assertCreated();

        $this->assertSame($before, AuditLog::query()->count());

        // Nenhum log, de nenhuma ação, cruza um eleitor com uma equipe.
        foreach (AuditLog::query()->get() as $log) {
            $this->assertNotContains($log->actor_user_id, [$voters[0]->id, $voters[1]->id]);
            $this->assertNotSame('PublicVote', $log->entity_type);
        }
    }

    public function test_evaluation_draft_is_not_audited_and_transitions_log_once_without_scores_or_comments(): void
    {
        $this->assign($this->jurorA, [$this->team1]);
        $assignment = $this->assignmentOf($this->jurorA, $this->team1);
        $base = "/api/v1/juror-assignments/{$assignment->id}/evaluation";
        $secret = 'Comentário confidencial do jurado';
        $count = fn (string $action) => AuditLog::query()->where('action', $action)->count();

        $before = AuditLog::query()->count();
        $this->actingAs($this->userA)->putJson($base, ['comments' => $secret, 'scores' => [['criterion_id' => $this->c1->id, 'score' => 7.25]]])->assertOk();
        $this->assertSame($before, AuditLog::query()->count(), 'Rascunho não gera auditoria.');

        $this->actingAs($this->userA)->postJson("{$base}/submit", ['comments' => $secret, 'scores' => $this->fullScores(7.25, 3)])->assertOk();
        $evaluation = Evaluation::query()->sole();
        $this->actingAs($this->adminUser)->postJson("/api/v1/evaluations/{$evaluation->id}/request-revision", ['reason' => 'Rever viabilidade'])->assertOk();
        $this->actingAs($this->userA)->postJson("{$base}/submit", ['comments' => $secret, 'scores' => $this->fullScores(7.25, 4)])->assertOk();

        $this->assertSame(1, $count(AuditAction::EVALUATION_SUBMITTED));
        $this->assertSame(1, $count(AuditAction::EVALUATION_REVISION_REQUESTED));
        $this->assertSame(1, $count(AuditAction::EVALUATION_RESUBMITTED));

        foreach (AuditLog::query()->where('module', 'evaluations')->where('entity_type', 'Evaluation')->get() as $log) {
            $payload = json_encode([$log->before_data, $log->after_data, $log->description, $log->entity_label]);
            $this->assertStringNotContainsString($secret, $payload);
            $this->assertStringNotContainsString('7.25', $payload);
            $this->assertStringNotContainsString('"score', $payload);
            $this->assertSame($this->event->id, $log->event_id);
            $this->assertSame('Avaliação · Equipe 1', $log->entity_label);
        }
    }

    public function test_moving_a_participant_creates_a_single_semantic_log(): void
    {
        $participant = Participant::factory()->for($this->event)->create();
        $this->actingAs($this->adminUser)->postJson("/api/v1/teams/{$this->team1->id}/members", ['participant_id' => $participant->id])->assertSuccessful();
        $lastLog = (int) AuditLog::query()->max('id');

        $this->actingAs($this->adminUser)->patchJson("/api/v1/participants/{$participant->id}/team", ['team_id' => $this->team2->id])->assertOk();

        $logs = AuditLog::query()->where('id', '>', $lastLog)->get();
        $this->assertSame([AuditAction::PARTICIPANT_TEAM_CHANGED], $logs->pluck('action')->all());
        $this->assertSame($participant->person->full_name, $logs->first()->entity_label);
        $this->assertSame($this->event->id, $logs->first()->event_id);
    }

    public function test_assignment_list_change_is_one_log(): void
    {
        $before = AuditLog::query()->count();

        $this->actingAs($this->adminUser)->putJson("/api/v1/jurors/{$this->jurorA->id}/assignments", ['team_ids' => [$this->team1->id, $this->team2->id, $this->team3->id]])->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->count());
        $this->assertSame('Jurada A', AuditLog::query()->latest('id')->first()->entity_label);
    }

    public function test_second_seed_run_does_not_create_artificial_logs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = AuditLog::query()->count();
        $teams = Team::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, AuditLog::query()->count());
        $this->assertSame($teams, Team::query()->count());
        // Os logs do seed já nascem enriquecidos.
        $this->assertSame(0, AuditLog::query()->whereNotNull('actor_user_id')->whereNull('actor_role_code')->count());
        $this->assertSame(0, AuditLog::query()->whereNotNull('entity_type')->whereNull('entity_label')->count());
    }
}
