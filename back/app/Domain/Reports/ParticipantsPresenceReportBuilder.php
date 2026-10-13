<?php

namespace App\Domain\Reports;

use App\Domain\Participants\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Participant;
use Illuminate\Support\Facades\DB;

/**
 * Participantes e Presença: lista de participantes (turma, status, equipe atual) e presença por EventDay.
 *
 * Presença por dia: PRESENT (há check-in de alguma inscrição da Person neste evento naquele dia) ou
 * NO_RECORD (não há). Participante sem inscrição externa é permitido e fica NO_RECORD.
 * Sem e-mail, telefone ou documento: o relatório não precisa deles.
 */
class ParticipantsPresenceReportBuilder extends ReportBuilder
{
    public const PRESENT = 'PRESENT';

    public const NO_RECORD = 'NO_RECORD';

    public function __construct(private readonly AttendanceStats $attendance) {}

    public function type(): string
    {
        return 'participants-presence';
    }

    public function title(): string
    {
        return 'Participantes e Presença';
    }

    protected function sections(Event $event): array
    {
        $days = $this->days($event);
        $dayIds = $days->pluck('id')->all();
        $present = $this->attendance->presentDaysByPerson($event);

        // Uma consulta para a lista inteira: pessoa, turma e equipe ativa por join (sem N+1).
        $rows = DB::table('participants as p')
            ->join('people', 'people.id', '=', 'p.person_id')
            ->leftJoin('classes as c', 'c.id', '=', 'p.class_id')
            ->leftJoin('team_members as tm', fn ($join) => $join->on('tm.participant_id', '=', 'p.id')->where('tm.active', true))
            ->leftJoin('teams as t', 't.id', '=', 'tm.team_id')
            ->where('p.event_id', $event->id)
            ->orderBy('people.full_name')
            ->orderBy('p.id')
            ->get(['p.id', 'p.person_id', 'p.status', 'people.full_name', 'c.id as class_id', 'c.name as class_name', 't.id as team_id', 't.name as team_name']);

        $participants = $rows->map(function ($row) use ($dayIds, $present) {
            $days = $present[$row->person_id] ?? [];

            return [
                'id' => (int) $row->id,
                'name' => $row->full_name,
                'status' => $row->status,
                'class' => $row->class_id === null ? null : ['id' => (int) $row->class_id, 'name' => $row->class_name],
                'team' => $row->team_id === null ? null : ['id' => (int) $row->team_id, 'name' => $row->team_name],
                'presence' => collect($dayIds)->mapWithKeys(fn (int $dayId) => [
                    (string) $dayId => in_array($dayId, $days, true) ? self::PRESENT : self::NO_RECORD,
                ])->all(),
            ];
        });

        $byClass = $rows->groupBy(fn ($row) => $row->class_id ?? 0)->map(fn ($group) => [
            'class' => $group->first()->class_id === null ? null : ['id' => (int) $group->first()->class_id, 'name' => $group->first()->class_name],
            'total' => $group->count(),
        ])->sortBy(fn ($item) => $item['class']['name'] ?? "\u{10FFFF}")->values()->all();

        return [
            'summary' => [
                'total' => $rows->count(),
                'by_status' => $this->countByEnum(Participant::query()->where('event_id', $event->id), 'status', ParticipantStatus::class),
                'with_team' => $rows->whereNotNull('team_id')->count(),
                'without_team' => $rows->whereNull('team_id')->count(),
                'by_class' => $byClass,
                'presence_by_day' => $days->map(fn (EventDay $day) => [
                    'event_day_id' => $day->id,
                    'participants_present' => $participants->filter(fn ($item) => $item['presence'][(string) $day->id] === self::PRESENT)->count(),
                ])->values()->all(),
                'with_any_presence' => $participants->filter(fn ($item) => in_array(self::PRESENT, $item['presence'], true))->count(),
            ],
            'participants' => $participants->values()->all(),
        ];
    }
}
