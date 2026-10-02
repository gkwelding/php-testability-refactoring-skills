<?php

namespace Tests\EvalHidden;

use App\Support\ExchangeRateRegistry;
use Tests\TestCase;

// Hidden characterisation tests: copied in by evals/run.sh after the refactor, never shown to the agent.
// They go through POST /reports/summary/{currency}.
class OrderSummaryExporterTest extends TestCase
{
    protected function tearDown(): void
    {
        ExchangeRateRegistry::reset();

        parent::tearDown();
    }

    private function export(string $currency, array $lines): string
    {
        return $this->postJson("/reports/summary/{$currency}", ['lines' => $lines])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();
    }

    public function test_lines_are_converted_to_the_report_currency_and_totalled(): void
    {
        $csv = $this->export('GBP', [
            ['sku' => 'A', 'amount' => 1000, 'currency' => 'GBP'],
            ['sku' => 'B', 'amount' => 1000, 'currency' => 'EUR'],
            ['sku' => 'C', 'amount' => 999, 'currency' => 'usd'],
        ]);

        $this->assertSame("sku,amount\nA,£10.00\nB,£8.50\nC,£7.89\nTOTAL,£26.39\n", $csv);
    }

    public function test_conversion_into_another_currency_rounds_half_up(): void
    {
        $csv = $this->export('eur', [
            ['sku' => 'A', 'amount' => 1000, 'currency' => 'GBP'],
            ['sku' => 'B', 'amount' => 17, 'currency' => 'GBP'],
        ]);

        $this->assertSame("sku,amount\nA,€11.76\nB,€0.20\nTOTAL,€11.96\n", $csv);
    }

    public function test_lines_without_an_exchange_rate_are_skipped_and_listed(): void
    {
        $csv = $this->export('GBP', [
            ['sku' => 'A', 'amount' => 500, 'currency' => 'JPY'],
            ['sku' => 'B', 'amount' => 250, 'currency' => 'GBP'],
            ['sku' => 'C', 'amount' => 100, 'currency' => 'CHF'],
        ]);

        $this->assertSame("sku,amount\nB,£2.50\nTOTAL,£2.50\n# skipped: A C\n", $csv);
    }

    public function test_an_unknown_report_currency_is_shown_by_code(): void
    {
        $csv = $this->export('JPY', [['sku' => 'A', 'amount' => 500, 'currency' => 'JPY']]);

        $this->assertSame("sku,amount\nA,JPY 5.00\nTOTAL,JPY 5.00\n", $csv);
    }

    public function test_refunds_are_negative(): void
    {
        $csv = $this->export('GBP', [
            ['sku' => 'A', 'amount' => 1000, 'currency' => 'GBP'],
            ['sku' => 'R', 'amount' => -1250, 'currency' => 'GBP'],
        ]);

        $this->assertSame("sku,amount\nA,£10.00\nR,-£12.50\nTOTAL,-£2.50\n", $csv);
    }

    public function test_an_empty_export_has_a_zero_total(): void
    {
        $this->assertSame("sku,amount\nTOTAL,£0.00\n", $this->export('GBP', []));
    }

    public function test_it_uses_the_shared_exchange_rate_registry(): void
    {
        ExchangeRateRegistry::instance()->set('EUR', 0.9);
        ExchangeRateRegistry::instance()->set('JPY', 0.005);

        $csv = $this->export('GBP', [
            ['sku' => 'B', 'amount' => 1000, 'currency' => 'EUR'],
            ['sku' => 'J', 'amount' => 10000, 'currency' => 'JPY'],
        ]);

        $this->assertSame("sku,amount\nB,£9.00\nJ,£0.50\nTOTAL,£9.50\n", $csv);
    }
}
