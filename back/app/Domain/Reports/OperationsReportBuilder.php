<?php

namespace App\Domain\Reports;

use App\Domain\Challenges\Enums\ChallengeStatus;
use App\Domain\Companies\Enums\CompanyStatus;
use App\Domain\Demands\Enums\DemandPriority;
use App\Domain\Meetings\Enums\MeetingStatus;
use App\Domain\Occurrences\Enums\OccurrenceCategory;
use App\Domain\Occurrences\Enums\OccurrenceStatus;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Teams\Enums\TeamStatus;
use App\Models\Challenge;
use App\Models\Company;
use App\Models\Event;
use App\Models\Meeting;
use App\Models\Occurrence;
use App\Models\Sector;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * Gestão e Operação: setores, reuniões, empresas, desafios, equipes, pendências e ocorrências do evento.
 * Só o que existe no domínio: sem atas, decisões, documentos, equipamentos, salas ou finanças.
 */
class OperationsReportBuilder extends ReportBuilder
{
    public function __construct(private readonly OverdueTasks $overdue) {}

    public function type(): string
    {
        return 'operations';
    }

    public function title(): string
    {
        return 'Gestão e Operação';
    }

    protected function sections(Event $event): array
    {
        return [
            'sectors' => $this->sectors($event),
            'meetings' => $this->meetings($event),
            'companies' => $this->companies($event),
            'challenges' => $this->challenges($event),
            'teams' => $this->teams($event),
            'tasks' => $this->tasks($event),
            'occurrences' => $this->occurrences($event),
        ];
    }

    private function sectors(Event $event): array
    {
        $items = Sector::query()->where('event_id', $event->id)->orderBy('name')->get(['id', 'name', 'active']);

        return [
            'summary' => ['total' => $items->count(), 'active' => $items->where('active', true)->count(), 'inactive' => $items->where('active', false)->count()],
            'items' => $items->map(fn (Sector $s) => ['id' => $s->id, 'name' => $s->name, 'active' => $s->active])->all(),
        ];
    }

    private function meetings(Event $event): array
    {
        $query = Meeting::query()->where('event_id', $event->id);
        $items = (clone $query)->with('sector:id,name')->orderBy('scheduled_at')->orderBy('id')
            ->get(['id', 'sector_id', 'title', 'scheduled_at', 'location', 'status']);

        return [
            'summary' => [
                'total' => $items->count(),
                'general' => $items->whereNull('sector_id')->count(),
                'sector' => $items->whereNotNull('sector_id')->count(),
                'by_status' => $this->countByEnum($query, 'status', MeetingStatus::class),
            ],
            'items' => $items->map(fn (Meeting $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'scope' => $m->sector_id === null ? 'GENERAL' : 'SECTOR',
                'sector' => $m->sector === null ? null : ['id' => $m->sector->id, 'name' => $m->sector->name],
                'scheduled_at' => $m->scheduled_at?->toIso8601String(),
                'location' => $m->location,
                'status' => $m->status?->value,
            ])->all(),
        ];
    }

