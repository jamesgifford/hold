<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\Tests\TestCase;

/**
 * Class-based (not Pest) so it can override the environment at BOOT time: the
 * provider decides whether to register routes during boot, before any Pest
 * `beforeEach` runs.
 */
class RoutesDisabledTest extends TestCase
{
    public function test_it_registers_no_package_routes_when_disabled(): void
    {
        $this->assertFalse(Route::has('hold.signup'));
        $this->assertFalse(Route::has('hold.preview'));
    }

    public function test_the_published_routes_stub_works_when_loaded_manually(): void
    {
        // A developer who owns routing loads the stub themselves.
        Route::middleware('web')->prefix('hold')->group(dirname(__DIR__, 2).'/stubs/routes.stub');

        // Refresh the name lookup, exactly as dispatching a request would, so the
        // ->name() assignments are visible to Route::has() outside a request.
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('hold.signup'));
        $this->assertTrue(Route::has('hold.preview'));

        // And the signup endpoint actually functions.
        $this->post('hold/signup', ['email' => 'stub@example.com', 'context' => 'prelaunch'])
            ->assertRedirect();

        $this->assertDatabaseHas('hold_signups', ['email' => 'stub@example.com']);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('jamesgifford.hold.routes.register', false);
    }
}
