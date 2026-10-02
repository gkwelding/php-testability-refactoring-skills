<?php

namespace App\Tests\Unit;

use App\Inventory\StockLevels;
use App\Service\ReorderPlanner;
use PHPUnit\Framework\TestCase;

class ReorderPlannerTest extends TestCase
{
    public function test_orders_round_up_to_whole_packs(): void
    {
        $stock = $this->createStub(StockLevels::class);
        $stock->method('levels')->willReturn(['A' => 3]);

        $plan = (new ReorderPlanner($stock))->plan(['A' => 10], 6);

        $this->assertSame([['sku' => 'A', 'on_hand' => 3, 'order' => 12]], $plan);
    }
}
