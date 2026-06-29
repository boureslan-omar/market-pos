<?php
// Migration 17 — v3.5.0 DB changes
// Called by auto_update.php after zip extraction

// 1. suppliers.customer_id column
$cols = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'customer_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN customer_id INT NULL DEFAULT NULL AFTER balance");
}

// 2. VFD settings defaults
$pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('vfd_enabled','0')");
$pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('vfd_com_port','COM3')");

// 3. Fix manifest URL if still HTML URL
$cur = $pdo->query("SELECT value FROM settings WHERE settings.key='update_manifest_url'")->fetchColumn();
$rawUrl = 'https://raw.githubusercontent.com/boureslan-omar/market-pos/main/dahdouh/manifest.json';
if ($cur && strpos($cur, 'raw.githubusercontent.com') === false) {
    $pdo->prepare("UPDATE settings SET value=? WHERE settings.key='update_manifest_url'")->execute([$rawUrl]);
}

return true;
