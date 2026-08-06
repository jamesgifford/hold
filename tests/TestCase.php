<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JamesGifford\Hold\HoldServiceProvider;
use JamesGifford\Hold\Models\HoldSignup;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;

abstract class TestCase extends OrchestraTestCase
{
    // Test isolation: migrate once, then wrap each test in a transaction that
    // is rolled back afterwards. Chosen over drop-all-tables-per-test because it
    // is twice as fast (~22s vs ~46s on MariaDB) and empirically just as sound
    // here — the suite is green across random orderings, repeated seeds, and
    // parallel workers, including the installer tests that run their own DDL.
    use RefreshDatabase;

    /**
     * Release this test's database connections.
     *
     * Every test builds a fresh application and leaves a PDO handle behind;
     * across a large suite that eventually exhausts max_connections mid-run.
     *
     * This must run AFTER parent::tearDown() and NOT via
     * beforeApplicationDestroyed(): those callbacks run LIFO, so disconnecting
     * there would tear down SQLite's :memory: database while the migration
     * rollback still needed it. The manager is captured before teardown because
     * the container is gone afterwards.
     */
    protected function tearDown(): void
    {
        $database = $this->app?->bound('db') === true ? $this->app->make('db') : null;

        parent::tearDown();

        if ($database instanceof DatabaseManager) {
            foreach (array_keys($database->getConnections()) as $name) {
                $database->purge($name);
            }
        }
    }

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

        // Testbench defaults queue.default to 'sync', which DISCARDS job delays.
        // Leaving it there would mean the auto-announce tests assert a
        // change-of-mind window under exactly the config where it does not
        // exist (Queue::fake() hides the difference). Default to a connection
        // that can defer, matching a real deployment; the tests that care about
        // sync opt into it explicitly.
        $app['config']->set('queue.default', 'database');

        // Laravel's file-based maintenance driver writes a flag into the shared
        // Testbench skeleton, which parallel workers would then see as each
        // other's state. Backing the cache driver with the array store keeps
        // `down`/`up` in memory, per application. The dedicated `array`
        // maintenance driver does the same but only exists from
        // laravel/framework v13.16 — above this package's declared ^13.0 floor,
        // so the lowest-dependency CI run could not resolve it.
        $app['config']->set('app.maintenance.driver', 'cache');
        $app['config']->set('app.maintenance.store', 'array');

        $this->defineDatabaseConnection($app);
        $this->isolateWorkerStorage($app);

        // Tests exercise the in-package HoldSignup model directly; point the
        // package's model resolution at it (the published app model doesn't
        // exist in the test app).
        $app['config']->set('jamesgifford.hold.models.signup', HoldSignup::class);
    }

    /**
     * Give each parallel worker its own storage tree.
     *
     * Prelaunch mode's source of truth is a flag file under storage/, so workers
     * sharing the one Testbench skeleton would read and clear each other's holds.
     * Serial runs are left on the skeleton's own storage path, unchanged.
     *
     * @param  Application  $app
     */
    protected function isolateWorkerStorage($app): void
    {
        $token = getenv('TEST_TOKEN') ?: ($_SERVER['TEST_TOKEN'] ?? null);

        if (! is_scalar($token) || (string) $token === '') {
            return;
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hold-worker-'.$token.DIRECTORY_SEPARATOR.'storage';

        foreach (['app', 'logs', 'framework/cache', 'framework/sessions', 'framework/views'] as $directory) {
            $full = $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

            if (! is_dir($full)) {
                @mkdir($full, 0777, true);
            }
        }

        $app->useStoragePath($path);
    }

    /**
     * The suite runs against MariaDB — the real deployment target — so
     * engine-specific behaviour that SQLite cannot express (TIMESTAMP
     * auto-update, index collation, chunked updates under a real unique
     * constraint) is actually exercised rather than assumed.
     *
     * Set DB_CONNECTION=sqlite for a quick offline run; CI covers both.
     *
     * @param  Application  $app
     */
    protected function defineDatabaseConnection($app): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: 'mariadb');

        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        $settings = [
            'driver' => $connection,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => $this->databaseName(),
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : 'root',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];

        // Only honour a socket when one is genuinely configured. An empty
        // DB_SOCKET (what phpunit.xml and CI both declare) must NOT become
        // 'unix_socket' => '', which the driver would prefer over host/port and
        // then fail to connect through.
        $socket = getenv('DB_SOCKET');

        if (is_string($socket) && $socket !== '') {
            $settings['unix_socket'] = $socket;
        }

        $this->ensureDatabaseExists($settings);

        $app['config']->set('database.connections.'.$connection, $settings);
    }

    /**
     * The schema this process should use.
     *
     * Under `pest --parallel` each worker is a separate process sharing one
     * server, so a single schema would have them racing on each other's
     * migrations. paratest hands every worker a TEST_TOKEN; giving each its own
     * schema is what makes parallel runs isolated rather than merely fast.
     */
    protected function databaseName(): string
    {
        $database = getenv('DB_DATABASE') ?: 'hold_test';
        $token = getenv('TEST_TOKEN') ?: ($_SERVER['TEST_TOKEN'] ?? null);

        return is_scalar($token) && (string) $token !== ''
            ? $database.'_'.$token
            : $database;
    }

    /**
     * Create this process's schema if it is not there yet.
     *
     * Per-worker schemas cannot be created ahead of time, because how many
     * workers run is decided by the runner. Connecting without a database and
     * issuing CREATE DATABASE IF NOT EXISTS is idempotent and cheap.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function ensureDatabaseExists(array $settings): void
    {
        static $created = [];

        $database = (string) $settings['database'];

        if (isset($created[$database])) {
            return;
        }

        $created[$database] = true;

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d', $settings['host'], $settings['port']),
            (string) $settings['username'],
            (string) $settings['password'],
        );

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /**
     * Subclasses overriding this MUST call parent:: first; a guard test in
     * tests/Unit/DriftGuardTest.php enforces that.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Support/migrations');
    }
}
