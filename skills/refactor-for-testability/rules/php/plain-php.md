---
title: Plain PHP: Globals, I/O and Statics
tags: php, globals, io, curl, filesystem, statics, composition-root
---

## Plain PHP: Globals, I/O and Statics

Without a container, the class's callers build it with `new`. Every collaborator you extract has to be built somewhere: in the existing entry point (front controller, CLI script, bootstrap file) or as a default parameter. Find that place first.

### Superglobals and Environment

Read `$_SERVER`, `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `getenv()` and `$_ENV` at the edge (controller, script) and pass the values in. Don't wrap superglobals in a `Request` interface unless the project already has a request object.

**Incorrect:**

```php
public function isAllowed(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'], explode(',', getenv('ALLOWED_IPS')), true);
}
```

**Correct:**

```php
public function __construct(private readonly array $allowedIps) {}

public function isAllowed(string $ip): bool
{
    return in_array($ip, $this->allowedIps, true);
}

// at the entry point, where the old code ran:
$guard = new IpGuard(explode(',', (string) getenv('ALLOWED_IPS')));
$guard->isAllowed($_SERVER['REMOTE_ADDR']);
```

Check that moving the `getenv()` read doesn't change *when* it happens in a way that matters (a long-running worker that reloads env, a value set later in bootstrap).

### Network I/O

Move `curl_*`, `file_get_contents('https://...')` and SDK calls behind a small interface named for what the domain needs. Move the existing lines **unchanged** into the implementation, including their error handling or lack of it. Improving error handling is a separate change.

If the project already has `psr/http-client` and a PSR-18 client wired up, depend on `Psr\Http\Client\ClientInterface` instead of writing your own interface.

**Incorrect (before):**

```php
public function import(): int
{
    $rates = json_decode(file_get_contents(getenv('RATES_URL')), true);
    file_put_contents(__DIR__ . '/../var/rates.json', json_encode(['at' => time(), 'rates' => $rates]));

    return count($rates);
}
```

**Correct (after):**

```php
<?php

declare(strict_types=1);

namespace App\Rates;

use Psr\Clock\ClockInterface;

interface RateSource
{
    /** @return array<string, float> */
    public function fetch(): array;
}

final class HttpRateSource implements RateSource
{
    public function __construct(private readonly string $url)
    {
    }

    public function fetch(): array
    {
        return json_decode(file_get_contents($this->url), true);
    }
}

final class RateImporter
{
    public function __construct(
        private readonly RateSource $source,
        private readonly string $storagePath,
        private readonly ClockInterface $clock,
    ) {
    }

    public function import(): int
    {
        $rates = $this->source->fetch();
        file_put_contents($this->storagePath, json_encode(['at' => $this->clock->now()->getTimestamp(), 'rates' => $rates]));

        return count($rates);
    }
}
```

The entry point now builds `new RateImporter(new HttpRateSource(getenv('RATES_URL')), __DIR__ . '/../var/rates.json', new SystemClock())` (`SystemClock` is in `time-ids-randomness.md`).

### Filesystem

Don't put the local filesystem behind an interface. Inject the **path** (file or directory) and use a real temp directory in tests: `sys_get_temp_dir() . '/' . bin2hex(random_bytes(4))`, created in `setUp()`, removed in `tearDown()`. Abstract storage only when it's remote (S3, SFTP) or the project already uses Flysystem, in which case inject `League\Flysystem\FilesystemOperator` and build it over a temp directory in tests. (In Laravel, use the `Filesystem\Factory` contract instead; see `laravel/facades-and-contracts.md`.)

### Static Calls and Singletons

- Static call to your own stateless helper (`Money::format()`): leave it. It's a pure function, not a dependency.
- Static call that does I/O or holds state (`Db::query()`, `Config::get()`, `Logger::getInstance()->log()`): inject the instance. Keep the static API for other callers, and use it as the default if the class is built with `new` in many places.

**Incorrect:**

```php
// Fatal: static calls aren't allowed in parameter defaults (only `new` is, from PHP 8.1)
public function __construct(private readonly Logger $logger = Logger::getInstance()) {}
```

**Correct:**

```php
private readonly Logger $logger;

public function __construct(?Logger $logger = null)
{
    $this->logger = $logger ?? Logger::getInstance();
}
```

### Extending to Override (Avoid)

Subclassing the class under test to override a `protected` method that does I/O ("extract and override") works without touching callers, but it tests a subclass, not the class. Use it only as a temporary step to get characterisation tests in place when nothing else can, and say so in the report.
