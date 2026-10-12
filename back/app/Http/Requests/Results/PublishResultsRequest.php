<?php

namespace App\Http\Requests\Results;

use App\Models\ResultPublication;
use Illuminate\Foundation\Http\FormRequest;

/**
 * { "voting_session_id": 3 } — sessão pública escolhida explicitamente (CLOSED, do mesmo evento).
 */
class PublishResultsRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('publish', ResultPublication::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'voting_session_id' => ['required', 'integer'],
        ];
    }
}
