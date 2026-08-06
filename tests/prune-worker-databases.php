<?php

declare(strict_types=1);

/*
 * Drops the per-worker schemas (`hold_test_1` … `hold_test_N`) that a
 * `pest --parallel` run creates, so they never accumulate on the server.
 * Chained after the parallel run by the `composer test:parallel` script.
 * The base `hold_test` schema is kept. Not a test file — phpunit.xml only
 * loads tests/Unit and tests/Feature.
 */

if ((getenv('DB_CONNECTION') ?: 'mariadb') === 'sqlite') {
    return;
}

$database = getenv('DB_DATABASE') ?: 'hold_test';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d', getenv('DB_HOST') ?: '127.0.0.1', (int) (getenv('DB_PORT') ?: 3306)),
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : 'root',
);

$statement = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?');
$statement->execute([$database.'\_%']);

foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $schema) {
    $pdo->exec('DROP DATABASE `'.str_replace('`', '', (string) $schema).'`');
    echo "  pruned {$schema}\n";
}
