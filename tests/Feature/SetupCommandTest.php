<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;

beforeEach(function () {
    // The base TestCase pre-migrates the support schema; drop it so a setup
    // --migrate run is exercised against a genuinely clean database.
    Schema::dropIfExists('hold_signups');

    $this->appRoot = sys_get_temp_dir().'/hold-setup-'.uniqid();
    File::makeDirectory($this->appRoot, 0777, true);

    $this->app->setBasePath($this->appRoot);
    $this->app->useStoragePath($this->appRoot.'/storage');
    $this->app->useDatabasePath($this->appRoot.'/database');
});

afterEach(function () {
    File::deleteDirectory($this->appRoot);
});

it('publishes every asset at its exact path (with extensions) and migrates', function () {
    $this->artisan('jamesgifford:hold:setup', ['--force' => true, '--migrate' => true])
        ->assertSuccessful();

    // Exact expected paths, including file extensions (guards the .php vs
    // .blade.php mangling class of bug).
    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/prelaunch.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/maintenance.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/announcement.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/team.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/receipt.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/verify.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/verified.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/unsubscribed.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/storage/jamesgifford/hold/.gitignore'))->toBeTrue();

    // The published 503 is the thin shim; the real form lives in maintenance.blade.php.
    expect(File::get($this->appRoot.'/resources/views/errors/503.blade.php'))
        ->toContain("@include('hold::maintenance')");
    expect(File::get($this->appRoot.'/resources/views/vendor/hold/maintenance.blade.php'))
        ->toContain('value="maintenance"');

    // Exactly one timestamped migration was published per stem, the
    // verification one sorting after the create one.
    $create = File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php');
    $addVerification = File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php');
    expect($create)->toHaveCount(1);
    expect($addVerification)->toHaveCount(1);
    expect(basename($addVerification[0]) > basename($create[0]))->toBeTrue();

    // The published model is renamed + renamespaced (App\Models\HoldSignup).
    expect(File::get($this->appRoot.'/app/Models/HoldSignup.php'))
        ->toContain('namespace App\\Models;')
        ->toContain('class HoldSignup extends Model')
        ->not->toContain('namespace JamesGifford\\Hold\\Models;');

    // --migrate ran it.
    expect(Schema::hasTable('hold_signups'))->toBeTrue();
});

it('publishes a 503 view that renders compiled Blade (no literal template syntax)', function () {
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    // Resolve the published shim through the real view finder (prepend so the
    // app's published copy wins over any framework default) and render it.
    $finder = View::getFinder();

    // prependLocation() lives on the concrete finder the framework binds, not on
    // ViewFinderInterface — assert that binding rather than assume it.
    expect($finder)->toBeInstanceOf(FileViewFinder::class);
    /** @var FileViewFinder $finder */
    $finder->prependLocation($this->appRoot.'/resources/views');
    View::flushFinderCache();
    $html = view('errors.503')->render();

    // Compiled: the maintenance form is present and no raw Blade leaked through.
    expect($html)->toContain('Notify me')
        ->toContain('value="maintenance"')
        ->not->toContain('{{')
        ->not->toContain('@include')
        ->not->toContain('@php');
});

it('lists full published paths and reports skipped steps in the summary', function () {
    // No --migrate, so the migration run is a skipped step.
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Published these files:')
        ->expectsOutputToContain($this->appRoot.'/config/jamesgifford/hold.php')
        ->expectsOutputToContain($this->appRoot.'/resources/views/errors/503.blade.php')
        ->expectsOutputToContain($this->appRoot.'/app/Models/HoldSignup.php')
        ->expectsOutputToContain('Skipped:')
        ->expectsOutputToContain('database migration run');
});

