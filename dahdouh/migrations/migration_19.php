<?php
// Migration 19 — Catch-up DB columns missed by migration_13 (were only in upgrade13.php)
try {
    // cash_register_log.settled_by (Owner Cash Tracker)
    $cols = $pdo->query("SHOW COLUMNS FROM cash_register_log LIKE 'settled_by'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN settled_by INT NULL DEFAULT NULL");
    }

    // products.units_per_box (box/pcs toggle)
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'units_per_box'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1");
    }

    // products.sell_price_box (box price)
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'sell_price_box'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN sell_price_box DECIMAL(10,4) NULL DEFAULT NULL");
    }

    // cash_register_log.type ENUM — ensure 'adjustment' is included
    $pdo->exec("ALTER TABLE cash_register_log MODIFY COLUMN type ENUM('opening','sale','withdrawal','deposit','void','expense','refund','adjustment') NOT NULL DEFAULT 'sale'");

    return true;
} catch (Throwable $e) {
    echo 'Migration 19 error: ' . $e->getMessage() . PHP_EOL;
    return false;
}
