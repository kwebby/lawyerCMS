<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Unit\Business;

use App\Domain\Finance\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_fractional_quantity_and_half_up_tax_use_exact_integer_arithmetic(): void
    {
        $result = Money::calculate([['description' => 'Consultation', 'quantity' => '1.125', 'unit_minor' => '101', 'tax_bps' => 750]]);
        $this->assertSame('114', $result['subtotal_minor']);
        $this->assertSame('9', $result['tax_minor']);
        $this->assertSame('123', $result['total_minor']);
    }

    public function test_discount_allocation_preserves_every_minor_unit(): void
    {
        $result = Money::calculate([
            ['description' => 'A', 'quantity' => '1', 'unit_minor' => '101', 'tax_bps' => 1000],
            ['description' => 'B', 'quantity' => '1', 'unit_minor' => '202', 'tax_bps' => 1000],
            ['description' => 'C', 'quantity' => '1', 'unit_minor' => '303', 'tax_bps' => 1000],
        ], '101');
        $this->assertSame('101', $result['discount_minor']);
        $this->assertSame(101, array_sum(array_column($result['items'], 'discount_minor')));
        $this->assertSame((int) $result['total_minor'], array_sum(array_column($result['items'], 'total_minor')));
        $this->assertSame((int) $result['subtotal_minor'] - 101 + (int) $result['tax_minor'], (int) $result['total_minor']);
    }

    public function test_large_discount_allocation_does_not_overflow_or_use_floats(): void
    {
        $result = Money::calculate([
            ['description' => 'A', 'quantity' => '1', 'unit_minor' => '499999999999', 'tax_bps' => 0],
            ['description' => 'B', 'quantity' => '1', 'unit_minor' => '499999999999', 'tax_bps' => 0],
        ], '888888888887');
        $this->assertSame('111111111111', $result['total_minor']);
        $this->assertSame(888888888887, array_sum(array_column($result['items'], 'discount_minor')));
    }
}
