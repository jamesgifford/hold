<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Hold\Installer\PackageMigration;

/*
 * The add_verification_to_hold_signups_table upgrade migration: it must add
 * verified_at to a pre-1.4.0 table and grandfather every existing row
 * (verified_at = requested_at), while being a genuine no-op — no error, no
 * row touched — wherever the column already exists, which is what makes it
 * safe for both a fresh install (create stub already has the column) and a
 * migration table that has already run it once.
 */

function holdVerificationMigrationStubPath(): string
{
    return dirname(__DIR__, 2).'/database/migrations/add_verification_to_hold_signups_table.php.stub';
}

it('backfills verified_at for rows that existed before the column did', function () {
    // Rebuild the pre-1.4.0 table shape (no verified_at) to exercise a
    // genuine upgrade, not just re-running the migration against a table the
    // create stub already gave the column to.
    Schema::dropIfExists(PackageMigration::TABLE);
    Schema::create(PackageMigration::TABLE, function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('context');
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->datetime('requested_at');
        $table->timestamp('notified_at')->nullable();
        $table->timestamp('unsubscribed_at')->nullable();
        $table->timestamps();
        $table->index('context');
        $table->index('notified_at');
    });

    $requestedAt = Carbon::parse('2026-01-01 09:00:00');
    DB::table(PackageMigration::TABLE)->insert([
        'email' => 'pre-1.4@example.com',
        'context' => 'prelaunch',
        'requested_at' => $requestedAt,
        'created_at' => $requestedAt,
        'updated_at' => $requestedAt,
    ]);

    (require holdVerificationMigrationStubPath())->up();

    $verifiedAt = DB::table(PackageMigration::TABLE)->value('verified_at');

    expect($verifiedAt)->not->toBeNull();
    expect(Carbon::parse($verifiedAt)->equalTo($requestedAt))->toBeTrue();

    // Restore the standard schema (verified_at included) so later tests in
    // this run see the shape they expect — this DDL commits outright on
    // MariaDB, same as DatabaseSchemaTest's own drop-and-rebuild test.
    Schema::dropIfExists(PackageMigration::TABLE);
    (require dirname(__DIR__, 2).'/database/migrations/create_hold_signups_table.php.stub')->up();
});

it('does nothing on a table where the column already exists and nothing is unverified', function () {
    // The already-migrated test schema already has verified_at (the create
    // stub ships it directly now) — this is the fresh-install shape. Queried
    // via DB::table(), not the Eloquent model, so this schema-level test
    // stays independent of the model's own verified_at cast (added later,
    // alongside the contract and factory).
    $alreadyVerified = Carbon::parse('2026-02-02 00:00:00');
    DB::table(PackageMigration::TABLE)->insert([
        'email' => 'already-verified@example.com',
        'context' => 'prelaunch',
        'requested_at' => Carbon::now(),
        'verified_at' => $alreadyVerified,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    (require holdVerificationMigrationStubPath())->up();

    $verifiedAt = DB::table(PackageMigration::TABLE)->where('email', 'already-verified@example.com')->value('verified_at');

    expect(Carbon::parse($verifiedAt)->equalTo($alreadyVerified))->toBeTrue();
});

it('is idempotent: running it again never overwrites an already-set verified_at', function () {
    $verifiedAt = Carbon::parse('2026-03-03 00:00:00');
    DB::table(PackageMigration::TABLE)->insert([
        'email' => 'idempotent@example.com',
        'context' => 'prelaunch',
        'requested_at' => Carbon::now(),
        'verified_at' => $verifiedAt,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    (require holdVerificationMigrationStubPath())->up();
    (require holdVerificationMigrationStubPath())->up();

    $result = DB::table(PackageMigration::TABLE)->where('email', 'idempotent@example.com')->value('verified_at');

    expect(Carbon::parse($result)->equalTo($verifiedAt))->toBeTrue();
});
