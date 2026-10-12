<?php

namespace App\Domain\Results;

use App\Domain\Evaluations\EvaluationScoring;
use App\Domain\Teams\Enums\TeamStatus;
use App\Domain\Voting\VotingAggregates;
use App\Models\Event;
use App\Models\ResultPublication;
use App\Models\Team;
use App\Models\VotingSession;
use App\Support\Math\Decimal;

/**
 * Junta os dois resultados SEM combiná-los: técnico (jurados, regra da Fase 6) e popular (votos da sessão).
 * Nenhum voto entra no técnico; nenhuma nota entra no popular. Sem score combinado, sem desempate artificial.
 */
class ResultsCalculator
{
    public const RULES = [
        'technical' => 'juror_evaluations',
        'popular' => 'one_person_one_vote',
        'combined' => false,
    ];

    public function __construct(
        private readonly EvaluationScoring $scoring,
        private readonly VotingAggregates $voting,
    ) {}

    /**
     * Ranking técnico: comparado em precisão completa (decimal), exibido com duas casas.
     * Equipes ativas sem avaliação válida aparecem sem posição.
     *
     * @return array{teams: list<array<string, mixed>>, has_ties: bool}
     */
    public function technical(Event $event): array
    {
        $scores = $this->scoring->exactTechnicalScores($event);

        $rows = Team::query()->where('event_id', $event->id)
            ->where(fn ($q) => $q->where('status', TeamStatus::Active->value)->orWhereIn('id', array_keys($scores)))
            ->orderBy('name')
            ->get()
            ->map(function (Team $team) use ($scores) {
                $exact = $scores[$team->id]['score'] ?? null;

                return [
                    'team' => ['id' => $team->id, 'name' => $team->name],
                    'technical_score' => $exact === null ? null : Decimal::round($exact),
                    'technical_score_exact' => $exact,
                    'valid_evaluations' => $scores[$team->id]['valid_evaluations'] ?? 0,
                ];
            })
            ->values()
            ->all();

        $ranked = Ranking::competition(
            $rows,
            fn (array $row) => $row['technical_score_exact'],
            fn (string $a, string $b) => Decimal::compare($a, $b),
            fn (array $row) => $row['team']['name'],
        );

        return ['teams' => $ranked, 'has_ties' => Ranking::hasTies($ranked)];
    }

    /**
     * Completude técnica: toda atribuição ACTIVE precisa de avaliação SUBMITTED.
     *
     * @return array{complete: bool, pending_count: int, pending: list<array<string, mixed>>}
     */
    public function completeness(Event $event): array
    {
        $pending = $this->scoring->pendingAssignments($event);

        return ['complete' => $pending === [], 'pending_count' => count($pending), 'pending' => $pending];
    }

    /**
     * Avaliações técnicas válidas (SUBMITTED com atribuição ACTIVE) do evento. Zero bloqueia a publicação:
     * evento "somente voto popular" não existe como consequência acidental.
     */
    public function validEvaluationsCount(Event $event): int
    {
        return array_sum(array_column($this->scoring->exactTechnicalScores($event), 'valid_evaluations'));
    }

    /**
     * Prévia administrativa. A parte popular só aparece com uma sessão CLOSED do próprio evento.
     *
     * @return array<string, mixed>
     */
    public function preview(Event $event, ?VotingSession $session): array
    {
        $completeness = $this->completeness($event);
        $blockers = [];

        if (ResultPublication::hasPublishedFor($event->id)) {
            $blockers[] = ['code' => 'ALREADY_PUBLISHED', 'message' => 'Já existe resultado publicado. Revogue a publicação atual para publicar uma nova versão.'];
        }

        if ($session === null) {
            $blockers[] = ['code' => 'VOTING_SESSION_REQUIRED', 'message' => 'Selecione a sessão de votação pública (voting_session_id).'];
        } elseif ($session->event_id !== $event->id) {
            $blockers[] = ['code' => 'VOTING_SESSION_OTHER_EVENT', 'message' => 'A sessão de votação não pertence a este evento.'];
            $session = null;
        } elseif (! $session->isClosed()) {
            $blockers[] = ['code' => 'VOTING_SESSION_NOT_CLOSED', 'message' => "A sessão de votação precisa estar encerrada (status: {$session->status->value})."];
        }

        if ($this->validEvaluationsCount($event) === 0) {
            $blockers[] = ['code' => 'NO_VALID_EVALUATIONS', 'message' => 'Não há nenhuma avaliação técnica válida (enviada, com atribuição ativa).'];
        }

        if (! $completeness['complete']) {
            $blockers[] = ['code' => 'EVALUATIONS_INCOMPLETE', 'message' => "{$completeness['pending_count']} atribuição(ões) ativa(s) sem avaliação enviada. Conclua ou revogue explicitamente."];
        }

        $closed = $session?->isClosed() === true;

        return [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'voting_session' => $session === null ? null : $this->sessionSummary($session),
            'technical' => $this->technical($event),
            'popular' => $closed ? $this->voting->results($session) : null,
            'turnout' => $session === null ? null : $this->voting->turnout($session),
            'completeness' => $completeness,
            'blockers' => $blockers,
            'can_publish' => $blockers === [],
            'rules' => self::RULES,
        ];
    }

    /**
     * Snapshot congelado na publicação: só agregados (sem votantes, notas individuais ou comentários de jurados).
     *
     * @return array<string, mixed>
     */
    public function snapshot(Event $event, VotingSession $session, int $version, string $publishedAt): array
    {
        return [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'version' => $version,
            'published_at' => $publishedAt,
            'voting_session' => $this->sessionSummary($session),
            'technical' => $this->technical($event),
            'popular' => $this->voting->results($session),
            'turnout' => $this->voting->turnout($session),
            'rules' => self::RULES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionSummary(VotingSession $session): array
    {
        return [
            'id' => $session->id,
            'name' => $session->name,
            'status' => $session->status->value,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
        ];
    }
}
