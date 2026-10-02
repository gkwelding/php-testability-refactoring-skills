<?php

namespace Tests\Unit;

use App\Services\PaymentLinkService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PaymentLinkServiceTest extends TestCase
{
    public function test_a_cached_link_is_reused_without_calling_the_provider(): void
    {
        $cache = new Repository(new ArrayStore());
        $cache->put('payment-link:A-1', ['id' => 'x', 'url' => 'u', 'expires_at' => 'e'], 60);
        $http = new Factory();
        $http->fake();

        $link = (new PaymentLinkService($cache, $http, new NullLogger(), new Config([])))->create('A-1', 100);

        $this->assertTrue($link['reused']);
        $http->assertNothingSent();
    }
}
