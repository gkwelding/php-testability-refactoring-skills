<?php

namespace App\Tests\EvalHidden;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// Hidden characterisation tests: copied in by evals/run.sh after the refactor, never shown to the agent.
// They go through POST /reorders, with WAREHOUSE_FEED_URL pointing at a temp file.
class ReorderPlannerTest extends WebTestCase
{
    private string $feed;

    protected function setUp(): void
    {
        $this->feed = sys_get_temp_dir().'/eval-feed-'.bin2hex(random_bytes(4)).'.json';
        $_SERVER['WAREHOUSE_FEED_URL'] = $_ENV['WAREHOUSE_FEED_URL'] = $this->feed;
        file_put_contents($this->feed, json_encode(['items' => [
            ['sku' => 'A', 'on_hand' => 3],
            ['sku' => 'B', 'on_hand' => 10],
            ['sku' => 'C', 'on_hand' => '0'],
        ]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->feed);

        parent::tearDown();
    }

    private function plan(array $body): array
    {
        $client = static::createClient();
        $client->request('POST', '/reorders', content: json_encode($body));

        return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true)];
    }

    public function test_it_orders_up_to_par_largest_order_first(): void
    {
        [$code, $body] = $this->plan(['par' => ['A' => 10, 'B' => 10, 'C' => 5, 'D' => 2]]);

        $this->assertSame(200, $code);
        $this->assertSame([
            ['sku' => 'A', 'on_hand' => 3, 'order' => 7],
            ['sku' => 'C', 'on_hand' => 0, 'order' => 5],
            ['sku' => 'D', 'on_hand' => 0, 'order' => 2],
        ], $body);
    }

    public function test_orders_are_rounded_up_to_whole_packs_and_ties_sorted_by_sku(): void
    {
        [$code, $body] = $this->plan(['par' => ['D' => 2, 'C' => 5, 'A' => 10], 'pack_size' => 6]);

        $this->assertSame(200, $code);
        $this->assertSame([
            ['sku' => 'A', 'on_hand' => 3, 'order' => 12],
            ['sku' => 'C', 'on_hand' => 0, 'order' => 6],
            ['sku' => 'D', 'on_hand' => 0, 'order' => 6],
        ], $body);
    }

    public function test_nothing_to_order_when_stock_is_at_par(): void
    {
        [$code, $body] = $this->plan(['par' => ['A' => 3, 'B' => 9]]);

        $this->assertSame(200, $code);
        $this->assertSame([], $body);
    }

    public function test_an_unreadable_feed_returns_503(): void
    {
        unlink($this->feed);

        [$code, $body] = $this->plan(['par' => ['A' => 10]]);

        $this->assertSame(503, $code);
        $this->assertSame(['error' => 'Warehouse feed unavailable'], $body);
    }

    public function test_a_malformed_feed_is_a_server_error(): void
    {
        file_put_contents($this->feed, '{"items": [');

        [$code] = $this->plan(['par' => ['A' => 10]]);

        $this->assertSame(500, $code);
    }
}
