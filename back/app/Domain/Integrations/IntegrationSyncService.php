<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Integrations\Enums\TicketCategory;
use App\Domain\Jurors\JurorService;
use App\Integrations\Even3\Even3Exception;
use App\Integrations\EventPlatform\EventPlatformProvider;
use App\Models\EventIntegration;
use App\Models\ExternalTicketType;
use App\Models\IntegrationSyncRun;
use App\Models\Person;
use App\Models\User;
use App\Support\Database\TransactionLock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Sincronização central (plataforma → HackLab): evento → entradas → participantes.
 *
 * - no máximo 1 RUNNING por integração (lock da linha + índice único parcial); um RUNNING abandonado há
 *   mais de STALE_MINUTES é encerrado como FAILED (STALE_RUN);
 * - cada participante numa transação pequena (RegistrationRecorder): um item ruim não derruba os outros;
 * - SUCCESS / PARTIAL (algum item rejeitado, em conflito ou com erro) / FAILED (recurso externo essencial);
 * - contadores só contam mudança real; avisos sem payload externo nem segredo;
 * - ausência de um item na resposta nunca cancela inscrição;
 * - o que é seguro é automático: ao fim de um sync de inscritos que não falhou, cada Person com inscrição JUROR
 *   confiável (CONFIRMED, sem identity_mismatch, entrada mapeada) e sem Juror no evento vira Juror ativo pelo
 *   JurorService (contador jurors_created). Nunca cria User, Participant, Company nem altera o Event.
 *   Exceções ficam derivadas para a lista "Precisa de atenção" (Even3Attention).
 */
class IntegrationSyncService
{
    public const STALE_MINUTES = 30;

    private const MAX_WARNINGS = 200;

    public function __construct(
        private readonly EventPlatformProvider $provider,
        private readonly RegistrationRecorder $recorder,
        private readonly JurorService $jurors,
    ) {}

