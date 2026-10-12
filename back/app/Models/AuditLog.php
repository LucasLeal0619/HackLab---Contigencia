<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Registro de auditoria. Somente inserção (também garantido por trigger no banco).
 *
 * actor_* em texto e entity_label são fotografias do momento da ação; os IDs são a identidade relacional.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_person_id',
        'actor_name',
        'actor_email',
        'actor_role_code',
        'actor_role_name',
        'actor_sector_id',
        'actor_sector_name',
        'event_id',
        'action',
        'module',
        'entity_type',
        'entity_id',
        'entity_label',
        'description',
        'before_data',
        'after_data',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'actor_user_id' => 'integer',
            'actor_person_id' => 'integer',
            'actor_sector_id' => 'integer',
            'event_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs é somente inserção.'));
        static::deleting(fn () => throw new LogicException('audit_logs é somente inserção.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Só para o nome atual do evento; o ID gravado é a referência histórica.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
