<?php

namespace App\Http\Resources;

use App\Models\AccountAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountAccessGrant
 *
 * Liberação de acesso. Não tem senha nem token (não existem nesta tabela).
 */
class AccountAccessGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'role' => $this->whenLoaded('role', fn () => ['code' => $this->role->code->value, 'name' => $this->role->name]),
            'sector' => $this->whenLoaded('sector', fn () => $this->sector === null ? null : ['id' => $this->sector->id, 'name' => $this->sector->name]),
            'person' => $this->whenLoaded('person', fn () => ['id' => $this->person->id, 'full_name' => $this->person->full_name, 'email' => $this->person->email]),
            'consumed_at' => $this->consumed_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
