<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
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
