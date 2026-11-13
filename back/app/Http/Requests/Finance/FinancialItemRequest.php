<?php

namespace App\Http\Requests\Finance;

use App\Models\FinancialItem;
use Illuminate\Foundation\Http\FormRequest;

class FinancialItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('post')
            ? $this->user()->can('create', [FinancialItem::class, $this->route('event')])
            : $this->user()->can('update', $this->route('financialItem'));
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['category', 'item', 'specification', 'unit', 'supplier', 'status', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        if ($this->input('unit_price') === '') {
            $data['unit_price'] = null;
        }

        if ($this->input('supplier') === '') {
            $data['supplier'] = null;
        }

        if ($this->input('notes') === '') {
            $data['notes'] = null;
        }

        $this->merge($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes|required';

        return [
            'event_id' => ['prohibited'],
            'total' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'category' => [$required, 'string', 'max:120'],
            'item' => [$required, 'string', 'max:180'],
            'specification' => [$required, 'string'],
            'quantity' => [$required, 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'unit' => [$required, 'string', 'max:24'],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:180'],
            'status' => [$required, 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
