<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Enums\IntegrationStatus;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "external_event_id": "793572", "status": "ACTIVE" } — vínculo explícito (nunca inferido).
 */
class ConfigureEven3Request extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', EventIntegration::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_scalar($this->input('external_event_id'))) {
            $this->merge(['external_event_id' => trim((string) $this->input('external_event_id'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_event_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'status' => ['required', Rule::enum(IntegrationStatus::class)],
        ];
    }
}
