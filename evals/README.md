# Evals

Checks whether `refactor-for-testability` makes classes unit testable with smaller, safer changes than a plain prompt, and which rule changes help or hurt.

For each target, `run.sh` scaffolds a fresh Laravel or Symfony app, adds the fixture code, and asks `claude -p` to refactor the target twice:

| Variant | Prompt |
|---|---|
| `without` | "Refactor `<target>` so it can be unit tested, without changing its behaviour." |
| `with` | `/refactor-for-testability <target>`, with this repo's `skills/` copied into the app's `.claude/skills/` |
| `baseline` | No agent: the untouched fixture, scored the same way. It shows the starting values and proves the hidden tests pass before any refactor |

After each run it copies in the hidden characterisation tests, runs them, and writes one row per run to `results.csv`:

| Column | Meaning | Why |
|---|---|---|
| `hidden_pass`, `hidden_tests` | Hidden characterisation tests passing, out of the total. They live in `hidden/`, go through the public entry point (an HTTP route, as a feature or `WebTestCase` test), and are copied into the app only after the agent has finished, so it can't see or edit them | Behaviour kept. Anything below `hidden_tests` is a regression |
| `blocking_left`, `blocking_detail` | Calls left in the target file that a plain `PHPUnit\Framework\TestCase` can't control: facades (`Cache::`, `Http::`, `Log::`, ...), `config()` / `app()` / `resolve()`, `time()` / `date()` / `random_int()` / no-argument `new DateTime*()`, superglobals, plus each target's own hidden collaborators (`new CurrencyConverter`, `ExchangeRateRegistry::`, `new WarehouseFeed`). Counted with PHP's tokenizer, so comments and `::class` don't count | Testability gained. `now()`, `Date::` and `Str::uuid()` aren't counted: `Carbon::setTestNow()` and `Str::createUuidsUsing()` control them without the app, and the skill deliberately keeps them. A target-specific collaborator built in the constructor (`$converter ?? new CurrencyConverter(...)`) is a default a test can override, so those count only outside `__construct` |
| `unit_tests`, `unit_failed` | Tests in new test files the agent wrote that extend plain `PHPUnit\Framework\TestCase` and name the target, and how many of them fail | Proof the target can now be built with doubles and exercised without a booted framework. A facade or `config()` call reached from such a test errors ("facade root has not been set") |
| `suite_tests`, `suite_failed` | The app's whole suite after the refactor (the agent's tests plus the skeleton's), before the hidden tests are copied in | The agent's own tests pass |
| `api_kept` | 1 if every public method of the target (except the constructor) keeps its exact signature, parameter names and defaults included, and `final` stays as it was. From reflection before and after | Callers keep working. The constructor may change: the hidden tests check callers and container wiring still work |
| `new_classes`, `new_interfaces` | Classes, traits, enums and interfaces declared in new production files | Restraint. Fewer is better unless it sits at a real seam: `ReorderPlanner` needs one interface (its collaborator is `final` and does I/O), the other targets need none |
| `prod_files`, `prod_lines` | Production files changed and lines added plus removed, outside `tests/` | Restraint: the smallest diff that works |
| `cost_usd`, `turns`, `minutes` | From `claude -p --output-format json` | What the result cost |

Scoring runs with `PAO_DISABLE=1` and reads JUnit XML. Laravel 13 skeletons ship `laravel/pao`, which switches PHPUnit to JSON output whenever it detects an AI agent (`CLAUDECODE`, `AI_AGENT` and others, so any run started from Claude Code). `claude -p` itself still runs with pao, as it would in a real Laravel 13 project.

There is no harness-owned "build it by reflection and call it" probe. A generic probe can construct the class from stubs, but it can't know what each stub should return, so calling the method either fails for reasons unrelated to testability or proves nothing; and code reading `$_SERVER` or `time()` runs fine in a plain `TestCase` while still being untestable. The tokenizer count plus the agent's own passing plain-`TestCase` tests measure the same thing without guessing.

## Blind review

At the end of a run, `judge.sh` asks one tool-less `claude -p` per target to compare the two production diffs. It sees the code before the refactor (with the app classes it imports) and each variant's changes outside `tests/`, labelled A and B in random order, and picks A, B or tie for:

