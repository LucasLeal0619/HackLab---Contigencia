<?php

namespace App\Http\Requests\Voting;

use App\Models\VotingSession;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação (POST) e edição (PATCH, só em DRAFT) da sessão. Só o nome: status muda por operação própria.
 */
class VotingSessionRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->isMethod('post')
            ? $this->user()->can('create', VotingSession::class)
            : $this->user()->can('manage', $this->route('session'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $session = $this->route('session');
        $eventId = $session?->event_id ?? $this->route('event')->id;

        return [
            'name' => [
                'required', 'string', 'max:120',
                function (string $attribute, mixed $value, Closure $fail) use ($eventId, $session) {
                    $exists = VotingSession::query()->where('event_id', $eventId)
                        ->whereRaw('lower(name) = lower(?)', [$value])
                        ->when($session, fn ($q) => $q->whereKeyNot($session->id))
                        ->exists();

                    if ($exists) {
                        $fail('Já existe uma sessão de votação com este nome neste evento.');
                    }
                },
            ],
            'status' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Use as operações open, close e cancel para mudar o status.',
        ];
    }
}
