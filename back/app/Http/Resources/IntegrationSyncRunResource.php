<?php

namespace App\Http\Resources;

use App\Models\IntegrationSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IntegrationSyncRun
 *
 * Resumo para quem consulta (contadores e códigos de aviso). Avisos detalhados (ids externos) e mensagem de
 * erro só com withDetails() (quem administra a integração). Nunca há token, segredo ou payload externo.
 */
class IntegrationSyncRunResource extends JsonResource
{
    /** Atributo da requisição que libera os detalhes numa listagem. */
    public const DETAILS_ATTRIBUTE = 'integration_sync_details';

    private bool $details = false;

    public function withDetails(bool $details = true): static
    {
        $this->details = $details;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $warnings = $this->warnings ?? [];
        $details = $this->details || $request->attributes->get(self::DETAILS_ATTRIBUTE) === true;

        return [
            'id' => $this->id,
            'event_integration_id' => $this->event_integration_id,
            'scope' => $this->scope?->value,
            'trigger' => $this->trigger?->value,
            'status' => $this->status?->value,
            'started_by_user_id' => $this->started_by_user_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'counters' => (object) ($this->counters ?? []),
            'warning_codes' => (object) collect($warnings)->countBy('code')->all(),
            'error_code' => $this->error_code,
            'warnings' => $this->when($details, $warnings),
            'error_message' => $this->when($details, $this->error_message),
        ];
    }
}
