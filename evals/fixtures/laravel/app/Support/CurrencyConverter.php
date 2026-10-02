<?php

namespace App\Support;

class CurrencyConverter
{
    public function __construct(private ExchangeRateRegistry $rates)
    {
    }

    /** Converts minor units between currencies, rounding half up. */
    public function convert(int $amount, string $from, string $to): int
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $amount;
        }

        return (int) round($amount * $this->rates->toGbp($from) / $this->rates->toGbp($to));
    }
}
