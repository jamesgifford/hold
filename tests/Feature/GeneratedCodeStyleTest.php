<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
 * Every PHP file the package writes into a consuming app must survive that
 * app's `pint --test` untouched. A fresh Laravel app's pint.json is the bare
 * "laravel" preset, so that is the style checked here — NOT this package's
 * own stricter pint.json. Pint (a dev dependency) fixes a copy of each file;
 * any difference is exactly the diff a consumer's lint check would fail on.
 */

beforeEach(function () {
    Schema::dropIfExists('hold_signups');

    $this->appRoot = sys_get_temp_dir().'/hold-style-'.uniqid();
    File::makeDirectory($this->appRoot, 0777, true);

    $this->app->setBasePath($this->appRoot);
    $this->app->useStoragePath($this->appRoot.'/storage');
    $this->app->useDatabasePath($this->appRoot.'/database');
});

afterEach(function () {
    File::deleteDirectory($this->appRoot);
});

it('writes only PHP files that pass a fresh Laravel app\'s Pint', function () {
    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    // The routes file is opt-in (vendor:publish --tag=jamesgifford-hold-routes),
    // which copies the stub verbatim.
    File::ensureDirectoryExists($this->appRoot.'/routes');
    File::copy(dirname(__DIR__, 2).'/stubs/routes.stub', $this->appRoot.'/routes/hold.php');

    $written = [];
    foreach (File::allFiles($this->appRoot) as $file) {
        $path = $file->getPathname();
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            $written[$path] = File::get($path);
        }
    }

    expect(array_keys($written))
        ->toContain($this->appRoot.'/config/jamesgifford/hold.php')
        ->toContain($this->appRoot.'/app/Models/HoldSignup.php')
        ->toContain($this->appRoot.'/routes/hold.php')
        ->and(File::glob($this->appRoot.'/database/migrations/*.php'))->toHaveCount(2);

    $config = $this->appRoot.'/pint.json';
    File::put($config, (string) json_encode(['preset' => 'laravel']));

    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pint', '--config='.$config, $this->appRoot]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue("Pint failed to run:\n".$process->getOutput().$process->getErrorOutput());

    foreach ($written as $path => $contents) {
        expect(File::get($path))->toBe($contents, "Pint's laravel preset would reformat ".substr($path, strlen($this->appRoot) + 1));
    }
});
