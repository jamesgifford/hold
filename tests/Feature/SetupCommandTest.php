<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

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
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.blade.php'))->toBeTrue()
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/storage/jamesgifford/hold/.gitignore'))->toBeTrue();

    // The published 503 is the thin shim; the real form lives in maintenance.blade.php.
    expect(File::get($this->appRoot.'/resources/views/errors/503.blade.php'))
        ->toContain("@include('hold::maintenance')");
    expect(File::get($this->appRoot.'/resources/views/vendor/hold/maintenance.blade.php'))
        ->toContain('value="maintenance"');

    // Exactly one timestamped migration was published.
    $migrations = File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php');
    expect($migrations)->toHaveCount(1);

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
    View::getFinder()->prependLocation($this->appRoot.'/resources/views');
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

    $migrations = File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php');
    expect($migrations)->toHaveCount(1);

    expect(File::get($this->appRoot.'/config/jamesgifford/hold.php'))->toContain('edited-by-user');
});
