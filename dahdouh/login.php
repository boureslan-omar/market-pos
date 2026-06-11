<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: /dahdouh/'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, full_name, role FROM users WHERE username=? AND is_active=1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['role']       = $user['role'];
            $redirect = $_GET['redirect'] ?? '/dahdouh/';
            header('Location: ' . $redirect); exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}
$storeName = setting('store_name', 'Zoughaib Market');
$logoExists = file_exists(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= htmlspecialchars($storeName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<?php
$_brand  = setting('theme_brand_color',  '#2d5a2d');
$_dark   = hexDarken($_brand);
[$_r,$_g,$_b] = hexToRgb($_brand);
echo "<style>
:root{--brand:$_brand;--brand-dark:$_dark;--bs-primary:$_brand;--bs-primary-rgb:$_r,$_g,$_b;}
.btn-primary{--bs-btn-bg:$_brand;--bs-btn-border-color:$_dark;--bs-btn-hover-bg:$_dark;--bs-btn-hover-border-color:$_dark;}
body{background:linear-gradient(135deg,var(--brand-dark) 0%,var(--brand) 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.login-card{width:100%;max-width:400px;}
.brand-header{background:linear-gradient(135deg,var(--brand-dark),var(--brand));color:#fff;border-radius:12px 12px 0 0;padding:2rem;text-align:center;}
</style>";
?>
</head>
<body>
<div class="login-card">
    <div class="brand-header">
        <?php if ($logoExists): ?>
        <img src="/dahdouh/assets/img/logo.png" alt="logo" style="height:72px;width:72px;object-fit:contain;margin-bottom:.75rem;border-radius:8px;background:#fff;padding:4px"><br>
        <?php else: ?>
        <i class="bi bi-shop fs-1 mb-2 d-block"></i>
        <?php endif; ?>
        <div class="fw-bold fs-5"><?= htmlspecialchars($storeName) ?></div>
        <div class="small opacity-75">Point of Sale System</div>
    </div>
    <div class="card border-0 shadow-lg" style="border-radius:0 0 12px 12px">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-4 text-center">Sign In</h5>
            <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
