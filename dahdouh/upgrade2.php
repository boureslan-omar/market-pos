<?php
require_once __DIR__ . '/includes/config.php';

function colExists2($pdo, $table, $col) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->fetchColumn();
}
function tableExists2($pdo, $table) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetchColumn();
}

$steps = [];
$errors = [];

if (isset($_POST['upgrade'])) {
    try {
        if (!colExists2($pdo, 'suppliers', 'email')) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone");
            $steps[] = '✓ suppliers.email added';
        }
        if (!colExists2($pdo, 'suppliers', 'balance')) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0");
            $steps[] = '✓ suppliers.balance added';
        }
        if (!tableExists2($pdo, 'supplier_ledger')) {
            $pdo->exec("CREATE TABLE supplier_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                purchase_id INT NULL,
                type ENUM('purchase','payment','adjustment') NOT NULL DEFAULT 'purchase',
                amount DECIMAL(10,2) NOT NULL,
                note TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
            )");
            $steps[] = '✓ supplier_ledger table created';
        }
        if (empty($steps)) $steps[] = 'ℹ️  All up to date — nothing to change.';
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DB Upgrade v3</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow p-4" style="max-width:520px;width:100%">
    <h4 class="fw-bold mb-1" style="color:#2d5a2d">Database Upgrade — v3</h4>
    <p class="text-muted small mb-3">Adds supplier debt tracking (balance + ledger).</p>
    <?php if ($steps): ?>
    <div class="alert alert-success">
        <ul class="mb-0"><?php foreach ($steps as $s) echo "<li>$s</li>"; ?></ul>
    </div>
    <a href="/dahdouh/" class="btn btn-primary w-100">← Back to Dashboard</a>
    <?php elseif ($errors): ?>
    <?php foreach ($errors as $e) echo "<div class='alert alert-danger'>$e</div>"; ?>
    <form method="POST"><button name="upgrade" class="btn btn-warning w-100">Retry</button></form>
    <?php else: ?>
    <ul class="small text-muted mb-4">
        <li>suppliers.email — contact email field</li>
        <li>suppliers.balance — running balance (how much we owe each supplier)</li>
        <li>supplier_ledger — full transaction history per supplier</li>
    </ul>
    <form method="POST"><button name="upgrade" class="btn btn-success w-100 py-2 fw-bold">Run Upgrade v3</button></form>
    <?php endif; ?>
</div>
</body>
</html>
