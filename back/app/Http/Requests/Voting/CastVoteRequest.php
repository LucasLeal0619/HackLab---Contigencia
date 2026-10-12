<?php

namespace App\Http\Requests\Voting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * { "team_id": 3 } — exatamente uma equipe. Candidatura e status da sessão: PublicVoteService (sob lock).
 */
class CastVoteRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('vote', $this->route('session'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_id' => ['required', 'integer'],
        ];
    }
}
