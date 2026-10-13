<?php

namespace App\Domain\Reports;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Base dos relatórios (Fase 11): metadados comuns e contagens agregadas no SQL.
 *
 * Relatório é derivado: lê o estado atual do banco, não é persistido e não gera auditoria.
 * Nunca devolve senha, documento pessoal, código de check-in, segredos ou dados de auditoria.
 */
abstract class ReportBuilder
{
    /**
     * Dias do evento lidos uma vez por geração.
     *
     * @var Collection<int, EventDay>|null
     */
    private ?Collection $days = null;

    abstract public function type(): string;

    abstract public function title(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function sections(Event $event): array;

    /**
     * @return array<string, mixed>
     */
    public function build(Event $event, User $actor): array
    {
        $this->days = null;
        $actor->loadMissing('person:id,full_name', 'role');

        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => [
                'id' => $actor->id,
                'name' => $actor->person?->full_name,
                'role' => ['code' => $actor->role?->code?->value, 'name' => $actor->role?->name],
            ],
            'event' => $this->event($event),
        ] + $this->sections($event);
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'location' => $event->location,
            'timezone' => $event->timezone,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'status' => $event->status?->value,
            'days' => $this->days($event)->map(fn (EventDay $day) => [
                'id' => $day->id,
                'day_number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'label' => $day->label,
                'start_time' => $day->start_time === null ? null : substr($day->start_time, 0, 5),
                'end_time' => $day->end_time === null ? null : substr($day->end_time, 0, 5),
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, EventDay>
     */
    protected function days(Event $event): Collection
    {
        return $this->days ??= EventDay::query()->where('event_id', $event->id)->orderBy('date')->orderBy('day_number')->get();
    }

    /**
     * Contagem por valor de uma coluna, com todos os códigos do enum presentes (zero quando não há).
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, int>
     */
    protected function countByEnum(EloquentBuilder|QueryBuilder $query, string $column, string $enum): array
    {
        $counts = (clone $query)->reorder()->groupBy($column)->selectRaw("{$column} as code, count(*) as total")->pluck('total', 'code');

        $result = [];
        foreach ($enum::cases() as $case) {
            $result[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $result;
    }
}
