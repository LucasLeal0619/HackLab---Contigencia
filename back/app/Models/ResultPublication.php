<?php

namespace App\Models;

use App\Domain\Results\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Publicação oficial (versão N) com o snapshot congelado. Só muda de PUBLISHED para REVOKED.
 */
class ResultPublication extends Model
{
    protected $fillable = [
        'event_id',
        'voting_session_id',
        'version',
        'snapshot',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'snapshot' => 'array',
            'published_at' => 'datetime',
            'revoked_at' => 'datetime',
            'version' => 'integer',
            'voting_session_id' => 'integer',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === PublicationStatus::Published;
    }

    public function votingSession(): BelongsTo
    {
        return $this->belongsTo(VotingSession::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public static function hasPublishedFor(int $eventId): bool
    {
        return self::query()->where('event_id', $eventId)->where('status', PublicationStatus::Published->value)->exists();
    }
}
