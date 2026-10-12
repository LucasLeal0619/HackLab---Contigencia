<?php

namespace App\Domain\Events;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Events\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eventos e dias do evento. Nenhuma quantidade de dias é assumida: os dias são cadastrados.
 */
class EventService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Event
    {
        return DB::transaction(function () use ($data) {
            $event = Event::query()->create($data + ['status' => EventStatus::Planned]);

            $this->audit->record(
                AuditAction::EVENT_CREATED,
                'events',
                "Evento {$event->name} criado.",
                entity: $event,
                after: $this->snapshot($event),
            );

            return $event->load('days');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data): Event
    {
        return DB::transaction(function () use ($event, $data) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $before = $this->snapshot($event);
            $event->fill($data);

            if (! $event->isDirty()) {
                return $event->load('days');
            }

            if ($event->end_date->lt($event->start_date)) {
                throw ValidationException::withMessages(['end_date' => 'A data final não pode ser anterior à inicial.']);
            }

            $outside = $event->days()
                ->where(fn ($q) => $q->where('date', '<', $event->start_date->toDateString())
                    ->orWhere('date', '>', $event->end_date->toDateString()))
                ->count();

            if ($outside > 0) {
                throw ValidationException::withMessages([
                    'start_date' => "O novo período deixaria {$outside} dia(s) cadastrado(s) fora do evento.",
                ]);
            }

            $event->save();

            $this->audit->record(
                AuditAction::EVENT_UPDATED,
                'events',
                "Evento {$event->name} alterado.",
                entity: $event,
                before: $before,
                after: $this->snapshot($event),
            );

            return $event->load('days');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addDay(Event $event, array $data): EventDay
    {
        return DB::transaction(function () use ($event, $data) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->ensureDateInsideEvent($event, $data['date']);

            $day = $event->days()->create($data);

            $this->audit->record(
                AuditAction::EVENT_DAY_CREATED,
                'events',
                "Dia {$day->day_number} ({$day->label}) adicionado ao evento {$event->name}.",
                entity: $day,
                after: $this->daySnapshot($day),
            );

            return $day;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDay(EventDay $day, array $data): EventDay
    {
        return DB::transaction(function () use ($day, $data) {
            $event = Event::query()->lockForUpdate()->findOrFail($day->event_id);
            $before = $this->daySnapshot($day);
            $day->fill($data);

            if (! $day->isDirty()) {
                return $day;
            }

            $this->ensureDateInsideEvent($event, $day->date);
            $day->save();

            $this->audit->record(
                AuditAction::EVENT_DAY_UPDATED,
                'events',
                "Dia {$day->day_number} do evento {$event->name} alterado.",
                entity: $day,
                before: $before,
                after: $this->daySnapshot($day),
            );

            return $day;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Event $event): array
    {
        return [
            'name' => $event->name,
            'description' => $event->description,
            'location' => $event->location,
            'timezone' => $event->timezone,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'status' => $event->status?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function daySnapshot(EventDay $day): array
    {
        return [
            'event_id' => $day->event_id,
            'day_number' => $day->day_number,
            'date' => $day->date?->toDateString(),
            'label' => $day->label,
            'start_time' => $day->start_time,
            'end_time' => $day->end_time,
        ];
    }

    private function ensureDateInsideEvent(Event $event, mixed $date): void
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($date->lt($event->start_date) || $date->gt($event->end_date)) {
            throw ValidationException::withMessages([
                'date' => "A data precisa estar entre {$event->start_date->format('d/m/Y')} e {$event->end_date->format('d/m/Y')}.",
            ]);
        }
    }
}
