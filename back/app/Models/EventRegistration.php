<?php

namespace App\Models;

use App\Domain\Integrations\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscrição externa de uma Person num evento. Classifica; não concede conta, perfil nem vínculo de domínio.
 * source_* guarda o que veio da origem sem sobrescrever o cadastro interno.
 */
class EventRegistration extends Model
{
    protected $fillable = [
        'event_id',
        'event_integration_id',
        'person_id',
        'external_attendee_id',
        'external_person_id',
        'external_registration_id',
        'external_ticket_type_id',
        'external_category_label',
        'check_in_code',
        'registered_at',
        'source_name',
        'source_badge_name',
        'source_email',
        'source_phone',
        'source_document',
        'identity_mismatch',
        'last_seen_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'status_changed_at' => 'datetime',
            'registered_at' => 'datetime',
            'identity_mismatch' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'person_id' => 'integer',
            'event_id' => 'integer',
            'external_ticket_type_id' => 'integer',
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

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(ExternalTicketType::class, 'external_ticket_type_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
