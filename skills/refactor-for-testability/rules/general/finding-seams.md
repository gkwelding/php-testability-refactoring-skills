---
title: Finding Hidden Dependencies and Choosing the Seam
tags: refactoring, seams, dependencies, interfaces, injection
---

## Finding Hidden Dependencies and Choosing the Seam

### Inventory First

List every hidden dependency in the target before changing anything. Each line of the list becomes one small step.

| Look for | Why it blocks a unit test |
|---|---|
| Facades and helpers: `Cache::`, `Mail::`, `Http::`, `Storage::`, `DB::`, `Log::`, `config()`, `app()`, `resolve()` | Need a booted Laravel app |
| `new` of a service (`new StripeClient(...)`, `new PdfGenerator()`) inside a method | Can't be replaced |
| Static calls on non-facade classes (`Geo::lookup()`, `Registry::getInstance()`) | Can't be replaced; often hidden global state |
| `time()`, `date()`, `new DateTime()`, `microtime()`, `hrtime()` | Real clock, can't be frozen |
| `rand()`, `mt_rand()`, `random_int()`, `uniqid()`, `Uuid::v4()` | Non-deterministic |
| `$_SERVER`, `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `getenv()`, `$_ENV` | Global state |
| `file_get_contents`, `fopen`, `curl_*`, `mail()`, `exec()` | Real I/O |
| A constructor that queries, reads files, calls an API or reads config | Work runs before the test can intervene |
| `final` class with no interface, used as a collaborator | Can't be doubled |

Value objects, DTOs, enums, exceptions and pure helpers created with `new` are **not** hidden dependencies. Leave them.

### Smallest Seam That Works

Stop at the first rung that makes the unit testable:

1. **Pass the value in.** If a method only needs "now", a config value or a request field, take it as a parameter from the caller that already has it.
2. **Extract pure logic.** Move the calculation or decision out of the I/O-heavy method into a method or class that takes plain values. Often this is all that's needed: the pure part gets unit tests, the thin shell stays covered by the Feature/Kernel test.
3. **Inject an abstraction the framework or a PSR already provides.** Laravel contracts, Symfony service interfaces, `Psr\Clock\ClockInterface`, `Psr\Log\LoggerInterface`, `Symfony\Contracts\HttpClient\HttpClientInterface`. No new interface to maintain.
4. **Inject the concrete class.** A non-final class you own can be doubled directly. An interface adds nothing until there's a second implementation.
5. **Introduce your own interface**, only at a real seam: I/O, a third-party API or SDK, the clock, randomness. Name it after what the domain needs (`ExchangeRates`), not the vendor (`FixerIoClientInterface`), and give it only the methods the target calls.

**Incorrect:**

```php
// Speculative: an interface and a repository for a class with one implementation and no I/O
interface DiscountCalculatorInterface { public function discountFor(Order $order): int; }
interface OrderRepositoryInterface { public function find(int $id): ?Order; }
final class EloquentOrderRepository implements OrderRepositoryInterface { /* wraps Order::find() */ }
```

**Correct:**

```php
// Pure logic extracted; Eloquent stays where it was
final class DiscountCalculator
{
    public function discountFor(int $subtotalPence, int $loyaltyYears): int
    {
        return match (true) {
            $loyaltyYears >= 5 => intdiv($subtotalPence, 10),
            $loyaltyYears >= 2 => intdiv($subtotalPence, 20),
            default => 0,
        };
    }
}
```

### Constructors That Do Work

Move work out of the constructor into the method that needs it, or into a factory the container calls. Keep the constructor to assignments. Check the characterisation tests cover the path where the work used to fail early: an exception that was thrown on construction now happens on first use, which is a behaviour change to report.

### Static Singletons and Registries

Don't rewrite the singleton. Inject the instance into the target (`Registry::getInstance()` becomes a constructor parameter), and leave `getInstance()` for the other callers. The default can stay `Registry::getInstance()` while callers construct the target with `new`.

### `final` Classes Without an Interface

- Yours, and you need to double it at a real seam: extract an interface with only the methods the target uses, have the class implement it, depend on the interface. Keep `final`.
- Yours, but no I/O behind it: use the real object in the unit test. No interface.
- Vendor's: wrap it behind your own small interface at the point you use it, or use the vendor's test double if one exists (`MockClock`, `MockHttpClient`, Laravel fakes).

Never remove `final` to allow mocking, and don't add `dg/bypass-finals`.

### What Not to Do

- No service locator: replacing a facade with `app(Foo::class)` inside the method hides the same dependency.
- No container or framework rewrite, no new DI library, no moving the class to a new layer or namespace.
- No setter injection for required collaborators; it leaves a window where the object is unusable.
- No test-only branches (`if (app()->runningUnitTests())`, `if ($this->testMode)`).
