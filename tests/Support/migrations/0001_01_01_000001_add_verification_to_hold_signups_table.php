<?php

declare(strict_types=1);

/*
 * Test-only migration. Re-exports the shipped stub verbatim — one source of
 * truth for the upgrade path, same convention as the sibling create-table
 * migration in this directory. Named to sort immediately after it, matching
 * the fresh-timestamp ordering PackageMigration gives the two stubs when
 * setup publishes them into a real app.
 */
return require __DIR__.'/../../../database/migrations/add_verification_to_hold_signups_table.php.stub';
