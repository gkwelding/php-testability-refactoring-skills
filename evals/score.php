<?php

// Targets and scoring for evals/run.sh. Run from inside the scratch app (relative paths, so a native
// Windows php can open them).
//
//   php score.php targets                 "framework|target|hidden test" per line
//   php score.php api <target>            public API of the target class, one line per method (reflection)
//   php score.php row <target> <run>      CSV fragment for one run, from <run>.* files written by run.sh
//   php score.php prod-diff <run>.diff    the production-code part of a diff (used by judge.sh)

// Calls a plain PHPUnit TestCase can't control without a booted framework or the real world.
// now(), Date::, Str::uuid() and Str::orderedUuid() are not listed: Carbon::setTestNow() and
// Str::createUuidsUsing() control them without the app, and the skill deliberately keeps them.
const BLOCKING = [
    'static:Cache', 'static:Http', 'static:Log', 'static:Mail', 'static:Storage', 'static:DB', 'static:Event',
    'static:Bus', 'static:Queue', 'static:Config', 'static:App', 'static:Notification', 'static:Redis',
    'static:Session', 'static:Auth',
    'fn:config', 'fn:app', 'fn:resolve', 'fn:cache', 'fn:logger', 'fn:env', 'fn:request', 'fn:session',
    'fn:time', 'fn:microtime', 'fn:hrtime', 'fn:date', 'fn:getenv', 'fn:rand', 'fn:mt_rand', 'fn:random_int', 'fn:uniqid',
    'var:$_SERVER', 'var:$_ENV', 'var:$_GET', 'var:$_POST', 'var:$_COOKIE', 'var:$_SESSION', 'var:$_REQUEST',
    'new:DateTime()', 'new:DateTimeImmutable()',
];

// target => [framework, class, hidden test, extra blocking calls specific to this target]
const TARGETS = [
    'app/Services/PaymentLinkService.php' => ['laravel', 'App\Services\PaymentLinkService', 'PaymentLinkServiceTest', []],
    'app/Services/OrderSummaryExporter.php' => ['laravel', 'App\Services\OrderSummaryExporter', 'OrderSummaryExporterTest',
        ['new:CurrencyConverter', 'static:ExchangeRateRegistry']],
    'src/Service/MaintenanceMode.php' => ['symfony', 'App\Service\MaintenanceMode', 'MaintenanceModeTest', []],
    'src/Service/ReorderPlanner.php' => ['symfony', 'App\Service\ReorderPlanner', 'ReorderPlannerTest', ['new:WarehouseFeed']],
];

[, $command] = $argv + [1 => ''];

match ($command) {
    'targets' => array_map(fn ($t, $m) => print("{$m[0]}|{$t}|{$m[2]}\n"), array_keys(TARGETS), TARGETS),
    'api' => print(implode("\n", api(TARGETS[$argv[2]][1]))."\n"),
    'row' => print(row($argv[2], $argv[3])."\n"),
    'prod-diff' => print(implode('', prodDiff((string) @file_get_contents($argv[2])))),
    default => fwrite(STDERR, "usage: php score.php targets|api|row|prod-diff ...\n"),
};

/** @return list<string> */
function api(string $class): array
{
    require 'vendor/autoload.php';
    if (!class_exists($class)) {
        return ['missing'];
    }
    $ref = new ReflectionClass($class);
    $lines = [$ref->isFinal() ? 'final class' : 'class'];
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isConstructor() || $m->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $params = array_map(fn (ReflectionParameter $p) => trim(($p->getType() ?? '').' '.($p->isPassedByReference() ? '&' : '')
            .($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : '')), $m->getParameters());
        $lines[] = ($m->isStatic() ? 'static ' : '').$m->getName().'('.implode(', ', $params).')'
            .($m->hasReturnType() ? ': '.$m->getReturnType() : '');
    }
    sort($lines);

    return $lines;
}

