<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Process-wide table of exchange rates to GBP, loaded once and shared.
 */
class ExchangeRateRegistry
{
    private static ?self $instance = null;

    /** @var array<string, float> */
    private array $toGbp = ['GBP' => 1.0, 'EUR' => 0.85, 'USD' => 0.79];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function set(string $currency, float $toGbp): void
    {
        $this->toGbp[strtoupper($currency)] = $toGbp;
    }

    public function toGbp(string $currency): float
    {
        return $this->toGbp[strtoupper($currency)]
            ?? throw new InvalidArgumentException("No exchange rate for {$currency}");
    }
}
