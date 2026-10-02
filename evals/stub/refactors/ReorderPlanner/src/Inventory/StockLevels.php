<?php

namespace App\Inventory;

interface StockLevels
{
    /** @return array<string, int> units on hand per SKU */
    public function levels(): array;
}
