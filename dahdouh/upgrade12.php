<?php
// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE 12 — LBP Cash Drawer + Payment Method Fixes
//  Run this once on existing v3.1.0 installations.
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

// ── Block 1: purchases.payment_method column ──────────────────────────────────
try {
    if (!col($pdo, 'purchases', 'payment_method')) {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN payment_method ENUM('pay_later','cash_register','cash_owner','cash_register_lbp') NOT NULL DEFAULT 'pay_later' AFTER total_amount");
        $steps[] = 'purchases.payment_method column added';
    } else {
        // Column exists — ensure ENUM includes cash_register_lbp
        $pdo->exec("ALTER TABLE purchases MODIFY COLUMN payment_method ENUM('pay_later','cash_register','cash_owner','cash_register_lbp') NOT NULL DEFAULT 'pay_later'");
        $steps[] = 'purchases.payment_method ENUM updated (cash_register_lbp added if missing)';
    }
} catch (PDOException $e) {
    $errors[] = 'Block 1: ' . $e->getMessage();
}

// ── Block 2: version.json ─────────────────────────────────────────────────────
try {
    $vf = json_decode(@file_get_contents(__DIR__ . '/version.json') ?: '{}', true) ?: [];
    $installed = $vf['installed_upgrades'] ?? [];
    if (!in_array(12, $installed)) {
        $installed[] = 12;
        sort($installed);
        $vf['installed_upgrades'] = $installed;
        $vf['last_updated'] = date('Y-m-d');
        file_put_contents(__DIR__ . '/version.json', json_encode($vf, JSON_PRETTY_PRINT));
        $steps[] = 'version.json updated — upgrade 12 marked installed';
    } else {
        $steps[] = 'version.json — upgrade 12 already marked installed';
    }
} catch (Exception $e) {
    $errors[] = 'Block 2 (version.json): ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 12</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
<div class="card shadow-sm p-4">
  <h4 class="fw-bold mb-1">Upgrade 12 — LBP Cash Drawer</h4>
  <p class="text-muted small mb-4">Adds <code>cash_register_lbp</code> payment method to purchases table.</p>

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
    Upgrade 12 complete. You may delete this file from the server.
  </div>
  <?php endif; ?>

  <a href="/dahdouh/" class="btn btn-primary mt-3">Go to Dashboard</a>
</div>
</div>
</body>
</html>
