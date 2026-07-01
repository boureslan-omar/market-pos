<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin');

$noticeFile = __DIR__ . '/../update_notice.json';
$notice = [];
if (file_exists($noticeFile)) {
    $notice = json_decode(file_get_contents($noticeFile), true) ?? [];
    unlink($noticeFile); // show once
}

$from    = htmlspecialchars($notice['from_version'] ?? '—');
$to      = htmlspecialchars($notice['to_version']   ?? '—');
$rawLog  = $notice['changelog'] ?? '';
$updated = $notice['updated_at'] ?? date('Y-m-d H:i:s');

// Split changelog on semicolons or newlines into bullet points
$lines = array_filter(array_map('trim', preg_split('/[;\n]+/', $rawLog)));
// Remove leading version tag like "v3.5.6 —" from first item
if (!empty($lines)) {
    $lines[0] = trim(preg_replace('/^v[\d.]+\s*[—\-]+\s*/u', '', $lines[0]));
}

renderHead('System Updated');
renderNav('dashboard');
?>

<div class="container py-5" style="max-width:680px">

  <div class="text-center mb-4">
    <div class="mb-3">
      <span class="badge bg-success fs-6 px-3 py-2">
        <i class="bi bi-arrow-up-circle-fill me-2"></i>System Updated Successfully
      </span>
    </div>
    <h2 class="fw-bold mb-1">Version <?= $to ?></h2>
    <p class="text-muted mb-0">
      Updated from <strong>v<?= $from ?></strong>
      &nbsp;·&nbsp;
      <i class="bi bi-clock me-1"></i><?= htmlspecialchars($updated) ?>
    </p>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex align-items-center gap-2">
      <i class="bi bi-list-check text-primary fs-5"></i>
      <strong>What's New in v<?= $to ?></strong>
    </div>
    <div class="card-body">
      <?php if (!empty($lines)): ?>
        <ul class="mb-0" style="line-height:2">
          <?php foreach ($lines as $line): ?>
            <?php if (trim($line) === '') continue; ?>
            <li><?= htmlspecialchars($line) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-muted mb-0">No changelog available for this release.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="d-grid">
    <a href="/dahdouh/" class="btn btn-primary btn-lg">
      <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
    </a>
  </div>

</div>

<?php renderFoot(); ?>
