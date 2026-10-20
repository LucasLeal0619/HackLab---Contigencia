<?php

namespace App\Models;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Domain\Integrations\Enums\SyncStatus;
use Carbon\CarbonImmutable;
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
            'external_start_date' => 'date',
            'external_end_date' => 'date',
            'external_metadata_synced_at' => 'datetime',
            'event_id' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === IntegrationStatus::Active;
    }

    /**
     * Retrato mínimo do evento externo (título e datas), lido na configuração e no sync de escopo EVENT/ALL.
     * Data em formato inesperado fica nula (nunca inventada). Não salva: quem chama decide.
     *
     * @param  array<string, mixed>  $external  formato normalizado do EventPlatformProvider
     */
    public function rememberExternalEvent(array $external): void
    {
        $this->forceFill([
            'external_event_title' => $external['title'] ?? $this->external_event_title,
            'external_start_date' => self::externalDate($external['start_date'] ?? null),
            'external_end_date' => self::externalDate($external['end_date'] ?? null),
            'external_metadata_synced_at' => now(),
        ]);
    }

    public static function externalDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
