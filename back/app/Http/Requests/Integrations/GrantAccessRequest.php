<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Auth\AccountAccessGrantService;
use App\Domain\Users\Enums\RoleCode;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Liberar acesso" para um inscrito como Organização: perfil (Gestor, Editor ou Consultor) e setor.
 * Nunca senha. As regras de setor são revalidadas no AccountAccessGrantService e no banco.
 */
class GrantAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('grantAccess', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(array_map(fn (RoleCode $r) => $r->value, AccountAccessGrantService::ALLOWED_ROLES))],
            'sector_id' => ['nullable', 'integer'],
            'password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'A liberação de acesso só aceita Gestor, Editor ou Consultor.',
            'password.prohibited' => 'A senha é criada pela própria pessoa no primeiro acesso.',
        ];
    }
}
