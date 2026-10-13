<?php

namespace Tests\Feature\Reports;

use App\Domain\Evaluations\EvaluationService;
use App\Domain\Users\Enums\RoleCode;
use App\Models\ResultPublication;
use App\Models\Team;
use App\Models\User;
use App\Models\VotingSession;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\ReportScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Encerramento e Resultados: técnico, popular e oficial separados; progresso sem notas; votação sem voto
 * individual nem contagem por equipe fora do snapshot oficial; resultado oficial = snapshot publicado.
 *
 * Avaliações: jurado A avalia Equipes 1 e 2, jurado B avalia a Equipe 1 (comentários sigilosos).
 */
class ClosingResultsReportTest extends DatabaseTestCase
{
    use JuryScenario;
    use ReportScenario;
    use VotingScenario;

    private const SECRET_A = 'Comentário sigiloso do jurado A';

    private const SECRET_B = 'Comentário sigiloso do jurado B';

    /** @var list<User> */
    private array $voterList;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
        $this->assign($this->jurorA, [$this->team1, $this->team2]);
        $this->assign($this->jurorB, [$this->team1]);
        $this->voterList = $this->voters(5);
    }

    private function submitAs(User $user, $juror, Team $team, float $s1, float $s2, ?string $comments = null): void
    {
        app(EvaluationService::class)->submit($this->assignmentOf($juror, $team), $user, ['comments' => $comments, 'scores' => $this->fullScores($s1, $s2)]);
    }

    private function closedWithVotes(): VotingSession
    {
        $v = $this->voterList;

        return $this->closedSession(
            $this->event,
            [$this->team1, $this->team2, $this->team3],
            $v,
            [[$v[0], $this->team3], [$v[1], $this->team3], [$v[2], $this->team3], [$v[3], $this->team1]],
        );
    }

    /**
     * Varredura de privacidade: nada de nota/comentário individual, eleitor, contato ou segredo.
     */
    private function assertPrivate(array $data): void
    {
        $voterEmails = array_map(fn (User $u) => $u->email, $this->voterList);
        $this->assertPayloadExcludes(
            $data,
            ['user_id', 'voter', 'voters', 'email', 'phone', 'document', 'comments', 'comment', 'scores', 'score', 'password', 'check_in_code', 'api_token', 'webhook_secret'],
            [self::SECRET_A, self::SECRET_B, ...$voterEmails],
        );
    }

    public function test_progress_jurors_and_sessions_without_publication(): void
    {
        $this->submitAs($this->userA, $this->jurorA, $this->team1, 8, 4, self::SECRET_A);
        $closed = $this->closedWithVotes();
        $open = $this->openSession($this->event, [$this->team1, $this->team2], [$this->voterList[0], $this->voterList[4]], 'Sessão aberta');
        $this->castVote($open, $this->voterList[0], $this->team2);
        $draft = $this->draftSession($this->event, [$this->team1], [], 'Sessão rascunho');

        $data = $this->report($this->admin(), 'closing-results', $this->event)->assertOk()->json('data');

        // Progresso pela regra de domínio: 3 atribuições, 1 enviada.
        $this->assertSame(3, $data['evaluations']['summary']['expected']);
        $this->assertSame(1, $data['evaluations']['summary']['submitted']);
        $this->assertSame(2, $data['evaluations']['summary']['pending']);
        $team1 = collect($data['evaluations']['teams'])->firstWhere('team.name', 'Equipe 1');
        $this->assertSame([2, 1], [$team1['assigned_jurors'], $team1['submitted']]);

        // Jurados: só contagens.
        $this->assertSame(3, $data['jurors']['total']);
        $this->assertSame(3, $data['jurors']['active_assignments']);

        $sessions = collect($data['voting'])->keyBy('id');
        $this->assertSame('CLOSED', $sessions[$closed->id]['status']);
        $this->assertSame([5, 4, 1, 80], [$sessions[$closed->id]['eligible_voters'], $sessions[$closed->id]['votes_cast'], $sessions[$closed->id]['pending_voters'], (int) $sessions[$closed->id]['turnout_percent']]);
        $this->assertSame('OPEN', $sessions[$open->id]['status']);
        $this->assertSame([2, 1], [$sessions[$open->id]['eligible_voters'], $sessions[$open->id]['votes_cast']]);
        $this->assertSame('DRAFT', $sessions[$draft->id]['status']);
        $this->assertSame(0, $sessions[$draft->id]['votes_cast']);

        // Sem contagem por equipe na votação (nem da sessão aberta, nem da fechada não publicada).
        $this->assertPayloadExcludes($data['voting'], ['vote_count', 'teams', 'percentage']);

        // Sem publicação: mensagem e nenhum ranking técnico não publicado.
        $this->assertSame(['published' => false, 'message' => 'Resultado oficial ainda não publicado.'], $data['official_result']);
        $this->assertStringNotContainsString('technical_score', json_encode($data));

        $this->assertPrivate($data);
    }

    public function test_official_result_comes_from_published_snapshot(): void
    {
        $this->submitAs($this->userA, $this->jurorA, $this->team1, 8, 4, self::SECRET_A);
        $this->submitAs($this->userA, $this->jurorA, $this->team2, 10, 5);
        $this->submitAs($this->userB, $this->jurorB, $this->team1, 5, 2, self::SECRET_B);
        $session = $this->closedWithVotes();
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/results/publish", ['voting_session_id' => $session->id])->assertCreated();
        $publication = ResultPublication::query()->sole();

        $consultant = $this->userWithRole(RoleCode::Consultant);
        $official = $this->report($consultant, 'closing-results', $this->event)->assertOk()->json('data.official_result');

        $this->assertTrue($official['published']);
        $this->assertSame($publication->version, $official['version']);
        $this->assertSame($publication->snapshot['technical'], $official['technical']);
        $this->assertSame($publication->snapshot['popular'], $official['popular']);
        $this->assertSame($publication->snapshot['turnout'], $official['turnout']);
        // Técnico e popular separados, sem nota combinada.
        $this->assertSame('Equipe 2', $official['technical']['teams'][0]['team']['name']);
        $this->assertSame('Equipe 3', $official['popular']['teams'][0]['team']['name']);
        $this->assertFalse($official['rules']['combined']);
        $this->assertPayloadExcludes($official, ['combined_score', 'final_score', 'total_score']);

        // Mudar votos/avaliações depois não muda o oficial: é o snapshot imutável.
        $this->assertSame($official, $this->report($this->admin(), 'closing-results', $this->event)->json('data.official_result'));

        $this->assertPrivate($this->report($consultant, 'closing-results', $this->event)->json('data'));
    }

    public function test_general_report_marks_publication_and_has_no_vote_details(): void
    {
        $this->submitAs($this->userA, $this->jurorA, $this->team1, 8, 4, self::SECRET_A);
        $this->submitAs($this->userA, $this->jurorA, $this->team2, 10, 5);
        $this->submitAs($this->userB, $this->jurorB, $this->team1, 5, 2, self::SECRET_B);
        $session = $this->closedWithVotes();
        $this->actingAs($this->adminUser)->postJson("/api/v1/events/{$this->event->id}/results/publish", ['voting_session_id' => $session->id])->assertCreated();

        foreach (ReportAuthorizationTest::TYPES as $type) {
            $data = $this->report($this->admin(), $type, $this->event)->assertOk()->json('data');
            $this->assertPrivate($data);
        }

        $general = $this->report($this->admin(), 'general', $this->event)->json('data.summary');
        $this->assertTrue($general['official_result']['published']);
        $this->assertSame(1, $general['official_result']['version']);
        $this->assertSame(['expected' => 3, 'submitted' => 3, 'pending' => 0, 'completion_percent' => 100], array_map(fn ($v) => is_float($v) ? (int) $v : $v, $general['evaluations']));
    }
}
