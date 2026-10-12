<?php

namespace Tests\Feature\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Evaluations\EvaluationService;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\PublicVote;
use App\Models\ResultPublication;
use App\Models\User;
use App\Models\VotingSession;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\RealConcurrencyTestCase;

/**
 * Concorrência REAL: processos PHP separados (tests/Concurrency/worker.php), cada um com a própria conexão,
 * disparando juntos contra o PostgreSQL. Cobre o pico do dia 3: duplo clique, requisições simultâneas,
 * muitos votantes ao mesmo tempo, fechamento durante votos, publicações e revisão simultâneas.
 *
 * Não usa RefreshDatabase (a transação do teste esconderia os dados dos workers): os dados são gravados de
 * verdade no hacklab_test e o banco é truncado antes e depois de cada teste.
 */
class RealConcurrencyTest extends RealConcurrencyTestCase
{
    use JuryScenario;
    use VotingScenario;

    public function test_simultaneous_votes_of_the_same_user_for_different_teams_create_exactly_one_vote(): void
    {
        $this->buildJuryScenario();
        [$voter] = $this->voters(1);
        $session = $this->openSession($this->event, [$this->team1, $this->team2], [$voter]);

        $jobs = [];
        foreach ([$this->team1, $this->team2, $this->team1, $this->team2, $this->team1, $this->team2] as $team) {
            $jobs[] = ['vote', ['session_id' => $session->id, 'user_id' => $voter->id, 'team_id' => $team->id]];
        }

        $results = $this->runConcurrently($jobs);

        $this->assertSame(1, PublicVote::query()->count());
        $winner = PublicVote::query()->sole()->team_id;

        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok'] && $r['created']));
        // Mesma equipe da vencedora: idempotente (mesmo voto); outra equipe: 422 previsível.
        foreach ($results as $i => $result) {
            if ($jobs[$i][1]['team_id'] === $winner) {
                $this->assertTrue($result['ok']);
                $this->assertSame(PublicVote::query()->sole()->id, $result['vote_id']);
            } else {
                $this->assertSame(422, $result['status']);
            }
        }

        // Nenhuma das tentativas gerou auditoria (o voto nunca é auditado).
        $this->assertSame(0, AuditLog::query()->where('actor_user_id', $voter->id)->count());
    }

