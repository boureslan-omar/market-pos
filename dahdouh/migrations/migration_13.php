<?php
// Migration 13 — Ensure cash_register_log.type ENUM includes 'refund'
// File patches (suppliers.php, api.php) are applied via zip extraction by the auto-updater.
try {
    $pdo->exec("ALTER TABLE cash_register_log MODIFY COLUMN type ENUM('opening','sale','withdrawal','deposit','void','expense','refund') NOT NULL DEFAULT 'sale'");
    return true;
} catch (Throwable $e) {
    echo 'Migration 13 error: ' . $e->getMessage() . PHP_EOL;
    return false;
}
