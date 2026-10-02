---
title: Clock, IDs and Randomness
tags: php, clock, psr-20, carbon, uuid, randomizer, laravel, symfony
---

## Clock, IDs and Randomness

### Which Clock Seam

| Code uses | Stack | Do |
|---|---|---|
| `now()`, `today()`, `Carbon::now()`, `Date::now()` | Laravel | **Usually keep it.** Already a seam (see below). |
| `time()`, `date()`, `new DateTime()`, `new DateTimeImmutable()` | Laravel | Replace with `now()` (or `CarbonImmutable::now()`) so it can be frozen. Inject a clock only for the cases below. |
| Anything reading the time | Symfony | Inject `Psr\Clock\ClockInterface` (or `Symfony\Component\Clock\ClockInterface` if you need `sleep()`). |
| Anything reading the time | Plain PHP | Inject `Psr\Clock\ClockInterface` if `psr/clock` is installed, otherwise take `DateTimeImmutable $now` as a method parameter. |

### Laravel: Keep `now()` Unless There's a Reason

`now()` resolves through the `Date` facade, which falls back to a default factory when no app is booted, so it works in a plain `PHPUnit\Framework\TestCase`. Freeze it with `Illuminate\Support\Carbon::setTestNow()` (that is what `travelTo()` calls) and reset it in `tearDown()`:

```php
use Illuminate\Support\Carbon;

protected function tearDown(): void
{
    Carbon::setTestNow();
}

public function test_is_stale_older_than_an_hour_returns_true(): void
{
    Carbon::setTestNow('2024-01-01 12:00:00');

    $this->assertTrue((new QuoteValidity())->isStale(new DateTimeImmutable('2024-01-01 10:59:59')));
}
```

Use `Illuminate\Support\Carbon`, not `Carbon\Carbon`: it freezes both `Carbon` and `CarbonImmutable`. On Carbon 2 (Laravel 10), `Carbon\Carbon::setTestNow()` leaves `CarbonImmutable::now()` on the real clock.

Inject `Psr\Clock\ClockInterface` instead when the class must run outside Laravel (a shared domain package), the project already has a clock abstraction, or the user asks for it. Laravel binds no `ClockInterface`, so add one in `AppServiceProvider::register()`, and bind Carbon's factory so `travelTo()` in existing Feature tests still controls it:

```php
use Carbon\FactoryImmutable;
use Psr\Clock\ClockInterface;

$this->app->singleton(ClockInterface::class, fn () => new FactoryImmutable());
```

Don't bind `Symfony\Component\Clock\NativeClock` in a Laravel app: it ignores `travelTo()`, so every existing Feature test that freezes time would silently start using the real clock for that code. `psr/clock` arrives with Carbon on Laravel 10-13, but list it in `composer.json` (with the user's agreement) if production code type-hints it.

### Symfony: Inject the Clock

With `symfony/clock` installed, FrameworkBundle registers a `clock` service aliased to both `Symfony\Component\Clock\ClockInterface` and `Psr\Clock\ClockInterface`, so autowiring works with no config. Without the package the aliases are removed and autowiring fails: check `composer.json` first.

**Incorrect:**

```php
$expiresAt = new \DateTimeImmutable('+30 days');
```

**Correct:**

```php
public function __construct(private readonly ClockInterface $clock) {}

// ...
$expiresAt = $this->clock->now()->modify('+30 days');
```

`now()` returns a `DatePoint`, which extends `DateTimeImmutable`, in the default timezone, so formatting and comparisons are unchanged.

- Unit tests: `new MockClock('2024-01-01 09:00:00')`; move it with `$clock->modify('+5 minutes')` or `$clock->sleep(300)`.
- Kernel/Web tests: `use ClockSensitiveTrait;` and `static::mockTime('2024-01-01 09:00:00')`. The `clock` service delegates to the global clock, so this also controls the injected `ClockInterface`.
- Code that calls `Clock::get()->now()` or the `Symfony\Component\Clock\now()` function is already freezable with `mockTime()`. Injecting is still better for unit tests, but it isn't required to pin behaviour.
- If changing the constructor would break many `new` callers, `use ClockAwareTrait;` gives the class a `#[Required]` `setClock()` and a protected `now()` that falls back to the global clock when nothing is set.

### Plain PHP Clock

```php
<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
```

`time()` becomes `$this->clock->now()->getTimestamp()`; `date('Y-m-d')` becomes `$this->clock->now()->format('Y-m-d')`. Both use the default timezone, as before. In tests, stub `ClockInterface` to return a fixed `DateTimeImmutable`, or use `MockClock` if `symfony/clock` is already a dev dependency.

### UUIDs

| Code uses | Do |
|---|---|
| Laravel `Str::uuid()`, `Str::orderedUuid()`, `Str::ulid()` | **Keep.** Freeze with `Str::createUuidsUsing(...)` / `Str::freezeUuids()` / `Str::createUlidsUsing(...)`, which work without a booted app. Reset with `Str::createUuidsNormally()` / `Str::createUlidsNormally()`. |
| Laravel, `Ramsey\Uuid\Uuid::uuid4()` directly | Switch to `Str::uuid()`: it returns `Uuid::uuid4()` unless a factory is set, so output is unchanged. |
| Symfony `Uuid::v4()`, `Uuid::v7()`, `new UuidV4()` | Inject `Symfony\Component\Uid\Factory\UuidFactory` (autowired as `uuid.factory`). |

**Symfony version trap:** `UuidFactory::create()` returns the configured default version (`framework.uid.default_uuid_version`: 6 unless set on Symfony 6.4, 7 from 7.0). Replacing `Uuid::v4()` with `$factory->create()` changes the IDs. Keep the version explicit:

```php
// was: Uuid::v4()
$id = $this->uuids->randomBased()->create();
// was: Uuid::v7() / Uuid::v6() / Uuid::v1()
$id = $this->uuids->timeBased()->create(); // only if framework.uid.time_based_uuid_version matches
```

In tests: `new MockUuidFactory(['0b8d5b3a-...'])` on symfony/uid 7.4+. On older versions, `UuidFactory` and `RandomBasedUuidFactory` aren't final, so stub `randomBased()` to return a stub whose `create()` returns `Uuid::fromString('...')`.

### Randomness

| Code uses | Do |
|---|---|
| `random_int($a, $b)`, `random_bytes($n)` | Inject `Random\Randomizer` (PHP 8.2+): `getInt($a, $b)`, `getBytes($n)`. Its default engine is `Random\Engine\Secure`, the same CSPRNG. |
| `shuffle()`, `array_rand()`, `str_shuffle()` | `shuffleArray()`, `pickArrayKeys()`, `shuffleBytes()` on the injected `Randomizer` |
| `mt_rand()` with `mt_srand($seed)` somewhere for reproducibility | `new Randomizer(new Random\Engine\Mt19937($seed))`, so the seeded sequence stays the caller's choice |
| Laravel `Str::random()` | **Keep.** Freeze with `Str::createRandomStringsUsing(fn () => 'fixed')`, reset with `Str::createRandomStringsNormally()`. |

`Randomizer` is `final`: don't mock it. In tests pass `new Randomizer(new Mt19937(42))`, whose sequence is deterministic, or assert properties (range, length) rather than values. If a test needs an exact value from a non-seeded source, introduce a one-method interface (`DiceRoller::roll(): int`) at that seam instead.
