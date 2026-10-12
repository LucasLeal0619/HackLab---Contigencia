<?php

namespace App\Http\Requests\Voting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Motivo opcional em DRAFT; obrigatório para cancelar sessão OPEN (regra no VotingSessionService).
 */
class CancelVotingSessionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('session'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
