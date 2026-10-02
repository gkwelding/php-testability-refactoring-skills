---
name: refactor-for-testability
description: "Refactor existing PHP code (Laravel, Symfony or plain PHP) so it can be unit tested, without changing its behaviour. Pins current behaviour with characterisation tests first, then extracts hidden dependencies (facades and helpers in services, `new` of collaborators, static calls and singletons, `now()`/`time()`/`new DateTime()`, `rand()`/UUIDs, superglobals, `curl`/`file_get_contents`, constructors doing work) into injected collaborators in small verified steps, and reports what changed. Use when the user says things like 'make this testable', 'this class is hard to test', 'remove the facade calls from this service', 'inject the clock', 'get rid of the static calls', 'decouple this from Laravel/Symfony so I can unit test it', 'extract this logic out of the controller', or acts on a test-generation report that flagged untestable coupling. Not for writing tests for code that is already testable (use generate-php-tests), general clean-up or renaming, performance work, framework upgrades, or architecture rewrites (repositories everywhere, hexagonal layers, swapping the DI container)."
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Refactor PHP for Testability

Make a PHP class unit testable by turning its hidden dependencies into injected collaborators, with the smallest change that works and no change in behaviour.

**Target to refactor:** $ARGUMENTS

If no target was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), look in the conversation for a class reported as hard to test (for example a `generate-php-tests` report naming facades, `new`, statics or `final` classes). If there is one, confirm it with the user. Otherwise ask for a target. Don't pick files from the branch on your own: a refactor needs a deliberate target.

## Quality Standards

- Behaviour first: no production edit until characterisation tests pass against the unchanged code.
- Smallest seam that works. No speculative interfaces, layers or repositories.
- One move at a time, tests after each.
- Every class and method you use must exist in this project's installed versions. Check `vendor/` when unsure.
- Ask before any behaviour change, new package, or config/infrastructure edit.

---

## Step 1: Read the Code and Its Surroundings

1. **Stack** from `composer.json` / `composer.lock`: framework and version, PHP version, test runner (PHPUnit/Pest and version), mocking library, static analysis (PHPStan/Psalm config files), code style tool, and whether `psr/clock`, `symfony/clock`, `symfony/uid` are installed.
2. **The target** class in full, plus the types it uses.
3. **Its callers**: `grep` for `new ClassName`, `ClassName::`, type-hints and container wiring (service providers, `services.yaml`, `bind`/`singleton`, `#[AsAlias]`).
4. **Existing tests** of the target and its callers. Read them; they may already pin the behaviour.
5. **The rules** for this target (see Rules Reference).

## Step 2: Inventory and Plan

List every hidden dependency (`general/finding-seams.md`), and for each one the seam you'll use and why it's the lowest rung that works, or why it stays (an idiomatic facade in a controller, `Job::dispatch()` on a unique job, `now()` in Laravel). Print the plan:

```
## Plan for {ClassName}

| # | Dependency (file:line) | Seam | Step |
|---|---|---|---|
| 1 | Cache::remember (L42) | inject Illuminate\Contracts\Cache\Repository | constructor param, container-resolved |
| 2 | now() (L57) | keep: freezable with Carbon::setTestNow | none |

**Callers affected:** {list or "container-resolved only"}
**Behaviour changes:** none | {list: needs user agreement}
**Characterisation tests to write:** {effects to pin, and at which level}
```

If the plan has no behaviour changes, new packages or config edits, continue straight to Step 3. Otherwise stop and ask.

## Step 3: Pin Current Behaviour

Write characterisation tests at the level that works today (`general/characterisation-tests.md`): Laravel Feature test with fakes, Symfony `KernelTestCase`/`WebTestCase`, or plain PHPUnit with a temp directory. Cover every effect the plan could disturb. Run them against the unchanged code and see them pass. Don't touch production code until they do.

## Step 4: Refactor in Small Steps

For each row of the plan (`general/behaviour-preservation.md` plus the framework rules):

1. Introduce the seam with the old behaviour as the default or wiring.
2. Switch the call site(s).
3. Update callers that construct the class with `new`.
4. Run the characterisation tests. Red: undo the step and take a smaller one.

## Step 5: Unit Tests for the Extracted Logic

If the `generate-php-tests` skill is available, hand it the refactored class. Otherwise write focused unit tests with plain `PHPUnit\Framework\TestCase` (or Pest without the framework), building the SUT with `new` and doubling only the seams from Step 4. They must pass without booting the framework.

