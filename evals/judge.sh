#!/usr/bin/env bash
# Blind side-by-side review of the production-code changes the two variants made for each target in
# a results folder. The refactors are shown as A and B in random order; verdicts are mapped back.
#
# Usage: evals/judge.sh <results folder>     (run.sh calls this at the end unless JUDGE=0)
# Env:   MODEL             model for claude -p (default: your claude default)
#        JUDGE_BUDGET_USD  spend cap per comparison (default 1)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$1" # relative paths from here also work with a native Windows php
budget=${JUDGE_BUDGET_USD:-1}

criteria=(minimal behaviour idiomatic overall)
props=""
for c in "${criteria[@]}"; do
    props+="\"$c\":{\"type\":\"object\",\"properties\":{\"winner\":{\"type\":\"string\",\"enum\":[\"A\",\"B\",\"tie\"]},\"reason\":{\"type\":\"string\"}},\"required\":[\"winner\",\"reason\"]},"
done
required=$(printf '"%s",' "${criteria[@]}")
schema="{\"type\":\"object\",\"properties\":{${props%,}},\"required\":[${required%,}]}"

# a_was says which variant was shown as A; the reasons refer to the refactors as A and B.
echo "framework,target,criterion,winner,a_was,reason" > judge.csv

tail -n +2 results.csv | cut -d, -f1,2 | sort -u | while IFS=, read -r fw target; do
    name=$fw-$(basename "$target" .php)
    [ -f "$name-with.diff" ] && [ -f "$name-without.diff" ] || continue

    # Random order, so position can't favour a variant.
    if (( RANDOM % 2 )); then a=with b=without; else a=without b=with; fi

    {
        cat <<'EOF'
Two refactors, A and B, were made independently to the same PHP class. Each was asked to make the class unit testable without changing its behaviour. Compare only the production-code changes shown (tests are left out).

For each criterion, pick A, B or tie, and give one sentence that cites something specific in the diffs:
- minimal: the smaller change that still makes the class unit testable. No speculative interfaces, layers, files or renames; an interface only at a real seam (I/O, a third-party API, the clock, randomness).
- behaviour: less risk of changing what the code does for its callers and the app: same results, exceptions and their timing, config reads, defaults, container wiring, public signatures.
- idiomatic: uses the framework's own abstractions (contracts, PSR interfaces, autowiring) the way a maintainer of this app would expect.
- overall: the refactor you would rather merge.

Judge quality, not quantity: a bigger change is not better by itself.
EOF
        echo
        echo "=== Code before the refactor ==="
        echo "// $target"
        cat "$root/evals/fixtures/$fw/$target"
        # The app classes the target imports (collaborators, registries, final classes).
        case $fw in laravel) base=app ;; symfony) base=src ;; esac
        { grep -oE '^use App\\[A-Za-z\\]+;' "$root/evals/fixtures/$fw/$target" || true; } | sed -E 's/^use App\\//; s/;$//; s#\\#/#g' |
            while read -r class; do
                f=$root/evals/fixtures/$fw/$base/$class.php
                [ -f "$f" ] && { echo; echo "// $base/$class.php"; cat "$f"; }
            done
        echo
        echo "=== Refactor A ==="
        php "$root/evals/score.php" prod-diff "$name-$a.diff"
        echo "=== Refactor B ==="
        php "$root/evals/score.php" prod-diff "$name-$b.diff"
    } > "$name.judge-prompt.txt"

    echo "== judging $name (A = $a)"
    claude -p --tools "" --output-format json --json-schema "$schema" --max-budget-usd "$budget" \
        --no-session-persistence ${MODEL:+--model "$MODEL"} \
        < "$name.judge-prompt.txt" > "$name.judge.json" 2> "$name.judge.err" || echo "   judge failed, see $name.judge.err"

    php -r '
        [, $json, $a, $b, $fw, $target] = $argv;
        $verdict = json_decode((string) @file_get_contents($json), true)["structured_output"] ?? [];
        $out = fopen("judge.csv", "a");
        foreach ($verdict as $criterion => $v) {
            $winner = ["A" => $a, "B" => $b][$v["winner"]] ?? "tie";
            fputcsv($out, [$fw, $target, $criterion, $winner, $a, $v["reason"]], escape: "");
        }
    ' "$name.judge.json" "$a" "$b" "$fw" "$target"
done

php -r '
    $rows = array_map(fn ($l) => str_getcsv($l, escape: ""), array_slice(file("judge.csv", FILE_IGNORE_NEW_LINES), 1));
    $tally = [];
    foreach ($rows as [, , $criterion, $winner]) {
        $tally[$criterion][$winner] = ($tally[$criterion][$winner] ?? 0) + 1;
    }
    printf("\n%-14s %5s %8s %4s\n", "criterion", "with", "without", "tie");
    foreach ($tally as $criterion => $t) {
        printf("%-14s %5d %8d %4d\n", $criterion, $t["with"] ?? 0, $t["without"] ?? 0, $t["tie"] ?? 0);
    }
'
echo
echo "Verdicts with reasons: $1/judge.csv"
