<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Hold\Installer\PackageMigration;
use JamesGifford\Hold\Models\HoldSignup;

/*
 * Engine-level invariants of the shipped migration.
 *
 * These exist because SQLite cannot express the failure modes they guard: it has
 * no TIMESTAMP auto-update behaviour and no server-side collation on a unique
 * index. Running the suite against MariaDB is what makes them meaningful.
 */

function holdMigrationStubPath(): string
{
    return dirname(__DIR__, 2).'/database/migrations/create_hold_signups_table.php.stub';
}

/**
 * @return array<string, mixed>|null
 */
function holdColumnMeta(string $column): ?array
{
    $row = DB::selectOne(
        'SELECT DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB::connection()->getDatabaseName(), PackageMigration::TABLE, $column],
    );

    return $row === null ? null : (array) $row;
}

function holdOnMariaDb(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

// --- The TIMESTAMP auto-update trap ----------------------------------------

it('gives requested_at no implicit ON UPDATE, even where explicit_defaults_for_timestamp is off', function () {
    // With explicit_defaults_for_timestamp = 0 (the historical MySQL/MariaDB
    // default, and still reachable in production) the server silently promotes
    // a table's FIRST non-nullable TIMESTAMP column to
    // `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
    //
    // requested_at is that column, and it is updated whenever notified_at is
    // stamped by an announcement run — so the implicit ON UPDATE would rewrite
    // "when this address requested the current hold" to "when we last emailed
    // them", corrupting the lifecycle the package documents.
    DB::statement('SET SESSION explicit_defaults_for_timestamp = 0');

    try {
        Schema::dropIfExists(PackageMigration::TABLE);
        (require holdMigrationStubPath())->up();

        $meta = holdColumnMeta('requested_at');

        expect($meta)->not->toBeNull();
        expect(strtolower((string) $meta['EXTRA']))->not->toContain('on update');
    } finally {
        DB::statement('SET SESSION explicit_defaults_for_timestamp = 1');
    }
})->skip(fn () => ! holdOnMariaDb(), 'MariaDB/MySQL-specific schema behaviour.');

it('keeps requested_at unchanged when the row is updated for any other reason', function () {
    // The behavioural half of the invariant above: true on every engine, and the
    // assertion that actually matters to the announcement lifecycle.
    Carbon::setTestNow('2026-01-01 09:00:00');
    $signup = HoldSignup::factory()->prelaunch()->create();
    $requestedAt = $signup->requested_at->copy();

    Carbon::setTestNow('2026-06-01 12:00:00');
    $signup->forceFill(['notified_at' => Carbon::now()])->save();
    $signup->unsubscribe();
    Carbon::setTestNow();

    expect($signup->fresh()->requested_at->equalTo($requestedAt))->toBeTrue();
});

// --- Unique email constraint ------------------------------------------------

it('stores one row per address regardless of the casing submitted', function () {
    // MariaDB's utf8mb4_unicode_ci makes the unique index case-insensitive while
    // SQLite and Postgres are case-sensitive. The controller lower-cases before
    // writing, so the outcome is one row either way — assert that rather than
    // depending on the engine's collation.
    $this->post('hold/signup', ['email' => 'Person@Example.com', 'context' => 'prelaunch'])
        ->assertRedirect();
    $this->post('hold/signup', ['email' => 'PERSON@example.COM', 'context' => 'prelaunch'])
        ->assertRedirect();
    $this->post('hold/signup', ['email' => 'person@example.com', 'context' => 'prelaunch'])
        ->assertRedirect();

    expect(HoldSignup::count())->toBe(1)
        ->and(HoldSignup::first()->email)->toBe('person@example.com');
});

// --- verified_at ------------------------------------------------------------

it('creates verified_at as a nullable column', function () {
    // Nullable, not driver-specific: insert a row omitting it and confirm the
    // write succeeds and the column reads back null — works identically on
    // MariaDB and SQLite, unlike an information_schema query.
    DB::table(PackageMigration::TABLE)->insert([
        'email' => 'nullable-verified@example.com',
        'context' => 'prelaunch',
        'requested_at' => Carbon::now(),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    expect(
        DB::table(PackageMigration::TABLE)->where('email', 'nullable-verified@example.com')->value('verified_at')
    )->toBeNull();
});

it('enforces the unique email index at the database level', function () {
    HoldSignup::factory()->create(['email' => 'unique@example.com']);

    expect(fn () => DB::table(PackageMigration::TABLE)->insert([
        'email' => 'unique@example.com',
        'context' => 'prelaunch',
        'requested_at' => Carbon::now(),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