## Step 6: Verify and Report

Run the checks in `general/verification.md` (characterisation tests, neighbours and callers, static analysis if configured, container wiring, diff review), then print the report described there.

---

## Troubleshooting

**Target not found.** Say which paths you searched and ask.

**Already testable.** If every dependency is injected or is a freezable seam (`now()`, `Str::uuid()`), say so and suggest `generate-php-tests` instead. Don't refactor for its own sake.

**Can't write a characterisation test without changing the code.** Stop. Propose the smallest enabling change (usually making a URL, path or config value overridable, or temporary "extract and override" in a test subclass) and ask before making it.

**Characterisation test fails on the unchanged code.** Your understanding or the environment is wrong. Fix the test, not the code. If it depends on something unavailable (database, extension, network), report it and ask; don't refactor without a safety net.

**A step turns the tests red and the smaller step does too.** The seam changes behaviour. Revert, describe the difference, and ask.

**The right seam needs a new package** (`psr/clock`, `symfony/clock`). Ask before `composer require`. Offer the no-package alternative (pass the value as a parameter, a small own interface).

**Many callers construct the class with `new`.** Use an optional constructor parameter with the old behaviour as the default, and list the callers in the report rather than editing them all.

**Not Laravel or Symfony.** Use the general and `php/` rules, and say the framework rules didn't apply. Other frameworks' containers (Laminas, Yii, Slim + PHP-DI): follow the project's existing wiring and ask before adding any.

**Test suite can't run** (no test DB, missing extension, Docker not running). Report it and stop. Don't refactor unverified.

---

## Example

```
User: /refactor-for-testability app/Services/InvoiceReminder.php

Step 1: Laravel 12, PHP 8.3, PHPUnit 11, PHPStan level 6. InvoiceReminder is
        resolved by the container from SendRemindersCommand only. It calls
        Cache::remember for the customer's reminder count, Mail::to()->send(),
        SendReminderSms::dispatch() (ShouldBeUnique), and now() for the
        overdue check. One existing Feature test covers the command.

Step 2: Plan: inject Cache\Repository and Mail\Mailer (keep ->to()->send());
        keep SendReminderSms::dispatch() (unique lock); keep now() (freezable);
        extract the overdue/escalation decision into ReminderPolicy (pure).
        No behaviour changes, no new packages.

Step 3: tests/Feature/Services/InvoiceReminderTest.php: 5 tests (mail queued
        to customer, SMS job pushed after third reminder, nothing sent before
        due date, cache key incremented, not sent twice on the same day).
        Green on the unchanged code.

Step 4: 4 steps, tests green after each. Mail::fake() moved before
        app(InvoiceReminder::class) in one existing test.

Step 5: tests/Unit/Services/ReminderPolicyTest.php (6 tests) and
        tests/Unit/Services/InvoiceReminderTest.php (3 tests), plain TestCase.

Step 6: Feature + Unit green, tests/Feature/Console green, PHPStan clean.
        Report printed; one possible bug noted (reminders count weekends).
```

---

## Rules Reference

Paths are relative to `./rules/`. Read the ones that apply before changing code.

### Always

- `general/finding-seams.md` - inventory of hidden dependencies, the smallest-seam ladder, statics, `final`, constructors doing work
- `general/characterisation-tests.md` - pinning current behaviour at the level that works today
- `general/behaviour-preservation.md` - what counts as behaviour, small steps, keeping callers working
- `general/verification.md` - checks after the refactor, and the report

### By Target

| Target | Also read |
|---|---|
| Anything reading the time, generating IDs or random values | `php/time-ids-randomness.md` |
| Plain PHP: superglobals, `getenv`, `curl`/`file_get_contents`, filesystem, static singletons | `php/plain-php.md` |
| **Laravel** service, Action, domain class, job, listener, command using facades/helpers | `laravel/facades-and-contracts.md` |
| **Laravel** controller with business logic, model with logic, repository question | `laravel/models-controllers-actions.md` |
| **Laravel** code dispatching jobs, events, mail or notifications | `laravel/facades-and-contracts.md` (dispatch and fake-order traps) |
| **Symfony** service, handler, command, controller | `symfony/services.md` |
| **Symfony** code using `HttpClient::create()`, `Uuid::v4()`, `new DateTimeImmutable()`, Messenger | `symfony/services.md`, `php/time-ids-randomness.md` |
