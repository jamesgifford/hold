<?php

declare(strict_types=1);

use JamesGifford\Hold\Announcements\Announcer;

/*
 * Drift guards.
 *
 * Every check here derives its expectation from the SOURCE and asserts the docs,
 * config and stubs agree with it — rather than restating a list that would itself
 * drift. Each guard carries an explicit allowlist, empty by default, so any
 * exception has to be added deliberately and is visible in review.
 *
 * These run without an application on purpose: they read files from disk, so
 * they stay fast and cannot be masked by runtime config.
 */

/**
 * Deliberate exceptions to the guards below, keyed by guard.
 *
 * Every list is empty on purpose: an exception has to be added here explicitly,
 * which puts it in the diff and in front of a reviewer rather than letting a
 * guard quietly stop guarding.
 *
 * @return list<string>
 */
function holdAllowlist(string $guard): array
{
    // Typed here rather than inline on constants: an empty array literal is
    // otherwise inferred as array{}, which makes every lookup below provably
    // false and hides the fact that the guard is doing real work.
    /** @var array<string, list<string>> $allowlists */
    $allowlists = [
        // Commands that may exist in code without appearing in the docs.
        'undocumented-commands' => [],
        // Command flags that may exist in code without appearing in the docs.
        'undocumented-flags' => [],
        // Config keys the code may read without the shipped config defining them.
        'undefined-config-reads' => [],
        // Config keys the shipped config may define without anything reading them.
        'unread-config-keys' => [],
        // Config keys that may exist without being documented in the README.
        'undocumented-config' => [],
    ];

    return $allowlists[$guard] ?? [];
}

function holdRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, string> path => contents
 */
