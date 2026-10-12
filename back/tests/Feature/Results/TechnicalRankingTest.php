<?php

namespace Tests\Feature\Results;

use App\Domain\Evaluations\EvaluationService;
use App\Domain\Results\ResultsCalculator;
use App\Models\EvaluationCriterion;
use App\Models\Team;
use App\Models\User;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Ranking técnico: regra da Fase 6 (SUBMITTED + atribuição ACTIVE), comparada em precisão completa.
 *
 * Com um terceiro critério (0–3, peso 1), Σpeso = 6 e:
 *   percentual = 5·s1 + (25/3)·(s2 − 1) + (50/9)·s3     (dízimas: float não serve para comparar)
 */
class TechnicalRankingTest extends DatabaseTestCase
{
    use JuryScenario;
    use VotingScenario;

    private EvaluationCriterion $c3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
        $this->c3 = EvaluationCriterion::factory()->for($this->event)->create(['name' => 'Impacto', 'min_score' => 0, 'max_score' => 3, 'weight' => 1, 'sort_order' => 3]);
    }

    private function submit($juror, Team $team, float $s1, float $s2, float $s3): void
    {
        app(EvaluationService::class)->submit($this->assignmentOf($juror, $team), $this->userA, ['scores' => [
            ['criterion_id' => $this->c1->id, 'score' => $s1],
            ['criterion_id' => $this->c2->id, 'score' => $s2],
            ['criterion_id' => $this->c3->id, 'score' => $s3],
        ]]);
    }

    /**
     * @return array<string, array<string, mixed>> por nome da equipe
     */
    private function technical(): array
    {
        $rows = app(ResultsCalculator::class)->technical($this->event)['teams'];

        return array_combine(array_column(array_column($rows, 'team'), 'name'), $rows);
    }

    public function test_equal_results_from_different_compositions_are_a_technical_tie(): void
    {
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->assign($this->jurorB, [$this->team1]);

        // Equipe 1: (1,1,0) = 5 e (0,1,0.9) = 5 → 5.  Equipe 2: (0,1,0.9) = 5 → 5.  Empate, mesmo com 2 x 1 avaliações.
        $this->submit($this->jurorA, $this->team1, 1, 1, 0);
        $this->submit($this->jurorB, $this->team1, 0, 1, 0.9);
        $this->submit($this->jurorA, $this->team2, 0, 1, 0.9);

        $rows = $this->technical();
        $this->assertSame(1, $rows['Equipe 1']['rank']);
        $this->assertSame(1, $rows['Equipe 2']['rank']);
        $this->assertTrue($rows['Equipe 1']['tied']);
        $this->assertSame(2, $rows['Equipe 1']['valid_evaluations']);
        $this->assertSame(1, $rows['Equipe 2']['valid_evaluations']);
        $this->assertNull($rows['Equipe 3']['rank']);
    }

    public function test_values_equal_only_after_rounding_are_not_a_tie(): void
    {
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->assign($this->jurorB, [$this->team1]);
        $this->assign($this->jurorNoAccount, [$this->team1]);

        // Equipe 1: (5 + 5 + 5,00555…) / 3 = 5,00185… → exibe 5.00.  Equipe 2: 5 → exibe 5.00.
        $this->submit($this->jurorA, $this->team1, 1, 1, 0);
        $this->submit($this->jurorB, $this->team1, 1, 1, 0);
        $this->submit($this->jurorNoAccount, $this->team1, 0.99, 1, 0.01);
        $this->submit($this->jurorA, $this->team2, 1, 1, 0);

        $rows = $this->technical();
        $this->assertSame(5.0, $rows['Equipe 1']['technical_score']);
        $this->assertSame(5.0, $rows['Equipe 2']['technical_score']);
        $this->assertSame(1, $rows['Equipe 1']['rank']);
        $this->assertSame(2, $rows['Equipe 2']['rank']);
        $this->assertFalse($rows['Equipe 1']['tied']);
        $this->assertSame(1, bccomp($rows['Equipe 1']['technical_score_exact'], $rows['Equipe 2']['technical_score_exact'], 30));
    }

    public function test_votes_never_break_or_change_a_technical_tie(): void
    {
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->submit($this->jurorA, $this->team1, 8, 4, 2);
        $this->submit($this->jurorA, $this->team2, 8, 4, 2);

        $before = $this->technical();

        /** @var list<User> $voters */
        $voters = $this->voters(3);
        $this->closedSession($this->event, [$this->team1, $this->team2], $voters, [[$voters[0], $this->team1], [$voters[1], $this->team1], [$voters[2], $this->team1]]);

        $after = $this->technical();
        $this->assertSame($before, $after);
        $this->assertSame([1, 1], [$after['Equipe 1']['rank'], $after['Equipe 2']['rank']]);
    }

    public function test_only_submitted_evaluations_with_active_assignment_count(): void
    {
        $this->assign($this->jurorA, [$this->team1]);
        $this->assign($this->jurorB, [$this->team1]);
        $this->submit($this->jurorA, $this->team1, 10, 5, 3); // 100
        $this->submit($this->jurorB, $this->team1, 0, 1, 0);  // 0

        // Revogar B: só A conta (100). Rascunho também não conta.
        $this->assign($this->jurorB, []);
        $this->assign($this->jurorNoAccount, [$this->team1]);
        app(EvaluationService::class)->saveDraft($this->assignmentOf($this->jurorNoAccount, $this->team1), $this->userA, ['scores' => [['criterion_id' => $this->c1->id, 'score' => 0]]]);

        $rows = $this->technical();
        $this->assertSame(100.0, $rows['Equipe 1']['technical_score']);
        $this->assertSame(1, $rows['Equipe 1']['valid_evaluations']);
    }
}
