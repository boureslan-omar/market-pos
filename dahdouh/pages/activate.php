<?php
// Activation page — no login required, but license must not yet be valid.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/license.php';

$message = '';
$success = false;

// If already licensed, redirect home
if (file_exists(LIC_FILE)) {
    $existing = validateLicense(trim(file_get_contents(LIC_FILE)));
    if ($existing !== false) {
        header('Location: /dahdouh/');
        exit;
    }
}

$reason    = htmlspecialchars($_GET['reason'] ?? '');
$machineId = formatMachineId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key    = trim($_POST['license_key'] ?? '');
    $client = validateLicense($key);

    if ($client !== false) {
        // Save the license file
        if (file_put_contents(LIC_FILE, $key) !== false) {
            $_SESSION[LIC_SESSION] = $client;
            $success    = true;
            $clientName = htmlspecialchars($client['client'] ?? '');
            $licType    = $client['type'] ?? 'lifetime';
            $expiresAt  = $client['expires_at'] ?? 0;
            $message    = "License activated for: <strong>$clientName</strong>";
            if ($licType === 'yearly' && $expiresAt > 0) {
                $message .= '<br><span class="small text-muted">Annual license &mdash; valid until ' . date('d M Y', $expiresAt) . '</span>';
            } else {
                $message .= '<br><span class="small text-muted">Lifetime license</span>';
            }
        } else {
            $message = "Could not write license file. Check folder permissions.";
        }
    } else {
        $message = "Invalid license key. Make sure you copied it exactly, and that it was generated for this machine.";
    }
}

define('STORE_NAME_LIC', setting('store_name', 'Market POS'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Activate — <?= htmlspecialchars(STORE_NAME_LIC) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background: #f0f4f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 520px; width: 100%; }
  .machine-id { font-family: 'Courier New', monospace; font-size: 1.3rem; letter-spacing: 3px; color: #2d5a2d; font-weight: bold; }
</style>
</head>
<body>
<div class="card shadow p-4">

  <div class="text-center mb-4">
    <?php if (file_exists(__DIR__.'/../assets/img/logo.png')): ?>
    <img src="/dahdouh/assets/img/logo.png" style="height:64px;object-fit:contain"><br>
    <?php endif; ?>
    <h4 class="fw-bold mt-2" style="color:#2d5a2d"><?= htmlspecialchars(STORE_NAME_LIC) ?></h4>
    <p class="text-muted small">Software Activation</p>
  </div>

  <?php if ($reason): ?>
  <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= $reason ?></div>
  <?php endif; ?>

  <?php if ($success): ?>

    <div class="alert alert-success text-center">
      <i class="bi bi-patch-check-fill fs-1 d-block mb-2 text-success"></i>
      <?= $message ?>
    </div>
    <a href="/dahdouh/login.php" class="btn btn-success w-100 py-2 fw-bold">
      <i class="bi bi-box-arrow-in-right me-2"></i>Continue to Login
    </a>

  <?php else: ?>

    <!-- Step 1: Show Machine ID -->
    <div class="card bg-light border-0 p-3 mb-4">
      <div class="text-muted small mb-1"><i class="bi bi-cpu me-1"></i>Your Machine ID</div>
      <div class="machine-id text-center py-2"><?= htmlspecialchars($machineId) ?></div>
      <div class="text-muted small text-center mt-1">
        Send this code to your software provider to receive your license key.
      </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Step 2: Enter license key -->
    <form method="POST">
      <label class="form-label fw-bold">License Key</label>
      <textarea name="license_key" class="form-control font-monospace mb-3" rows="4"
        placeholder="Paste your license key here..." required></textarea>
      <button type="submit" class="btn btn-success w-100 py-2 fw-bold">
        <i class="bi bi-key me-2"></i>Activate Software
      </button>
    </form>

    <hr class="my-3">
    <p class="text-muted small text-center mb-0">
      <i class="bi bi-telephone me-1"></i>Need a license? Contact your software provider.
    </p>

  <?php endif; ?>

</div>
</body>
</html>
