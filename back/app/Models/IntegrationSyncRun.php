<?php

namespace App\Models;

use App\Domain\Integrations\Enums\SyncScope;
use App\Domain\Integrations\Enums\SyncStatus;
use App\Domain\Integrations\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma execução de sincronização: contadores e avisos seguros (sem payload externo nem segredo).
 */
class IntegrationSyncRun extends Model
{
    protected $fillable = [
        'event_integration_id',
        'scope',
        'trigger',
        'started_by_user_id',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => SyncScope::class,
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counters' => 'array',
            'warnings' => 'array',
            'event_integration_id' => 'integer',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(EventIntegration::class, 'event_integration_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function auditEventId(): ?int
    {
        return $this->integration?->event_id;
    }

    public function auditLabel(): string
    {
        return "Sincronização #{$this->id} ({$this->scope?->value})";
    }
}
