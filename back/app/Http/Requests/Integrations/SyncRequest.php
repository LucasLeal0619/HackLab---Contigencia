<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Enums\SyncScope;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "scope": "ALL" } — EVENT, TICKETS, ATTENDEES ou ALL (evento → entradas → participantes).
 */
class SyncRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('sync', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::enum(SyncScope::class)],
            // Disparo automático do front (a cada 10 min com Administrador ativo): mesmo sync, origem SCHEDULED.
            'automatic' => ['sometimes', 'boolean'],
        ];
    }
}
