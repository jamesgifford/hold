<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests;

use JamesGifford\Hold\HoldServiceProvider;
use JamesGifford\Hold\Models\Signup;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            HoldServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Deterministic key so cookie encryption and signed routes are stable.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Array cache/session so per-test state (e.g. rate limiter) never leaks.
        $app['config']->set('cache.default', 'array');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Tests exercise the in-package Signup model directly; point the
        // package's model resolution at it (the published app model doesn't
        // exist in the test app).
        $app['config']->set('jamesgifford.hold.models.signup', Signup::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Support/migrations');
    }
}
