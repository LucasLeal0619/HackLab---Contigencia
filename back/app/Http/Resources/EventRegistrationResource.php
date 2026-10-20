<?php

namespace App\Http\Resources;

use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventRegistration
 *
 * "links" é só um resumo derivado (participante/jurado/conta da mesma Person); a consulta não concede nada.
 */
class EventRegistrationResource extends JsonResource
{
    /** @var array<string, mixed> */
    private array $links = [];

    /**
     * @param  array<string, mixed>  $links
     */
    public function withLinks(array $links): static
    {
        $this->links = $links;

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
            'status' => $this->status?->value,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'person' => $this->whenLoaded('person', fn () => [
                'id' => $this->person->id,
                'full_name' => $this->person->full_name,
                'email' => $this->person->email,
            ]),
            'external' => [
                'attendee_id' => $this->external_attendee_id,
                'person_id' => $this->external_person_id,
                'registration_id' => $this->external_registration_id,
                'category_label' => $this->external_category_label,
                'check_in_code' => $this->check_in_code,
                'registered_at' => $this->registered_at?->toIso8601String(),
            ],
            'ticket' => $this->whenLoaded('ticketType', fn () => $this->ticketType === null ? null : [
                'id' => $this->ticketType->id,
                'external_ticket_id' => $this->ticketType->external_ticket_id,
                'external_name' => $this->ticketType->external_name,
                'internal_category' => $this->ticketType->internal_category?->value,
            ]),
            'source' => [
                'name' => $this->source_name,
                'badge_name' => $this->source_badge_name,
                'email' => $this->source_email,
                'phone' => $this->source_phone,
                // Privacidade: nunca o documento completo; só o final, para conferência.
                'document' => $this->source_document === null ? null : '•••'.mb_substr($this->source_document, -3),
            ],
            'identity_mismatch' => $this->identity_mismatch,
            // Quantidade de check-ins (Presente se > 0; senão "Sem registro").
            'check_ins' => $this->whenCounted('attendance'),
            'links' => (object) $this->links,
            'attendance' => $this->whenLoaded('attendance', fn () => $this->attendance->sortBy('observed_at')->values()->map(fn ($record) => [
                'id' => $record->id,
                'kind' => $record->kind?->value,
                'event_day_id' => $record->event_day_id,
                'external_activity_id' => $record->external_activity_id,
                'checked_in_at' => $record->checked_in_at?->toIso8601String(),
                'observed_at' => $record->observed_at?->toIso8601String(),
                'source' => $record->source?->value,
            ])),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
