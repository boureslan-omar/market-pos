MIGRATIONS FOLDER
=================

Each file here is a database/system migration script that runs automatically
during an update. Files must be named: migration_N.php (e.g. migration_9.php)

Each migration script must:
  - Use $pdo (available from the included config.php)
  - Return true on success, false on failure
  - Be idempotent (safe to run multiple times without breaking anything)
  - NOT output any HTML — plain text only (it runs via CLI)

Example migration_9.php:
------------------------------------------------------
<?php
// Migration 9 — Add discount_type column to sales table
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) DEFAULT 'fixed'");
    return true;
} catch (Throwable $e) {
    echo 'Migration 9 error: ' . $e->getMessage() . PHP_EOL;
    return false;
}
------------------------------------------------------

Migrations 1-8 are already applied on existing installs (listed in version.json).
New migrations start from 9.