    private function companies(Event $event): array
    {
        $query = Company::query()->where('event_id', $event->id);
        $items = (clone $query)->withCount('challenges')->orderBy('name')->get(['id', 'name', 'type', 'status']);

        return [
            'summary' => ['total' => $items->count(), 'by_status' => $this->countByEnum($query, 'status', CompanyStatus::class)],
            'items' => $items->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type?->value,
                'status' => $c->status?->value,
                'challenges_count' => (int) $c->challenges_count,
            ])->all(),
        ];
    }

    private function challenges(Event $event): array
    {
        $query = Challenge::query()->where('event_id', $event->id);
        $items = (clone $query)->with('company:id,name', 'team:id,name,challenge_id')->orderBy('title')->get(['id', 'company_id', 'title', 'status']);

        return [
            'summary' => [
                'total' => $items->count(),
                'with_team' => $items->filter(fn (Challenge $c) => $c->team !== null)->count(),
                'by_status' => $this->countByEnum($query, 'status', ChallengeStatus::class),
            ],
            'items' => $items->map(fn (Challenge $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'company' => $c->company === null ? null : ['id' => $c->company->id, 'name' => $c->company->name],
                'status' => $c->status?->value,
                'team' => $c->team === null ? null : ['id' => $c->team->id, 'name' => $c->team->name],
            ])->all(),
        ];
    }

    private function teams(Event $event): array
    {
        $query = Team::query()->where('event_id', $event->id);
        $items = (clone $query)->withCount('activeMemberships')->with('challenge:id,title')->orderBy('name')->get(['id', 'name', 'code', 'status', 'challenge_id']);

        return [
            'summary' => [
                'total' => $items->count(),
                'with_challenge' => $items->whereNotNull('challenge_id')->count(),
                'by_status' => $this->countByEnum($query, 'status', TeamStatus::class),
            ],
            'items' => $items->map(fn (Team $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'status' => $t->status?->value,
                'members_count' => (int) $t->active_memberships_count,
                'challenge' => $t->challenge === null ? null : ['id' => $t->challenge->id, 'title' => $t->challenge->title],
            ])->all(),
        ];
    }

    private function tasks(Event $event): array
    {
        $query = Task::query()->where('event_id', $event->id);
        $items = (clone $query)->with('originSector:id,name', 'responsibleSector:id,name')->orderBy('id')
            ->get(['id', 'reference', 'title', 'origin_sector_id', 'responsible_sector_id', 'priority', 'status', 'due_at']);

        return [
            'summary' => [
                'total' => $items->count(),
                'open' => $items->filter(fn (Task $t) => ! $t->isClosed())->count(),
                'overdue' => $this->overdue->apply(clone $query)->count(),
                'by_status' => $this->countByEnum($query, 'status', TaskStatus::class),
                'by_priority' => $this->countByEnum($query, 'priority', DemandPriority::class),
                'by_responsible_sector' => $this->bySector($items),
            ],
            'items' => $items->map(fn (Task $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'origin_sector' => $t->originSector === null ? null : ['id' => $t->originSector->id, 'name' => $t->originSector->name],
                'responsible_sector' => $t->responsibleSector === null ? null : ['id' => $t->responsibleSector->id, 'name' => $t->responsibleSector->name],
                'priority' => $t->priority?->value,
                'status' => $t->status?->value,
                'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->isOverdue(),
            ])->all(),
        ];
    }

    private function occurrences(Event $event): array
    {
        $query = Occurrence::query()->where('event_id', $event->id);
        $items = (clone $query)->with('responsibleSector:id,name', 'eventDay:id,day_number,date,label', 'team:id,name')->orderBy('id')
            ->get(['id', 'reference', 'title', 'category', 'responsible_sector_id', 'priority', 'status', 'event_day_id', 'occurred_at', 'team_id', 'resolution']);

        return [
            'summary' => [
                'total' => $items->count(),
                'by_status' => $this->countByEnum($query, 'status', OccurrenceStatus::class),
                'by_category' => $this->countByEnum($query, 'category', OccurrenceCategory::class),
                'by_priority' => $this->countByEnum($query, 'priority', DemandPriority::class),
            ],
            'items' => $items->map(fn (Occurrence $o) => [
                'id' => $o->id,
                'reference' => $o->reference,
                'title' => $o->title,
                'category' => $o->category?->value,
                'responsible_sector' => $o->responsibleSector === null ? null : ['id' => $o->responsibleSector->id, 'name' => $o->responsibleSector->name],
                'priority' => $o->priority?->value,
                'status' => $o->status?->value,
                'event_day' => $o->eventDay === null ? null : ['id' => $o->eventDay->id, 'day_number' => $o->eventDay->day_number, 'date' => $o->eventDay->date?->toDateString(), 'label' => $o->eventDay->label],
                'occurred_at' => $o->occurred_at?->toIso8601String(),
                'team' => $o->team === null ? null : ['id' => $o->team->id, 'name' => $o->team->name],
                'resolution' => $o->status === OccurrenceStatus::Resolved ? $o->resolution : null,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Task>  $items
     * @return list<array{sector: array{id: int, name: string}|null, total: int, open: int}>
     */
    private function bySector($items): array
    {
        return $items->groupBy('responsible_sector_id')->map(fn ($group) => [
            'sector' => $group->first()->responsibleSector === null ? null : ['id' => $group->first()->responsibleSector->id, 'name' => $group->first()->responsibleSector->name],
            'total' => $group->count(),
            'open' => $group->filter(fn (Task $t) => ! $t->isClosed())->count(),
        ])->sortBy(fn ($row) => $row['sector']['name'] ?? '')->values()->all();
    }
}
