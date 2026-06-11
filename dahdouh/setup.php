<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pos_minimarket');
define('STORE_NAME', 'Zoughaib Market');

$errors  = [];
$success = false;

if (isset($_POST['install'])) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $sql = file_get_contents(__DIR__ . '/install.sql');

        // Split on semicolons but not inside strings — simple approach works for our DDL
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt) $pdo->exec($stmt);
        }
        $success = true;
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow p-4" style="max-width:500px;width:100%">
    <div class="text-center mb-4">
        <?php if (file_exists(__DIR__ . '/assets/img/logo.png')): ?>
        <img src="/dahdouh/assets/img/logo.png" alt="logo" style="height:96px;width:96px;object-fit:contain">
        <?php else: ?>
        <i class="bi bi-shop display-4" style="color:#2d5a2d"></i>
        <?php endif; ?>
        <h3 class="fw-bold mt-2" style="color:#2d5a2d"><?= STORE_NAME ?></h3>
        <p class="text-muted">Database Installation</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i>
        <strong>Installation complete!</strong><br>
        Database <code><?= DB_NAME ?></code> created with all tables.
    </div>
    <a href="/dahdouh/" class="btn btn-primary w-100 py-2 fw-bold">
        <i class="bi bi-speedometer2 me-2"></i>Open Dashboard
    </a>
    <?php else: ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <p class="text-muted small mb-3">This will create the database and all required tables. Run once only.</p>
    <ul class="small text-muted mb-4">
        <li>Host: <code><?= DB_HOST ?></code></li>
        <li>User: <code><?= DB_USER ?></code></li>
        <li>Database: <code><?= DB_NAME ?></code></li>
    </ul>
    <p class="small mb-3"><strong>New in this version:</strong></p>
    <ul class="small text-muted mb-4">
        <li>Batch pricing (FIFO) — merges same-price batches, creates new ones otherwise</li>
        <li>Bulk product type for vegetables/fruits (no stock tracking)</li>
        <li>Customer accounts with credit/debt ledger</li>
        <li>Multi-currency (USD ↔ LBP) with live exchange rate</li>
        <li>Cash register tracking with withdrawal notes</li>
        <li>Barcode scanner support + auto-print toggle</li>
    </ul>

    <form method="POST">
        <button name="install" class="btn btn-success w-100 py-2 fw-bold">
            <i class="bi bi-database-add me-2"></i>Install Database
        </button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
