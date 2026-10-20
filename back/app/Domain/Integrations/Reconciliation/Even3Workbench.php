<?php

namespace App\Domain\Integrations\Reconciliation;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\IntegrationSyncService;
use App\Models\Event;
use App\Models\EventIntegration;
use App\Models\ExternalTicketType;
use App\Models\IntegrationSyncRun;

/**
 * Resumo do Centro Even3: estado da integração e contagens de pendências, só do PostgreSQL.
 * Não chama a Even3, não grava nada e não devolve dados pessoais nem segredo.
 */
class Even3Workbench
{
    public function __construct(
        private readonly ReconciliationCandidates $candidates,
        private readonly EventMetadataReconciler $metadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Event $event): array
    {
        $integration = EventIntegration::even3For($event->id);

        if ($integration === null) {
            return [
                'event_id' => $event->id,
                'configured' => false,
                'integration_status' => null,
                'external_event_id' => null,
                'external_event_title' => null,
                'sync' => ['running' => false, 'running_since' => null, 'last_sync_at' => null, 'last_sync_status' => null, 'last_error_code' => null],
                'pending' => null,
                'ignored' => null,
                'unmapped_tickets' => 0,
            ];
        }

        $running = IntegrationSyncRun::query()->where('event_integration_id', $integration->id)
            ->where('status', SyncStatus::Running->value)
            ->where('started_at', '>=', now()->subMinutes(IntegrationSyncService::STALE_MINUTES))
            ->orderByDesc('started_at')->first();

        return [
            'event_id' => $event->id,
            'configured' => true,
            'integration_status' => $integration->status->value,
            'external_event_id' => $integration->external_event_id,
            'external_event_title' => $integration->external_event_title,
            'sync' => [
                'running' => $running !== null,
                'running_since' => $running?->started_at?->toIso8601String(),
                'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
                'last_sync_status' => $integration->last_sync_status?->value,
                'last_error_code' => $integration->last_error_code,
            ],
            'pending' => [
                'participants' => $this->candidates->participantsToMaterialize($integration),
                'jurors' => $this->candidates->pendingCount($integration, ReconciliationKind::Juror),
                'organization' => $this->candidates->pendingCount($integration, ReconciliationKind::Organization),
                'company_guests' => $this->candidates->pendingCount($integration, ReconciliationKind::CompanyGuest),
                'public_without_access' => $this->candidates->publicWithoutAccess($integration),
                'event_differences' => $this->metadata->differenceCount($event, $integration),
            ],
            'ignored' => [
                'jurors' => $this->candidates->ignoredCount($integration, ReconciliationKind::Juror),
                'organization' => $this->candidates->ignoredCount($integration, ReconciliationKind::Organization),
                'company_guests' => $this->candidates->ignoredCount($integration, ReconciliationKind::CompanyGuest),
            ],
            'unmapped_tickets' => ExternalTicketType::query()->where('event_integration_id', $integration->id)
                ->where('active', true)->whereNull('internal_category')->count(),
        ];
    }
}
