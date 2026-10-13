<?php

namespace App\Models;

use App\Domain\Users\Enums\RoleCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de primeiro acesso de uma Person sem User. Guarda só o hash do token.
 */
class AccountActivationToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'person_id',
        'requested_email',
        'intended_role',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'intended_role' => RoleCode::class,
            'expires_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
