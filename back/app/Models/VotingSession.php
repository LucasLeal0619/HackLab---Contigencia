<?php

namespace App\Models;

use App\Domain\Voting\Enums\VotingSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sessão de votação pública de um evento. Status alterado só pelo VotingSessionService.
 */
class VotingSession extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VotingSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'event_id' => 'integer',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === VotingSessionStatus::Draft;
    }

    public function isOpen(): bool
    {
        return $this->status === VotingSessionStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === VotingSessionStatus::Closed;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Equipes candidatas (explícitas).
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'voting_session_teams');
    }

    public function eligibilities(): HasMany
    {
        return $this->hasMany(VotingEligibility::class);
    }

    /**
     * Uso interno (contagens agregadas). Nunca serializar eleitor → equipe.
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PublicVote::class);
    }
}
