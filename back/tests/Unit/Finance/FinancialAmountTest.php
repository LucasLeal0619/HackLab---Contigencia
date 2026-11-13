<?php

namespace Tests\Unit\Finance;

use App\Models\FinancialItem;
use App\Support\Math\FinancialAmount;
use Tests\TestCase;

class FinancialAmountTest extends TestCase
{
    public function test_total_is_calculated_and_rounded_to_currency_precision(): void
    {
        $item = new FinancialItem(['quantity' => '10', 'unit_price' => '5']);
        $fractional = new FinancialItem(['quantity' => '1', 'unit_price' => '1.235']);

        $this->assertSame('50.00', FinancialAmount::total($item));
        $this->assertSame('1.24', FinancialAmount::total($fractional));
    }

    public function test_undefined_price_is_distinct_from_zero_price(): void
    {
        $pending = new FinancialItem(['quantity' => '3', 'unit_price' => null]);
        $free = new FinancialItem(['quantity' => '3', 'unit_price' => '0']);

        $this->assertNull(FinancialAmount::total($pending));
        $this->assertSame('0.00', FinancialAmount::total($free));
    }
}
