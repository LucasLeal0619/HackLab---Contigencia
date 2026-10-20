<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Companies\Enums\CompanyStatus;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Cadastrar como Jurado" a partir de um candidato da Even3: mesmos campos opcionais do cadastro manual.
 */
class ConfirmJurorCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reconcileJurors', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where('event_id', $this->route('event')->id)->whereNot('status', CompanyStatus::Inactive->value),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['company_id.exists' => 'Empresa inexistente, inativa ou de outro evento.'];
    }
}