    public function test_double_click_identical_votes_are_idempotent(): void
    {
        $this->buildJuryScenario();
        [$voter] = $this->voters(1);
        $session = $this->openSession($this->event, [$this->team1, $this->team2], [$voter]);

        $results = $this->runConcurrently(array_fill(0, 5, ['vote', ['session_id' => $session->id, 'user_id' => $voter->id, 'team_id' => $this->team1->id]]));

        $this->assertSame(1, PublicVote::query()->count());
        $this->assertSame(5, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['created']));
        $this->assertCount(1, array_unique(array_column($results, 'vote_id')));
    }

    public function test_many_different_voters_at_the_same_time_all_count(): void
    {
        $this->buildJuryScenario();
        $voters = $this->voters(15);
        $session = $this->openSession($this->event, [$this->team1, $this->team2, $this->team3], $voters);
        $teams = [$this->team1, $this->team2, $this->team3];

        $results = $this->runConcurrently(array_map(
            fn (User $voter, int $i) => ['vote', ['session_id' => $session->id, 'user_id' => $voter->id, 'team_id' => $teams[$i % 3]->id]],
            $voters,
            array_keys($voters),
        ));

        $this->assertSame(15, $this->countWhere($results, fn ($r) => $r['ok'] && $r['created']));
        $this->assertSame(15, PublicVote::query()->count());
        $this->assertSame([5, 5, 5], PublicVote::query()->groupBy('team_id')->orderBy('team_id')->selectRaw('count(*) as c')->pluck('c')->map(fn ($c) => (int) $c)->all());
    }

    public function test_simultaneous_opening_of_two_sessions_of_the_same_event_opens_only_one(): void
    {
        $this->buildJuryScenario();
        $voters = $this->voters(1);
        $first = $this->draftSession($this->event, [$this->team1], $voters, 'Sessão 1');
        $second = $this->draftSession($this->event, [$this->team1], $voters, 'Sessão 2');
        $actor = $this->votingActor();

        $results = $this->runConcurrently([
            ['open', ['session_id' => $first->id, 'actor_id' => $actor->id]],
            ['open', ['session_id' => $second->id, 'actor_id' => $actor->id]],
        ]);

        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['status'] === 422));
        $this->assertSame(1, VotingSession::query()->where('status', 'OPEN')->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_SESSION_OPENED)->count());
    }

    public function test_closing_while_votes_arrive_leaves_a_coherent_database(): void
    {
        $this->buildJuryScenario();
        $voters = $this->voters(12);
        $session = $this->openSession($this->event, [$this->team1, $this->team2], $voters);

        $jobs = array_map(fn (User $voter) => ['vote', ['session_id' => $session->id, 'user_id' => $voter->id, 'team_id' => $this->team1->id]], $voters);
        array_splice($jobs, 6, 0, [['close', ['session_id' => $session->id, 'actor_id' => $this->votingActor()->id]]]);

        $results = $this->runConcurrently($jobs);

        $session->refresh();
        $this->assertSame('CLOSED', $session->status->value);

        $accepted = $this->countWhere($results, fn ($r) => $r['ok'] && ($r['created'] ?? false));
        $rejected = $this->countWhere($results, fn ($r) => $r['status'] === 422);
        $this->assertSame(12, $accepted + $rejected, 'Todo voto é aceito antes do fechamento ou recusado depois (nunca erro inesperado).');
        $this->assertSame($accepted, PublicVote::query()->count());

        // Nenhum voto gravado depois do fechamento.
        $this->assertSame(0, PublicVote::query()->where('cast_at', '>', $session->closed_at)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_SESSION_CLOSED)->count());
        $this->assertSame($accepted, AuditLog::query()->where('action', AuditAction::VOTING_SESSION_CLOSED)->sole()->after_data['votes_cast']);
    }

    public function test_two_simultaneous_publications_produce_exactly_one(): void
    {
        [$session, $actor] = $this->publishableScenario();

        $results = $this->runConcurrently([
            ['publish', ['event_id' => $this->event->id, 'session_id' => $session->id, 'actor_id' => $actor->id]],
            ['publish', ['event_id' => $this->event->id, 'session_id' => $session->id, 'actor_id' => $actor->id]],
        ]);

        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok']));
        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['status'] === 422));
        $this->assertSame(1, ResultPublication::query()->count());
        $this->assertSame(1, ResultPublication::query()->sole()->version);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::RESULTS_PUBLISHED)->count());
    }

    public function test_publish_while_a_revision_is_requested_never_publishes_a_changing_technical_set(): void
    {
        [$session, $actor] = $this->publishableScenario();
        $evaluation = Evaluation::query()->where('juror_id', $this->jurorB->id)->sole();

        $results = $this->runConcurrently([
            ['publish', ['event_id' => $this->event->id, 'session_id' => $session->id, 'actor_id' => $actor->id]],
            ['request_revision', ['evaluation_id' => $evaluation->id, 'actor_id' => $actor->id]],
        ]);

        [$publish, $revision] = $results;
        $this->assertSame(1, $this->countWhere($results, fn ($r) => $r['ok']), 'Exatamente uma das duas operações vence.');

        if ($publish['ok']) {
            // Publicou primeiro: a revisão foi barrada e o snapshot bate com o banco.
            $this->assertSame(422, $revision['status']);
            $this->assertSame('SUBMITTED', $evaluation->fresh()->status->value);
            $this->assertSame(2, ResultPublication::query()->sole()->snapshot['technical']['teams'][0]['valid_evaluations']);
        } else {
            // Revisão primeiro: a publicação foi barrada por avaliação incompleta.
            $this->assertSame(422, $publish['status']);
            $this->assertSame('REVISION_REQUESTED', $evaluation->fresh()->status->value);
            $this->assertSame(0, ResultPublication::query()->count());
        }
    }

    /**
     * Equipe 1 avaliada por A e B (completo) e sessão fechada com votos.
     *
     * @return array{0: VotingSession, 1: User}
     */
    private function publishableScenario(): array
    {
        $this->buildJuryScenario();
        $this->assign($this->jurorA, [$this->team1]);
        $this->assign($this->jurorB, [$this->team1]);
        app(EvaluationService::class)->submit($this->assignmentOf($this->jurorA, $this->team1), $this->userA, ['scores' => $this->fullScores(8, 4)]);
        app(EvaluationService::class)->submit($this->assignmentOf($this->jurorB, $this->team1), $this->userB, ['scores' => $this->fullScores(5, 2)]);

        $voters = $this->voters(2);
        $session = $this->closedSession($this->event, [$this->team1, $this->team2], $voters, [[$voters[0], $this->team1], [$voters[1], $this->team2]]);

        return [$session, $this->adminUser];
    }
}
