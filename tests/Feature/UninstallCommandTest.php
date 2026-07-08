<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->appRoot = sys_get_temp_dir().'/hold-uninstall-'.uniqid();
    File::makeDirectory($this->appRoot, 0777, true);

    $this->app->setBasePath($this->appRoot);
    $this->app->useStoragePath($this->appRoot.'/storage');
    $this->app->useDatabasePath($this->appRoot.'/database');

    // Install everything (the hold_signups table already exists via the base
    // TestCase's support migration, so no --migrate is needed here).
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->appRoot);
});

it('round-trips: setup then uninstall returns the app to a clean state', function () {
    expect(Schema::hasTable('hold_signups'))->toBeTrue();

    $this->artisan('jamesgifford:hold:uninstall', ['--force' => true])->assertSuccessful();

    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/app/Models/Hold/Signup.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/prelaunch.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.blade.php'))->toBeFalse()
        ->and(File::isDirectory($this->appRoot.'/storage/jamesgifford/hold'))->toBeFalse();

    expect(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toBeEmpty();
    expect(Schema::hasTable('hold_signups'))->toBeFalse();
});

it('keeps the table and migration file with --keep-data', function () {
    $this->artisan('jamesgifford:hold:uninstall', ['--force' => true, '--keep-data' => true])
        ->assertSuccessful();

    // Data-bearing assets kept.
    expect(Schema::hasTable('hold_signups'))->toBeTrue()
        ->and(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toHaveCount(1);

    // Non-data assets still removed.
    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/app/Models/Hold/Signup.php'))->toBeFalse();
});
