<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Voto público: definitivo e somente inserção (também garantido por trigger no banco).
 *
 * Sigilo na aplicação: nenhum Resource administrativo serializa eleitor → equipe.
 * Não há relações user()/team() de propósito, para não facilitar esse cruzamento.
 */
class PublicVote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'voting_session_id',
        'user_id',
        'team_id',
        'cast_at',
    ];

    protected function casts(): array
    {
        return [
            'cast_at' => 'datetime',
            'user_id' => 'integer',
            'team_id' => 'integer',
            'voting_session_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('public_votes é somente inserção: voto é definitivo.'));
        static::deleting(fn () => throw new LogicException('public_votes é somente inserção: voto é definitivo.'));
    }
}
