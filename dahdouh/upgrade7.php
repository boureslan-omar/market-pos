<?php
// Upgrade 7 — Margin calculator in Products form + Purchases price pre-fill fix
// No database changes required. This upgrade is a UI-only addition.
// Safe to run multiple times.

$checks = [];

// Verify the products page contains the new margin feature
$productPage = file_get_contents(__DIR__ . '/pages/products.php');
if ($productPage === false) {
    $checks[] = ['error', 'Could not read pages/products.php'];
} else {
    if (strpos($productPage, 'use-margin') !== false && strpos($productPage, 'calcSellFromMargin') !== false) {
        $checks[] = ['ok', 'pages/products.php — margin calculator feature is present'];
    } else {
        $checks[] = ['error', 'pages/products.php — margin calculator feature NOT found. Make sure you copied the updated file.'];
    }
    if (strpos($productPage, 'toggleMarginMode') !== false) {
        $checks[] = ['ok', 'pages/products.php — toggleMarginMode() function is present'];
    } else {
        $checks[] = ['error', 'pages/products.php — toggleMarginMode() function missing'];
    }
    if (strpos($productPage, 'use-margin-box') !== false) {
        $checks[] = ['ok', 'pages/products.php — box margin mode is present'];
    } else {
        $checks[] = ['error', 'pages/products.php — box margin mode missing'];
    }
}

// Verify the purchases page has the cost/sell pre-fill fix
$purchasesPage = file_get_contents(__DIR__ . '/pages/purchases.php');
if ($purchasesPage === false) {
    $checks[] = ['error', 'Could not read pages/purchases.php'];
} else {
    if (strpos($purchasesPage, 'costEl.value = cost') !== false && strpos($purchasesPage, 'parseFloat(sell) > 0) sellEl.value') !== false) {
        $checks[] = ['ok', 'pages/purchases.php — cost/sell price pre-fill fix is present'];
    } else {
        $checks[] = ['error', 'pages/purchases.php — cost/sell price pre-fill fix NOT found'];
    }
}

$allOk = !in_array('error', array_column($checks, 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 7 — Margin Calculator</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; padding: 40px 16px; }
  .card { background: #fff; border-radius: 10px; padding: 36px 40px; max-width: 600px; width: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
  h2 { margin: 0 0 6px; color: #1a3a1a; }
  .sub { color: #666; font-size: 14px; margin-bottom: 28px; }
  .check { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; font-size: 14px; }
  .icon-ok    { color: #2e7d32; font-size: 18px; margin-top: 1px; }
  .icon-error { color: #c62828; font-size: 18px; margin-top: 1px; }
  .result { margin-top: 28px; padding: 16px 20px; border-radius: 8px; font-size: 15px; font-weight: 600; }
  .result.ok    { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
  .result.error { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
  .note { margin-top: 20px; font-size: 13px; color: #555; background: #f9f9f9; padding: 12px 16px; border-radius: 6px; border-left: 4px solid #2d5a2d; }
  .btn { display: inline-block; margin-top: 24px; padding: 10px 22px; background: #2d5a2d; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600; }
  .btn:hover { background: #1a3a1a; }
</style>
</head>
<body>
<div class="card">
  <h2>Upgrade 7</h2>
  <p class="sub">Margin Calculator + Purchases Price Pre-fill Fix</p>

  <?php foreach ($checks as [$status, $msg]): ?>
  <div class="check">
    <span class="icon-<?= $status ?>"><?= $status === 'ok' ? '✔' : '✘' ?></span>
    <span><?= htmlspecialchars($msg) ?></span>
  </div>
  <?php endforeach; ?>

  <div class="result <?= $allOk ? 'ok' : 'error' ?>">
    <?= $allOk
      ? '✔ Upgrade 7 applied successfully — no database changes were required.'
      : '✘ One or more checks failed. Make sure pages/products.php is the latest version.' ?>
  </div>

  <div class="note">
    <strong>What this upgrade adds:</strong><br><br>
    <strong>1. Margin Calculator (Products form)</strong><br>
    In the Products form (Add / Edit), the Margin % column now has a
    <em>"Set by margin %"</em> checkbox next to the Sell Price field.<br>
    • <strong>Checkbox OFF</strong> (default): enter sell price manually — margin badge shows live.<br>
    • <strong>Checkbox ON</strong>: enter your desired margin % — sell price is calculated automatically
      using the formula <em>sell = cost ÷ (1 − margin/100)</em>.<br>
    The same toggle is available for Box Sell Price when unit = box.<br><br>
    <strong>2. Purchases — correct cost &amp; sell price pre-fill</strong><br>
    When selecting a product in the New Purchase form, the Cost Price and Sell Price fields
    are now pre-filled with the product's current values from the database.
    Any change you make to the sell price is saved globally to that product.
  </div>

  <?php if ($allOk): ?>
  <a href="/dahdouh/pages/products.php" class="btn">Go to Products →</a>
  <?php endif; ?>
</div>
</body>
</html>
