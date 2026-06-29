<?php
// ── Upgrade 17 — v3.5.0 ──────────────────────────────────────────────────────
// Features:
//   #1  Bulk items on receipt — no qty column, just selling price total
//   #2  Cash checkout blocks Confirm if amount given < total due
//   #3  Box products — single card, click uses box sell price
//   #4  Box/Pcs toggle per cart row after scan or click
//   #5  Edit purchases (already in v3.4.0, verified working)
//   #6  Add new customer from POS — person-plus icon opens modal
//   #7  Purchases search bar — filter by supplier name or date
//   #8  New supplier from purchase form — + button quick-create
//   #9  Edit expenses + add new expense categories (datalist)
//  #10  VFD/LED display via COM port — configurable in Settings > Hardware
//  #11  Floating virtual numpad for touch-screen checkout
//  #12  Fix barcode printing — no blank pages, no browser header/footer
//  #13  Bold all thermal receipt text
//  #14  Supplier-customer link (Option A: auto-credit customer on supplier payment)
//  #15  Auto-update: fix manifest URL + background startup check

require_once __DIR__ . '/includes/config.php';

$errors  = [];
$done    = [];
$skipped = [];

// ─── Block 1: suppliers.customer_id column ────────────────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'customer_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN customer_id INT NULL DEFAULT NULL AFTER balance");
        $done[] = 'Block 1: suppliers.customer_id column added';
    } else {
        $skipped[] = 'Block 1: suppliers.customer_id already exists';
    }
} catch (Throwable $e) {
    $errors[] = 'Block 1 (suppliers.customer_id): ' . $e->getMessage();
}

// ─── Block 2: VFD settings defaults ──────────────────────────────────────────
try {
    $pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('vfd_enabled','0')");
    $pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('vfd_com_port','COM3')");
    $done[] = 'Block 2: VFD settings defaults inserted (INSERT IGNORE)';
} catch (Throwable $e) {
    $errors[] = 'Block 2 (VFD settings): ' . $e->getMessage();
}

// ─── Block 3: Fix manifest URL (HTML → raw GitHub raw URL) ───────────────────
try {
    $cur = $pdo->query("SELECT value FROM settings WHERE settings.key='update_manifest_url'")->fetchColumn();
    $rawUrl = 'https://raw.githubusercontent.com/boureslan-omar/market-pos/main/dahdouh/manifest.json';
    if ($cur && strpos($cur, 'raw.githubusercontent.com') === false) {
        $pdo->prepare("UPDATE settings SET value=? WHERE settings.key='update_manifest_url'")->execute([$rawUrl]);
        $done[] = 'Block 3: manifest URL fixed to raw GitHub URL';
    } elseif (!$cur) {
        $pdo->prepare("INSERT IGNORE INTO settings (key, value) VALUES ('update_manifest_url',?)")->execute([$rawUrl]);
        $done[] = 'Block 3: manifest URL inserted (was empty)';
    } else {
        $skipped[] = 'Block 3: manifest URL already correct';
    }
} catch (Throwable $e) {
    $errors[] = 'Block 3 (manifest URL): ' . $e->getMessage();
}

// ─── Block 4: version.json → v3.5.0 ─────────────────────────────────────────
try {
    $vf = __DIR__ . '/version.json';
    $v  = json_decode(file_exists($vf) ? file_get_contents($vf) : '{}', true) ?: [];
    if (($v['version'] ?? '') !== '3.5.0') {
        $upgrades = array_values(array_unique(array_merge($v['installed_upgrades'] ?? [], range(1, 17))));
        sort($upgrades);
        file_put_contents($vf, json_encode([
            'version'            => '3.5.0',
            'installed_upgrades' => $upgrades,
            'last_updated'       => date('Y-m-d'),
            'install_date'       => $v['install_date'] ?? date('Y-m-d'),
        ], JSON_PRETTY_PRINT) . PHP_EOL);
        $done[] = 'Block 4: version.json updated to v3.5.0';
    } else {
        $skipped[] = 'Block 4: version.json already v3.5.0';
    }
} catch (Throwable $e) {
    $errors[] = 'Block 4 (version.json): ' . $e->getMessage();
}

// ─── Output ───────────────────────────────────────────────────────────────────
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Upgrade 17</title>
<link rel="stylesheet" href="/dahdouh/assets/css/pos.css">
<style>body{font-family:system-ui,sans-serif;padding:2rem;max-width:720px;margin:auto}
.ok{color:#16a34a}.sk{color:#6b7280}.er{color:#dc2626}code{font-size:.85em;background:#f3f4f6;padding:2px 6px;border-radius:4px}</style>
</head><body>
<h3>Upgrade 17 — v3.5.0</h3>
<?php foreach ($done    as $m): ?><p class="ok">✓ <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
<?php foreach ($skipped as $m): ?><p class="sk">— <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
<?php foreach ($errors  as $m): ?><p class="er">✗ <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
<?php if (!$errors): ?>
<hr><p class="ok"><strong>✓ Upgrade 17 complete.</strong> System is now v3.5.0.</p>
<?php else: ?>
<hr><p class="er"><strong>Upgrade completed with errors.</strong> Review above and fix manually if needed.</p>
<?php endif; ?>
<p><a href="/dahdouh/">← Back to Dashboard</a></p>
</body></html>
