<?php
require_once __DIR__ . '/includes/config.php';
requireRole('admin');

$steps = [];

try {
    $pdo->exec("ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1 AFTER unit");
    $steps[] = "✓ Added products.units_per_box";
} catch (PDOException) {
    $steps[] = "— products.units_per_box already exists";
}

try {
    $pdo->exec("ALTER TABLE products ADD COLUMN sell_price_box DECIMAL(10,4) NULL DEFAULT NULL AFTER units_per_box");
    $steps[] = "✓ Added products.sell_price_box";
} catch (PDOException) {
    $steps[] = "— products.sell_price_box already exists";
}

echo "<pre style='font-family:monospace;padding:20px'>";
echo "<strong>Upgrade 4 — Box/Packaging Support</strong>\n\n";
foreach ($steps as $s) echo $s . "\n";
echo "\nDone. <a href='/dahdouh/'>Return to dashboard</a></pre>";
