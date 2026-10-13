<?php

namespace App\Domain\Reports;

use App\Domain\Evaluations\EvaluationScoring;
use App\Domain\Jurors\Enums\AssignmentStatus;
use App\Domain\Jurors\Enums\JurorStatus;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Voting\VotingAggregates;
use App\Models\Event;
use App\Models\Juror;
use App\Models\JurorTeamAssignment;
use App\Models\ResultPublication;
use App\Models\VotingSession;

/**
 * Encerramento e Resultados. Mantém separados avaliação técnica, votação pública e publicação oficial;
 * nunca há nota combinada.
 *
 * - Avaliações: só progresso (EvaluationScoring::progress), sem notas, comentários ou jurado ↔ nota.
 * - Jurados: só contagens (sem contato).
 * - Votação: participação por sessão; contagem por equipe só aparece pelo resultado oficial publicado.
 * - Resultado oficial: snapshot imutável da publicação PUBLISHED atual, sem recalcular.
 */
class ClosingResultsReportBuilder extends ReportBuilder
{
    public function __construct(
        private readonly EvaluationScoring $scoring,
        private readonly VotingAggregates $voting,
    ) {}

    public function type(): string
    {
        return 'closing-results';
    }

    public function title(): string
    {
        return 'Encerramento e Resultados';
    }

    protected function sections(Event $event): array
    {
        return [
            'evaluations' => $this->evaluations($event),
            'jurors' => $this->jurors($event),
            'voting' => $this->votingSessions($event),
            'official_result' => $this->officialResult($event),
        ];
    }

    private function evaluations(Event $event): array
    {
        $teams = collect($this->scoring->progress($event));
        $expected = (int) $teams->sum('assigned_jurors');
        $submitted = (int) $teams->sum('submitted');

        return [
            'summary' => [
                'expected' => $expected,
                'submitted' => $submitted,
                'draft' => (int) $teams->sum('draft'),
                'revision_requested' => (int) $teams->sum('revision_requested'),
                'not_started' => (int) $teams->sum('not_started'),
                'pending' => $expected - $submitted,
                'completion_percent' => $expected === 0 ? null : round(100 * $submitted / $expected, 2),
            ],
            'teams' => $teams->values()->all(),
        ];
    }

    private function jurors(Event $event): array
    {
        $query = Juror::query()->where('event_id', $event->id);

        return [
            'total' => (clone $query)->count(),
            'by_status' => $this->countByEnum($query, 'status', JurorStatus::class),
            'active_assignments' => JurorTeamAssignment::query()->where('event_id', $event->id)->where('status', AssignmentStatus::Active->value)->count(),
        ];
    }

    /**
     * Participação por sessão (agregado). Nunca eleitor → equipe nem contagem por equipe fora do snapshot oficial.
     */
    private function votingSessions(Event $event): array
    {
        return VotingSession::query()->where('event_id', $event->id)->withCount('teams')->orderBy('id')
            ->get(['id', 'name', 'status', 'opened_at', 'closed_at', 'cancelled_at'])
            ->map(fn (VotingSession $session) => [
                'id' => $session->id,
                'name' => $session->name,
                'status' => $session->status?->value,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'cancelled_at' => $session->cancelled_at?->toIso8601String(),
                'candidates' => (int) $session->teams_count,
            ] + $this->voting->turnout($session))
            ->all();
    }

    private function officialResult(Event $event): array
    {
        $publication = ResultPublication::query()->where('event_id', $event->id)
            ->where('status', PublicationStatus::Published->value)->first(['id', 'version', 'published_at', 'snapshot']);

        if ($publication === null) {
            return ['published' => false, 'message' => 'Resultado oficial ainda não publicado.'];
        }

        $snapshot = $publication->snapshot;

        return [
            'published' => true,
            'version' => $publication->version,
            'published_at' => $publication->published_at?->toIso8601String(),
            'voting_session' => $snapshot['voting_session'] ?? null,
            'technical' => $snapshot['technical'] ?? null,
            'popular' => $snapshot['popular'] ?? null,
            'turnout' => $snapshot['turnout'] ?? null,
            'rules' => $snapshot['rules'] ?? null,
        ];
    }
}
