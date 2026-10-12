<?php

namespace App\Http\Requests\Audit;

use App\Domain\Users\Enums\RoleCode;
use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da auditoria. Datas em ISO-8601 (o backend trabalha em UTC). per_page 1–100 (padrão 50).
 */
class ListAuditLogsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    /**
     * Autorização antes da validação: sem permissão, 403 sem detalhes de validação.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'event_id' => ['sometimes', 'integer', 'min:1'],
            // Query string: aceita true/false além de 1/0.
            'has_event' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            'module' => ['sometimes', 'string', 'max:64'],
            'action' => ['sometimes', 'string', 'max:64'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'actor_role' => ['sometimes', 'string', Rule::in(array_map(fn (RoleCode $r) => $r->value, RoleCode::cases()))],
            'actor_sector_id' => ['sometimes', 'integer', 'min:1'],
            'entity_type' => ['sometimes', 'string', 'max:64'],
            'entity_id' => ['sometimes', 'string', 'max:64'],
            'ip_address' => ['sometimes', 'ip'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => array_filter(['sometimes', 'date', $this->filled('date_from') ? 'after_or_equal:date_from' : null]),
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'date_to precisa ser igual ou posterior a date_from.',
            'per_page.max' => 'per_page vai até '.self::MAX_PER_PAGE.'.',
        ];
    }
}
