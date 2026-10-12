<?php

namespace App\Http\Resources;

use App\Models\VotingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VotingSession
 *
 * Totais agregados apenas (candidatas, elegíveis, votos recebidos). Nunca contagem por equipe
 * nem eleitor → equipe: a contagem por equipe só existe em GET /voting-sessions/{id}/results (CLOSED).
 */
class VotingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_by_user_id' => $this->created_by_user_id,
            'candidates_count' => $this->whenCounted('teams'),
            'eligible_voters_count' => $this->whenCounted('eligibilities'),
            'votes_cast_count' => $this->whenCounted('votes'),
            'candidates' => $this->whenLoaded('teams', fn () => $this->teams->sortBy('name')->values()->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'status' => $team->status?->value,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
