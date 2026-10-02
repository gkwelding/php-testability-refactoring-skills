---
title: Symfony Services and Autowiring
tags: symfony, autowiring, services-yaml, http-client, messenger, clock, uid, test-container
---

## Symfony Services and Autowiring

With the default `config/services.yaml` (`_defaults: autowire: true, autoconfigure: true` and `App\: resource: '../src/'`), any class under `src/` gets its constructor arguments by type. Replacing `new` or a static call with a constructor parameter usually needs **no config change**. Check `services.yaml` first: if the target is excluded or wired by hand, update that definition instead.

### Hidden Dependency → Injected Service

| In the class | Inject | Notes |
|---|---|---|
| `new \DateTimeImmutable()`, `time()`, `date()` | `Psr\Clock\ClockInterface` | needs `symfony/clock`; see `php/time-ids-randomness.md` |
| `Uuid::v4()`, `Uuid::v7()` | `Symfony\Component\Uid\Factory\UuidFactory` | keep the version explicit; see `php/time-ids-randomness.md` |
| `HttpClient::create()`, `new CurlHttpClient()`, `file_get_contents('https://...')` | `Symfony\Contracts\HttpClient\HttpClientInterface` | see the defaults trap below |
| A scoped client built by hand for one API | `HttpClientInterface $githubClient` | a client configured as `framework.http_client.scoped_clients.github.client` autowires by that camelCased argument name |
| `new SomeService(...)` | `SomeService` | the container builds it |
| `$container->get('foo')`, a service locator | the service itself | |
| Handler called directly for side effects | `Symfony\Component\Messenger\MessageBusInterface` | only if the old code dispatched; calling a handler directly and dispatching are different behaviours |
| `$_ENV['X']`, `getenv('X')` | `#[Autowire(env: 'X')] private readonly string $x` | |
| A parameter (`%kernel.project_dir%`) read via `ParameterBagInterface` | `#[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir` | |

`#[Autowire]` is `Symfony\Component\DependencyInjection\Attribute\Autowire`. Use a `bind:` under `_defaults` in `services.yaml` only when several services need the same scalar.

### Trap: The Framework Client Isn't `HttpClient::create()`

The autowired `http_client` service is built from `framework.http_client.default_options` (base URI, headers, timeouts, retries if `retry_failed` is set). Code that called `HttpClient::create()` got none of those. Before switching, read `config/packages/framework.yaml`: if default options exist, the requests change. Either inject a scoped client configured to match the old behaviour, or report the difference before making it.

### Testing the Injected Services

- Clock: `new MockClock('2024-01-01 09:00:00')`.
- HTTP: `new MockHttpClient(new JsonMockResponse(['ok' => true], ['http_code' => 201]), 'https://crm.test')`; check `$client->getRequestsCount()`, or pass a callback to inspect method, URL and options.
- UUIDs: `new MockUuidFactory([...])` (symfony/uid 7.4+) or a stubbed `UuidFactory`.
- Messenger: a `MessageBusInterface` mock **must return an `Envelope`**. `Envelope` is `final`, so PHPUnit can't generate a default and the call errors:

**Incorrect:**

```php
$bus = $this->createMock(MessageBusInterface::class);
$bus->expects($this->once())->method('dispatch'); // RuntimeException: Envelope is final and cannot be doubled
```

**Correct:**

```php
$bus = $this->createMock(MessageBusInterface::class);
$bus->expects($this->once())->method('dispatch')
    ->willReturnCallback(fn (object $message): Envelope => new Envelope($message));
```

### Don't Change Wiring for Tests

- **Don't make services `public: true`** to fetch or replace them in tests. In `KernelTestCase`, `static::getContainer()` returns the test container, which reaches private services, and `static::getContainer()->set(Foo::class, $double)` replaces them. A private service nothing uses is removed at compile time; fetch the service that depends on it instead.
- **Don't put test doubles under `src/`.** When an interface under the `App\` resource has exactly one implementation, Symfony aliases it automatically. A second implementation (a fake, an in-memory version) silently removes that alias and autowiring of the interface fails. Keep doubles in `tests/`. If a real second implementation is needed, mark the default with `#[AsAlias]` on the implementation (or an alias in `services.yaml`) in the same change.
- Introducing an interface for a class that is autowired by its concrete type: existing type-hints on the concrete class keep working. Switch type-hints to the interface only in the target, not across the codebase.

### Doctrine

Same rule as Eloquent: don't hide queries behind new interfaces to mock `EntityManagerInterface` or `QueryBuilder` chains. Keep repositories as they are, test them against a real database, and extract the decision logic between queries into a class that takes entities or plain values.
