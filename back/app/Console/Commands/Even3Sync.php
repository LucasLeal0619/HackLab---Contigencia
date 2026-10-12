<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncTrigger;
use App\Domain\Integrations\IntegrationAdminService;
use App\Domain\Integrations\IntegrationSyncService;
use App\Models\EventIntegration;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reconciliação pela API (pronta para o Scheduler; frequência de produção ainda não definida — sem keepalive).
 * Usa o mesmo serviço do sync manual (1 RUNNING por integração). Não gera AuditLog: o histórico é o sync run.
 */
class Even3Sync extends Command
{
    protected $signature = 'even3:sync {--event= : id do Event no HackLab (padrão: todas as integrações ativas)} {--scope=ALL : EVENT, TICKETS, ATTENDEES ou ALL}';

    protected $description = 'Sincroniza (Even3 → HackLab) as integrações ativas';

    public function handle(IntegrationSyncService $sync): int
    {
        try {
            IntegrationAdminService::ensureEnabled();
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $scope = SyncScope::tryFrom(strtoupper((string) $this->option('scope')));

        if ($scope === null) {
            $this->error('Escopo inválido. Use EVENT, TICKETS, ATTENDEES ou ALL.');

            return self::FAILURE;
        }

        $integrations = EventIntegration::query()->where('provider', EventIntegration::PROVIDER_EVEN3)
            ->where('status', IntegrationStatus::Active->value)
            ->when($this->option('event'), fn ($q, $eventId) => $q->where('event_id', (int) $eventId))
            ->get();

        $exit = self::SUCCESS;

        foreach ($integrations as $integration) {
            try {
                $run = $sync->execute($sync->start($integration, $scope, SyncTrigger::Scheduled, null));
            } catch (ConflictHttpException $e) {
                $this->warn("Evento {$integration->event_id}: {$e->getMessage()}");

                continue;
            }

            $this->line("Evento {$integration->event_id} (externo {$integration->external_event_id}): {$run->status->value} — ".json_encode($run->counters));
            $exit = $run->status->value === 'FAILED' ? self::FAILURE : $exit;
        }

        if ($integrations->isEmpty()) {
            $this->line('Nenhuma integração Even3 ativa.');
        }

        return $exit;
    }
}
