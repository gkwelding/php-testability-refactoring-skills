<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentLinkService
{
    /**
     * Creates a hosted payment link for an order, or returns the one already created for it.
     *
     * @return array{id: string, url: string, expires_at: string, reused: bool}
     */
    public function create(string $orderRef, int $amountPence, string $currency = 'GBP'): array
    {
        $key = 'payment-link:'.$orderRef;

        if (($link = Cache::get($key)) !== null) {
            return $link + ['reused' => true];
        }

        $id = (string) Str::uuid();
        $expiresAt = now()->addHours((int) config('payments.link_ttl_hours'))->startOfMinute();

        $response = Http::withToken(config('payments.key'))
            ->acceptJson()
            ->post(rtrim(config('payments.url'), '/').'/links', [
                'id' => $id,
                'reference' => $orderRef,
                'amount' => $amountPence,
                'currency' => strtoupper($currency),
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

        if ($response->failed()) {
            Log::warning('Payment link creation failed', ['order' => $orderRef, 'status' => $response->status()]);

            throw new RuntimeException("Could not create a payment link for order {$orderRef}");
        }

        $link = [
            'id' => $id,
            'url' => $response->json('url'),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put($key, $link, $expiresAt);
        Log::info('Payment link created', ['order' => $orderRef, 'id' => $id]);

        return $link + ['reused' => false];
    }
}
