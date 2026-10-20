<?php

namespace App\Http\Requests\Integrations;

use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vincula um candidato Empresa/Convidado como representante de uma empresa existente do mesmo evento.
 */
class LinkCompanyGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reconcileCompanyGuests', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('event_id', $this->route('event')->id)],
            'title' => ['nullable', 'string', 'max:120'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['company_id.exists' => 'Empresa inexistente ou de outro evento.'];
    }
}
