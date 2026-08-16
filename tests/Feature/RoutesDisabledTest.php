<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Support\Verification;
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
        $this->assertFalse(Route::has('hold.verify'));
        $this->assertFalse(Route::has('hold.unsubscribe'));
    }

    public function test_verification_url_degrades_to_null_when_routes_are_disabled(): void
    {
        // Mirrors EnableCommand::printPreviewLink()'s graceful degradation: a
        // link that can't be minted returns null rather than throwing, so
        // Verification::send() can no-op instead of crashing signup capture.
        $signup = HoldSignup::factory()->unverified()->create();

        $this->assertNull(Verification::url($signup));
    }

    public function test_mail_sends_with_no_unsubscribe_link_or_headers_when_routes_are_disabled(): void
    {
        // Same degradation as Verification::url() above, for
        // FormatsHoldMail::applyUnsubscribe() — the mail itself still
        // builds fine, just without the opt-out link/headers.
        $signup = HoldSignup::factory()->prelaunch()->create();

        $mail = (new LaunchAnnouncement($signup))->toMail(new AnonymousNotifiable);

        $this->assertArrayNotHasKey('unsubscribeUrl', $mail->viewData);
        $this->assertSame([], $mail->callbacks);
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
        $this->assertTrue(Route::has('hold.verify'));
        $this->assertTrue(Route::has('hold.unsubscribe'));

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
