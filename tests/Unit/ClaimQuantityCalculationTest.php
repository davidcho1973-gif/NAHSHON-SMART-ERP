<?php

namespace Tests\Unit;

use App\Services\Finance\ClaimQuantityCalculation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClaimQuantityCalculationTest extends TestCase
{
    public function test_wall_calculation_subtracts_openings_and_ignores_supplied_total(): void
    {
        $result = (new ClaimQuantityCalculation)->calculate([
            ['length' => 20, 'height' => 12, 'count' => 2, 'deduction' => 42, 'quantity' => 9999],
            ['length' => 10, 'height' => 12, 'deduction' => 20],
        ]);
        $this->assertSame(538.0, $result['total']);
        $this->assertSame(438.0, $result['rows'][0]['quantity']);
    }

    public function test_over_deduction_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ClaimQuantityCalculation)->calculate([['length' => 1, 'deduction' => 2]]);
    }

    public function test_formula_text_is_not_evaluated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ClaimQuantityCalculation)->calculate([['length' => '=1+2']]);
    }
}
