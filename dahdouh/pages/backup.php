<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/backup_functions.php';
requireRole('admin');

$message = '';

// ── AJAX: run backup ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_backup') {
    header('Content-Type: application/json');
    echo json_encode(runBackup($pdo));
    exit;
}

// ── Save settings ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    saveSetting($pdo, 'backup_folder', rtrim(trim($_POST['backup_folder'] ?? ''), '\\/'));
    saveSetting($pdo, 'backup_keep',   max(1, (int)($_POST['backup_keep'] ?? 10)));
    $message = 'success:Backup settings saved.';
}

// ── Download backup file ──────────────────────────────────────────────────────
if (isset($_GET['dl'])) {
    $name   = basename($_GET['dl']);
    $folder = setting('backup_folder', backupDefaultFolder());
    $path   = $folder . DIRECTORY_SEPARATOR . $name;
    if (file_exists($path) && preg_match('/\.(sql|zip)$/', $name)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    $message = 'error:File not found.';
}

// ── Delete backup file ────────────────────────────────────────────────────────
if (isset($_GET['del'])) {
    $name   = basename($_GET['del']);
    $folder = setting('backup_folder', backupDefaultFolder());
    $path   = $folder . DIRECTORY_SEPARATOR . $name;
    if (file_exists($path) && preg_match('/\.(sql|zip)$/', $name)) {
        unlink($path);
        $message = 'success:Backup deleted.';
    }
}

$backupFolder = setting('backup_folder', backupDefaultFolder());
$backupKeep   = (int)setting('backup_keep', 10);
$lastBackup   = setting('last_backup', '');
$backups      = listBackups($backupFolder);
$folderOk     = is_dir($backupFolder) && is_writable($backupFolder);

renderHead('Backup');
renderNav('backup');
alertBox($message);
?>
<div class="container py-4" style="max-width:860px">
<h4 class="fw-bold mb-4"><i class="bi bi-cloud-arrow-up me-2"></i>Database &amp; Files Backup</h4>

<!-- Status strip -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card stat-card p-3 text-center">
            <div class="text-muted small mb-1">Last Backup</div>
            <div class="fw-bold <?= $lastBackup ? 'text-success' : 'text-danger' ?>">
                <?= $lastBackup ? htmlspecialchars($lastBackup) : 'Never' ?>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card stat-card p-3 text-center">
            <div class="text-muted small mb-1">Stored Backups</div>
            <div class="fw-bold"><?= count($backups) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card stat-card p-3 text-center">
            <div class="text-muted small mb-1">Backup Folder</div>
            <div class="fw-bold <?= $folderOk ? 'text-success' : 'text-warning' ?>" style="font-size:.75rem;word-break:break-all">
                <?= $folderOk ? '<i class="bi bi-check-circle me-1"></i>Ready' : '<i class="bi bi-exclamation-triangle me-1"></i>Not found yet' ?>
            </div>
        </div>
    </div>
</div>

<!-- Backup Now -->
<div class="card stat-card p-4 mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h6 class="fw-bold mb-1">Manual Backup</h6>
            <div class="text-muted small">Creates a full database dump (.sql) and a zip of all system files.</div>
        </div>
        <button class="btn btn-primary px-4" id="btn-backup" onclick="runBackup()">
            <i class="bi bi-cloud-arrow-up me-2"></i>Backup Now
        </button>
    </div>
    <div id="backup-status" class="mt-3" style="display:none"></div>
</div>

<!-- Settings -->
<form method="POST">
<input type="hidden" name="save_settings" value="1">
<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">BACKUP SETTINGS</h6>
    <div class="mb-3">
        <label class="form-label fw-semibold">Backup Folder Path</label>
        <input type="text" name="backup_folder" class="form-control font-monospace"
               value="<?= htmlspecialchars($backupFolder) ?>"
               placeholder="C:\Users\Public\Documents\MarketBackup">
        <div class="form-text">
            To back up to Google Drive automatically, install <strong>Google Drive Desktop</strong> on this PC,
            then set this path to your Google Drive folder (e.g. <code>G:\My Drive\MarketBackup</code>).
            Google Drive will sync every new backup to the cloud in the background.
        </div>
    </div>
    <div class="mb-0" style="max-width:200px">
        <label class="form-label fw-semibold">Keep last N backups</label>
        <input type="number" name="backup_keep" class="form-control" value="<?= $backupKeep ?>" min="1" max="100">
        <div class="form-text">Older backups are deleted automatically.</div>
    </div>
</div>

<!-- Auto-schedule instructions -->
<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">AUTOMATIC DAILY BACKUP (Windows Task Scheduler)</h6>
    <p class="small text-muted mb-2">Run this once to create a nightly backup job. Open <strong>Command Prompt as Administrator</strong> and paste:</p>
    <pre class="bg-dark text-white rounded p-3 small mb-2" style="overflow-x:auto">schtasks /create /tn "MarketPOS_Backup" /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\dahdouh\backup_cron.php\"" /sc daily /st 02:00 /ru SYSTEM /f</pre>
    <div class="form-text">This runs a silent backup every night at 2:00 AM. A log is saved to <code>C:\xampp\htdocs\dahdouh\backup_cron.log</code>.</div>
    <div class="mt-2">
        <a href="/dahdouh/backup_cron.log" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-text me-1"></i>View Backup Log
        </a>
        <a href="?test_cron=1" class="btn btn-sm btn-outline-secondary ms-2" onclick="return confirm('Run the cron script now to test it?')">
            <i class="bi bi-play me-1"></i>Test Cron Script
        </a>
    </div>
    <?php if (isset($_GET['test_cron'])): ?>
    <div class="mt-3 alert alert-info small">
        <?php
        ob_start();
        include __DIR__ . '/../backup_cron.php';
        $out = ob_get_clean();
        echo '<strong>Cron output:</strong><br>' . nl2br(htmlspecialchars($out));
        ?>
    </div>
    <?php endif; ?>
</div>

<button type="submit" class="btn btn-secondary px-4">Save Settings</button>
</form>

<!-- Backup list -->
<?php if ($backups): ?>
<div class="card stat-card mt-4">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark">
        <tr><th>Date</th><th>Type</th><th>File</th><th>Size</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
    <tr>
        <td class="small"><?= htmlspecialchars($b['date']) ?></td>
        <td>
            <?php if ($b['type'] === 'db'): ?>
            <span class="badge bg-primary">Database</span>
            <?php else: ?>
            <span class="badge bg-secondary">Files</span>
            <?php endif; ?>
        </td>
        <td class="small font-monospace text-muted"><?= htmlspecialchars($b['name']) ?></td>
        <td class="small"><?= round($b['size'] / 1024, 1) ?> KB</td>
        <td class="text-end">
            <a href="?dl=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-primary me-1">
                <i class="bi bi-download"></i>
            </a>
            <a href="?del=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-danger"
               onclick="return confirm('Delete this backup?')">
                <i class="bi bi-trash"></i>
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>
</div>

<script>
function runBackup() {
    const btn  = document.getElementById('btn-backup');
    const stat = document.getElementById('backup-status');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Running backup…';
    stat.style.display = '';
    stat.innerHTML = '<div class="text-muted small">Dumping database and zipping files — this may take a moment…</div>';

    fetch('/dahdouh/pages/backup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=run_backup'
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-arrow-up me-2"></i>Backup Now';
        if (d.ok) {
            let msg = `<div class="alert alert-success mb-0">
                <strong><i class="bi bi-check-circle me-1"></i>Backup complete!</strong><br>
                <span class="small">DB: ${d.sql}</span>`;
            if (d.zip) msg += `<br><span class="small">Files: ${d.zip}</span>`;
            if (d.warning) msg += `<br><span class="text-warning small">⚠ ${d.warning}</span>`;
            msg += '</div>';
            stat.innerHTML = msg;
            setTimeout(() => location.reload(), 2500);
        } else {
            stat.innerHTML = `<div class="alert alert-danger mb-0"><strong>Backup failed:</strong> ${d.error}</div>`;
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-arrow-up me-2"></i>Backup Now';
        stat.innerHTML = `<div class="alert alert-danger mb-0">Request failed: ${e}</div>`;
    });
}
</script>
<?php renderFoot(); ?>
