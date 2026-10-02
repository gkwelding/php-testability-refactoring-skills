<?php

namespace App\Support;

class MoneyFormatter
{
    private const SYMBOLS = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'];

    public function format(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);
        $sign = $minor < 0 ? '-' : '';

        return $sign.(self::SYMBOLS[$currency] ?? $currency.' ').number_format(abs($minor) / 100, 2);
    }
}
