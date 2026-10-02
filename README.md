# PHP Testability Refactoring Skills

An agent skill for refactoring Laravel, Symfony and plain-PHP code so it can be unit tested, without changing what it does: hidden dependencies become injected collaborators, in small steps, with characterisation tests run after each one.

**Pin → Plan → Refactor in small steps → Unit test → Verify**

## Skills

| Skill | What it does |
|---|---|
| `refactor-for-testability` | Lists the target's hidden dependencies and the smallest seam for each, pins current behaviour with characterisation tests at the level that works today, refactors one step at a time with the tests run after each, adds unit tests for the extracted logic (or hands off to `generate-php-tests`), runs static analysis if configured, and reports every change. |

## What's Covered

**General (all PHP):** an inventory of hidden dependencies, a smallest-seam-first ladder (pass the value in, extract pure logic, inject an existing abstraction, inject the concrete class, and only then a new interface at a real I/O, clock or randomness seam), characterisation tests and how to pin values the old code can't freeze, what counts as behaviour (exception timing, side-effect order, parameter names, laziness), keeping `new` callers working with optional constructor parameters, static singletons, `final` classes, constructors that do work.

**Clock, IDs, randomness:** when to keep Laravel's `now()` (it works without a booted app and freezes with `Illuminate\Support\Carbon::setTestNow()`) and when to inject `Psr\Clock\ClockInterface` (bound to Carbon's `FactoryImmutable` so `travelTo()` still works, not `NativeClock`), Symfony's `clock` service and `mockTime()`, the `UuidFactory::create()` version trap, `Random\Randomizer` with seeded engines.

**Laravel:** facade-to-contract table for Cache, Events, Bus, Mail, Notifications, Filesystem, HTTP client, Log and config, where facades should stay, the `Job::dispatch()` vs `Bus\Dispatcher::dispatch()` trap (unique locks), the fake-before-resolve trap, unit testing with real `Http\Client\Factory` / `ArrayStore` instances and a `PendingMail`-returning mailer mock, `giveConfig()` and `#[Config]`, bindings, extracting logic from controllers and models into Actions without hiding Eloquent behind repositories.

**Symfony:** autowiring as the default, `ClockInterface`, `UuidFactory`, `HttpClientInterface` (and the trap that the framework client isn't `HttpClient::create()`), scoped clients, `MessageBusInterface` mocks that must return an `Envelope`, `#[Autowire(env:/param:)]`, why services shouldn't go `public` for tests, the single-implementation alias that a test double in `src/` silently breaks, Doctrine.

**Plain PHP:** superglobals and `getenv()` read at the edge, network I/O behind a small domain interface (moving the lines unchanged), the local filesystem tested with a temp directory rather than abstracted, composition roots, what `new` can and can't be a parameter default.

## How It Complements `php-unit-tests-skills`

[php-unit-tests-skills](https://github.com/gkwelding/php-unit-tests-skills) writes tests for code as it is. When it meets untestable coupling (facades or `new` deep inside a class, static calls, `final` classes with no interface, `now()`/`time()` calls) it tests at the level that works and reports the coupling instead of changing production code.

This skill takes that report as input: it changes the production code, keeps the behaviour, and hands the result back to `generate-php-tests` for proper unit tests. Each works alone; installed together, the test skill's coupling report becomes this skill's target.

## Install

### Claude Code plugin

```
/plugin marketplace add gkwelding/php-testability-refactoring-skills
/plugin install php-testability-refactoring-skills@php-testability-refactoring-skills
```

The command becomes `/php-testability-refactoring-skills:refactor-for-testability <target>`.

### Copy into a project or user skills folder

```
cp -r skills/refactor-for-testability ~/.claude/skills/
# or per project:
cp -r skills/* .claude/skills/
```

### claude.ai

Build the package, then upload `dist/refactor-for-testability.skill` (Settings → Capabilities → Skills):

```
sh scripts/build-skills.sh
```

The script packages the committed files at `HEAD`; commit edits first.

## Usage

```
/refactor-for-testability app/Services/InvoiceReminder.php
/refactor-for-testability src/Billing/TrialStarter.php
/refactor-for-testability lib/RateImporter.php
/refactor-for-testability          # no target: uses a class flagged as hard to test earlier in the conversation, or asks
```

Or just ask: "make this service testable", "remove the facade calls from InvoiceReminder", "inject the clock into TrialStarter".

## Ground Rules the Skill Enforces

- No production edit until characterisation tests pass against the unchanged code
- One step at a time, tests after each; a red step is undone, not debugged forward
- Public behaviour, signatures callers depend on, wiring and config stay the same, or the change is named and agreed first
- No speculative interfaces, repositories or layers, and no container rewrites
- No new packages, no `public: true` for tests, no removing `final`, no test-only branches in production code

## Layout

```
skills/
└── refactor-for-testability/
    ├── SKILL.md
    └── rules/
        ├── general/     # seams, characterisation tests, behaviour preservation, verification
        ├── php/         # clock/IDs/randomness, plain PHP globals, I/O and statics
        ├── laravel/     # facades and contracts, models/controllers/Actions
        └── symfony/     # services, autowiring, HttpClient, Messenger, test container
scripts/build-skills.sh  # packages dist/*.skill for claude.ai
evals/                   # with/without comparison on Laravel and Symfony fixtures (see evals/README.md)
```

## Status

First version. The classes, methods, service IDs and config keys the rules name have been checked against the Laravel 10.50, 11.57, 12.69 and 13.34 sources, Symfony 6.4 and 8.1 (`clock`, `uid`, `http-client`, `messenger`, `dependency-injection`, `framework-bundle`), Carbon 2.73 and 3.14, `psr/clock` 1.0, and PHPUnit 10.5, 11 and 13, with the key test patterns run on PHP 8.5. Treat it as a strong starting point and adjust the rules to your house style.

## Evals

`evals/run.sh` refactors four fixture classes (a Laravel service full of facades, a Laravel service doing `new` of collaborators and a static registry call, a Symfony service reading `$_SERVER` and `time()`, a Symfony service using a `final` I/O class) with and without the skill, and scores each run on:

- **Behaviour kept:** hidden characterisation tests, copied in only after the refactor, still pass
- **Testability gained:** blocking calls left in the target (facades, `config()`, globals, the real clock, hidden collaborators), and the agent's plain-`TestCase` unit tests passing
- **Restraint:** public signatures unchanged, new classes and interfaces, production diff size
- **Cost:** dollars, turns and minutes, plus an optional blind A/B review of the two diffs

First result, one sample (`PaymentLinkService`, $1.47 in total): both variants kept all 8 hidden tests passing, removed all 8 blocking calls in 30 changed lines and added no new types. `with` also pinned behaviour with feature tests first, cost $0.80 against $0.47, and split the blind review 2–2. See [evals/README.md](evals/README.md) for what each score means, how to run it and what it costs.

## Licence

MIT. See [LICENSE](LICENSE).
