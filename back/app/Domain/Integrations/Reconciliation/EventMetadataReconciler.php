<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Events\EventService;
use App\Domain\Integrations\IdentityNormalizer;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Divergências entre o evento do HackLab e o último retrato do evento na Even3 (título e datas guardados
 * na configuração/sincronização). Nunca chama a Even3 e nunca sobrescreve sozinho.
 *
 * Aplicar: o front escolhe só QUAIS campos; os valores vêm do retrato persistido e passam pelo EventService
 * (mesmas validações e mesma auditoria da edição manual).
 */
class EventMetadataReconciler
{
    public const FIELDS = ['name', 'start_date', 'end_date'];

    public function __construct(
        private readonly EventService $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{field: string, hacklab: ?string, even3: ?string, different: bool}>
     */
    public function compare(Event $event, ?EventIntegration $integration): array
    {
        if ($integration === null) {
            return [];
        }

        $external = $this->externalValues($integration);
        $internal = [
            'name' => $event->name,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
        ];

        return array_map(fn (string $field) => [
            'field' => $field,
            'hacklab' => $internal[$field],
            'even3' => $external[$field],
            // Sem valor da Even3 = desconhecido, não divergente.
            'different' => $external[$field] !== null && ($field === 'name'
                ? IdentityNormalizer::label($external[$field]) !== IdentityNormalizer::label((string) $internal[$field])
                : $external[$field] !== $internal[$field]),
        ], self::FIELDS);
    }

    public function differenceCount(Event $event, ?EventIntegration $integration): int
    {
        return count(array_filter($this->compare($event, $integration), fn (array $row) => $row['different']));
    }

    /**
     * @param  list<string>  $fields
     */
    public function apply(Event $event, EventIntegration $integration, array $fields, ?User $actor): Event
    {
        $external = $this->externalValues($integration);
        $data = [];

        foreach (array_values(array_unique($fields)) as $field) {
            if ($external[$field] === null) {
                throw ValidationException::withMessages(['fields' => "A Even3 não informou {$this->fieldLabel($field)}. Sincronize o evento antes."]);
            }

            $data[$field] = $external[$field];
        }

        if (isset($data['name']) && mb_strlen($data['name']) > 255) {
            throw ValidationException::withMessages(['fields' => 'O nome do evento na Even3 tem mais de 255 caracteres.']);
        }

        return DB::transaction(function () use ($event, $integration, $data, $actor) {
            $before = ['name' => $event->name, 'start_date' => $event->start_date?->toDateString(), 'end_date' => $event->end_date?->toDateString()];
            $updated = $this->events->update($event, $data);

            $this->audit->record(
                AuditAction::EVEN3_EVENT_METADATA_APPLIED,
                'integrations',
                "Dados da Even3 aplicados ao evento {$updated->name}: ".implode(', ', array_map(fn ($f) => $this->fieldLabel($f), array_keys($data))).'.',
                entity: $integration,
                before: array_intersect_key($before, $data),
                after: $data,
                actor: $actor,
                event: $event,
            );

            return $updated;
        });
    }

    /**
     * @return array{name: ?string, start_date: ?string, end_date: ?string}
     */
    private function externalValues(EventIntegration $integration): array
    {
        return [
            'name' => filled($integration->external_event_title) ? trim($integration->external_event_title) : null,
            'start_date' => $integration->external_start_date?->toDateString(),
            'end_date' => $integration->external_end_date?->toDateString(),
        ];
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => 'o nome',
            'start_date' => 'a data inicial',
            'end_date' => 'a data final',
        };
    }
}
