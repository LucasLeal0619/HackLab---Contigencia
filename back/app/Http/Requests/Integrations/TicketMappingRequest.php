<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Enums\TicketCategory;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "internal_category": "PARTICIPANT" } ou null para remover um mapeamento incorreto.
 */
class TicketMappingRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'internal_category' => ['present', 'nullable', Rule::enum(TicketCategory::class)],
        ];
    }
}
