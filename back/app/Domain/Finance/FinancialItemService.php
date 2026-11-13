<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogger;
use App\Models\Event;
use App\Models\FinancialItem;
use App\Support\Math\FinancialAmount;
use Illuminate\Support\Facades\DB;

class FinancialItemService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Event $event, array $data): FinancialItem
    {
        return DB::transaction(function () use ($event, $data) {
            $item = $event->financialItems()->create($data);

            $this->audit->record(
                AuditAction::FINANCIAL_ITEM_CREATED,
                'finance',
                "Item financeiro \"{$item->item}\" cadastrado.",
                entity: $item,
                after: $this->snapshot($item),
            );

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialItem $item, array $data): FinancialItem
    {
        return DB::transaction(function () use ($item, $data) {
            $before = $this->snapshot($item);
            $item->fill($data);

            if (! $item->isDirty()) {
                return $item;
            }

            $item->save();
            $this->audit->record(
                AuditAction::FINANCIAL_ITEM_UPDATED,
                'finance',
                "Item financeiro \"{$item->item}\" alterado.",
                entity: $item,
                before: $before,
                after: $this->snapshot($item),
            );

            return $item;
        });
    }

    public function delete(FinancialItem $item): void
    {
        DB::transaction(function () use ($item) {
            $before = $this->snapshot($item);
            $this->audit->record(
                AuditAction::FINANCIAL_ITEM_DELETED,
                'finance',
                "Item financeiro \"{$item->item}\" excluído.",
                entity: $item,
                before: $before,
            );
            $item->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FinancialItem $item): array
    {
        return [
            'event_id' => $item->event_id,
            'category' => $item->category,
            'item' => $item->item,
            'specification' => $item->specification,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price,
            'total_amount' => FinancialAmount::total($item),
            'supplier' => $item->supplier,
            'status' => $item->status,
            'notes' => $item->notes,
        ];
    }
}
