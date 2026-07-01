<?php
// upgrade18.php — v3.5.1
// Pure code improvements, no DB schema changes.
// Applies automatically via auto-update system (migration_18.php handles DB side — none).
require_once __DIR__ . '/includes/config.php';
requireRole('admin');

$results = [];

// ── No DB changes in this upgrade ────────────────────────────────────────────
$results[] = '✓ No database changes required for v3.5.1.';

// ── Update version.json ───────────────────────────────────────────────────────
$vf = __DIR__ . '/version.json';
$v  = json_decode(file_get_contents($vf), true);
if (!in_array(18, $v['installed_upgrades'])) {
    $v['installed_upgrades'][] = 18;
}
$v['version']      = '3.5.1';
$v['last_updated'] = date('Y-m-d');
file_put_contents($vf, json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$results[] = '✓ version.json updated to v3.5.1.';

echo '<pre style="font-family:monospace;padding:20px">';
echo "upgrade18.php — v3.5.1\n";
echo str_repeat('─', 50) . "\n";
foreach ($results as $r) echo $r . "\n";
echo str_repeat('─', 50) . "\n";
echo "Done.\n";
echo '</pre>';