    public function start(EventIntegration $integration, SyncScope $scope, SyncTrigger $trigger, ?User $actor): IntegrationSyncRun
    {
        try {
            return DB::transaction(function () use ($integration, $scope, $trigger, $actor) {
                EventIntegration::query()->lockForUpdate()->findOrFail($integration->id);

                $running = IntegrationSyncRun::query()->where('event_integration_id', $integration->id)
                    ->where('status', SyncStatus::Running->value)->lockForUpdate()->first();

                if ($running !== null) {
                    if ($running->started_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
                        $this->finish($running, SyncStatus::Failed, [], [], 'STALE_RUN', 'Sincronização abandonada (sem conclusão) encerrada automaticamente.');
                    } else {
                        throw new ConflictHttpException("Já existe uma sincronização em andamento para esta integração (#{$running->id}).");
                    }
                }

                $run = new IntegrationSyncRun([
                    'event_integration_id' => $integration->id,
                    'scope' => $scope,
                    'trigger' => $trigger,
                    'started_by_user_id' => $actor?->id,
                    'started_at' => now(),
                ]);
                $run->forceFill(['status' => SyncStatus::Running, 'counters' => []])->save();

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Já existe uma sincronização em andamento para esta integração.');
        }
    }

    public function execute(IntegrationSyncRun $run): IntegrationSyncRun
    {
        $integration = EventIntegration::query()->with('event')->findOrFail($run->event_integration_id);
        $counters = [];
        $warnings = [];

        try {
            if (in_array($run->scope, [SyncScope::Event, SyncScope::All], true)) {
                $this->syncEvent($integration, $counters);
            }

            if (in_array($run->scope, [SyncScope::Tickets, SyncScope::All], true)) {
                $this->syncTickets($integration, $counters, $warnings);
            }

            if (in_array($run->scope, [SyncScope::Attendees, SyncScope::All], true)) {
                $this->syncAttendees($integration, $counters, $warnings);
            }
        } catch (Even3Exception $e) {
            return $this->finish($run, SyncStatus::Failed, $counters, $warnings, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            // Mensagem genérica: exceções inesperadas podem carregar dados que não devem ir para o histórico.
            report($e);

            return $this->finish($run, SyncStatus::Failed, $counters, $warnings, 'UNEXPECTED', 'Erro inesperado durante a sincronização ('.class_basename($e).').');
        }

        if (in_array($run->scope, [SyncScope::Attendees, SyncScope::All], true)) {
            try {
                $counters['jurors_created'] = $this->createTrustedJurors($integration);
            } catch (Throwable $e) {
                report($e);
                $this->warn($warnings, 'JURORS_NOT_CREATED', null, 'Inscrições sincronizadas, mas os jurados não puderam ser criados ('.class_basename($e).').');
            }
        }

        $itemProblems = ($counters['attendees_rejected'] ?? 0) + ($counters['attendees_conflicts'] ?? 0) + ($counters['attendees_failed'] ?? 0);

        return $this->finish($run, $itemProblems > 0 ? SyncStatus::Partial : SyncStatus::Success, $counters, $warnings);
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function syncEvent(EventIntegration $integration, array &$counters): void
    {
        $event = $this->provider->fetchEvent($integration->external_event_id);

        $integration->rememberExternalEvent($event);
        $integration->forceFill(['last_connection_checked_at' => now()])->save();

        $counters['event_checked'] = 1;
    }

    /**
     * @param  array<string, int>  $counters
     * @param  list<array<string, mixed>>  $warnings
     */
    private function syncTickets(EventIntegration $integration, array &$counters, array &$warnings): void
    {
        $result = $this->provider->fetchTickets($integration->external_event_id);
        $counters += ['tickets_seen' => 0, 'tickets_created' => 0, 'tickets_updated' => 0, 'tickets_unchanged' => 0, 'tickets_deactivated' => 0, 'tickets_invalid' => 0];

        foreach ($result['problems'] as $problem) {
            $counters['tickets_invalid']++;
            $this->warn($warnings, $problem['problem'], null, "Entrada \"{$problem['name']}\" sem identificador único (ticket_id) na resposta: ignorada.");
        }

        $seen = [];

        DB::transaction(function () use ($integration, $result, &$counters, &$seen) {
            RegistrationRecorder::lock($integration);
            $now = now();

            foreach ($result['tickets'] as $ticket) {
                $counters['tickets_seen']++;
                $seen[] = $ticket['external_ticket_id'];

                $model = ExternalTicketType::query()->where('event_integration_id', $integration->id)
                    ->where('external_ticket_id', $ticket['external_ticket_id'])->first();

                if ($model === null) {
                    ExternalTicketType::query()->create([
                        'event_id' => $integration->event_id,
                        'event_integration_id' => $integration->id,
                        'external_ticket_id' => $ticket['external_ticket_id'],
                        'external_name' => $ticket['name'] !== '' ? $ticket['name'] : "Entrada {$ticket['external_ticket_id']}",
                        'active' => true,
                        'last_seen_at' => $now,
                    ]);
                    $counters['tickets_created']++;

                    continue;
                }

                // Renomear não perde o mapeamento (identidade = external_ticket_id).
                $model->fill(['external_name' => $ticket['name'] !== '' ? $ticket['name'] : $model->external_name, 'active' => true]);
                $counters[$model->isDirty() ? 'tickets_updated' : 'tickets_unchanged']++;
                $model->last_seen_at = $now;
                $model->save();
            }

            // Só depois de uma leitura completa: lista não vazia e todas as entradas identificáveis.
            // Entradas conhecidas que sumiram ficam inativas (nunca apagadas).
            if ($seen !== [] && $result['problems'] === []) {
                $counters['tickets_deactivated'] = ExternalTicketType::query()->where('event_integration_id', $integration->id)
                    ->where('active', true)->whereNotIn('external_ticket_id', $seen)->update(['active' => false, 'updated_at' => $now]);
            }
        });

        if ($seen === [] && $result['problems'] === [] && ExternalTicketType::query()->where('event_integration_id', $integration->id)->where('active', true)->exists()) {
            $this->warn($warnings, 'TICKETS_EMPTY_RESPONSE', null, 'A plataforma não devolveu nenhuma entrada; as entradas conhecidas não foram desativadas.');
        }
    }

    /**
     * @param  array<string, int>  $counters
     * @param  list<array<string, mixed>>  $warnings
     */
    private function syncAttendees(EventIntegration $integration, array &$counters, array &$warnings): void
    {
        $counters += ['attendee_pages' => 0, 'attendees_seen' => 0, 'attendees_created' => 0, 'attendees_updated' => 0, 'attendees_unchanged' => 0,
            'attendees_rejected' => 0, 'attendees_conflicts' => 0, 'attendees_failed' => 0, 'persons_created' => 0];

        foreach ($this->provider->fetchAttendees() as $page) {
            $counters['attendee_pages']++;

            foreach ($page as $attendee) {
                $counters['attendees_seen']++;

                try {
                    $outcome = $this->recorder->record($integration, $attendee, 'API');
                } catch (Throwable $e) {
                    report($e);
                    $counters['attendees_failed']++;
                    $this->warn($warnings, 'ITEM_FAILED', $attendee['external_attendee_id'] ?? null, 'Falha ao processar a inscrição ('.class_basename($e).').');

                    continue;
                }

                $key = match ($outcome['result']) {
                    'created' => 'attendees_created',
                    'updated' => 'attendees_updated',
                    'unchanged' => 'attendees_unchanged',
                    'rejected' => 'attendees_rejected',
                    default => 'attendees_conflicts',
                };
                $counters[$key]++;
                $counters['persons_created'] += $outcome['person_created'] ? 1 : 0;

                foreach ($outcome['warnings'] as $warning) {
                    $this->warn($warnings, $warning['code'], $warning['external_id'], $warning['message']);
                }
            }
        }
    }

    /**
     * Jurado confiável → Juror ativo (sem conta, sem equipes). Serializa por Person; o unique(event_id, person_id)
     * de jurors é a última barreira contra duplicação.
     */
    private function createTrustedJurors(EventIntegration $integration): int
    {
        $event = $integration->event()->firstOrFail();
        $people = Person::query()->whereIn('people.id', Even3Attention::trustedPeople($integration, TicketCategory::Juror))
            ->whereNotExists(fn ($q) => $q->from('jurors as j')->whereColumn('j.person_id', 'people.id')->where('j.event_id', $event->id))
            ->orderBy('people.id')->pluck('people.id');
        $created = 0;

        foreach ($people as $personId) {
            try {
                $created += DB::transaction(function () use ($event, $personId) {
                    TransactionLock::acquire(91005, (int) $personId);
                    if (DB::table('jurors')->where('event_id', $event->id)->where('person_id', $personId)->exists()) {
                        return 0;
                    }
                    $this->jurors->create($event, ['person_id' => $personId]);

                    return 1;
                });
            } catch (UniqueConstraintViolationException) {
                // Outro processo criou ao mesmo tempo: já existe, segue.
            }
        }

        return $created;
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function warn(array &$warnings, string $code, ?string $externalId, string $message): void
    {
        if (count($warnings) < self::MAX_WARNINGS) {
            $warnings[] = ['code' => $code, 'external_id' => $externalId, 'message' => $message];
        }
    }

    /**
     * @param  array<string, int>  $counters
     * @param  list<array<string, mixed>>  $warnings
     */
    private function finish(IntegrationSyncRun $run, SyncStatus $status, array $counters, array $warnings, ?string $errorCode = null, ?string $errorMessage = null): IntegrationSyncRun
    {
        $now = now();

        $run->forceFill([
            'status' => $status,
            'finished_at' => $now,
            'duration_ms' => (int) max(0, $run->started_at->diffInMilliseconds($now)),
            'counters' => $counters,
            'warnings' => $warnings === [] ? null : $warnings,
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 1000),
        ])->save();

        EventIntegration::query()->whereKey($run->event_integration_id)->update([
            'last_sync_at' => $now,
            'last_sync_status' => $status->value,
            'last_error_code' => $errorCode,
            'last_error_at' => $errorCode === null ? null : $now,
            'updated_at' => $now,
        ]);

        return $run;
    }
}
