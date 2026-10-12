<?php

namespace Tests\Feature\Voting;

use App\Domain\Audit\AuditAction;
use App\Domain\Evaluations\EvaluationService;
use App\Domain\Results\ResultPublicationService;
use App\Domain\Voting\PublicVoteService;
use App\Domain\Voting\VotingSessionService;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\PublicVote;
use App\Models\ResultPublication;
use App\Models\VotingSession;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\JuryScenario;
use Tests\Concerns\VotingScenario;
use Tests\DatabaseTestCase;

/**
 * Cópias desatualizadas (o model carregado pela rota antes de outra requisição terminar): toda decisão
 * relê o estado sob lock. A concorrência com processos paralelos está em RealConcurrencyTest.
 */
class VotingConcurrencyTest extends DatabaseTestCase
{
    use JuryScenario;
    use VotingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildJuryScenario();
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A operação deveria ser recusada.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_stale_open_session_does_not_accept_a_vote_after_close(): void
    {
        [$voter] = $this->voters(1);
        $session = $this->openSession($this->event, [$this->team1], [$voter]);
        $stale = VotingSession::query()->findOrFail($session->id);

        $this->closeSession($session);

        $this->assertTrue($stale->isOpen());
        $this->rejects(fn () => app(PublicVoteService::class)->cast($stale, $voter, $this->team1->id));
        $this->assertSame(0, PublicVote::query()->count());
    }

    public function test_stale_draft_session_cannot_be_reconfigured_after_open(): void
    {
        $voters = $this->voters(2);
        $session = $this->draftSession($this->event, [$this->team1], [$voters[0]]);
        $stale = VotingSession::query()->findOrFail($session->id);

        app(VotingSessionService::class)->open($session);

        $this->rejects(fn () => app(VotingSessionService::class)->syncTeams($stale, [$this->team1->id, $this->team2->id]));
        $this->rejects(fn () => app(VotingSessionService::class)->syncEligibilities($stale, [$voters[0]->id, $voters[1]->id], $this->adminUser));
        $this->rejects(fn () => app(VotingSessionService::class)->cancel($stale, null));
    }

    public function test_stale_copies_cannot_open_two_sessions_or_close_twice(): void
    {
        $voters = $this->voters(1);
        $first = $this->draftSession($this->event, [$this->team1], $voters, 'Primeira');
        $second = $this->draftSession($this->event, [$this->team1], $voters, 'Segunda');
        $staleFirst = VotingSession::query()->findOrFail($first->id);

        app(VotingSessionService::class)->open($first);
        $this->rejects(fn () => app(VotingSessionService::class)->open($second));
        $this->rejects(fn () => app(VotingSessionService::class)->open($staleFirst));

        app(VotingSessionService::class)->close($first);
        $this->rejects(fn () => app(VotingSessionService::class)->close($staleFirst));

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_SESSION_OPENED)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::VOTING_SESSION_CLOSED)->count());
    }

    public function test_publish_and_revision_from_stale_copies_never_both_succeed(): void
    {
        $this->assign($this->jurorA, [$this->team1]);
        app(EvaluationService::class)->submit($this->assignmentOf($this->jurorA, $this->team1), $this->userA, ['scores' => $this->fullScores()]);
        $voters = $this->voters(1);
        $session = $this->closedSession($this->event, [$this->team1], $voters, [[$voters[0], $this->team1]]);
        $staleEvaluation = Evaluation::query()->sole();

        app(ResultPublicationService::class)->publish($this->event, $session->id, $this->adminUser);
        $this->rejects(fn () => app(ResultPublicationService::class)->publish($this->event, $session->id, $this->adminUser));
        $this->rejects(fn () => app(EvaluationService::class)->requestRevision($staleEvaluation, $this->adminUser, 'Tarde demais'));

        $this->assertSame(1, ResultPublication::query()->count());
        $this->assertSame('SUBMITTED', $staleEvaluation->fresh()->status->value);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::RESULTS_PUBLISHED)->count());
    }

    public function test_double_revoke_from_the_same_moment_revokes_once(): void
    {
        $this->assign($this->jurorA, [$this->team1]);
        app(EvaluationService::class)->submit($this->assignmentOf($this->jurorA, $this->team1), $this->userA, ['scores' => $this->fullScores()]);
        $voters = $this->voters(1);
        $session = $this->closedSession($this->event, [$this->team1], $voters, []);
        app(ResultPublicationService::class)->publish($this->event, $session->id, $this->adminUser);

        app(ResultPublicationService::class)->revoke($this->event, 'Primeiro motivo', $this->adminUser);
        $this->rejects(fn () => app(ResultPublicationService::class)->revoke($this->event, 'Segundo motivo', $this->adminUser));

        $this->assertSame('Primeiro motivo', ResultPublication::query()->sole()->revoke_reason);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::RESULTS_PUBLICATION_REVOKED)->count());
    }
}
