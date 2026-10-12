<?php

namespace App\Models;

use App\Domain\Integrations\Enums\AttendanceKind;
use App\Domain\Integrations\Enums\AttendanceSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um check-in observado. Vários por dia são permitidos; presença diária é derivada (sem booleano global).
 * checked_in_at só com horário explícito da origem; observed_at é o instante observado.
 */
class AttendanceRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'event_integration_id',
        'event_registration_id',
        'event_day_id',
        'kind',
        'external_activity_id',
        'checked_in_at',
        'observed_at',
        'source',
        'webhook_receipt_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AttendanceKind::class,
            'source' => AttendanceSource::class,
            'checked_in_at' => 'datetime',
            'observed_at' => 'datetime',
            'event_day_id' => 'integer',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(EventDay::class, 'event_day_id');
    }
}