function row(string $target, string $run): string
{
    [, , $hiddenTest, $extra] = TARGETS[$target];
    $diff = (string) @file_get_contents("$run.diff");

    // Behaviour kept: the hidden characterisation tests, run after the refactor.
    $hidden = @simplexml_load_file("$run.hidden.xml");
    $hiddenTotal = preg_match_all('/function test_/', (string) @file_get_contents("tests/EvalHidden/$hiddenTest.php"));
    $hiddenPass = $hidden
        ? (int) $hidden->testsuite['tests'] - (int) $hidden->testsuite['failures'] - (int) $hidden->testsuite['errors'] - (int) $hidden->testsuite['skipped']
        : 0;

    // Testability: blocking calls left in the target, and agent-written plain TestCase tests that pass.
    $left = [];
    // A target-specific collaborator built in the constructor (`$converter ?? new CurrencyConverter(...)`)
    // is a default a test can override, so those only count outside __construct.
    foreach (calls((string) @file_get_contents($target)) as [$call, $inConstructor]) {
        if (in_array($call, BLOCKING, true) || (!$inConstructor && in_array($call, $extra, true))) {
            $left[$call] = ($left[$call] ?? 0) + 1;
        }
    }
    $leftDetail = $left === [] ? "-" : implode(' ', array_map(fn ($c, $n) => preg_replace('/^\w+:/', '', $c).($n > 1 ? "x$n" : ''), array_keys($left), $left));

    $agent = @simplexml_load_file("$run.agent.xml");
    $suiteTests = $agent ? (int) $agent->testsuite['tests'] : '';
    $suiteFailed = $agent ? (int) $agent->testsuite['failures'] + (int) $agent->testsuite['errors'] : '';

    $short = basename($target, '.php');
    $unitTests = $unitFailed = 0;
    foreach (addedFiles($diff) as $path => $code) {
        $plain = preg_match('/^use PHPUnit\\\\Framework\\\\TestCase;/m', $code) && preg_match('/extends\s+TestCase\b/', $code)
            || preg_match('/extends\s+\\\\?PHPUnit\\\\Framework\\\\TestCase\b/', $code);
        if (!str_starts_with($path, 'tests/') || !str_contains($code, $short) || !$plain) {
            continue;
        }
        foreach ($agent ? $agent->xpath('//testsuite[@file]') : [] as $suite) {
            if (str_ends_with(str_replace('\\', '/', (string) $suite['file']), $path)) {
                $unitTests += (int) $suite['tests'];
                $unitFailed += (int) $suite['failures'] + (int) $suite['errors'];
            }
        }
    }

    // Restraint: new types, public API, production diff size.
    $newClasses = $newInterfaces = 0;
    foreach (addedFiles($diff) as $path => $code) {
        if (str_starts_with($path, 'tests/')) {
            continue;
        }
        $tokens = token_get_all($code);
        foreach ($tokens as $i => $t) {
            $prev = prevToken($tokens, $i);
            if (is_array($t) && in_array($t[0], [T_CLASS, T_TRAIT, T_ENUM], true) && !in_array($prev, [T_NEW, T_DOUBLE_COLON], true)) {
                $newClasses++;
            }
            if (is_array($t) && $t[0] === T_INTERFACE) {
                $newInterfaces++;
            }
        }
    }

    $before = array_filter(explode("\n", (string) @file_get_contents("$run.api-before.txt")));
    $after = array_filter(explode("\n", (string) @file_get_contents("$run.api-after.txt")));
    $apiKept = $before === [] ? '' : (int) (array_diff($before, $after) === []);

    $prodLines = 0;
    $prodFiles = [];
    foreach (prodDiff($diff) as $path => $chunk) {
        $prodFiles[$path] = true;
        $prodLines += preg_match_all('/^[+-](?![+-]{2} )/m', $chunk);
    }

    $claude = json_decode((string) @file_get_contents("$run.claude.json"), true) ?? [];
    $cost = isset($claude['total_cost_usd']) ? round($claude['total_cost_usd'], 2) : '';
    $minutes = isset($claude['duration_ms']) ? round($claude['duration_ms'] / 60000, 1) : '';

    return implode(',', [
        $hiddenPass, $hiddenTotal, array_sum($left), $leftDetail, $unitTests, $unitFailed, $suiteTests, $suiteFailed,
        $apiKept, $newClasses, $newInterfaces, count($prodFiles), $prodLines, $cost, $claude['num_turns'] ?? '', $minutes,
    ]);
}

