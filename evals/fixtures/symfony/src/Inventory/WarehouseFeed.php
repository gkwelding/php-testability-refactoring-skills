<?php

namespace App\Inventory;

use RuntimeException;

/**
 * Reads current stock levels from the warehouse's JSON feed.
 */
final class WarehouseFeed
{
    public function __construct(private string $url)
    {
    }

    /** @return array<string, int> units on hand per SKU */
    public function levels(): array
    {
        $json = @file_get_contents($this->url);
        if ($json === false) {
            throw new RuntimeException('Warehouse feed unavailable');
        }

        $levels = [];
        foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['items'] as $item) {
            $levels[$item['sku']] = (int) $item['on_hand'];
        }

        return $levels;
    }
}
