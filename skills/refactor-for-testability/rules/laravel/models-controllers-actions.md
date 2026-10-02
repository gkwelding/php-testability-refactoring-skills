---
title: Laravel Models, Controllers and Actions
tags: laravel, eloquent, controllers, actions, repositories, extraction
---

## Laravel Models, Controllers and Actions

### Don't Hide Eloquent to Make It Mockable

Queries are best tested against a real database (`RefreshDatabase` + factories). Wrapping `Order::where(...)` in an `OrderRepositoryInterface` just so a unit test can mock it adds a layer, a binding and a mock that re-states the query, and the query itself is still untested.

- Leave `Model::query()`, relationships, scopes and `DB::transaction()` where they are.
- Extract the **decision or calculation** that sits between the queries into a class that takes plain values or models, and unit test that.
- Keep a repository only if the project already uses the pattern for that model.

### Controller → Action / Calculator

Keep HTTP concerns in the controller: Form Request validation, authorisation, the response, redirects, flash messages, and the facades used for them. Move the domain logic into a class the controller receives by method injection.

**Incorrect:**

```php
public function store(StoreOrderRequest $request): RedirectResponse
{
    $subtotal = collect($request->validated('lines'))->sum(fn (array $line) => $line['qty'] * $line['unit_price']);
    $discount = $request->user()->created_at->lt(now()->subYears(2)) ? intdiv($subtotal, 20) : 0;
    $order = $request->user()->orders()->create(['subtotal' => $subtotal, 'discount' => $discount]);

    Mail::to($request->user())->send(new OrderPlaced($order));

    return redirect()->route('orders.show', $order);
}
```

**Correct:**

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use Carbon\CarbonInterface;

final class PriceOrder
{
    /**
     * @param list<array{qty: int, unit_price: int}> $lines
     * @return array{subtotal: int, discount: int}
     */
    public function __invoke(array $lines, CarbonInterface $customerSince): array
    {
        $subtotal = collect($lines)->sum(fn (array $line) => $line['qty'] * $line['unit_price']);
        $discount = $customerSince->lt(now()->subYears(2)) ? intdiv($subtotal, 20) : 0;

        return ['subtotal' => $subtotal, 'discount' => $discount];
    }
}
```

```php
public function store(StoreOrderRequest $request, PriceOrder $priceOrder): RedirectResponse
{
    $order = $request->user()->orders()->create($priceOrder($request->validated('lines'), $request->user()->created_at));

    Mail::to($request->user())->send(new OrderPlaced($order));

    return redirect()->route('orders.show', $order);
}
```

The calculation lines moved unchanged apart from the inputs. `collect()` and `now()` work in a plain `PHPUnit\Framework\TestCase` (freeze time with `Illuminate\Support\Carbon::setTestNow()`), so `PriceOrder` is unit testable without injecting anything else. The existing Feature test still covers the controller, the insert and the mail.

### Moving Logic Out of Models

- Methods that only compute from attributes (`$order->isOverdue()`, `$invoice->totalWithTax()`) are already unit testable with `new Order([...])` / `Order::factory()->make()`, as long as they don't query. Leave them.
- A model method that queries and decides: split the query (stays on the model or a scope) from the decision (a method taking the query result).
- Keep persistence going through Eloquent (`save()`, `create()`, `update()`). Replacing it with `DB::table()->insert()` or `saveQuietly()` stops model events, observers, casts, timestamps and `$fillable` protection, which is a behaviour change.
- Moving `Order::create($request->validated())` into an Action keeps mass-assignment rules only if the Action still goes through the model. Don't switch to `forceCreate()` or `unguarded()`.

### Action Conventions

Laravel has no Action base class. Follow the project's existing convention (folder, `handle()` vs `execute()` vs `__invoke()`); if there is none, use `app/Actions` and one public method. Inject collaborators through the constructor, pass request data as arguments. Don't pass the `Request` object into an Action.
