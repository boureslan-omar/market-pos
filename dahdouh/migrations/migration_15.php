<?php
// Migration 15 — Customer Display + Cash Drawer default settings (no schema changes)
try {
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?,?)")->execute(['customer_display_enabled', '0']);
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?,?)")->execute(['cash_drawer_enabled', '0']);
    return true;
} catch (Throwable $e) {
    echo 'Migration 15 error: ' . $e->getMessage() . PHP_EOL;
    return false;
}