/**
 * Calls in PHP source as [call, inConstructor], where call is "static:Name", "fn:name", "new:Name"
 * (plus "new:Name()" when built with no arguments or 'now') or "var:$_X".
 */
function calls(string $code): array
{
    $tokens = array_values(array_filter(token_get_all($code), fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $calls = [];
    $depth = $attr = 0;
    $ctor = null; // true from "function __construct" to its "{", then the brace depth of its body
    foreach ($tokens as $i => $t) {
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            $ctor === true && $ctor = $depth;
        } elseif ($t === '}') {
            $ctor === $depth && $ctor = null;
            $depth--;
        } elseif (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === '__construct') {
            $ctor = true;
        }
        // Attributes (#[Config('x')], #[Autowire(env: 'X')]) are wiring, not calls.
        if (is_array($t) && $t[0] === T_ATTRIBUTE) {
            $attr = 1;
            continue;
        }
        if ($attr > 0) {
            $t === '[' && $attr++;
            $t === ']' && $attr--;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }
        $in = $ctor !== null;
        $next = $tokens[$i + 1] ?? null;
        $prev = $tokens[$i - 1] ?? null;
        $prevId = is_array($prev) ? $prev[0] : $prev;
        $name = in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? substr(strrchr('\\'.$t[1], '\\'), 1) : null;

        if ($t[0] === T_VARIABLE && str_starts_with($t[1], '$_')) {
            $calls[] = ['var:'.$t[1], $in];
        } elseif ($name !== null && $prevId === T_NEW) {
            $args = array_map(fn ($x) => is_array($x) ? $x[1] : $x, array_slice($tokens, $i + 1, 3));
            $empty = ($args[0] ?? '') !== '(' || ($args[1] ?? '') === ')' || (strtolower(trim($args[1] ?? '', '\'"')) === 'now' && ($args[2] ?? '') === ')');
            $calls[] = ['new:'.$name, $in];
            $empty && $calls[] = ['new:'.$name.'()', $in];
        } elseif ($name !== null && is_array($next) && $next[0] === T_DOUBLE_COLON) {
            $after = $tokens[$i + 2] ?? null;
            if (!is_array($after) || $after[0] !== T_CLASS) {
                $calls[] = ['static:'.$name, $in];
            }
        } elseif ($name !== null && $next === '(' && !in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
            $calls[] = ['fn:'.strtolower($name), $in];
        }
    }

    return $calls;
}

function prevToken(array $tokens, int $i): int|string|null
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }
    }

    return null;
}

/** @return array<string, string> path => full content of files the diff creates */
function addedFiles(string $diff): array
{
    $files = [];
    foreach (preg_split('/^diff --git /m', $diff) as $chunk) {
        if (str_contains($chunk, "\n--- /dev/null\n") && preg_match('#^\+\+\+ b/(.+\.php)$#m', $chunk, $m)) {
            preg_match_all('/^\+(?!\+\+ )(.*)$/m', $chunk, $lines);
            $files[$m[1]] = implode("\n", $lines[1])."\n";
        }
    }

    return $files;
}

/** @return array<string, string> path => diff chunk, for production files (not tests, lock files or the copied skills) */
function prodDiff(string $diff): array
{
    $out = [];
    foreach (preg_split('/^(?=diff --git )/m', $diff) as $chunk) {
        if (preg_match('#^diff --git a/(\S+)#', $chunk, $m) && !preg_match('#^(tests/|\.claude/|.*\.lock$|var/|storage/)#', $m[1])) {
            $out[$m[1]] = $chunk;
        }
    }

    return $out;
}
