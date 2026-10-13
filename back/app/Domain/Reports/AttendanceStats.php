<?php

namespace App\Domain\Reports;

use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Presença para relatórios, sempre em lote (sem consulta por pessoa/dia).
 *
 * Semântica da Fase 9: AttendanceRecord = check-in observado. Existe check-in no dia → presente;
 * não existe → sem registro (nunca "ausente"). A presença é da Person: qualquer inscrição dela
 * no mesmo evento conta, e vários check-ins no mesmo dia são uma presença diária.
 */
class AttendanceStats
{
    /**
     * Por EventDay: pessoas com presença registrada e total de check-ins.
     *
     * @return Collection<int, object{event_day_id: int, people_present: int, check_ins: int}>
     */
    public function byDay(Event $event): Collection
    {
        return DB::table('attendance_records as ar')
            ->join('event_registrations as er', 'er.id', '=', 'ar.event_registration_id')
            ->where('ar.event_id', $event->id)
            ->whereNotNull('ar.event_day_id')
            ->groupBy('ar.event_day_id')
            ->selectRaw('ar.event_day_id, count(distinct er.person_id) as people_present, count(*) as check_ins')
            ->get()
            ->keyBy('event_day_id');
    }

    public function peopleWithPresence(Event $event): int
    {
        return (int) DB::table('attendance_records as ar')
            ->join('event_registrations as er', 'er.id', '=', 'ar.event_registration_id')
            ->where('ar.event_id', $event->id)
            ->whereNotNull('ar.event_day_id')
            ->distinct()
            ->count('er.person_id');
    }

    public function totalCheckIns(Event $event): int
    {
        return DB::table('attendance_records')->where('event_id', $event->id)->count();
    }

    /**
     * Pares (person_id, event_day_id) com presença, agrupados por Person.
     *
     * @return array<int, list<int>> person_id → ids dos dias presentes
     */
    public function presentDaysByPerson(Event $event): array
    {
        return DB::table('attendance_records as ar')
            ->join('event_registrations as er', 'er.id', '=', 'ar.event_registration_id')
            ->where('ar.event_id', $event->id)
            ->whereNotNull('ar.event_day_id')
            ->select('er.person_id', 'ar.event_day_id')
            ->distinct()
            ->get()
            ->groupBy('person_id')
            ->map(fn (Collection $rows) => $rows->pluck('event_day_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }
}
