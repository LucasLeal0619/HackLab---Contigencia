<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Reconciliation\EventMetadataReconciler;
use App\Models\EventIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { "fields": ["name", "start_date", "end_date"] } — só QUAIS campos; os valores vêm do retrato salvo da Even3.
 * Qualquer valor enviado pelo front é ignorado (não há campo para isso).
 */
class ApplyEventMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('applyEventMetadata', EventIntegration::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'distinct', Rule::in(EventMetadataReconciler::FIELDS)],
        ];
    }
}
