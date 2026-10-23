<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Enums\AttendanceKind;
use App\Domain\Integrations\Enums\AttendanceSource;
use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\RegistrationStatus;
use App\Domain\Integrations\Enums\WebhookReceiptStatus;
use App\Integrations\Even3\Even3Mapper;
use App\Models\AttendanceRecord;
use App\Models\EventDay;
use App\Models\EventIntegration;
use App\Models\IntegrationWebhookReceipt;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processa um disparo do webhook da Even3 (já autenticado pelo controller).
 *
 * - Idempotente pelo id do disparo: PROCESSED/IGNORED não reprocessa; FAILED reprocessa no reenvio.
 * - Mesmo advisory lock e mesmo RegistrationRecorder do sync: webhook e API convergem para uma inscrição.
 * - Evento do payload precisa ser o external_event_id configurado (nunca pelo título).
 * - Gatilhos fora do escopo → IGNORED. Check-in → attendance_record (um por disparo).
 * - Sem AuditLog por inscrito; sem payload completo guardado (só o hash).
 *
 * Retorna [status HTTP, recibo]: 2xx para tudo que não adianta reenviar (inclusive conflitos de dados,
 * que a reconciliação pela API resolve); 500 só para falha inesperada (a Even3 reenvia).
 */
class Even3WebhookService
{
    public function __construct(
        private readonly Even3Mapper $mapper,
        private readonly RegistrationRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: IntegrationWebhookReceipt}
     */
    public function handle(array $payload, string $rawBody): array
    {
        $data = $this->mapper->webhook($payload);
        $hash = hash('sha256', $rawBody);

        $integration = $data['external_event_id'] === null ? null : EventIntegration::query()
            ->with('event')
            ->where('provider', EventIntegration::PROVIDER_EVEN3)
            ->where('external_event_id', $data['external_event_id'])
            ->where('status', IntegrationStatus::Active->value)
            ->first();

        if ($integration === null) {
            return [200, $this->orphanReceipt($data, $hash)];
        }

        try {
            return DB::transaction(function () use ($integration, $data, $hash) {
                RegistrationRecorder::lock($integration);

                $receipt = IntegrationWebhookReceipt::query()->where('event_integration_id', $integration->id)
                    ->where('external_delivery_id', $data['delivery_id'])->lockForUpdate()->first();

                if ($receipt !== null && in_array($receipt->status, [WebhookReceiptStatus::Processed, WebhookReceiptStatus::Ignored], true)) {
                    return [200, $receipt]; // reenvio: sem novo efeito
                }

                $receipt ??= new IntegrationWebhookReceipt([
                    'event_integration_id' => $integration->id,
                    'external_delivery_id' => $data['delivery_id'],
                    'received_at' => now(),
                ]);
                $receipt->fill(['event_type_id' => $data['type_id'], 'event_type_name' => mb_substr($data['type_name'] ?: '(sem nome)', 0, 255), 'payload_hash' => $hash]);
                $receipt->forceFill(['status' => WebhookReceiptStatus::Received, 'error_code' => null, 'error_message' => null])->save();

                [$status, $code, $message] = $this->process($integration, $receipt, $data);

                $receipt->forceFill(['status' => $status, 'processed_at' => now(), 'error_code' => $code, 'error_message' => $message])->save();

                return [200, $receipt];
            });
        } catch (Throwable $e) {
            report($e);

            // A transação foi desfeita: registra a falha à parte para permitir o reprocessamento no reenvio.
            $receipt = DB::transaction(function () use ($integration, $data, $hash, $e) {
                $receipt = IntegrationWebhookReceipt::query()->firstOrNew([
                    'event_integration_id' => $integration->id,
                    'external_delivery_id' => $data['delivery_id'],
                ]);
                $receipt->fill(['event_type_id' => $data['type_id'], 'event_type_name' => mb_substr($data['type_name'] ?: '(sem nome)', 0, 255), 'payload_hash' => $hash]);
                $receipt->received_at ??= now();
                $receipt->forceFill(['status' => WebhookReceiptStatus::Failed, 'error_code' => 'PROCESSING_FAILED', 'error_message' => 'Falha ao processar ('.class_basename($e).').'])->save();

                return $receipt;
            });

            return [500, $receipt];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: WebhookReceiptStatus, 1: ?string, 2: ?string}
     */
    private function process(EventIntegration $integration, IntegrationWebhookReceipt $receipt, array $data): array
    {
        $action = $data['action'];

        if ($action === Even3Mapper::ACTION_IGNORED) {
            return [WebhookReceiptStatus::Ignored, 'TRIGGER_NOT_HANDLED', 'Gatilho fora do escopo desta integração.'];
        }

        $explicit = match ($action) {
            Even3Mapper::ACTION_PENDING => RegistrationStatus::Pending,
            Even3Mapper::ACTION_CONFIRMED => RegistrationStatus::Confirmed,
            Even3Mapper::ACTION_CANCELLED => RegistrationStatus::Cancelled,
            default => null,
        };

        $outcome = $this->recorder->record($integration, $data['attendee'], 'WEBHOOK', $explicit, $data['observed_at']);

        if ($outcome['registration'] === null) {
            $warning = $outcome['warnings'][0] ?? ['code' => 'NOT_PROCESSED', 'message' => 'Inscrição não processada.'];

            return [WebhookReceiptStatus::Failed, $warning['code'], $warning['message']];
        }

        if (! in_array($action, [Even3Mapper::ACTION_CHECKIN_EVENT, Even3Mapper::ACTION_CHECKIN_ACTIVITY], true)) {
            $warning = $outcome['warnings'][0] ?? null;

            return [WebhookReceiptStatus::Processed, $warning['code'] ?? null, $warning['message'] ?? null];
        }

        $observedAt = $data['observed_at'] ?? CarbonImmutable::now();
        // checked_in_at só com horário explícito de check-in na origem (não documentado): fica nulo.
        $checkedInAt = null;
        [$dayId, $dayWarning] = $this->eventDayFor($integration, $checkedInAt ?? $observedAt);

        AttendanceRecord::query()->create([
            'event_id' => $integration->event_id,
            'event_integration_id' => $integration->id,
            'event_registration_id' => $outcome['registration']->id,
            'event_day_id' => $dayId,
            'kind' => $action === Even3Mapper::ACTION_CHECKIN_EVENT ? AttendanceKind::Event : AttendanceKind::Activity,
            'external_activity_id' => $data['external_activity_id'],
            'checked_in_at' => $checkedInAt,
            'observed_at' => $observedAt,
            'source' => AttendanceSource::Webhook,
            'webhook_receipt_id' => $receipt->id,
        ]);

        return [WebhookReceiptStatus::Processed, $dayWarning, $dayWarning === null ? null : 'Check-in registrado sem dia do evento.'];
    }

    /**
     * Instante UTC → timezone do evento (IANA) → data local → event_days.date. Nunca cria EventDay.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public function eventDayFor(EventIntegration $integration, CarbonImmutable $instant): array
    {
        $timezone = $integration->event?->timezone;

        if ($timezone === null) {
            return [null, 'EVENT_TIMEZONE_MISSING'];
        }

        $localDate = $instant->setTimezone($timezone)->toDateString();
        $dayId = EventDay::query()->where('event_id', $integration->event_id)->whereDate('date', $localDate)->value('id');

        return $dayId === null ? [null, 'EVENT_DAY_NOT_FOUND'] : [(int) $dayId, null];
    }

    /**
     * Evento externo sem integração ativa: registra (sem integração) como IGNORED e não processa nada.
     *
     * @param  array<string, mixed>  $data
     */
    private function orphanReceipt(array $data, string $hash): IntegrationWebhookReceipt
    {
        return DB::transaction(function () use ($data, $hash) {
            TransactionLock::acquire(91005, (string) $data['delivery_id']);

            $receipt = IntegrationWebhookReceipt::query()->whereNull('event_integration_id')
                ->where('external_delivery_id', $data['delivery_id'])->first();

            if ($receipt !== null) {
                return $receipt;
            }

            $receipt = new IntegrationWebhookReceipt([
                'event_integration_id' => null,
                'external_delivery_id' => $data['delivery_id'],
                'event_type_id' => $data['type_id'],
                'event_type_name' => mb_substr($data['type_name'] ?: '(sem nome)', 0, 255),
                'payload_hash' => $hash,
                'received_at' => now(),
            ]);
            $receipt->forceFill([
                'status' => WebhookReceiptStatus::Ignored,
                'processed_at' => now(),
                'error_code' => 'EVENT_MISMATCH',
                'error_message' => 'Evento externo sem integração Even3 ativa no HackLab.',
            ])->save();

            return $receipt;
        });
    }
}
