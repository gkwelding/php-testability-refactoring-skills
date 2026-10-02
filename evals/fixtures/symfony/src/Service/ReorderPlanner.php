<?php

namespace App\Service;

use App\Inventory\WarehouseFeed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ReorderPlanner
{
    public function __construct(
        #[Autowire(env: 'WAREHOUSE_FEED_URL')]
        private string $feedUrl,
    ) {
    }

    /**
     * What to order to bring each SKU back up to its par level, in whole packs, largest order first.
     *
     * @param  array<string, int>  $parLevels  target units on hand per SKU
     * @return list<array{sku: string, on_hand: int, order: int}>
     */
    public function plan(array $parLevels, int $packSize = 1): array
    {
        $levels = (new WarehouseFeed($this->feedUrl))->levels();

        $plan = [];
        foreach ($parLevels as $sku => $par) {
            $onHand = $levels[$sku] ?? 0;
            if ($onHand >= $par) {
                continue;
            }

            $plan[] = [
                'sku' => (string) $sku,
                'on_hand' => $onHand,
                'order' => (int) (ceil(($par - $onHand) / $packSize) * $packSize),
            ];
        }

        usort($plan, fn (array $a, array $b) => $b['order'] <=> $a['order'] ?: strcmp($a['sku'], $b['sku']));

        return $plan;
    }
}
