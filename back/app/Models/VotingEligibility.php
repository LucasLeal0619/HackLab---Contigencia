<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Elegibilidade explícita de um User VOTER numa sessão. Entra/sai só em DRAFT; nunca é alterada.
 */
class VotingEligibility extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'voting_session_id',
        'user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'voting_session_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('voting_eligibilities não é alterada.'));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VotingSession::class, 'voting_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
