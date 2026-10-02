<?php

namespace Tests\EvalHidden;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

// Hidden characterisation tests: copied in by evals/run.sh after the refactor, never shown to the agent.
// They go through POST /payment-links and use Laravel's own fakes and freezing helpers, as a
// project's existing feature tests would.
class PaymentLinkServiceTest extends TestCase
{
    private const ID_1 = '0b7e1f5c-3c2a-4d8e-9f10-2a6b8c4d1e01';
    private const ID_2 = '0b7e1f5c-3c2a-4d8e-9f10-2a6b8c4d1e02';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-10 09:15:42');
        Str::createUuidsUsingSequence([Uuid::fromString(self::ID_1), Uuid::fromString(self::ID_2)]);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_it_creates_a_link_with_the_provider(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599, 'currency' => 'eur'])
            ->assertStatus(201)
            ->assertExactJson([
                'id' => self::ID_1,
                'url' => 'https://pay.example.test/l/abc',
                'expires_at' => '2026-03-12T09:15:00+00:00',
                'reused' => false,
            ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://pay.example.test/v1/links'
            && $request->header('Authorization') === ['Bearer test-key']
            && $request->header('Accept') === ['application/json']
            && $request->data() === [
                'id' => self::ID_1,
                'reference' => 'A-100',
                'amount' => 2599,
                'currency' => 'EUR',
                'expires_at' => '2026-03-12T09:15:00+00:00',
            ]);
    }

    public function test_currency_defaults_to_gbp(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])->assertStatus(201);

        Http::assertSent(fn (Request $request) => $request['currency'] === 'GBP');
    }

    public function test_it_reads_provider_settings_from_config_on_each_call(): void
    {
        config(['payments.url' => 'https://other.example.test/api/', 'payments.key' => 'k-2', 'payments.link_ttl_hours' => 1]);
        Http::fake(['*' => Http::response(['url' => 'https://other.example.test/l/1'], 201)]);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 100])
            ->assertStatus(201)
            ->assertJsonPath('expires_at', '2026-03-10T10:15:00+00:00');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://other.example.test/api/links'
            && $request->header('Authorization') === ['Bearer k-2']);
    }

    public function test_a_second_request_for_the_same_order_reuses_the_cached_link(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);
        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])->assertStatus(201);

        $this->travel(47)->hours();

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])
            ->assertStatus(200)
            ->assertExactJson([
                'id' => self::ID_1,
                'url' => 'https://pay.example.test/l/abc',
                'expires_at' => '2026-03-12T09:15:00+00:00',
                'reused' => true,
            ]);
        Http::assertSentCount(1);
    }

    public function test_the_cached_link_expires_with_the_link(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);
        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])->assertStatus(201);

        $this->travel(49)->hours();

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])
            ->assertStatus(201)
            ->assertJsonPath('id', self::ID_2)
            ->assertJsonPath('reused', false);
        Http::assertSentCount(2);
    }

    public function test_orders_are_cached_separately(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])->assertStatus(201);
        $this->postJson('/payment-links', ['order' => 'A-101', 'amount' => 2599])
            ->assertStatus(201)
            ->assertJsonPath('id', self::ID_2);
    }

    public function test_a_provider_failure_is_logged_returns_502_and_caches_nothing(): void
    {
        Log::spy();
        Http::fakeSequence()
            ->push(['error' => 'down'], 500)
            ->push(['url' => 'https://pay.example.test/l/abc'], 201);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Could not create a payment link for order A-100']);

        Log::shouldHaveReceived('warning')->once()
            ->with('Payment link creation failed', ['order' => 'A-100', 'status' => 500]);
        Log::shouldNotHaveReceived('info');

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])
            ->assertStatus(201)
            ->assertJsonPath('id', self::ID_2);
    }

    public function test_a_created_link_is_logged(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['url' => 'https://pay.example.test/l/abc'], 201)]);

        $this->postJson('/payment-links', ['order' => 'A-100', 'amount' => 2599])->assertStatus(201);

        Log::shouldHaveReceived('info')->once()->with('Payment link created', ['order' => 'A-100', 'id' => self::ID_1]);
    }
}
