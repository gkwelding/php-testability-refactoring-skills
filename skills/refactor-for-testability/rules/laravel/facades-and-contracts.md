---
title: Laravel Facades, Helpers and Contracts
tags: laravel, facades, contracts, container, service-provider, fakes
---

## Laravel Facades, Helpers and Contracts

### Where Facades Stay

Facades are idiomatic and already testable through fakes in Feature tests. Don't replace them in controllers, routes, middleware, Form Requests, service providers, Artisan command closures or Blade. Jobs and Artisan commands can take collaborators as `handle()` parameters (the container calls `handle()`), which is often less churn than constructor changes. Listeners are resolved from the container, so constructor injection works there.

Replace them in **services, Actions and domain classes** that you want to unit test with plain `PHPUnit\Framework\TestCase`.

### Facade → Contract

All of these are bound by the framework (Laravel 10-13); no service provider needed.

| In the class | Inject | Call |
|---|---|---|
| `Cache::get/put/remember`, `cache()` | `Illuminate\Contracts\Cache\Repository` | same methods |
| `Cache::store('redis')` | `Illuminate\Contracts\Cache\Factory` | `->store('redis')` |
| `Event::dispatch()`, `event()` | `Illuminate\Contracts\Events\Dispatcher` | `->dispatch($event)` |
| `Bus::dispatch()` | `Illuminate\Contracts\Bus\Dispatcher` | `->dispatch($job)` (not for `Job::dispatch()` or the `dispatch()` helper; see the trap below) |
| `Mail::to($u)->send($m)` | `Illuminate\Contracts\Mail\Mailer` | `->to($u)->send($m)` (keep the `to()` call: `PendingMail::to()` picks up the recipient's `HasLocalePreference`) |
| `Mail::mailer('ses')` | `Illuminate\Contracts\Mail\Factory` | `->mailer('ses')` |
| `Notification::send()` | `Illuminate\Contracts\Notifications\Dispatcher` | `->send($notifiables, $notification)` |
| `Storage::disk('s3')` | `Illuminate\Contracts\Filesystem\Factory` | `->disk('s3')` at call time |
| `Http::get()` etc. | `Illuminate\Http\Client\Factory` (concrete; there is no contract) | same methods |
| `Log::info()` | `Psr\Log\LoggerInterface` | same methods |
| `config('services.x.key')` | a constructor scalar | see "Config Values" |
| `app(Foo::class)`, `resolve(Foo::class)` | `Foo` as a constructor parameter | |
| `auth()->user()`, `request()->input()` | nothing: pass the value in from the controller | |

`$user->notify(...)` is a model method; leave it. `DB::` and Eloquent stay too (see `models-controllers-actions.md`).

### Trap: `Job::dispatch()` Is Not `Bus\Dispatcher::dispatch()`

`SendInvoice::dispatch($invoice)` and the `dispatch($job)` helper return a `PendingDispatch` that, on destruct, acquires the `ShouldBeUnique` lock before dispatching (and, on Laravel 13, honours `PreparesForDispatch`). `$bus->dispatch(new SendInvoice($invoice))` skips that. For unique jobs, keep `SendInvoice::dispatch(...)` and verify it with `Queue::fake()` / `Bus::fake()` in the Feature test; extract the logic around it instead.

### Trap: Fake Before You Resolve

`Event::fake()`, `Mail::fake()`, `Bus::fake()`, `Queue::fake()` and `Notification::fake()` swap the container binding. A service resolved **before** the fake holds the real dispatcher, so the characterisation test that passed with facades now sends real mail or fails its assertion.

**Incorrect:**

```php
protected function setUp(): void
{
    parent::setUp();
    $this->reminder = app(InvoiceReminder::class); // resolved with the real Mailer
}

public function test_remind_overdue_invoice_queues_reminder(): void
{
    Mail::fake();
    $this->reminder->remind($invoice); // Mail::assertQueued fails
}
```

**Correct:**

```php
public function test_remind_overdue_invoice_queues_reminder(): void
{
    Mail::fake();

    app(InvoiceReminder::class)->remind($invoice);

    Mail::assertQueued(InvoiceOverdue::class);
}
```

`Http::fake()` modifies the shared `Factory` singleton, so order doesn't matter for HTTP. `Storage::fake('s3')` replaces the disk inside the manager: inject the `Filesystem\Factory` and call `disk()` per use. Injecting `Illuminate\Contracts\Filesystem\Filesystem` (or `#[Storage('s3')]`) captures the disk at construction, so the SUT must be resolved after `Storage::fake()`.

### Unit Testing the Refactored Class

Build the SUT with `new`. Where Laravel has a cheap real implementation, use it instead of a mock:

```php
$http = new \Illuminate\Http\Client\Factory();
$http->fake(['api.example.com/*' => \Illuminate\Http\Client\Factory::response(['rate' => 1.25])]);
$cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());

$service = new RateService($http, $cache);
// ... $http->assertSent(fn (Request $request) => ...), $http->assertSentCount(1)
```

For `Mailer`, return a real `PendingMail` from the mock's `to()` and capture what reaches `send()`:

```php
$mailer = $this->createMock(Mailer::class);
$mailer->method('to')->willReturnCallback(fn ($users) => (new PendingMail($mailer))->to($users));
$sent = null;
$mailer->expects($this->once())->method('send')
    ->willReturnCallback(function ($mailable) use (&$sent) { $sent = $mailable; return null; });
```

Then assert `$sent->hasTo('ann@example.com')` and its fields. Mock `Events\Dispatcher`, `Bus\Dispatcher` and `Notifications\Dispatcher` directly.

### Config Values

Pass config into the constructor as a scalar instead of calling `config()` in the method:

```php
// AppServiceProvider::register()
$this->app->when(RateService::class)
    ->needs('$apiKey')
    ->giveConfig('services.rates.key');
```

On Laravel 11+, if `vendor/laravel/framework/src/Illuminate/Container/Attributes/Config.php` exists, `#[Config('services.rates.key')] private readonly string $apiKey` on the constructor parameter does the same with no provider code.

Behaviour check: the value is now read when the service is resolved, not on every call. Tests that call `config([...])` must do so before resolving the service, and a singleton keeps the old value for its lifetime. Say so if the target is registered as a singleton or used in Octane/queue workers.

### Binding

Concrete classes and the contracts above resolve automatically. Add a binding only for an interface you introduced, in `AppServiceProvider::register()` (or the provider that owns the area):

```php
$this->app->bind(ExchangeRates::class, HttpExchangeRates::class);
```

Use `singleton()` only if the old code relied on shared state (a static cache, a memoised client). Don't create a new service provider for one binding.
