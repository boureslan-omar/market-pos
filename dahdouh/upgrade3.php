<?php
require_once __DIR__ . '/includes/config.php';

function col3($pdo,$t,$c){return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();}
function tbl3($pdo,$t){return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();}

$steps=[];$errors=[];

if(isset($_POST['upgrade'])){
    try {
        // ── users ─────────────────────────────────────────────────────────────
        if(!tbl3($pdo,'users')){
            $pdo->exec("CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role ENUM('admin','cashier','stock') NOT NULL DEFAULT 'cashier',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            // Default admin: admin / admin123
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,'Administrator','admin')")
                ->execute(['admin',$hash]);
            $steps[]='✓ users table created (default login: admin / admin123)';
        }

        // ── sales: void support ───────────────────────────────────────────────
        if(!col3($pdo,'sales','is_void')){
            $pdo->exec("ALTER TABLE sales ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER note");
            $steps[]='✓ sales.is_void added';
        }
        if(!col3($pdo,'sales','void_reason')){
            $pdo->exec("ALTER TABLE sales ADD COLUMN void_reason VARCHAR(255) NULL AFTER is_void");
            $steps[]='✓ sales.void_reason added';
        }
        if(!col3($pdo,'sales','voided_at')){
            $pdo->exec("ALTER TABLE sales ADD COLUMN voided_at TIMESTAMP NULL AFTER void_reason");
            $steps[]='✓ sales.voided_at added';
        }
        if(!col3($pdo,'sales','voided_by')){
            $pdo->exec("ALTER TABLE sales ADD COLUMN voided_by INT NULL AFTER voided_at");
            $steps[]='✓ sales.voided_by added';
        }

        // ── cash_register_log: dual-currency ──────────────────────────────────
        if(!col3($pdo,'cash_register_log','amount_lbp')){
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN amount_lbp DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER amount_usd");
            $steps[]='✓ cash_register_log.amount_lbp added';
        }
        if(!col3($pdo,'cash_register_log','balance_after_lbp')){
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN balance_after_lbp DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER balance_after_usd");
            $steps[]='✓ cash_register_log.balance_after_lbp added';
        }
        if(!col3($pdo,'cash_register_log','currency')){
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN currency ENUM('USD','LBP','BOTH') NOT NULL DEFAULT 'USD' AFTER type");
            $steps[]='✓ cash_register_log.currency added';
        }

        // ── purchase_orders: link to purchase on receive ──────────────────────
        if(!col3($pdo,'purchase_orders','received_purchase_id')){
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN received_purchase_id INT NULL AFTER delivery_date");
            $steps[]='✓ purchase_orders.received_purchase_id added';
        }

        if(empty($steps)) $steps[]='ℹ️  All already up to date.';

    } catch(Exception $e){ $errors[]=$e->getMessage(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DB Upgrade v4</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow p-4" style="max-width:560px;width:100%">
    <h4 class="fw-bold mb-1" style="color:#2d5a2d">Database Upgrade — v4</h4>
    <p class="text-muted small mb-3">Adds user authentication, sales void, dual-currency cash register, PO receive link.</p>
    <?php if($steps): ?>
    <div class="alert alert-success">
        <strong>Done!</strong>
        <ul class="mb-0 mt-2"><?php foreach($steps as $s) echo "<li>$s</li>"; ?></ul>
    </div>
    <?php if(in_array(true, array_map(fn($s)=>str_contains($s,'users table'), $steps))): ?>
    <div class="alert alert-warning mt-2">
        <strong>Default admin credentials:</strong><br>
        Username: <code>admin</code> &nbsp; Password: <code>admin123</code><br>
        <em>Change this immediately after logging in!</em>
    </div>
    <?php endif; ?>
    <a href="/dahdouh/" class="btn btn-primary w-100 mt-2">← Back to Dashboard</a>
    <?php elseif($errors): ?>
    <?php foreach($errors as $e) echo "<div class='alert alert-danger'>".htmlspecialchars($e)."</div>"; ?>
    <form method="POST"><button name="upgrade" class="btn btn-warning w-100">Retry</button></form>
    <?php else: ?>
    <ul class="small text-muted mb-4">
        <li>users table — login accounts with roles (admin / cashier / stock)</li>
        <li>sales void columns — void/refund completed sales</li>
        <li>dual-currency cash register — separate USD &amp; LBP drawers</li>
        <li>purchase_orders receive link — connect received PO to its purchase</li>
    </ul>
    <form method="POST">
        <button name="upgrade" class="btn btn-success w-100 py-2 fw-bold">Run Upgrade v4</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
