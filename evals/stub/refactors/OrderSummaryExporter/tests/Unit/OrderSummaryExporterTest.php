<?php

namespace Tests\Unit;

use App\Services\OrderSummaryExporter;
use App\Support\CurrencyConverter;
use PHPUnit\Framework\TestCase;

class OrderSummaryExporterTest extends TestCase
{
    public function test_converted_amounts_are_totalled(): void
    {
        $converter = $this->createStub(CurrencyConverter::class);
        $converter->method('convert')->willReturn(150);

        $csv = (new OrderSummaryExporter($converter))->export([
            ['sku' => 'A', 'amount' => 1, 'currency' => 'EUR'],
            ['sku' => 'B', 'amount' => 1, 'currency' => 'EUR'],
        ], 'GBP');

        $this->assertSame("sku,amount\nA,£1.50\nB,£1.50\nTOTAL,£3.00\n", $csv);
    }
}
