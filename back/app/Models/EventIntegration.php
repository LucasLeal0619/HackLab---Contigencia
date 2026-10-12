<?php

namespace App\Models;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vínculo explícito Event ↔ evento da plataforma externa (Even3). Não guarda token.
 */
class EventIntegration extends Model
{
    public const PROVIDER_EVEN3 = 'EVEN3';

    protected $fillable = [
        'event_id',
        'provider',
        'external_event_id',
        'external_event_title',
        'api_version',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'last_sync_status' => SyncStatus::class,
            'last_connection_checked_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_error_at' => 'datetime',
            'event_id' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === IntegrationStatus::Active;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(ExternalTicketType::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(IntegrationSyncRun::class);
    }

    public function auditLabel(): string
    {
        return "{$this->provider} · evento externo {$this->external_event_id}";
    }

    public static function even3For(int $eventId): ?self
    {
        return self::query()->where('event_id', $eventId)->where('provider', self::PROVIDER_EVEN3)->first();
    }
}
