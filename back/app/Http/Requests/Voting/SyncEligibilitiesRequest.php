<?php

namespace App\Http\Requests\Voting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "user_ids": [10, 11] } — lista final de eleitores elegíveis (só em DRAFT). Não cria conta.
 */
class SyncEligibilitiesRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manageEligibility', $this->route('session'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:5000'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.*.exists' => 'Usuário inexistente.',
            'user_ids.*.distinct' => 'Usuário repetido na lista.',
        ];
    }
}
