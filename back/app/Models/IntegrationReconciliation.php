<?php

namespace App\Models;

use App\Domain\Integrations\Enums\ReconciliationKind;
use App\Domain\Integrations\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisão do Administrador sobre um candidato de reconciliação (RESOLVED ou IGNORED).
 */
class IntegrationReconciliation extends Model
{
    public const RESOLUTION_JUROR_CREATED = 'JUROR_CREATED';

    public const RESOLUTION_REPRESENTATIVE_LINKED = 'REPRESENTATIVE_LINKED';

    public const RESOLUTION_IGNORED = 'IGNORED';

    protected $fillable = [
        'event_id',
        'event_integration_id',
        'person_id',
        'kind',
        'status',
        'resolution',
        'target_type',
        'target_id',
        'resolved_by_user_id',
        'resolved_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ReconciliationKind::class,
            'status' => ReconciliationStatus::class,
            'resolved_at' => 'datetime',
            'event_id' => 'integer',
            'person_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(EventIntegration::class, 'event_integration_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function auditLabel(): string
    {
        return "{$this->kind->value} · pessoa #{$this->person_id}";
    }
}
