<?php

namespace App\Http\Resources;

use App\Models\ResultPublication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ResultPublication
 *
 * O snapshot já contém só agregados. Dados de revogação aparecem só para quem administra (withAdminData).
 */
class ResultPublicationResource extends JsonResource
{
    private bool $adminData = false;

    public function withAdminData(): static
    {
        $this->adminData = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'version' => $this->version,
            'status' => $this->status?->value,
            'voting_session_id' => $this->voting_session_id,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by_user_id' => $this->when($this->adminData, $this->published_by_user_id),
            'revoked_at' => $this->when($this->adminData, fn () => $this->revoked_at?->toIso8601String()),
            'revoked_by_user_id' => $this->when($this->adminData, $this->revoked_by_user_id),
            'revoke_reason' => $this->when($this->adminData, $this->revoke_reason),
            'snapshot' => $this->snapshot,
        ];
    }
}
