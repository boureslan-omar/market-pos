<?php
require_once __DIR__ . '/includes/config.php';
requireRole('admin');

$steps = [];

// Add total column to purchase_items if missing
try {
    $pdo->exec("ALTER TABLE purchase_items ADD COLUMN total DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit_cost");
    $steps[] = '✓ Added purchase_items.total';
} catch (PDOException $e) {
    $steps[] = '— purchase_items.total: ' . $e->getMessage();
}

// Backfill total from quantity * unit_cost where total = 0
try {
    $pdo->exec("UPDATE purchase_items SET total = quantity * unit_cost WHERE total = 0 AND quantity > 0");
    $steps[] = '✓ Backfilled purchase_items.total (qty × unit_cost)';
} catch (PDOException $e) {
    $steps[] = '— backfill: ' . $e->getMessage();
}

// Add product_id as nullable to purchase_items if it is NOT NULL (needed for no-product receive rows)
try {
    $pdo->exec("ALTER TABLE purchase_items MODIFY COLUMN product_id INT NULL");
    $steps[] = '✓ purchase_items.product_id set to nullable';
} catch (PDOException $e) {
    $steps[] = '— product_id nullable: ' . $e->getMessage();
}

// Add new_product_upb column to purchase_order_items for auto-create feature
try {
    $pdo->exec("ALTER TABLE purchase_order_items ADD COLUMN new_product_upb SMALLINT NULL DEFAULT NULL");
    $steps[] = '✓ Added purchase_order_items.new_product_upb';
} catch (PDOException $e) {
    $steps[] = '— purchase_order_items.new_product_upb: ' . $e->getMessage();
}

echo "<pre style='font-family:monospace;padding:20px'>";
echo "<strong>Upgrade 6 — Purchase Items fixes</strong>\n\n";
foreach ($steps as $s) echo $s . "\n";
echo "\nDone. <a href='/dahdouh/'>Return to dashboard</a></pre>";
