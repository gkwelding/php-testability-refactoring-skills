<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PaymentLinkService
{
    public function __construct(
        private Cache $cache,
        private Http $http,
        private LoggerInterface $log,
        private Config $config,
    ) {
    }

    /**
     * Creates a hosted payment link for an order, or returns the one already created for it.
     *
     * @return array{id: string, url: string, expires_at: string, reused: bool}
     */
    public function create(string $orderRef, int $amountPence, string $currency = 'GBP'): array
    {
        $key = 'payment-link:'.$orderRef;

        if (($link = $this->cache->get($key)) !== null) {
            return $link + ['reused' => true];
        }

        $id = (string) Str::uuid();
        $expiresAt = now()->addHours((int) $this->config->get('payments.link_ttl_hours'))->startOfMinute();

        $response = $this->http->withToken($this->config->get('payments.key'))
            ->acceptJson()
            ->post(rtrim($this->config->get('payments.url'), '/').'/links', [
                'id' => $id,
                'reference' => $orderRef,
                'amount' => $amountPence,
                'currency' => strtoupper($currency),
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

        if ($response->failed()) {
            $this->log->warning('Payment link creation failed', ['order' => $orderRef, 'status' => $response->status()]);

            throw new RuntimeException("Could not create a payment link for order {$orderRef}");
        }

        $link = [
            'id' => $id,
            'url' => $response->json('url'),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->cache->put($key, $link, $expiresAt);
        $this->log->info('Payment link created', ['order' => $orderRef, 'id' => $id]);

        return $link + ['reused' => false];
    }
}