function holdFiles(string $subPath, string $pattern): array
{
    $files = [];

    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(holdRoot().'/'.$subPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($directory as $file) {
        if ($file->isFile() && preg_match($pattern, $file->getFilename()) === 1) {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

function holdDoc(string $name): string
{
    return (string) file_get_contents(holdRoot().'/'.$name);
}

/**
 * Extract a `## Heading` section from a markdown document.
 */
function holdDocSection(string $markdown, string $heading): string
{
    $pattern = '/^##\s+'.preg_quote($heading, '/').'\s*$(.*?)(?=^##\s|\z)/ms';

    return preg_match($pattern, $markdown, $m) === 1 ? $m[1] : '';
}

/**
 * The authoritative command inventory, parsed from the $signature properties.
 *
 * @return array<string, list<string>> command name => flags (e.g. '--force')
 */
function holdCommandInventory(): array
{
    $inventory = [];

    foreach (holdFiles('src/Console/Commands', '/\.php$/') as $contents) {
        if (preg_match('/\$signature\s*=\s*\'(.*?)\';/s', $contents, $m) !== 1) {
            continue;
        }

        $signature = $m[1];

        preg_match('/\A\s*([^\s{]+)/', $signature, $nameMatch);
        $name = $nameMatch[1] ?? '';

        preg_match_all('/\{--([a-z][a-z0-9-]*)/i', $signature, $flagMatches);

        $inventory[$name] = array_map(static fn (string $f) => '--'.$f, $flagMatches[1]);
    }

    ksort($inventory);

    return $inventory;
}

/**
 * Flatten an array to dot keys, treating a list as a leaf value.
 *
 * @param  array<array-key, mixed>  $array
 * @return list<string>
 */
function holdFlattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            $keys = array_merge($keys, holdFlattenKeys($value, $dotted));

            continue;
        }

        $keys[] = $dotted;
    }

    return $keys;
}

/**
 * @return list<string>
 */
function holdDefinedConfigKeys(): array
{
    /** @var array<string, mixed> $config */
    $config = require holdRoot().'/config/hold.php';

    return holdFlattenKeys($config);
}

/**
 * Config keys the runtime actually reads, as literal keys and as prefixes for
 * interpolated reads such as `notifications.classes.{$key}`.
 *
 * @return array{keys: list<string>, prefixes: list<string>}
 */
function holdConfigReads(): array
{
    $sources = array_merge(
        holdFiles('src', '/\.php$/'),
        holdFiles('resources/views', '/\.php$/'),
        holdFiles('routes', '/\.php$/'),
        holdFiles('stubs', '/\.stub$/'),
    );

    $keys = [];
    $prefixes = [];

    foreach ($sources as $contents) {
        preg_match_all('/config\(\s*[\'"]jamesgifford\.hold\.([^\'"]+)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $key) {
            if (str_contains($key, '{$')) {
                $prefixes[] = rtrim(substr($key, 0, strpos($key, '{$') ?: 0), '.');

                continue;
            }

            $keys[] = $key;
        }
    }

    return ['keys' => array_values(array_unique($keys)), 'prefixes' => array_values(array_unique($prefixes))];
}

// --- Commands ---------------------------------------------------------------

it('finds every command in the source, so the guards below have something to check', function () {
    // A parser that silently matched nothing would make every command guard pass
    // vacuously, which is exactly the failure mode these tests exist to prevent.
    expect(holdCommandInventory())->toHaveCount(6);
});

it('documents every command that exists in the code', function () {
    $readme = holdDocSection(holdDoc('README.md'), 'Commands');
    $skill = holdDocSection(holdDoc('resources/boost/skills/jamesgifford-hold/SKILL.md'), 'Commands');

    expect($readme)->not->toBe('')->and($skill)->not->toBe('');

    $missing = [];

    foreach (array_keys(holdCommandInventory()) as $command) {
        if (in_array($command, holdAllowlist('undocumented-commands'), true)) {
            continue;
        }

        if (! str_contains($readme, $command)) {
            $missing[] = "README Commands section is missing {$command}";
        }

        if (! str_contains($skill, $command)) {
            $missing[] = "SKILL.md Commands section is missing {$command}";
        }
    }

    expect($missing)->toBe([]);
});

it('documents every command flag that exists in the code', function () {
    $readme = holdDocSection(holdDoc('README.md'), 'Commands');
    $skill = holdDocSection(holdDoc('resources/boost/skills/jamesgifford-hold/SKILL.md'), 'Commands');

    $missing = [];

    foreach (holdCommandInventory() as $command => $flags) {
        foreach ($flags as $flag) {
            if (in_array("{$command} {$flag}", holdAllowlist('undocumented-flags'), true)) {
                continue;
            }

            if (! str_contains($readme, $flag)) {
                $missing[] = "README Commands section is missing {$command} {$flag}";
            }

            if (! str_contains($skill, $flag)) {
                $missing[] = "SKILL.md Commands section is missing {$command} {$flag}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('references no command that does not exist', function () {
    $known = array_keys(holdCommandInventory());
    $referenced = [];

    foreach (['README.md', 'CHANGELOG.md', 'resources/boost/skills/jamesgifford-hold/SKILL.md'] as $doc) {
        preg_match_all('/jamesgifford:hold:[a-z-]+/', holdDoc($doc), $matches);

        foreach ($matches[0] as $name) {
            $referenced[$name][] = $doc;
        }
    }

    $phantom = array_values(array_diff(array_keys($referenced), $known));

    expect($phantom)->toBe([]);
});

// --- Config keys ------------------------------------------------------------

it('defines every config key the code reads', function () {
    $defined = holdDefinedConfigKeys();
    $reads = holdConfigReads();

    expect($reads['keys'])->not->toBe([]);

    $undefined = [];

    foreach ($reads['keys'] as $key) {
        if (in_array($key, holdAllowlist('undefined-config-reads'), true) || in_array($key, $defined, true)) {
            continue;
        }

        $undefined[] = $key;
    }

    foreach ($reads['prefixes'] as $prefix) {
        $covered = array_filter($defined, static fn (string $k) => str_starts_with($k, $prefix.'.'));

        if ($covered === []) {
            $undefined[] = $prefix.'.*';
        }
    }

    expect($undefined)->toBe([]);
});

it('reads every config key the shipped config defines', function () {
    $reads = holdConfigReads();

    $unread = [];

    foreach (holdDefinedConfigKeys() as $key) {
        if (in_array($key, holdAllowlist('unread-config-keys'), true) || in_array($key, $reads['keys'], true)) {
            continue;
        }

        foreach ($reads['prefixes'] as $prefix) {
            if (str_starts_with($key, $prefix.'.')) {
                continue 2;
            }
        }

        $unread[] = $key;
    }

    expect($unread)->toBe([]);
});

it('documents every config key in the README', function () {
    $readme = holdDoc('README.md');

    $undocumented = [];

    foreach (holdDefinedConfigKeys() as $key) {
        if (in_array($key, holdAllowlist('undocumented-config'), true)) {
            continue;
        }

        // A key counts as documented if it, or any ancestor, is named — the
        // four notifications.classes.* entries are covered by their parent.
        $segments = explode('.', $key);
        $documented = false;

        while ($segments !== []) {
            if (str_contains($readme, implode('.', $segments))) {
                $documented = true;

                break;
            }

            array_pop($segments);
        }

        if (! $documented) {
            $undocumented[] = $key;
        }
    }

    expect($undocumented)->toBe([]);
});

it('names only real config keys in the README config table', function () {
    $section = holdDocSection(holdDoc('README.md'), 'Configuration');
    $defined = holdDefinedConfigKeys();

    // Table rows only: `| `some.key` | default | purpose |`.
    preg_match_all('/^\|\s*(`[^|]+`)\s*\|/m', $section, $rows);

    $named = [];
    foreach ($rows[1] as $cell) {
        preg_match_all('/`([a-z_]+(?:\.[a-z_]+)+)`/', $cell, $keys);
        $named = array_merge($named, $keys[1]);
    }

    expect($named)->not->toBe([]);

    $phantom = [];
    foreach (array_unique($named) as $key) {
        $isPrefix = array_filter($defined, static fn (string $k) => str_starts_with($k, $key.'.'));

        if (! in_array($key, $defined, true) && $isPrefix === []) {
            $phantom[] = $key;
        }
    }

    expect($phantom)->toBe([]);
});

it('resolves every notification key the code uses to a shipped default', function () {
    /** @var array<string, mixed> $config */
    $config = require holdRoot().'/config/hold.php';
    $configured = array_keys($config['notifications']['classes']);

    $used = [];

    foreach (holdFiles('src', '/\.php$/') as $contents) {
        preg_match_all('/notificationClass\(\s*\'([a-z_]+)\'/', $contents, $matches);
        $used = array_merge($used, $matches[1]);
    }

    // Announcer maps context => key through a constant rather than a literal call.
    $constants = (new ReflectionClass(Announcer::class))->getConstants();
    foreach ($constants as $value) {
        if (is_array($value)) {
            $used = array_merge($used, array_values($value));
        }
    }

    sort($used);
    sort($configured);

    expect(array_values(array_unique($used)))->toBe($configured);
});

// --- Renamed identifiers ----------------------------------------------------

it('has no reference left to the pre-rename Signup model', function () {
    // The model was renamed Signup -> HoldSignup; a stale reference in the docs
    // (or worse, the Boost skill) sends a reader to a class that does not exist.
    $offenders = [];

    $sources = array_merge(
        holdFiles('src', '/\.php$/'),
        holdFiles('resources', '/\.(php|md)$/'),
        ['README.md' => holdDoc('README.md'), 'CHANGELOG.md' => holdDoc('CHANGELOG.md')],
    );

    foreach ($sources as $path => $contents) {
        foreach (['/(?<!Hold)Signup::/', '/`Signup`/', '/Models\\\\Signup\b/'] as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = basename($path).' matches '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// --- Stub files -------------------------------------------------------------

it('holds published stubs to the same style as the code they land beside', function () {
    // Pint only globs *.php, so *.stub files are invisible to the formatter —
    // yet they are published into the host app as real PHP.
    $offenders = [];

    $stubs = array_merge(
        holdFiles('stubs', '/\.stub$/'),
        holdFiles('database/migrations', '/\.stub$/'),
    );

    expect($stubs)->not->toBe([]);

    foreach ($stubs as $path => $contents) {
        if (! str_contains($contents, 'declare(strict_types=1);')) {
            $offenders[] = basename($path).' is missing declare(strict_types=1)';
        }

        preg_match_all('/^use\s+([^;]+);$/m', $contents, $matches);
        $imports = $matches[1];

        $sorted = $imports;
        sort($sorted, SORT_STRING);

        if ($imports !== $sorted) {
            $offenders[] = basename($path).' has unsorted imports: '.implode(', ', $imports);
        }
    }

    expect($offenders)->toBe([]);
});

// --- Test harness contracts -------------------------------------------------

it('has every TestCase override call its parent', function () {
    // The base TestCase purges the schema and wires the connection in these
    // hooks. A subclass that overrides one without calling parent:: silently
    // opts out of that setup — and, because the previous test left a usable
    // schema behind, usually still passes in isolation while corrupting the run.
    $hooks = ['defineDatabaseMigrations', 'defineEnvironment', 'tearDown'];
    $offenders = [];

    foreach (holdFiles('tests', '/\.php$/') as $path => $contents) {
        if (str_ends_with($path, 'tests/TestCase.php')) {
            continue;
        }

        foreach ($hooks as $hook) {
            if (preg_match('/function\s+'.$hook.'\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $contents, $m) !== 1) {
                continue;
            }

            if (! str_contains($m[1], 'parent::'.$hook)) {
                $offenders[] = basename($path).'::'.$hook.'() does not call parent::'.$hook.'()';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('reads database credentials from the environment, never hardcoded in a test', function () {
    // Connection settings belong in phpunit.xml's <php> block so CI can override
    // them. A literal host or credential in a test file bypasses that entirely.
    $offenders = [];

    foreach (holdFiles('tests', '/\.php$/') as $path => $contents) {
        if (str_ends_with($path, 'tests/TestCase.php')) {
            continue;
        }

        if (preg_match('/[\'"](?:127\.0\.0\.1|localhost)[\'"]\s*,?\s*$/m', $contents) === 1
            && preg_match('/DB_HOST|database\.connections/', $contents) === 1) {
            $offenders[] = basename($path).' hardcodes a database host';
        }
    }

    expect($offenders)->toBe([]);
});
