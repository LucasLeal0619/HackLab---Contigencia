<?php

namespace App\Http\Resources;

use App\Models\FinancialItem;
use App\Support\Math\FinancialAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialItem
 */
class FinancialItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'category' => $this->category,
            'item' => $this->item,
            'specification' => $this->specification,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'total_amount' => FinancialAmount::total($this->resource),
            'supplier' => $this->supplier,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
