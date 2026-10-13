<?php

namespace App\Domain\Reports;

use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Domain\Companies\Enums\CompanyStatus;
use App\Domain\Evaluations\EvaluationScoring;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Meetings\Enums\MeetingStatus;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Teams\Enums\TeamStatus;
use App\Domain\Voting\Enums\VotingSessionStatus;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\Meeting;
use App\Models\Occurrence;
use App\Models\Participant;
use App\Models\ResultPublication;
use App\Models\SchoolClass;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use App\Models\VotingSession;

/**
 * Relatório Geral do Hackathon: resumo executivo com contagens agregadas no SQL.
 * Sem financeiro, documentos, equipamentos ou premiações (não existem no domínio).
 */
class GeneralReportBuilder extends ReportBuilder
{
    public function __construct(
        private readonly EvaluationScoring $scoring,
        private readonly AttendanceStats $attendance,
        private readonly OverdueTasks $overdue,
    ) {}

    public function type(): string
    {
        return 'general';
    }

    public function title(): string
    {
        return 'Relatório Geral do Hackathon';
    }

    protected function sections(Event $event): array
    {
        $participants = Participant::query()->where('event_id', $event->id);
        $withTeam = (clone $participants)->whereHas('activeMembership')->count();
        $totalParticipants = (clone $participants)->count();
        $tasks = Task::query()->where('event_id', $event->id);
        $occurrences = Occurrence::query()->where('event_id', $event->id);
        $registrations = EventRegistration::query()->where('event_id', $event->id);
        $byDay = $this->attendance->byDay($event);
        $progress = $this->progressTotals($event);
        $publication = ResultPublication::query()->where('event_id', $event->id)
            ->where('status', PublicationStatus::Published->value)->first(['id', 'version', 'published_at']);

        return [
            'summary' => [
                'participants' => [
                    'total' => $totalParticipants,
                    'by_status' => $this->countByEnum($participants, 'status', ParticipantStatus::class),
                    'with_team' => $withTeam,
                    'without_team' => $totalParticipants - $withTeam,
                ],
                'classes' => [
                    'total' => SchoolClass::query()->where('event_id', $event->id)->count(),
                    'active' => SchoolClass::query()->where('event_id', $event->id)->where('active', true)->count(),
                ],
                'teams' => [
                    'total' => Team::query()->where('event_id', $event->id)->count(),
                    'by_status' => $this->countByEnum(Team::query()->where('event_id', $event->id), 'status', TeamStatus::class),
                ],
                'companies' => [
                    'total' => Company::query()->where('event_id', $event->id)->count(),
                    'by_status' => $this->countByEnum(Company::query()->where('event_id', $event->id), 'status', CompanyStatus::class),
                ],
                'challenges' => [
                    'total' => Challenge::query()->where('event_id', $event->id)->count(),
                    'by_status' => $this->countByEnum(Challenge::query()->where('event_id', $event->id), 'status', ChallengeStatus::class),
                    'with_team' => Challenge::query()->where('event_id', $event->id)->whereHas('team')->count(),
                ],
                'sectors' => [
                    'total' => Sector::query()->where('event_id', $event->id)->count(),
                    'active' => Sector::query()->where('event_id', $event->id)->where('active', true)->count(),
                ],
                'meetings' => [
                    'total' => Meeting::query()->where('event_id', $event->id)->count(),
                    'by_status' => $this->countByEnum(Meeting::query()->where('event_id', $event->id), 'status', MeetingStatus::class),
                ],
                'tasks' => [
                    'total' => (clone $tasks)->count(),
                    'by_status' => $this->countByEnum($tasks, 'status', TaskStatus::class),
                    'open' => (clone $tasks)->where('status', '!=', TaskStatus::Completed->value)->count(),
                    'overdue' => $this->overdue->apply(clone $tasks)->count(),
                ],
                'occurrences' => [
                    'total' => (clone $occurrences)->count(),
                    'by_status' => $this->countByEnum($occurrences, 'status', OccurrenceStatus::class),
                    'open' => (clone $occurrences)->where('status', '!=', OccurrenceStatus::Resolved->value)->count(),
                ],
                'registrations' => [
                    'total' => (clone $registrations)->count(),
                    'by_status' => $this->countByEnum($registrations, 'status', RegistrationStatus::class),
                ],
                'attendance' => [
                    'people_with_presence' => $this->attendance->peopleWithPresence($event),
                    'check_ins' => $this->attendance->totalCheckIns($event),
                    'by_day' => $this->days($event)->map(fn (EventDay $day) => [
                        'event_day_id' => $day->id,
                        'people_present' => (int) ($byDay->get($day->id)?->people_present ?? 0),
                        'check_ins' => (int) ($byDay->get($day->id)?->check_ins ?? 0),
                    ])->values()->all(),
                ],
                'evaluations' => $progress,
                'voting' => [
                    'sessions' => VotingSession::query()->where('event_id', $event->id)->count(),
                    'by_status' => $this->countByEnum(VotingSession::query()->where('event_id', $event->id), 'status', VotingSessionStatus::class),
                ],
                'official_result' => [
                    'published' => $publication !== null,
                    'version' => $publication?->version,
                    'published_at' => $publication?->published_at?->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * Totais do progresso das avaliações, a partir da regra de domínio (EvaluationScoring::progress).
     *
     * @return array{expected: int, submitted: int, pending: int, completion_percent: float|null}
     */
    private function progressTotals(Event $event): array
    {
        $rows = collect($this->scoring->progress($event));
        $expected = (int) $rows->sum('assigned_jurors');
        $submitted = (int) $rows->sum('submitted');

        return [
            'expected' => $expected,
            'submitted' => $submitted,
            'pending' => $expected - $submitted,
            'completion_percent' => $expected === 0 ? null : round(100 * $submitted / $expected, 2),
        ];
    }
}
