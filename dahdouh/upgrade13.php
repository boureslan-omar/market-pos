<?php
// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE 13 — v3.2.0: Hold Sale, Debt Settlement, Edit Sale/Purchase,
//                        Owner Cash Tracker, Supplier Return Cash Refund,
//                        Box Pricing, Returns & POS Improvements
//  Run this once on existing v3.1.x installations.
//  Safe to run multiple times (idempotent).
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403); die('Access denied.');
}

$steps  = [];
$errors = [];

// ── Helper ────────────────────────────────────────────────────────────────────
function col($pdo, $table, $column) {
    $r = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $r->execute([$table, $column]);
    return (int)$r->fetchColumn() > 0;
}

// ── Block 1: cash_register_log.settled_by (Owner Cash Tracker) ───────────────
try {
    if (!col($pdo, 'cash_register_log', 'settled_by')) {
        $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN settled_by INT NULL DEFAULT NULL");
        $steps[] = 'cash_register_log.settled_by column added';
    } else {
        $steps[] = 'cash_register_log.settled_by — already exists, skipped';
    }
} catch (PDOException $e) {
    $errors[] = 'Block 1: ' . $e->getMessage();
}

// ── Block 2: products — units_per_box, sell_price_box (Quick Add box feature) ─
try {
    if (!col($pdo, 'products', 'units_per_box')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1");
        $steps[] = 'products.units_per_box column added';
    } else {
        $steps[] = 'products.units_per_box — already exists, skipped';
    }
} catch (PDOException $e) {
    $errors[] = 'Block 2a: ' . $e->getMessage();
}
try {
    if (!col($pdo, 'products', 'sell_price_box')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN sell_price_box DECIMAL(10,4) NULL DEFAULT NULL");
        $steps[] = 'products.sell_price_box column added';
    } else {
        $steps[] = 'products.sell_price_box — already exists, skipped';
    }
} catch (PDOException $e) {
    $errors[] = 'Block 2b: ' . $e->getMessage();
}

