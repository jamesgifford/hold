<?php

declare(strict_types=1);

/*
 * Test-only migration. The real migration ships as a `.stub` that the setup
 * command publishes into the host app with a fresh timestamp, so it is not
 * auto-discoverable by the test migrator. Rather than duplicate the schema, we
 * re-export the stub verbatim — one source of truth for the table shape.
 */
return require __DIR__.'/../../../database/migrations/create_hold_signups_table.php.stub';
