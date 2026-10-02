#!/usr/bin/env bash
# Refactor each fixture target with and without the skill, then score the result: hidden
# characterisation tests, blocking calls left in the target, plain-TestCase unit tests, public API,
# new types, production diff size, and cost.
#
# Usage: evals/run.sh [all|laravel|symfony|<part of a target path>]   e.g. evals/run.sh Maintenance
# Env:   MODEL       model for claude -p (default: your claude default)
#        BUDGET_USD  spend cap per claude run (default 5)
#        WORK        scratch directory for the scaffolded apps (default evals/.work)
#        VARIANTS    which runs to make per target (default "baseline without with"; baseline is the
#                    untouched fixture, scored without calling claude)
#        JUDGE       0 skips the blind side-by-side review at the end (evals/judge.sh)
set -euo pipefail
# The copied skill's paths can pass Windows' 260-character limit inside a deep WORK directory.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.longpaths GIT_CONFIG_VALUE_0=true

root=$(cd "$(dirname "$0")/.." && pwd)
work=${WORK:-$root/evals/.work}
command -v cygpath > /dev/null && work=$(cygpath -u "$work")
budget=${BUDGET_USD:-5}
which=${1:-all}
stamp=$(date +%Y%m%d-%H%M%S)
results=$work/results/$stamp
mkdir -p "$results"
csv=$results/results.csv
echo "framework,target,variant,hidden_pass,hidden_tests,blocking_left,blocking_detail,unit_tests,unit_failed,suite_tests,suite_failed,api_kept,new_classes,new_interfaces,prod_files,prod_lines,cost_usd,turns,minutes" > "$csv"
echo "claude: $(command -v claude || echo 'not on PATH')"

scaffold() {
    local fw=$1 dir=$work/$1
    if [ ! -d "$dir/.git" ]; then
        rm -rf "$dir"
        case $fw in
            laravel)
                composer create-project -n --quiet laravel/laravel "$dir"
                echo "require __DIR__.'/eval.php';" >> "$dir/routes/web.php" ;;
            symfony)
                composer create-project -n --quiet symfony/skeleton "$dir"
                (cd "$dir" && composer require -n --quiet symfony/clock && composer require -n --quiet --dev symfony/test-pack)
                echo 'WAREHOUSE_FEED_URL=https://warehouse.example.test/feed.json' >> "$dir/.env" ;;
        esac
        (cd "$dir" && git init -q)
    fi

    # Copy fixtures on every run so edits reach an existing scaffold. Package changes still need a
    # fresh scaffold: delete $work/<framework>. The hidden tests are not copied until after the refactor.
    (cd "$dir" && if git rev-parse -q --verify HEAD > /dev/null; then git reset -q --hard && git clean -qfd; fi)
    cp -r "$root/evals/fixtures/$fw/." "$dir/"
    (cd "$dir" && git add -A 2>/dev/null \
        && { git diff --cached --quiet || git -c user.name=eval -c user.email=eval@localhost commit -qm baseline; })
}

run_one() {
    local fw=$1 target=$2 hidden=$3 variant=$4 dir=$work/$1 name prompt
    name=$fw-$(basename "$target" .php)-$variant
    # Paths below are relative to $dir: a native Windows php can't open MSYS /tmp/... paths.
    local out=../results/$stamp/$name

    (cd "$dir" && git reset -q --hard && git clean -qfd && rm -rf var/cache)
    (cd "$dir" && php "$root/evals/score.php" api "$target" > "$out.api-before.txt")

    echo "== $name"
    if [ "$variant" != baseline ]; then
        if [ "$variant" = with ]; then
            mkdir -p "$dir/.claude/skills"
            cp -r "$root"/skills/* "$dir/.claude/skills/"
            prompt="/refactor-for-testability $target"
        else
            prompt="Refactor $target so it can be unit tested, without changing its behaviour."
        fi
        # Prompt on stdin: as an argument, Git Bash rewrites "/refactor-for-testability" into a file path, and MSYS_NO_PATHCONV
        # would leak into Claude's own shell. --setting-sources project keeps user plugins and hooks out.
        (cd "$dir" && printf '%s' "$prompt" | claude -p --setting-sources project ${MODEL:+--model "$MODEL"} \
            --max-budget-usd "$budget" --no-session-persistence --permission-mode acceptEdits --output-format json \
            --allowedTools "Read,Write,Edit,Glob,Grep,Bash(php:*),Bash(vendor/bin/phpunit:*),Bash(vendor/bin/pest:*),Bash(vendor/bin/phpstan:*),Bash(vendor/bin/pint:*),Bash(vendor/bin/php-cs-fixer:*),Bash(bin/phpunit:*),Bash(bin/console:*),Bash(composer dump-autoload:*),Bash(git diff:*),Bash(git status:*),Bash(git restore:*),Bash(git checkout:*)" \
            > "$out.claude.json" 2> "$out.claude.err") || echo "   claude exited non-zero, see $results/$name.claude.err"
        (cd "$dir" && php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); file_put_contents($argv[2], $j["result"] ?? "");' \
            "$out.claude.json" "$out.claude.txt")
    fi
    (cd "$dir" && git add -A -- . ':!.claude' 2>/dev/null && git diff --cached -- . > "$out.diff")
    (cd "$dir" && php "$root/evals/score.php" api "$target" > "$out.api-after.txt" 2>&1) || true

    # PAO_DISABLE=1 turns off laravel/pao, which Laravel 13 skeletons ship: when it detects an AI agent
    # (CLAUDECODE, AI_AGENT, ...) it switches PHPUnit to JSON output. Scoring reads JUnit XML instead.
    # auto_prepend_file is cleared because Laravel Herd sets one.
    (cd "$dir" && PAO_DISABLE=1 php -d auto_prepend_file= vendor/bin/phpunit --log-junit "$out.agent.xml" > "$out.agent.txt" 2>&1) || true

    # Only now do the hidden characterisation tests enter the app.
    cp -r "$root/evals/hidden/$fw/." "$dir/"
    (cd "$dir" && PAO_DISABLE=1 php -d auto_prepend_file= vendor/bin/phpunit "tests/EvalHidden/$hidden.php" \
        --log-junit "$out.hidden.xml" > "$out.hidden.txt" 2>&1) || true

    (cd "$dir" && echo "$fw,$target,$variant,$(php "$root/evals/score.php" row "$target" "$out")") >> "$csv"
}

targets=$(cd "$root/evals" && php score.php targets)
for fw in laravel symfony; do
    selected=()
    while IFS='|' read -r tfw target hidden; do
        [ "$tfw" = "$fw" ] || continue
        [ "$which" = all ] || [ "$which" = "$fw" ] || [[ "$target" == *"$which"* ]] || continue
        selected+=("$target|$hidden")
    done <<< "$targets"
    [ ${#selected[@]} -gt 0 ] || continue
    scaffold "$fw"
    for entry in "${selected[@]}"; do
        for variant in ${VARIANTS:-baseline without with}; do
            run_one "$fw" "${entry%%|*}" "${entry#*|}" "$variant"
        done
    done
done

echo
column -s, -t < "$csv" 2>/dev/null || cat "$csv"
echo
echo "Logs, diffs and results.csv: $results"

# Blind side-by-side review of each target's two refactors (one extra claude -p call per target).
[ "${JUDGE:-1}" = 0 ] || bash "$root/evals/judge.sh" "$results"
