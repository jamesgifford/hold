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
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/prelaunch.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/maintenance.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/announcement.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/team.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/receipt.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/mail/verify.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/verified.blade.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/vendor/hold/unsubscribed.blade.php'))->toBeFalse()
        ->and(File::isDirectory($this->appRoot.'/resources/views/vendor/hold'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/resources/views/errors/503.blade.php'))->toBeFalse()
        ->and(File::isDirectory($this->appRoot.'/storage/jamesgifford/hold'))->toBeFalse()
        ->and(File::isDirectory($this->appRoot.'/config/jamesgifford'))->toBeFalse();

    expect(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toBeEmpty();
    expect(File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php'))->toBeEmpty();
    expect(Schema::hasTable('hold_signups'))->toBeFalse();
});

it('keeps config/jamesgifford/ when another package has left files there', function () {
    File::put($this->appRoot.'/config/jamesgifford/other-package.php', '<?php return [];');

    $this->artisan('jamesgifford:hold:uninstall', ['--force' => true])->assertSuccessful();

    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/config/jamesgifford/other-package.php'))->toBeTrue()
        ->and(File::isDirectory($this->appRoot.'/config/jamesgifford'))->toBeTrue();
});

it('keeps the data when run with --no-interaction and without --force', function () {
    // `artisan ... -n` is the standard CI/deploy invocation, and it is the one
    // input state where the drop confirmation can never be answered. Treating
    // "cannot ask" as consent would silently delete every captured signup, so
    // dropping has to be an explicit opt-in via --force.
    expect(Schema::hasTable('hold_signups'))->toBeTrue();

    $this->artisan('jamesgifford:hold:uninstall', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('--force');

    // Published assets still go; the data does not.
    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeFalse();

    expect(Schema::hasTable('hold_signups'))->toBeTrue()
        ->and(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toHaveCount(1)
        ->and(File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php'))->toHaveCount(1);
});

it('keeps the table and both migration files with --keep-data', function () {
    $this->artisan('jamesgifford:hold:uninstall', ['--force' => true, '--keep-data' => true])
        ->assertSuccessful();

    // Data-bearing assets kept.
    expect(Schema::hasTable('hold_signups'))->toBeTrue()
        ->and(File::glob($this->appRoot.'/database/migrations/*_create_hold_signups_table.php'))->toHaveCount(1)
        ->and(File::glob($this->appRoot.'/database/migrations/*_add_verification_to_hold_signups_table.php'))->toHaveCount(1);

    // Non-data assets still removed.
    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(File::exists($this->appRoot.'/app/Models/HoldSignup.php'))->toBeFalse();
});

// --- Interactive paths ------------------------------------------------------

it('aborts without touching anything when the uninstall prompt is declined', function () {
    $this->artisan('jamesgifford:hold:uninstall')
        ->expectsConfirmation('Proceed with the uninstall described above?', 'no')
        ->expectsOutputToContain('Uninstall aborted.')
        ->assertFailed();

    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeTrue()
        ->and(Schema::hasTable('hold_signups'))->toBeTrue();
});

it('removes the assets but keeps the data when the drop prompt is declined', function () {
    $this->artisan('jamesgifford:hold:uninstall')
        ->expectsConfirmation('Proceed with the uninstall described above?', 'yes')
        ->expectsConfirmation(
            'Drop the hold_signups table now? This permanently deletes all captured signups.',
            'no',
        )
        ->expectsOutputToContain('drop declined')
        ->assertSuccessful();

    expect(File::exists($this->appRoot.'/config/jamesgifford/hold.php'))->toBeFalse()
        ->and(Schema::hasTable('hold_signups'))->toBeTrue();
});
