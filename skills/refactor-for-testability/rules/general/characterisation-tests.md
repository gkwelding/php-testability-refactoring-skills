---
title: Characterisation Tests
tags: refactoring, characterisation, safety-net, feature-tests, kernel-tests
---

## Characterisation Tests

Before changing any production line, pin what the code does **now**. A characterisation test asserts current behaviour, not intended behaviour. It is the only thing that tells you the refactor changed nothing.

### Rules

- Write them at whatever level works **today**: a Laravel Feature test with fakes, a Symfony `KernelTestCase` / `WebTestCase`, or a plain PHPUnit test that drives the real code with a temp directory. The point of the refactor is that a unit test isn't possible yet.
- Run them against the unchanged code and see them pass before the first edit. A test that has never passed against the old code proves nothing.
- Cover every observable effect the refactor could disturb: return value, exceptions (class and message), rows written, jobs/events/messages dispatched, mail sent, HTTP calls made, files written, cache keys, log records, and the order of side effects when it matters.
- Assert what the code actually returns, even if it looks wrong. If behaviour looks like a bug, keep it, add `// NOTE: current behaviour, possibly a bug: ...`, and report it. Fixing it is a separate change.
- Don't touch production code to make the characterisation test possible. If even that needs a change, stop and say so (see Troubleshooting in `SKILL.md`).
- If tests already cover the target, read them and run them. Add characterisation tests only for effects they miss.

**Incorrect:**

```php
// Written after the refactor, against the new seam: it can't detect a behaviour change
public function test_reminder_sends_mail(): void
{
    $mailer = $this->createMock(Mailer::class);
    $mailer->expects($this->once())->method('send');

    (new InvoiceReminder($mailer))->remind($invoice);
}
```

**Correct:**

```php
// Written first, against the unchanged code, at the level that works today
public function test_remind_overdue_invoice_queues_reminder_to_customer(): void
{
    Mail::fake();
    $this->travelTo(new DateTimeImmutable('2024-03-10 09:00:00'));
    $invoice = Invoice::factory()->for(Customer::factory()->state(['email' => 'ann@example.com']))
        ->create(['due_at' => '2024-03-01']);

    app(InvoiceReminder::class)->remind($invoice);

    Mail::assertQueued(InvoiceOverdue::class, fn (InvoiceOverdue $mail) => $mail->hasTo('ann@example.com'));
}
```

### Hard-to-Pin Values

The old code still reads the real clock, generates real IDs and calls real services. Freeze or fake them at the level that works now, without editing production code:

| Hidden dependency | Pin it in the characterisation test with |
|---|---|
| Laravel `now()`, `Carbon::now()`, `today()` | `$this->travelTo(...)` / `$this->freezeTime()` |
| Laravel `Str::uuid()`, `Str::orderedUuid()` | `Str::createUuidsUsing(...)` / `Str::freezeUuids()`, then `Str::createUuidsNormally()` |
| Laravel facades (`Mail`, `Http`, `Queue`, `Bus`, `Event`, `Storage`, `Notification`) | the facade's `fake()` |
| Symfony `Clock::get()` or the `clock` service | `use ClockSensitiveTrait;` then `static::mockTime('...')` |
| Symfony services you need to replace | `static::getContainer()->set(Id::class, $double)` before the SUT is fetched |
| `time()`, `date()`, `new DateTime()`, `rand()`, `uniqid()` | Can't be frozen. Assert on what doesn't depend on them (shape, length, relative order) and say so. |
| `curl_*`, `file_get_contents('https://...')` | Point the code at a local fixture if the URL is configurable; otherwise assert only the parts that don't need the network, and say so. |
| Filesystem | A temp directory created in `setUp()` and removed in `tearDown()` |

Don't rely on approximate assertions like `assertEqualsWithDelta(time(), ...)` to pin behaviour. If a value can't be pinned before the refactor, name it in the report as unverified.

### Keep or Delete Afterwards

Once proper unit tests exist, keep the characterisation tests that are good Feature/Kernel tests in their own right (one per end-to-end behaviour). Delete ones that only duplicate a unit test and say which you removed. Never delete one that is still the only test of an effect.
