<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenanceMiddleware;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JamesGifford\Hold\Console\Commands\AnnounceCommand;
use JamesGifford\Hold\Console\Commands\DisableCommand;
use JamesGifford\Hold\Console\Commands\EnableCommand;
use JamesGifford\Hold\Console\Commands\SetupCommand;
use JamesGifford\Hold\Console\Commands\UninstallCommand;
use JamesGifford\Hold\Console\Commands\UnsubscribeCommand;
use JamesGifford\Hold\Events\HoldSignupCaptured;
use JamesGifford\Hold\Http\BypassCookie;
use JamesGifford\Hold\Http\Middleware\PrelaunchMode;
use JamesGifford\Hold\Http\Middleware\PreventRequestsDuringMaintenance;
use JamesGifford\Hold\Listeners\ScheduleRestoreAnnouncement;
use JamesGifford\Hold\Listeners\SendHoldSignupReceipt;
use JamesGifford\Hold\Listeners\SendTeamHoldNotice;

/**
 * JamesGifford Hold package service provider.
 *
 * Integration is deliberately container-only: no host-app core files
 * (bootstrap/app.php, etc.) are ever modified, so `composer remove` fully
 * reverses everything this provider wires up — including the maintenance
 * middleware subclass, which is a plain container binding.
 *
 * Responsibilities:
 *  - Merge package config under the `jamesgifford.hold` namespace
 *  - Bind HoldState + the bypass-cookie helper as singletons
 *  - Bind the maintenance middleware subclass over the framework's, so the
 *    package routes stay reachable during `php artisan down`
 *  - Load + namespace the package views, and register publishable assets
 *  - Push the PrelaunchMode global middleware (a no-op unless a hold is active)
 *  - Register the package routes (when routes.register is true)
 *  - Register the five `jamesgifford:hold:*` console commands
 */
class HoldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hold.php', 'jamesgifford.hold');

        $this->app->singleton(HoldState::class);
        $this->app->singleton(BypassCookie::class);

        // Resolve the package's subclass wherever Laravel references the
        // framework maintenance middleware, so it merges the package routes into
        // the maintenance `except` list. Reversed automatically by composer remove.
        $this->app->bind(FrameworkMaintenanceMiddleware::class, PreventRequestsDuringMaintenance::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hold');

        $this->registerPublishing();
        $this->registerGlobalMiddleware();
        $this->registerRoutes();
        $this->registerEventListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SetupCommand::class,
                UninstallCommand::class,
                EnableCommand::class,
                DisableCommand::class,
                AnnounceCommand::class,
                UnsubscribeCommand::class,
            ]);
        }
    }

    /**
     * Push PrelaunchMode onto the global middleware stack.
     *
     * It runs on every request but short-circuits to next() with a single
     * is_file() check whenever prelaunch mode is inactive — the overhead is
     * negligible, and keeping it global means it can hold the whole app
     * (including non-web routes) the instant a hold is enabled, with nothing to
     * register per-route.
     */
    protected function registerGlobalMiddleware(): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $this->app->make(HttpKernel::class)->pushMiddleware(PrelaunchMode::class);
        }
    }

    /**
     * Wire the package's event listeners.
     *
     * Maintenance (native `down`/`up`) is observed via the framework's own
     * events, so entering/leaving it triggers the team notice and (optionally)
     * the delayed restore announcement. The prelaunch equivalents are wired
     * directly by the enable/disable commands. HoldSignupCaptured drives the
     * optional receipt.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(MaintenanceModeEnabled::class, SendTeamHoldNotice::class);
        Event::listen(MaintenanceModeDisabled::class, ScheduleRestoreAnnouncement::class);
        Event::listen(HoldSignupCaptured::class, SendHoldSignupReceipt::class);
    }

    /**
     * Register the package routes inside a group applying the configured prefix
     * and middleware. Skipped entirely when routes.register is false (the app
     * owns routing via the published routes stub).
     */
    protected function registerRoutes(): void
    {
        if (! config('jamesgifford.hold.routes.register', true)) {
            return;
        }

        Route::prefix((string) config('jamesgifford.hold.routes.prefix', 'hold'))
            ->middleware((array) config('jamesgifford.hold.routes.middleware', ['web']))
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/hold.php');
            });
    }

    /**
     * Register the publishable asset groups.
     *
     * Config, the HoldSignup model, the views, and the self-hosted routes stub are
     * publishable via `vendor:publish`. The migration is NOT a naive publish
     * group: its source is a `.stub` that must land with a fresh publish-time
     * Carbon timestamp, which `jamesgifford:hold:setup` owns.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/hold.php' => config_path('jamesgifford/hold.php'),
        ], 'jamesgifford-hold-config');

        $this->publishes([
            __DIR__.'/../src/Models/HoldSignup.php' => app_path('Models/HoldSignup.php'),
        ], 'jamesgifford-hold-models');

        $this->publishes([
            __DIR__.'/../resources/views/prelaunch.blade.php' => resource_path('views/vendor/hold/prelaunch.blade.php'),
            __DIR__.'/../resources/views/maintenance.blade.php' => resource_path('views/vendor/hold/maintenance.blade.php'),
            __DIR__.'/../resources/views/mail/announcement.blade.php' => resource_path('views/vendor/hold/mail/announcement.blade.php'),
            __DIR__.'/../resources/views/mail/team.blade.php' => resource_path('views/vendor/hold/mail/team.blade.php'),
            __DIR__.'/../resources/views/mail/receipt.blade.php' => resource_path('views/vendor/hold/mail/receipt.blade.php'),
            __DIR__.'/../resources/views/errors/503.blade.php' => resource_path('views/errors/503.blade.php'),
        ], 'jamesgifford-hold-views');

        $this->publishes([
            __DIR__.'/../stubs/routes.stub' => base_path('routes/hold.php'),
        ], 'jamesgifford-hold-routes');
    }
}
