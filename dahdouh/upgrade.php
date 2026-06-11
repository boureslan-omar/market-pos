<?php
require_once __DIR__ . '/includes/config.php';
define('STORE_NAME', setting('store_name', 'Zoughaib Market'));

function colExists($pdo, $table, $col) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->fetchColumn();
}
function tableExists($pdo, $table) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetchColumn();
}

$steps = [];
$errors = [];

if (isset($_POST['upgrade'])) {
    try {
        // ── products: consignment fields ─────────────────────────────────────
        if (!colExists($pdo,'products','product_source')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN product_source ENUM('owned','consignment') NOT NULL DEFAULT 'owned' AFTER product_type");
            $steps[] = '✓ products.product_source added';
        }
        if (!colExists($pdo,'products','consignment_supplier_id')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN consignment_supplier_id INT NULL AFTER product_source");
            $steps[] = '✓ products.consignment_supplier_id added';
        }
        if (!colExists($pdo,'products','consignment_cost')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN consignment_cost DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER consignment_supplier_id");
            $steps[] = '✓ products.consignment_cost added';
        }

        // ── sale_items: consignment flag ──────────────────────────────────────
        if (!colExists($pdo,'sale_items','is_consignment')) {
            $pdo->exec("ALTER TABLE sale_items ADD COLUMN is_consignment TINYINT(1) NOT NULL DEFAULT 0 AFTER product_type");
            $steps[] = '✓ sale_items.is_consignment added';
        }

        // ── consignment_ledger ────────────────────────────────────────────────
        if (!tableExists($pdo,'consignment_ledger')) {
            $pdo->exec("CREATE TABLE consignment_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                product_id INT NOT NULL,
                supplier_id INT NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                sell_price DECIMAL(10,4) NOT NULL,
                consignment_cost DECIMAL(10,4) NOT NULL,
                revenue DECIMAL(10,2) NOT NULL,
                supplier_due DECIMAL(10,2) NOT NULL,
                market_profit DECIMAL(10,2) NOT NULL,
                settled TINYINT(1) NOT NULL DEFAULT 0,
                sale_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            )");
            $steps[] = '✓ consignment_ledger table created';
        }

        // ── consignment_settlements ───────────────────────────────────────────
        if (!tableExists($pdo,'consignment_settlements')) {
            $pdo->exec("CREATE TABLE consignment_settlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                amount_paid DECIMAL(10,2) NOT NULL,
                note TEXT,
                settled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            )");
            $steps[] = '✓ consignment_settlements table created';
        }

        // ── purchase_orders ───────────────────────────────────────────────────
        if (!tableExists($pdo,'purchase_orders')) {
            $pdo->exec("CREATE TABLE purchase_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_number VARCHAR(50) UNIQUE,
                supplier_id INT NOT NULL,
                status ENUM('draft','sent','confirmed','received','cancelled') DEFAULT 'draft',
                delivery_date DATE,
                note TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            )");
            $steps[] = '✓ purchase_orders table created';
        }

        // ── purchase_order_items ──────────────────────────────────────────────
        if (!tableExists($pdo,'purchase_order_items')) {
            $pdo->exec("CREATE TABLE purchase_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_id INT NOT NULL,
                product_id INT,
                product_name VARCHAR(200) NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                unit VARCHAR(30) DEFAULT 'pcs',
                estimated_price DECIMAL(10,4) DEFAULT 0,
                note VARCHAR(200),
                FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
            )");
            $steps[] = '✓ purchase_order_items table created';
        }

        if (empty($steps)) $steps[] = 'ℹ️  All tables already up to date — nothing to change.';

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DB Upgrade</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow p-4" style="max-width:560px;width:100%">
    <div class="text-center mb-4">
        <?php if (file_exists(__DIR__.'/assets/img/logo.png')): ?>
        <img src="/dahdouh/assets/img/logo.png" style="height:72px;object-fit:contain"><br>
        <?php endif; ?>
        <h4 class="fw-bold mt-2" style="color:#2d5a2d"><?= htmlspecialchars(STORE_NAME) ?></h4>
        <p class="text-muted">Database Upgrade — v2</p>
    </div>

    <?php if ($steps): ?>
    <div class="alert alert-success">
        <strong>Upgrade complete!</strong>
        <ul class="mb-0 mt-2"><?php foreach ($steps as $s) echo "<li>$s</li>"; ?></ul>
    </div>
    <a href="/dahdouh/" class="btn btn-primary w-100 mt-2">← Back to Dashboard</a>

    <?php elseif ($errors): ?>
    <?php foreach ($errors as $e) echo "<div class='alert alert-danger'>$e</div>"; ?>
    <form method="POST"><button name="upgrade" class="btn btn-warning w-100">Retry</button></form>

    <?php else: ?>
    <p class="text-muted small mb-3">This upgrade adds:</p>
    <ul class="small text-muted mb-4">
        <li><strong>Consignment/Amenities</strong> — track supplier-owned inventory &amp; profits separately</li>
        <li><strong>Purchase Orders</strong> — create POs, print PDF, share via WhatsApp</li>
    </ul>
    <form method="POST">
        <button name="upgrade" class="btn btn-success w-100 py-2 fw-bold">
            <i class="bi bi-database-up me-2"></i>Run Upgrade
        </button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
