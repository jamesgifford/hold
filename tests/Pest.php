<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Console\RouteCacheCommand;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use JamesGifford\Hold\Tests\TestCase;

// Feature tests boot a Testbench app (and hit the database). The Unit suite is
// deliberately left unbound: the drift guards there only read files from disk,
// so they must not need an application — or a database — to run.
uses(TestCase::class)->in('Feature');

/**
 * Render a package view by name.
 *
 * Both the `view()` helper and the view factory take a `view-string`, which
 * static analysis can only prove for a literal view name. This takes any
 * string, so a test can render names it loops over.
 *
 * @param  array<string, mixed>  $data
 */
function holdRender(string $view, array $data = []): string
{
    return app(Factory::class)->make($view, $data)->render();
}

/**
 * Publish an edited copy of a package page template as the winning override.
 *
 * Lives here rather than in PageCopyTest.php (where it originated) so any
 * test file that needs it — PagePaletteTest.php, AppearanceConfigTest.php —
 * can run standalone instead of depending on PageCopyTest.php also being
 * loaded in the same Pest run.
 *
 * @param  array<string, string>  $edits
 */
function publishEditedPage(string $view, array $edits): void
{
    $source = File::get(dirname(__DIR__)."/resources/views/{$view}.blade.php");

    foreach ($edits as $search => $replace) {
        expect($source)->toContain($search);
        $source = str_replace($search, $replace, $source);
    }

    $dir = sys_get_temp_dir().'/hold-pagecopy-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir."/{$view}.blade.php", $source);

    View::prependNamespace('hold', $dir);
    View::flushFinderCache();
}

/**
 * Publish an edited copy of a package mail template as the winning override.
 *
 * Lives here rather than in EmailCopyTest.php (where it originated) — see
 * publishEditedPage()'s docblock; MailPaletteTest.php is the other caller.
 *
 * @param  array<string, string>  $edits
 */
function publishEditedMail(string $template, array $edits): void
{
    $source = File::get(dirname(__DIR__)."/resources/views/mail/{$template}.blade.php");

    foreach ($edits as $search => $replace) {
        expect($source)->toContain($search);
        $source = str_replace($search, $replace, $source);
    }

    $dir = sys_get_temp_dir().'/hold-emailcopy-'.uniqid();
    File::ensureDirectoryExists($dir.'/mail');
    File::put($dir."/mail/{$template}.blade.php", $source);

    View::prependNamespace('hold', $dir);
    View::flushFinderCache();
}

/**
 * Route names claimed by more than one registered route — each one a name
 * `php artisan route:cache` refuses to cache.
 *
 * @return array<int, string>
 */
function holdDuplicateRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn (RouteDefinition $route): ?string => $route->getName())
        ->filter()
        ->duplicates()
        ->unique()
        ->values()
        ->all();
}

/**
 * Cache the current route table exactly as `php artisan route:cache` does —
 * prepare every route for serialization, compile, var_export into the
 * framework's own cache stub — then load that file the way a cached app
 * boots. Throws the command's own LogicException on a duplicate route name.
 *
 * The command itself can't be called here: it builds a FRESH app from the
 * Testbench skeleton, which never registers this package, so it would cache a
 * table without the routes under test and pass no matter what they contain.
 */
function holdCacheRoutes(): void
{
    $routes = Route::getRoutes();
    assert($routes instanceof RouteCollection);

    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    $stub = dirname((string) (new ReflectionClass(RouteCacheCommand::class))->getFileName()).'/stubs/routes.stub';
    $path = sys_get_temp_dir().'/hold-routes-'.uniqid().'.php';

    File::put($path, str_replace('{{routes}}', var_export($routes->compile(), true), File::get($stub)));

    try {
        require $path;
    } finally {
        File::delete($path);
    }
}
