<?php

namespace App\Http\Requests\Voting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "team_ids": [1, 3, 5] } — lista final de equipes candidatas (só em DRAFT).
 */
class SyncCandidatesRequest extends FormRequest
{
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
            'team_ids' => ['present', 'array', 'max:500'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')->where('event_id', $this->route('session')->event_id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'team_ids.*.exists' => 'Equipe inexistente ou de outro evento.',
            'team_ids.*.distinct' => 'Equipe repetida na lista.',
        ];
    }
}
