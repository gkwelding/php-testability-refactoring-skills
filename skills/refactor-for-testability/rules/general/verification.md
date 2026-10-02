---
title: Verification and Report
tags: refactoring, verification, static-analysis, unit-tests, report
---

## Verification and Report

### After Every Step

Run the characterisation tests for the target. Run the project's runner the way the project does (`php artisan test --filter=...`, `vendor/bin/phpunit --filter=...`, `vendor/bin/pest --filter=...`, through Sail/DDEV/Docker Compose if that's how tests run here).

### When the Refactor Is Done

1. **Characterisation tests**: all pass, unchanged. If you edited one, explain why: a test that had to change means behaviour changed, or the test was coupled to structure (for example, it resolved the SUT before faking).
2. **Neighbouring tests**: run the directory or suite the target's tests sit in, plus tests of every caller you touched. Constructor changes break callers far from the target.
3. **Static analysis and style**: run PHPStan / Psalm / Pint / PHP-CS-Fixer only if configured, with the project's config. New errors in changed files must be fixed; don't raise the baseline or add ignores.
4. **Container wiring**: in Laravel, make sure at least one Feature test resolves the target from the container (directly or through the route or job that uses it). In Symfony, `php bin/console lint:container` checks that the container's service arguments resolve to the right types.
5. **Unit tests for the extracted logic**: if the `generate-php-tests` skill is available, hand the extracted classes to it. Otherwise write focused unit tests with plain `PHPUnit\Framework\TestCase` (or the project's Pest setup), building the SUT with `new` and doubles only at the seams you introduced. They must pass without a booted framework; if one needs `Tests\TestCase` or `KernelTestCase`, the refactor isn't finished.
6. **Diff review**: read the full diff once against `general/behaviour-preservation.md`. Every change should be explainable as "introduce seam", "use seam", "move unchanged lines" or "update caller".

### Report

```
## Refactor: {ClassName}

**Hidden dependencies found:** {list, with file:line}
**Changed:** {each step, in order}
**Left as is:** {dependency: reason, e.g. `Job::dispatch()`: ShouldBeUnique lock}
**Callers updated:** {file:line list, or "container-resolved only"}
**Wiring:** {binding/provider/services.yaml changes, or "none"}
**Behaviour changes:** none | {each one, and whether the user agreed}
**Characterisation tests:** {file}: {n} tests, green before the first step and after each step
**Unit tests:** {file}: {n} tests | handed to generate-php-tests
**Static analysis:** {tool: result} | not configured
**Possible bugs noticed (not fixed):** {list, or "none"}
```
