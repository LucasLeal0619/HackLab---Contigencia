<?php

namespace App\Http\Resources;

use App\Models\ExternalTicketType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExternalTicketType
 */
class ExternalTicketTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'external_ticket_id' => $this->external_ticket_id,
            'external_name' => $this->external_name,
            'internal_category' => $this->internal_category?->value,
            'mapped' => $this->internal_category !== null,
            'active' => $this->active,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'registrations_count' => $this->whenCounted('registrations'),
        ];
    }
}
