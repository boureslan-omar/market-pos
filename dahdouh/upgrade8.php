<?php
// Upgrade 8 — Automatic Database & Files Backup
// No database schema changes required — uses existing settings table.
// Safe to run multiple times.

$checks = [];

// Check required files
$files = [
    'includes/backup_functions.php' => 'Backup helper functions',
    'pages/backup.php'              => 'Backup management page',
    'backup_cron.php'               => 'Scheduled backup trigger',
];
foreach ($files as $rel => $label) {
    $path = __DIR__ . '/' . $rel;
    if (file_exists($path)) {
        $checks[] = ['ok', "$label — found ($rel)"];
    } else {
        $checks[] = ['error', "$label — NOT found ($rel)"];
    }
}

// Check backup nav entry in layout
$layout = file_get_contents(__DIR__ . '/includes/layout.php');
if ($layout && strpos($layout, 'backup') !== false && strpos($layout, 'bi-cloud-arrow-up') !== false) {
    $checks[] = ['ok', 'Navigation — Backup link present in navbar'];
} else {
    $checks[] = ['error', 'Navigation — Backup link missing from navbar (includes/layout.php)'];
}

// Check mysqldump is accessible
$dump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
if (file_exists($dump)) {
    $checks[] = ['ok', 'mysqldump.exe found at C:\\xampp\\mysql\\bin\\'];
} else {
    $checks[] = ['warn', 'mysqldump.exe not found at C:\\xampp\\mysql\\bin\\ — backup may fail (check your XAMPP path)'];
}

// Check ZipArchive
if (class_exists('ZipArchive')) {
    $checks[] = ['ok', 'ZipArchive PHP extension available — file backups will work'];
} else {
    $checks[] = ['warn', 'ZipArchive not available — database backups still work, but file zip backup will be skipped'];
}

// Check default backup folder is writable (create if missing)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
$folder = setting('backup_folder', 'C:\\Users\\Public\\Documents\\MarketBackup');
if (!is_dir($folder)) @mkdir($folder, 0755, true);
if (is_dir($folder) && is_writable($folder)) {
    $checks[] = ['ok', 'Backup folder is writable: ' . $folder];
} else {
    $checks[] = ['warn', 'Backup folder not yet accessible: ' . $folder . ' — it will be created on first backup'];
}

$allOk  = !in_array('error', array_column($checks, 0));
$hasWarn = in_array('warn', array_column($checks, 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 8 — Backup System</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; padding: 40px 16px; }
  .card { background: #fff; border-radius: 10px; padding: 36px 40px; max-width: 680px; width: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
  h2 { margin: 0 0 6px; color: #1a3a1a; }
  .sub { color: #666; font-size: 14px; margin-bottom: 28px; }
  .check { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; font-size: 14px; }
  .icon-ok   { color: #2e7d32; font-size: 18px; margin-top: 1px; }
  .icon-warn { color: #e65100; font-size: 18px; margin-top: 1px; }
  .icon-error{ color: #c62828; font-size: 18px; margin-top: 1px; }
  .result { margin-top: 28px; padding: 16px 20px; border-radius: 8px; font-size: 15px; font-weight: 600; }
  .result.ok   { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
  .result.warn { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
  .result.error{ background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
  .note { margin-top: 20px; font-size: 13px; color: #555; background: #f9f9f9; padding: 12px 16px; border-radius: 6px; border-left: 4px solid #2d5a2d; }
  .note code { background:#eee; padding: 1px 5px; border-radius:3px; font-size:12px; }
  .btn { display: inline-block; margin-top: 24px; padding: 10px 22px; background: #2d5a2d; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600; margin-right: 10px; }
  .btn:hover { background: #1a3a1a; }
  .btn-sec { background: #555; }
  .btn-sec:hover { background: #333; }
  pre { background:#1f1f1f; color:#eee; padding:12px 16px; border-radius:6px; font-size:12px; overflow-x:auto; }
</style>
</head>
<body>
<div class="card">
  <h2>Upgrade 8</h2>
  <p class="sub">Automatic Database &amp; Files Backup System</p>

  <?php foreach ($checks as [$status, $msg]): ?>
  <div class="check">
    <span class="icon-<?= $status ?>"><?= $status === 'ok' ? '✔' : ($status === 'warn' ? '⚠' : '✘') ?></span>
    <span><?= htmlspecialchars($msg) ?></span>
  </div>
  <?php endforeach; ?>

  <div class="result <?= !$allOk ? 'error' : ($hasWarn ? 'warn' : 'ok') ?>">
    <?php if (!$allOk): ?>
      ✘ One or more checks failed. Make sure all backup files are in place.
    <?php elseif ($hasWarn): ?>
      ⚠ Upgrade applied with warnings — review items above before using.
    <?php else: ?>
      ✔ Upgrade 8 applied successfully.
    <?php endif; ?>
  </div>

  <div class="note">
    <strong>What this upgrade adds:</strong><br><br>
    • <strong>Backup page</strong> (Admin → Backup in navbar) — manual "Backup Now" button,
      configurable folder, list of backups with download &amp; delete.<br><br>
    • <strong>Google Drive integration</strong> — install Google Drive Desktop on the PC, then
      set the backup folder to your Google Drive path (e.g. <code>G:\My Drive\MarketBackup</code>).
      Every backup is automatically synced to the cloud.<br><br>
    • <strong>Automatic nightly backup</strong> via Windows Task Scheduler.
      Run this once in <strong>cmd.exe as Administrator</strong>:<br>
    <pre>schtasks /create /tn "MarketPOS_Backup" /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\dahdouh\backup_cron.php\"" /sc daily /st 02:00 /ru SYSTEM /f</pre>
    This runs silently every night at 2:00 AM and logs results to <code>backup_cron.log</code>.<br><br>
    • Each backup creates two files: <strong>db_YYYY-MM-DD.sql</strong> (full database dump)
      and <strong>files_YYYY-MM-DD.zip</strong> (all system files). Oldest backups are removed
      automatically once the keep limit is reached.
  </div>

  <?php if ($allOk): ?>
  <a href="/dahdouh/pages/backup.php" class="btn">Go to Backup →</a>
  <a href="/dahdouh/" class="btn btn-sec">Dashboard</a>
  <?php endif; ?>
</div>
</body>
</html>
