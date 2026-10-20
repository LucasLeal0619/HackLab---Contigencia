<?php

namespace App\Models;

use App\Domain\Auth\Enums\AccessGrantSource;
use App\Domain\Auth\Enums\AccessGrantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liberação de acesso: o Administrador autoriza uma Person a ativar a própria conta com este perfil/setor.
 * Não guarda senha nem token. Consumida pelo primeiro acesso.
 */
class AccountAccessGrant extends Model
{
    protected $fillable = [
        'event_id',
        'person_id',
        'role_id',
        'sector_id',
        'source',
        'granted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccessGrantStatus::class,
            'source' => AccessGrantSource::class,
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'event_id' => 'integer',
            'person_id' => 'integer',
            'role_id' => 'integer',
            'sector_id' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === AccessGrantStatus::Pending;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function auditLabel(): string
    {
        return "Liberação de acesso #{$this->id}";
    }
}