| Criterion | Question |
|---|---|
| `minimal` | The smaller change that still makes the class unit testable; an interface only at a real seam |
| `behaviour` | Less risk of changing results, exceptions and their timing, config reads, defaults, wiring or signatures |
| `idiomatic` | Uses the framework's own abstractions the way a maintainer of the app would expect |
| `overall` | The refactor you would rather merge |

Verdicts, mapped back to `with` / `without`, go to `judge.csv` with a one-sentence reason each, and a tally is printed. `JUDGE=0 evals/run.sh` skips it; `evals/judge.sh evals/.work/results/<timestamp>` re-judges a saved run (`JUDGE_BUDGET_USD`, default 1, caps each comparison).

## Targets

| Fixture | Hidden dependencies | What the hidden tests pin |
|---|---|---|
| Laravel `PaymentLinkService` (behind `POST /payment-links`) | `Cache::`, `Http::`, `Log::`, `config()`, plus `now()` and `Str::uuid()` that can stay | Exact provider request (URL, token, payload, upper-cased currency), expiry rounded to the minute with `travelTo()`, IDs from `Str::createUuidsUsingSequence()`, config read on each call, cache reuse until the link expires, 502 and a warning log on a provider failure with nothing cached. A refactor that injects a clock the framework's `travelTo()` doesn't move, or generates IDs outside `Str`, breaks existing feature tests and fails here |
| Laravel `OrderSummaryExporter` (behind `POST /reports/summary/{currency}`, which builds it with `new`) | `new CurrencyConverter(ExchangeRateRegistry::instance())` in the method; `new MoneyFormatter()` is a pure helper that should stay | CSV output, half-up rounding both ways, skipped currencies, refunds, and that rates set on the shared registry are used. Changing the constructor without keeping `new OrderSummaryExporter()` working breaks the controller |
| Symfony `MaintenanceMode` (behind `GET /status`) | `$_SERVER['MAINTENANCE_FILE']`, `time()`; the local file read can stay and be tested with a temp file | Inactive without a file, 503 with `Retry-After` inside a window, default message, past and future windows, 500 on malformed JSON, the `var/maintenance.json` fallback, and the file re-read on each request (not cached in the constructor) |
| Symfony `ReorderPlanner` (behind `POST /reorders`) | `new WarehouseFeed($url)`, a `final` class doing I/O. The right seam is a small interface for it, keeping `final` | Orders up to par in whole packs, sort order with ties, nothing to order, 503 on an unreadable feed, 500 on a malformed one, `WAREHOUSE_FEED_URL` still wired |

Add a target by dropping its files under `fixtures/<framework>/` (paths mirror the app), its hidden test under `hidden/<framework>/tests/EvalHidden/`, and a line in `TARGETS` in `score.php`.

## Running

Needs `composer`, `git` and the `claude` CLI.

```
evals/run.sh                     # all four targets
evals/run.sh symfony             # one framework
evals/run.sh Maintenance         # targets whose path contains "Maintenance"
MODEL=claude-sonnet-5-5 BUDGET_USD=3 evals/run.sh laravel
VARIANTS=with JUDGE=0 evals/run.sh PaymentLinkService
```

The first run scaffolds the apps into `evals/.work/` (gitignored) and reuses them afterwards. Fixture edits are copied in on every run; delete a framework's folder to rebuild it after changing its packages. Each run writes `results.csv` plus per-run logs, diffs, JUnit files and the agent's final message to `evals/.work/results/<timestamp>/`.

**Cost:** 8 `claude -p` runs for `all`, each capped by `BUDGET_USD` (default 5), plus 4 judge calls capped by `JUDGE_BUDGET_USD` (default 1). `baseline` makes no call. Claude can only edit files and run `php`, the test, lint and static-analysis binaries in `vendor/bin`, `bin/console`, `composer dump-autoload`, and `git diff` / `status` / `restore` / `checkout` in the scratch app. It can't `composer require`, so the Symfony app ships with `symfony/clock` installed.

**Noise:** results vary between runs. Run each variant a few times before trusting a difference, and compare like with like (same model, same framework versions). Current `laravel/laravel` and `symfony/skeleton` releases ship a `CLAUDE.md`/`AGENTS.md`; they apply to both variants equally.

