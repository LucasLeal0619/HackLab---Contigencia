<?php

namespace App\Support\Math;

use App\Models\FinancialItem;

final class FinancialAmount
{
    public static function total(FinancialItem $item): ?string
    {
        if ($item->unit_price === null) {
            return null;
        }

        $product = bcmul((string) $item->quantity, (string) $item->unit_price, 8);

        return bcadd($product, '0.005', 2);
    }
}
