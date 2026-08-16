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

it('keeps an existing file when the overwrite prompt is declined', function () {
    File::makeDirectory($this->appRoot.'/config/jamesgifford', 0777, true);
    File::put($this->appRoot.'/config/jamesgifford/hold.php', '<?php return []; // mine');

    $this->artisan('jamesgifford:hold:setup')
        ->expectsConfirmation('config/jamesgifford/hold.php already exists. Overwrite it?', 'no')
        ->expectsQuestion('Press ENTER to continue', '')
        ->expectsConfirmation('Run the database migration now?', 'no')
        ->assertSuccessful();

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toContain('// mine');
});
