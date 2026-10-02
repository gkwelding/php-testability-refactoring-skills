---
title: Behaviour-Preserving Refactoring
tags: refactoring, behaviour, small-steps, signatures, callers
---

## Behaviour-Preserving Refactoring

A testability refactor changes structure only. Anything a caller, user or operator can observe stays the same, or the change is named in the report before it's made.

### What Counts as Behaviour

- Return values and types, including `null` vs empty array vs `false`
- Exceptions: class, message, and *when* they're thrown (before or after a side effect)
- Side effects and their order: rows written, jobs/events/messages dispatched, mail, HTTP calls, files, cache keys, logs
- Public and protected method signatures that other code calls or overrides, including parameter names (named arguments make them API)
- How the class is constructed by its callers: `new Foo()` in other code, container resolution, `services.yaml` / service provider wiring
- Config keys, env variables, cache key formats, queue names, route names
- Laziness: work that happened only on some paths must not move into the constructor, and vice versa

### Small Steps

One move at a time, characterisation tests after each:

1. Introduce the seam (constructor parameter, interface, extracted class) with the old behaviour as the default.
2. Run the characterisation tests.
3. Switch one call site to the seam.
4. Run them again.
5. Repeat until no hidden dependency is left in the unit, then remove the defaults only if every caller is wired by the container or updated in this change.

If a test goes red, undo the last step rather than debugging forward. A red step means the step was too big or changed behaviour.

### Keep Callers Working

Find every caller before changing a constructor: `grep -rn "new ClassName\|ClassName::" app src tests` plus container wiring. Then pick the least disruptive option:

| Callers | Do |
|---|---|
| Resolved only by the container (Laravel auto-resolution, Symfony autowiring) | Add constructor parameters freely; the container supplies them |
| `new Foo($a)` in a few places in this repo | Update them in the same change, and list them in the report |
| `new Foo($a)` in code you don't control (a published package, other repos) | Add the new parameter last, optional, defaulting to the old behaviour |
| A static entry point (`Foo::calculate()`) used widely | Keep it as a thin wrapper over an instance with injected collaborators; mark it for later removal, don't remove it now |

**Incorrect:**

```php
// Breaks every `new PriceCalculator($rates)` in the codebase
public function __construct(
    private readonly RateProvider $rates,
    private readonly ClockInterface $clock,
) {}
```

**Correct (when callers outside the change construct it):**

```php
public function __construct(
    private readonly RateProvider $rates,
    private readonly ClockInterface $clock = new SystemClock(),
) {}
```

`new` in a parameter default needs PHP 8.1+. On older PHP, take `?ClockInterface $clock = null` and assign `$clock ?? new SystemClock()` in the body.

### Don't Change While Refactoring

- No renames, reformatting, type tightening, `final`/`readonly` additions or "while I'm here" fixes in the same change. They bloat the diff and hide behaviour changes.
- No new packages. If the right seam needs one (`symfony/clock`, `psr/clock`), ask first; it may already be installed as a transitive dependency, but depend on it explicitly in `composer.json` only with the user's agreement.
- No config, `.env*`, `phpunit.xml`, `composer.json` or infrastructure changes without saying so first.
- Don't change visibility to make something testable (private → public, `public: true` on a Symfony service). Inject or extract instead.
