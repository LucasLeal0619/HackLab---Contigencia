<?php

namespace App\Http\Resources;

use App\Models\VotingEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VotingEligibility
 *
 * Mostra só SE a pessoa já votou (has_voted, derivado na query), nunca em quem.
 */
class VotingEligibilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voting_session_id' => $this->voting_session_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'status' => $this->user->status?->value,
                'role' => $this->user->role?->code?->value,
                'person' => ['id' => $this->user->person?->id, 'full_name' => $this->user->person?->full_name],
            ]),
            'has_voted' => (bool) $this->has_voted,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
