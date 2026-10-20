<?php

namespace App\Domain\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Participants\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operação explícita e idempotente: inscrição CONFIRMED + entrada PARTICIPANT → Participant(event, person)
 * AVAILABLE, sem turma, se ainda não existir. Participante existente não muda (status, turma, equipe, desafio).
 * Um único log por execução (sem um log por participante).
 */
class ParticipantMaterializer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{eligible: int, created: int, already_existing: int}
     */
    public function materialize(Event $event, User $actor): array
    {
        $integration = EventIntegration::even3For($event->id);

        if ($integration === null) {
            throw ValidationException::withMessages(['integration' => 'Este evento não tem integração Even3 configurada.']);
        }

        return DB::transaction(function () use ($event, $integration, $actor) {
            // Serializa materializações do mesmo evento; o unique(event_id, person_id) é a última barreira.
            DB::select('select pg_advisory_xact_lock(?, ?)', [91004, $event->id]);

            $personIds = EventRegistration::query()
                ->where('event_registrations.event_id', $event->id)
                ->where('event_registrations.event_integration_id', $integration->id)
                ->where('event_registrations.status', RegistrationStatus::Confirmed->value)
                ->join('external_ticket_types as t', 't.id', '=', 'event_registrations.external_ticket_type_id')
                ->where('t.internal_category', TicketCategory::Participant->value)
                ->orderBy('event_registrations.person_id')
                ->pluck('event_registrations.person_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $now = now();
            $created = DB::table('participants')->insertOrIgnore(array_map(fn (int $personId) => [
                'event_id' => $event->id,
                'person_id' => $personId,
                'class_id' => null,
                'status' => ParticipantStatus::Available->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $personIds));

            $result = ['eligible' => count($personIds), 'created' => $created, 'already_existing' => count($personIds) - $created];

            $this->audit->record(
                AuditAction::EVEN3_PARTICIPANTS_MATERIALIZED,
                'integrations',
                "Participantes materializados a partir das inscrições Even3: {$created} novo(s) de {$result['eligible']} elegível(is).",
                entity: $integration,
                after: $result,
                actor: $actor,
            );

            return $result;
        });
    }
}