// ── Block 3: api.php patch — add cost_price to batch SELECT in edit_sale ─────
try {
    $apiPath = __DIR__ . '/pages/api.php';
    $apiSrc  = file_get_contents($apiPath);
    $old = 'SELECT id, quantity_remaining FROM batches WHERE product_id=? AND quantity_remaining > 0 ORDER BY created_at ASC, id ASC LIMIT 1";
                    $b->execute([$newPid]);
                    $b = $b->fetch();
                    if ($b) {
                        $unitCost = (float)$b[\'cost_price\'] ?? 0;';
    $new = 'SELECT id, quantity_remaining, cost_price FROM batches WHERE product_id=? AND quantity_remaining > 0 ORDER BY created_at ASC, id ASC LIMIT 1";
                    $b->execute([$newPid]);
                    $b = $b->fetch();
                    if ($b) {
                        $unitCost = (float)($b[\'cost_price\'] ?? 0);';
    if (strpos($apiSrc, $old) !== false) {
        file_put_contents($apiPath, str_replace($old, $new, $apiSrc));
        $steps[] = 'api.php patched — cost_price added to edit_sale batch SELECT';
    } else {
        $steps[] = 'api.php — batch SELECT patch already applied, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 3 (api.php patch): ' . $e->getMessage();
}

// ── Block 3b: cash_register_log.type ENUM — add adjustment ───────────────────
// Edit Sale and Edit Purchase write type='adjustment' entries.
try {
    $pdo->exec("ALTER TABLE cash_register_log MODIFY COLUMN type ENUM('opening','sale','withdrawal','deposit','void','expense','refund','adjustment') NOT NULL");
    $steps[] = 'cash_register_log.type ENUM extended with adjustment';
} catch (PDOException $e) {
    $errors[] = 'Block 3b: ' . $e->getMessage();
}

// ── Block 4: suppliers.php patch — show Cash Register (LBP) option ───────────
try {
    $supPath = __DIR__ . '/pages/suppliers.php';
    $supSrc  = file_get_contents($supPath);
    $supOld  = '<option value="cash_usd">Cash Register</option>' . "\n" .
               '                        <option value="cash_lbp" style="display:none">Cash Register (LBP)</option>';
    $supNew  = '<option value="cash_usd">Cash Register (USD)</option>' . "\n" .
               '                        <option value="cash_lbp">Cash Register (LBP)</option>';
    if (strpos($supSrc, $supOld) !== false) {
        file_put_contents($supPath, str_replace($supOld, $supNew, $supSrc));
        $steps[] = 'suppliers.php patched — Cash Register (LBP) option made visible';
    } else {
        $steps[] = 'suppliers.php — LBP option patch already applied, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 4 (suppliers.php patch): ' . $e->getMessage();
}

// ── Block 5: api.php patch — add debt_settled to sale_receipt response ────────
try {
    $apiPath = __DIR__ . '/pages/api.php';
    $apiSrc  = file_get_contents($apiPath);
    $dsOld = "    \$sale['store_name']    = setting('store_name', 'Market');\n" .
             "    \$sale['store_address'] = setting('store_address', '');\n" .
             "    \$sale['store_phone']   = setting('store_phone', '');\n" .
             "    echo json_encode(\$sale);";
    $dsNew = "    \$sale['store_name']    = setting('store_name', 'Market');\n" .
             "    \$sale['store_address'] = setting('store_address', '');\n" .
             "    \$sale['store_phone']   = setting('store_phone', '');\n" .
             "    \$ds = \$pdo->prepare(\"SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE sale_id=? AND note LIKE 'Debt settlement%'\");\n" .
             "    \$ds->execute([\$saleId]);\n" .
             "    \$sale['debt_settled'] = (float)\$ds->fetchColumn();\n" .
             "    echo json_encode(\$sale);";
    if (strpos($apiSrc, $dsOld) !== false) {
        file_put_contents($apiPath, str_replace($dsOld, $dsNew, $apiSrc));
        $steps[] = 'api.php patched — debt_settled added to sale_receipt response';
    } else {
        $steps[] = 'api.php — debt_settled patch already applied, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 5 (api.php debt_settled): ' . $e->getMessage();
}

// ── Block 6: version.json ─────────────────────────────────────────────────────
try {
    $vf = json_decode(@file_get_contents(__DIR__ . '/version.json') ?: '{}', true) ?: [];
    $installed = $vf['installed_upgrades'] ?? [];
    if (!in_array(13, $installed)) {
        $installed[] = 13;
        sort($installed);
        $vf['installed_upgrades'] = $installed;
        $vf['version']      = '3.2.0';
        $vf['last_updated'] = date('Y-m-d');
        file_put_contents(__DIR__ . '/version.json', json_encode($vf, JSON_PRETTY_PRINT));
        $steps[] = 'version.json updated — v3.2.0, upgrade 13 marked installed';
    } else {
        $steps[] = 'version.json — upgrade 13 already marked installed';
    }
} catch (Exception $e) {
    $errors[] = 'Block 3 (version.json): ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 13 — v3.2.0</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:680px">
<div class="card shadow-sm p-4">
  <h4 class="fw-bold mb-1"><i class="bi bi-arrow-up-circle me-2 text-success"></i>Upgrade 13 — v3.2.0</h4>
  <p class="text-muted small mb-1">What's new in this upgrade:</p>
  <ul class="small text-muted mb-4">
    <li>Hold Sale &amp; Resume Sale in POS (localStorage-based)</li>
    <li>Debt Settlement at checkout — apply excess payment to customer debt</li>
    <li>Edit Receipts from Reports — add product by name or barcode scan (auto-picks on exact barcode match)</li>
    <li>Edit Purchases (adjusts stock, batch, supplier balance, cash register)</li>
    <li>Owner Cash Tracker in Cash Register page</li>
    <li>Supplier Return Cash Refund option</li>
    <li>Box Price display on POS product tiles</li>
    <li>Box sell price setup in Purchase Order receive form — dual pricing: retail (per unit) + wholesale (per box)</li>
    <li>Unified cash drawer (single option + USD/LBP toggle) across Purchases, Suppliers, Amenities</li>
    <li>Box stock count fix in POS cart</li>
    <li>Print receipt from Customer tab &amp; from Reports tab (per-receipt printer button)</li>
    <li>Edit receipt from Customer tab (same editor as Reports — adjust qty/price, remove/add items)</li>
  </ul>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong>Errors:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($steps): ?>
  <div class="alert alert-success">
    <strong>Completed steps:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($steps as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$errors): ?>
  <div class="alert alert-info mb-0">
    <i class="bi bi-check-circle me-1"></i>
    Upgrade 13 complete. You may delete this file from the server after applying.
  </div>
  <?php else: ?>
  <div class="alert alert-warning mb-0">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Some steps failed. Review errors above, fix manually if needed, then re-run.
  </div>
  <?php endif; ?>

  <a href="/dahdouh/" class="btn btn-primary mt-3">Go to Dashboard</a>
</div>
</div>
</body>
</html>
