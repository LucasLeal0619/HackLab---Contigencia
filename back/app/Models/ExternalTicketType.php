<?php

namespace App\Models;

use App\Domain\Integrations\Enums\TicketCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entrada da plataforma externa. Identidade = external_ticket_id; o nome pode mudar sem perder o mapeamento.
 */
class ExternalTicketType extends Model
{
    protected $fillable = [
        'event_id',
        'event_integration_id',
        'external_ticket_id',
        'external_name',
        'active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'internal_category' => TicketCategory::class,
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
            'event_integration_id' => 'integer',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(EventIntegration::class, 'event_integration_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'external_ticket_type_id');
    }

    public function auditLabel(): string
    {
        return "{$this->external_name} (entrada {$this->external_ticket_id})";
    }
}
