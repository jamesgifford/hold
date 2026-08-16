<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use JamesGifford\Hold\Tests\TestCase;

// Feature tests boot a Testbench app (and hit the database). The Unit suite is
// deliberately left unbound: the drift guards there only read files from disk,
// so they must not need an application — or a database — to run.
uses(TestCase::class)->in('Feature');

/**
 * Render a package view by name.
 *
 * The `view()` helper is typed to accept only a `view-string` that static
 * analysis can resolve on disk, which it cannot do for the package's `hold::`
 * namespace. The view factory contract takes a plain string.
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