### Windows

Run it from Git Bash. The script handles these:

- Git Bash rewrites `/refactor-for-testability ...` into a file path when passing it to a native exe, so `claude` runs with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`.
- A native Windows `php` can't open MSYS paths like `/tmp/...`, so every path given to `php` is relative to the app (the script `cd`s first). `WORK` may be a Windows or MSYS path; it's normalised with `cygpath -u`.
- The copied skill's paths can pass the 260-character limit in a deep `WORK` directory, so git runs with `core.longpaths=true` and `.claude/` is left out of the scored diff.
- Laravel Herd sets an `auto_prepend_file`; scoring clears it.

### Testing the harness without API spend

`stub/claude` stands in for the CLI: it applies a canned refactor from `stub/refactors/<Target>/` and prints a `claude -p` style JSON result (and a fixed verdict for judge calls). Put it first on `PATH` and check it's the one found before running, or you'll spend real money:

```
export PATH="$(cygpath -u "$PWD/evals/stub"):$PATH"   # plain "$PWD/evals/stub" outside Windows; a C:/ path breaks PATH on its colon
command -v claude                                       # must print .../evals/stub/claude
STUB_MODE=good evals/run.sh          # behaviour-preserving refactors: every hidden test passes, blocking_left 0
STUB_MODE=behaviour evals/run.sh     # one behaviour change per target: hidden_pass drops on every target
STUB_MODE=interface evals/run.sh     # an extra interface per touched class: new_interfaces and prod_lines rise
```

With the stub, the scores moved as they should:

| Stub | PaymentLinkService | OrderSummaryExporter | MaintenanceMode | ReorderPlanner |
|---|---|---|---|---|
| `baseline` (no refactor) | 8/8 hidden, 8 blocking | 7/7, 2 blocking | 8/8, 2 blocking | 5/5, 1 blocking |
| `good` | 8/8, 0 blocking, 0 interfaces | 7/7, 0, 0 | 8/8, 0, 0 | 5/5, 0, 1 |
| `behaviour` | 5/8 | 6/7 | 7/8 (and its unit test fails) | 4/5 |
| `interface` | 1 interface, 38 lines (was 29) | 1, 19 (was 10) | 1, 25 (was 16) | 3, 45 (was 29) |

## First result (one sample)

`BUDGET_USD=3 evals/run.sh PaymentLinkService` on Laravel 13.34, PHP 8.5, Claude Code 2.1.287 with its default model. **This is one sample of one target, not a benchmark.** It shows the harness works end to end, not that either variant is better.

| variant | hidden | blocking left | plain unit tests | suite | api kept | new types | prod lines | cost | turns | minutes |
|---|---|---|---|---|---|---|---|---|---|---|
| baseline | 8/8 | 8 (`Cache` x2, `config` x3, `Http`, `Log` x2) | 0 | 2 | yes | 0 | 0 | | | |
| without | 8/8 | 0 | 3, all pass | 5, all pass | yes | 0 | 30 | $0.47 | 14 | 3.9 |
| with | 8/8 | 0 | 5, all pass | 12, all pass | yes | 0 | 30 | $0.80 | 18 | 2.0 |

Both kept behaviour and removed every blocking call in the same number of lines, with no new types. `with` also wrote and ran 5 characterisation feature tests before changing code, as the skill asks; `without` wrote only unit tests. `without` injected `Illuminate\Support\DateFactory` for `now()`; `with` kept `now()` and read the three settings through `#[Config]` constructor attributes.

Blind review: `with` won `minimal` (the extra `DateFactory` isn't needed) and `idiomatic` (`#[Config]`); `without` won `behaviour` and `overall`. Its reason was that `#[Config]` reads config once, when the service is built, and types the values narrowly, so a runtime `config()->set()` on an already-resolved instance is ignored. The hidden tests can't see that, because the service is resolved fresh on each request. Total spend: $1.47 ($0.47 + $0.80 + $0.20 judge).

This run also found a scorer bug: the first count read the `#[Config(...)]` attributes as three `config()` calls. Attributes are now skipped, and the row above is the corrected score for the same saved run.
