<?php

namespace Tests\Feature\Evaluations;

use App\Domain\Evaluations\EvaluationScoring;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Users\Enums\RoleCode;
use App\Models\Evaluation;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * percentual = 100 * Σ(((score - min) / (max - min)) * peso) / Σ(peso)
 * Cenário: c1 0–10 peso 3; c2 1–5 peso 2 (pesos somam 5, não 100).
 */
class EvaluationScoringTest extends DatabaseTestCase
{
    use JuryScenario;
    use VotingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->assign($this->jurorB, [$this->team1]);
    }

    private function submit($user, $juror, $team, float $s1, float $s2): void
    {
        $this->actingAs($user)->postJson("/api/v1/juror-assignments/{$this->assignmentOf($juror, $team)->id}/evaluation/submit", ['scores' => $this->fullScores($s1, $s2)])->assertOk();
    }

    public function test_normalization_with_different_ranges_and_weights_not_summing_100(): void
    {
        $this->submit($this->userA, $this->jurorA, $this->team1, 8, 4); // (0.8*3 + 0.75*2) / 5 = 78%
        $this->submit($this->userB, $this->jurorB, $this->team1, 5, 2); // (0.5*3 + 0.25*2) / 5 = 40%

        $scoring = app(EvaluationScoring::class);
        $percents = Evaluation::query()->with('scores')->orderBy('id')->get()->map(fn ($e) => $scoring->percent($e))->all();
        $this->assertEqualsWithDelta(78.0, $percents[0], 1e-9);
        $this->assertEqualsWithDelta(40.0, $percents[1], 1e-9);

        $this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/technical-results")->assertOk()
            ->assertJsonPath('data.0.team.id', $this->team1->id)
            ->assertJsonPath('data.0.submitted_evaluations', 2)
            ->assertJsonPath('data.0.technical_score', 59);
    }

    public function test_min_and_max_scores_give_0_and_100(): void
    {
        $this->submit($this->userA, $this->jurorA, $this->team1, 0, 1);
        $this->submit($this->userA, $this->jurorA, $this->team2, 10, 5);

        $results = collect($this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/technical-results")->json('data'))->keyBy('team.id');
        $this->assertEquals(0, $results[$this->team1->id]['technical_score']);
        $this->assertEquals(100, $results[$this->team2->id]['technical_score']);
    }

    public function test_only_submitted_with_active_assignment_counts_and_revoked_returns_when_reactivated(): void
    {
        $this->submit($this->userA, $this->jurorA, $this->team1, 8, 4); // 78
        $this->submit($this->userB, $this->jurorB, $this->team1, 5, 2); // 40
        $this->actingAs($this->userA)->putJson("/api/v1/juror-assignments/{$this->assignmentOf($this->jurorA, $this->team2)->id}/evaluation", ['scores' => [['criterion_id' => $this->c1->id, 'score' => 10]]])->assertOk(); // rascunho não conta

        $score = fn () => collect($this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/technical-results")->json('data'))->keyBy('team.id');

        $this->assertEquals(59, $score()[$this->team1->id]['technical_score']);
        $this->assertNull($score()[$this->team2->id]['technical_score']);

        $this->assign($this->jurorB, []); // revoga B na equipe 1
        $this->assertEquals(78, $score()[$this->team1->id]['technical_score']);
        $this->assertSame(3, Evaluation::query()->count()); // histórico preservado

        $this->assign($this->jurorB, [$this->team1]); // reativa
        $this->assertEquals(59, $score()[$this->team1->id]['technical_score']);
    }

    public function test_progress_is_derived_and_inactive_juror_is_still_expected(): void
    {
        $this->assign($this->jurorNoAccount, [$this->team1]);
        $this->submit($this->userA, $this->jurorA, $this->team1, 8, 4);
        $this->actingAs($this->userB)->putJson("/api/v1/juror-assignments/{$this->assignmentOf($this->jurorB, $this->team1)->id}/evaluation", ['comments' => 'começando'])->assertOk();
        $this->jurorNoAccount->update(['status' => JurorStatus::Inactive]);

        $progress = collect($this->actingAs($this->adminUser)->getJson("/api/v1/events/{$this->event->id}/evaluation-progress")->assertOk()->json('data'))->keyBy('team.id');

        $this->assertSame([
            'team' => ['id' => $this->team1->id, 'name' => 'Equipe 1'],
            'assigned_jurors' => 3,
            'submitted' => 1,
            'draft' => 1,
            'revision_requested' => 0,
            'not_started' => 1,
            'completion_percent' => 33.33,
        ], $progress[$this->team1->id]);
        $this->assertSame(1, $progress[$this->team2->id]['not_started']);
        $this->assertNull($progress[$this->team3->id]['completion_percent']);
    }

    public function test_manager_and_consultant_see_progress_without_scores_or_comments(): void
    {
        $this->actingAs($this->userA)->postJson("/api/v1/juror-assignments/{$this->assignmentOf($this->jurorA, $this->team1)->id}/evaluation/submit", ['scores' => $this->fullScores(), 'comments' => 'Comentário sigiloso'])->assertOk();
        $evaluation = Evaluation::query()->sole();

        foreach ([RoleCode::Manager, RoleCode::Consultant] as $role) {
            $user = $this->userWithRole($role);
            $body = $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/evaluation-progress")->assertOk()->getContent();
            $this->assertStringNotContainsString('Comentário sigiloso', $body);
            $this->assertStringNotContainsString('score', $body);

            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/evaluations")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/evaluations/{$evaluation->id}")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/events/{$this->event->id}/technical-results")->assertForbidden();
        }

        foreach ([RoleCode::Editor, RoleCode::Voter] as $role) {
            $this->actingAs($this->userWithRole($role))->getJson("/api/v1/events/{$this->event->id}/evaluation-progress")->assertForbidden();
        }

        $this->actingAs($this->userA)->getJson("/api/v1/events/{$this->event->id}/technical-results")->assertForbidden();
    }

    /**
     * Fase 6 afirmava que a votação ainda não existia; na Fase 7 ela existe, e a regra preservada é que
     * voto público nunca entra no cálculo técnico.
     */
    public function test_public_votes_are_not_part_of_the_technical_calculation(): void
    {
        $this->submit($this->userA, $this->jurorA, $this->team1, 8, 4);
        $url = "/api/v1/events/{$this->event->id}/technical-results";
        $before = $this->actingAs($this->adminUser)->getJson($url)->json('data');

        $voters = $this->voters(3);
        $this->closedSession($this->event, [$this->team1, $this->team2], $voters, [[$voters[0], $this->team2], [$voters[1], $this->team2], [$voters[2], $this->team2]]);

        $this->actingAs($this->adminUser)->getJson($url)
            ->assertJsonPath('data', $before)
            ->assertJsonPath('meta.note', 'Resultado técnico parcial (somente jurados). Não inclui voto público nem é o resultado final.');
    }
}
