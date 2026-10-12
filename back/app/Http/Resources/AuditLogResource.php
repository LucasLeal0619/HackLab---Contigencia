<?php

namespace App\Http\Resources;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Audit\AuditDiff;
use App\Domain\Audit\AuditSanitizer;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 *
 * Listagem: sem before/after, IP e User-Agent (só no detalhe, via withDetails()).
 * O ator vem dos snapshots (perfil/setor daquele momento); o evento mostra o nome atual pela relação,
 * com o ID gravado como referência histórica.
 */
class AuditLogResource extends JsonResource
{
    private bool $details = false;

    public function withDetails(): static
    {
        $this->details = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'action' => ['code' => $this->action, 'label' => AuditActionCatalog::actionLabel($this->action)],
            'module' => ['code' => $this->module, 'label' => AuditActionCatalog::moduleLabel($this->module)],
            'description' => $this->description,
            'actor' => $this->actor_user_id === null && $this->actor_name === null ? null : [
                'user_id' => $this->actor_user_id,
                'person_id' => $this->actor_person_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'role' => $this->actor_role_code === null ? null : ['code' => $this->actor_role_code, 'name' => $this->actor_role_name],
                'sector_id' => $this->actor_sector_id,
                'sector_name' => $this->actor_sector_name,
            ],
            'event' => $this->event_id === null ? null : ['id' => $this->event_id, 'name' => $this->event?->name],
            'entity' => $this->entity_type === null ? null : [
                'type' => $this->entity_type,
                'id' => $this->entity_id,
                'label' => $this->entity_label,
            ],
        ];

        if (! $this->details) {
            return $summary;
        }

        // Defesa em profundidade: mesmo gravado sanitizado, o detalhe passa de novo pelo mesmo AuditSanitizer.
        $before = AuditSanitizer::sanitizeNullable($this->before_data);
        $after = AuditSanitizer::sanitizeNullable($this->after_data);

        return $summary + [
            'before_data' => $before,
            'after_data' => $after,
            'changes' => AuditDiff::changes($before, $after),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ];
    }
}
