<?php

namespace App\Services;

use App\Support\CurrencyConverter;
use App\Support\ExchangeRateRegistry;
use App\Support\MoneyFormatter;
use InvalidArgumentException;

class OrderSummaryExporter
{
    /**
     * One CSV row per line, converted to the report currency, then a total row.
     * Lines in a currency with no exchange rate are left out and listed in a trailing comment row.
     *
     * @param  list<array{sku: string, amount: int, currency: string}>  $lines
     */
    public function export(array $lines, string $currency): string
    {
        $converter = new CurrencyConverter(ExchangeRateRegistry::instance());
        $money = new MoneyFormatter();

        $rows = ['sku,amount'];
        $total = 0;
        $skipped = [];

        foreach ($lines as $line) {
            try {
                $amount = $converter->convert($line['amount'], $line['currency'], $currency);
            } catch (InvalidArgumentException) {
                $skipped[] = $line['sku'];

                continue;
            }

            $total += $amount;
            $rows[] = $line['sku'].','.$money->format($amount, $currency);
        }

        $rows[] = 'TOTAL,'.$money->format($total, $currency);

        if ($skipped !== []) {
            $rows[] = '# skipped: '.implode(' ', $skipped);
        }

        return implode("\n", $rows)."\n";
    }
}
