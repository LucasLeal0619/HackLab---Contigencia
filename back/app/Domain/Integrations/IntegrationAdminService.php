<?php

namespace App\Domain\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Integrations\Even3\Even3Exception;
use App\Integrations\EventPlatform\EventPlatformProvider;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\EventRegistration;
use App\Models\ExternalTicketType;
use App\Models\IntegrationSyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operações administrativas da integração Even3 (cada uma com um único log de auditoria):
 * configurar o vínculo, mapear entradas e disparar sincronização manual.
 */
class IntegrationAdminService
{
    public function __construct(
        private readonly EventPlatformProvider $provider,
        private readonly IntegrationSyncService $sync,
        private readonly AuditLogger $audit,
    ) {}

    public static function ensureEnabled(): void
    {
        if (! config('services.even3.enabled')) {
            throw ValidationException::withMessages(['integration' => 'Integração Even3 desabilitada (EVEN3_ENABLED=false).']);
        }

        if (blank(config('services.even3.token'))) {
            throw ValidationException::withMessages(['integration' => 'EVEN3_API_TOKEN não está configurado no backend.']);
        }
    }

    /**
     * Vínculo explícito Event ↔ evento externo, conferido na plataforma antes de ativar.
     * Nome/datas diferentes não bloqueiam: viram avisos.
     *
     * @return array{integration: EventIntegration, warnings: list<string>}
     */
    public function configure(Event $event, string $externalEventId, IntegrationStatus $status, User $actor): array
    {
        self::ensureEnabled();

        try {
            return DB::transaction(function () use ($event, $externalEventId, $status, $actor) {
                DB::table('events')->where('id', $event->id)->lockForUpdate()->first();
                $integration = EventIntegration::query()->where('event_id', $event->id)->where('provider', EventIntegration::PROVIDER_EVEN3)->lockForUpdate()->first();
                $before = $integration === null ? null : $this->snapshot($integration);
                $changingTarget = $integration !== null && $integration->external_event_id !== $externalEventId;

                if ($changingTarget && (EventRegistration::query()->where('event_integration_id', $integration->id)->exists()
                    || ExternalTicketType::query()->where('event_integration_id', $integration->id)->exists())) {
                    throw ValidationException::withMessages(['external_event_id' => 'A integração já tem entradas/inscrições sincronizadas: o evento externo não pode ser trocado.']);
                }

                $usedElsewhere = EventIntegration::query()->where('provider', EventIntegration::PROVIDER_EVEN3)
                    ->where('external_event_id', $externalEventId)->where('event_id', '<>', $event->id)->exists();

                if ($usedElsewhere) {
                    throw ValidationException::withMessages(['external_event_id' => 'Este evento externo já está vinculado a outro evento do HackLab.']);
                }

                $warnings = [];
                $integration ??= new EventIntegration([
                    'event_id' => $event->id,
                    'provider' => EventIntegration::PROVIDER_EVEN3,
                    'api_version' => 'V2',
                ]);

                // Ativar (ou trocar/criar) exige confirmar na plataforma que a chave acessa o evento.
                if ($status === IntegrationStatus::Active || ! $integration->exists || $changingTarget) {
                    $external = $this->fetchAccessibleEvent($externalEventId);
                    $warnings = $this->differences($event, $external);
                    $integration->external_event_title = $external['title'] ?? null;
                    $integration->last_connection_checked_at = now();
                }

                $integration->external_event_id = $externalEventId;
                $integration->status = $status;

                if (! $integration->isDirty(['external_event_id', 'status']) && $integration->exists) {
                    $integration->save();

                    return ['integration' => $integration, 'warnings' => $warnings];
                }

                $integration->save();

                $this->audit->record(
                    AuditAction::EVEN3_INTEGRATION_CONFIGURED,
                    'integrations',
                    "Integração Even3 do evento {$event->name}: evento externo {$externalEventId} ({$status->value}).",
                    entity: $integration,
                    before: $before,
                    after: $this->snapshot($integration) + ['warnings' => $warnings],
                    actor: $actor,
                );

                return ['integration' => $integration, 'warnings' => $warnings];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_event_id' => 'Este evento externo já está vinculado a outro evento do HackLab.']);
        }
    }

    public function changeMapping(ExternalTicketType $ticket, ?TicketCategory $category, User $actor): ExternalTicketType
    {
        return DB::transaction(function () use ($ticket, $category, $actor) {
            $ticket = ExternalTicketType::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = $ticket->internal_category?->value;

            if ($ticket->internal_category === $category) {
                return $ticket;
            }

            $ticket->forceFill(['internal_category' => $category])->save();

            $this->audit->record(
                AuditAction::EVEN3_TICKET_MAPPING_CHANGED,
                'integrations',
                "Entrada \"{$ticket->external_name}\" mapeada como ".($category?->value ?? 'sem categoria').'.',
                entity: $ticket,
                before: ['internal_category' => $before, 'external_ticket_id' => $ticket->external_ticket_id],
                after: ['internal_category' => $category?->value, 'external_ticket_id' => $ticket->external_ticket_id],
                actor: $actor,
            );

            return $ticket;
        });
    }

    /**
     * Sync manual: um log (EVEN3_SYNC_TRIGGERED); o resultado detalhado fica no IntegrationSyncRun.
     */
    public function triggerSync(EventIntegration $integration, SyncScope $scope, User $actor): IntegrationSyncRun
    {
        self::ensureEnabled();

        if (! $integration->isActive()) {
            throw ValidationException::withMessages(['integration' => 'Integração desativada: ative-a antes de sincronizar.']);
        }

        $run = $this->sync->start($integration, $scope, SyncTrigger::Manual, $actor);

        $this->audit->record(
            AuditAction::EVEN3_SYNC_TRIGGERED,
            'integrations',
            "Sincronização Even3 ({$scope->value}) disparada para o evento externo {$integration->external_event_id}.",
            entity: $run,
            after: ['sync_run_id' => $run->id, 'scope' => $scope->value],
            actor: $actor,
            event: $integration->event_id,
        );

        return $this->sync->execute($run);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccessibleEvent(string $externalEventId): array
    {
        try {
            return $this->provider->fetchEvent($externalEventId);
        } catch (Even3Exception $e) {
            $message = match ($e->errorCode) {
                Even3Exception::NOT_FOUND, Even3Exception::FORBIDDEN => "O evento externo {$externalEventId} não está acessível pela chave configurada.",
                Even3Exception::UNAUTHORIZED => 'A Even3 recusou a chave configurada (EVEN3_API_TOKEN).',
                default => "Não foi possível consultar a Even3 ({$e->errorCode}).",
            };

            throw ValidationException::withMessages(['external_event_id' => $message]);
        }
    }

    /**
     * @param  array<string, mixed>  $external
     * @return list<string>
     */
    private function differences(Event $event, array $external): array
    {
        $warnings = [];

        if (($external['title'] ?? null) !== null && IdentityNormalizer::label($external['title']) !== IdentityNormalizer::label($event->name)) {
            $warnings[] = 'O nome do evento na Even3 é diferente do nome no HackLab.';
        }

        foreach (['start_date' => 'início', 'end_date' => 'término'] as $field => $label) {
            if (($external[$field] ?? null) === null) {
                continue;
            }

            try {
                $externalDate = CarbonImmutable::parse($external[$field])->toDateString();
            } catch (\Throwable) {
                $warnings[] = "Data de {$label} da Even3 em formato inesperado.";

                continue;
            }

            if ($externalDate !== $event->{$field}?->toDateString()) {
                $warnings[] = "A data de {$label} na Even3 é diferente da do HackLab.";
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EventIntegration $integration): array
    {
        return [
            'provider' => $integration->provider,
            'external_event_id' => $integration->external_event_id,
            'status' => $integration->status?->value,
            'api_version' => $integration->api_version,
        ];
    }
}