it('honors a config edited before the (skipped) pause', function () {
    // Pre-publish an edited config: a custom model path/namespace. Non-interactive
    // setup must leave it untouched and then honor it for the model publish step.
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    $edited = str_replace(
        "'path' => 'app/Models'",
        "'path' => 'app/Domain/Hold'",
        File::get(dirname(__DIR__, 2).'/config/hold.php'),
    );
    $edited = str_replace(
        "'namespace' => 'App\\\\Models'",
        "'namespace' => 'App\\\\Domain\\\\Hold'",
        $edited,
    );
    File::put($this->appRoot.'/config/jamesgifford/hold.php', $edited);

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful();

    expect(File::exists($this->appRoot.'/app/Domain/Hold/HoldSignup.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeFalse();

    expect(File::get($this->appRoot.'/app/Domain/Hold/HoldSignup.php'))
        ->toContain('namespace App\\Domain\\Hold;');
});

it('is idempotent: a re-run adds no second migration and keeps the config', function () {
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    // Mark the config so we can detect a clobber.
    $marker = "\n// edited-by-user\n";
    File::append($this->appRoot.'/config/jamesgifford/hold.php', $marker);

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    expect(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toHaveCount(1);
    expect(File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php'))->toHaveCount(1);

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toContain('edited-by-user');
});

it('publishes only the missing migration stub when one is already present (upgrade scenario)', function () {
    // Simulates a 1.3.x install upgrading to 1.4.0: the create migration was
    // already published by a previous setup run; the add-verification one
    // was not (it did not exist yet). A re-run must publish only the latter
    // and leave the pre-existing one untouched, not republish or duplicate it.
    File::makeDirectory($this->appRoot.'/database/migrations', 0777, true);
    File::put(
        $this->appRoot.'/database/migrations/2026_01_01_000000_create_hold_signups_table.php',
        File::get(dirname(__DIR__, 2).'/database/migrations/create_hold_signups_table.php.stub'),
    );

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    expect(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toHaveCount(1);
    expect(File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php'))->toHaveCount(1);

    // Untouched: still lacks the "published by" marker publish() would add.
    expect(File::get($this->appRoot.'/database/migrations/2026_01_01_000000_create_hold_signups_table.php'))
        ->not->toContain('Published by the jamesgifford/hold package');
});

// --- The published model must satisfy the contract --------------------------

it('publishes a model that still implements the contract the package resolves', function () {
    // The package resolves models.signup through HoldSignupContract, and the
    // published copy is a rewritten file rather than a subclass — so if the
    // rewrite ever drops the interface, resolution fails at runtime.
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    $published = File::get($this->appRoot.'/app/Models/HoldSignup.php');

    expect($published)->toContain('namespace App\Models;')
        ->toContain('use JamesGifford\Hold\Contracts\HoldSignupContract;')
        ->toContain('class HoldSignup extends Model implements HoldSignupContract');
});

it('renames the published class when a different model basename is configured', function () {
    // Pre-publish an edited config, since setup re-reads the published file and
    // would otherwise discard a runtime config() override.
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', str_replace(
        "'signup' => 'App\\\\Models\\\\HoldSignup'",
        "'signup' => 'App\\\\Models\\\\Waitlist'",
        File::get(dirname(__DIR__, 2).'/config/hold.php'),
    ));

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    $published = File::get($this->appRoot.'/app/Models/Waitlist.php');

    expect($published)->toContain('class Waitlist extends Model implements HoldSignupContract')
        ->not->toContain('class HoldSignup extends');
});

// --- Interactive paths ------------------------------------------------------
//
// Every other test here passes --force, which skips every prompt. These drive the
// prompts instead, so the interactive branches are exercised rather than assumed.

it('pauses for review and offers the migration on an interactive run', function () {
    $this->artisan('jamesgifford:hold:setup')
        ->expectsOutputToContain('Review your configuration')
        ->expectsQuestion('Press ENTER to continue', '')
        ->expectsConfirmation('Run the database migration now?', 'no')
        ->expectsOutputToContain('run `php artisan migrate` when you are ready')
        ->assertSuccessful();

    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeTrue();
});

// --- Config diff: never overwritten just because a re-run exists -----------
//
// A config that CAN be safely read (even a wildly incomplete one) is never
// overwritten — the setup command diffs it against this version's shipped
// config by key and reports the result, leaving the file untouched. Only a
// file that can't be safely read at all falls back to asking.

it('leaves an existing (incomplete) config untouched and reports what is new, without prompting', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php return []; // mine');

    $this->artisan('jamesgifford:hold:setup')
        ->expectsOutputToContain('left untouched')
        ->expectsOutputToContain('New keys this version adds')
        ->expectsOutputToContain('routes.register')
        ->expectsConfirmation('Run the database migration now?', 'no')
        ->assertSuccessful();

    // Byte-for-byte untouched — not just "still contains my marker".
    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe('<?php return []; // mine');
});

it('reports the published config as already up to date when nothing is missing', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    $shipped = File::get(dirname(__DIR__, 2).'/config/hold.php');
    File::put($this->appRoot.'/config/jamesgifford/hold.php', $shipped);

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('already has every key this version ships');

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe($shipped);
});

it('reports keys the published config has that this version no longer reads', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);

    /** @var array<string, mixed> $shipped */
    $shipped = require dirname(__DIR__, 2).'/config/hold.php';
    $shipped['legacy_setting'] = 'old-value';
    $published = '<?php return '.var_export($shipped, true).';';
    File::put($this->appRoot.'/config/jamesgifford/hold.php', $published);

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('no longer reads')
        ->expectsOutputToContain('legacy_setting');

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe($published);
});

// --- Config diff: undiffable fallback ---------------------------------------
//
// A published config that can't be safely evaluated at all (syntax error, or
// it doesn't return a plain array) can't be diffed — that's the only case
// where overwriting is even on the table, and only interactively.

it('offers to overwrite an undiffable config on an interactive run, and does so when chosen', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php return "not an array";');

    $overwrite = 'Overwrite with the fresh package template (loses your customizations)';
    $abort = 'Abort setup (keep my file exactly as it is)';

    $this->artisan('jamesgifford:hold:setup')
        ->expectsOutputToContain('could not safely compare')
        ->expectsChoice('What would you like to do?', $overwrite, [$abort, $overwrite])
        ->expectsQuestion('Press ENTER to continue', '')
        ->expectsConfirmation('Run the database migration now?', 'no')
        ->assertSuccessful();

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))
        ->toBe(File::get(dirname(__DIR__, 2).'/config/hold.php'));
});

it('aborts the whole setup run when overwrite is declined for an undiffable config', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php return "not an array";');

    $overwrite = 'Overwrite with the fresh package template (loses your customizations)';
    $abort = 'Abort setup (keep my file exactly as it is)';

    $this->artisan('jamesgifford:hold:setup')
        ->expectsChoice('What would you like to do?', $abort, [$abort, $overwrite])
        ->expectsOutputToContain('Setup aborted')
        ->assertFailed();

    // Nothing past the config step ran.
    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe('<?php return "not an array";')
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeFalse()
        ->and(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toBe([]);
});

it('leaves an undiffable config untouched under --force instead of guessing', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php return "not an array";');

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('could not safely compare')
        ->expectsOutputToContain('run interactively to choose what to do');

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe('<?php return "not an array";');
});

it('treats a genuine syntax error in the published config the same as any other undiffable file', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php this is not valid php at all {{{');

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('could not safely compare');

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toBe('<?php this is not valid php at all {{{');
});
