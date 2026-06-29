<?php
date_default_timezone_set('Asia/Beirut');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/license.php';

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pos_minimarket');

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// Load settings from DB (with fallback defaults)
try {
    $GLOBALS['_settings'] = $pdo->query("SELECT `key`, value FROM settings")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $GLOBALS['_settings'] = [];
}

function setting($key, $default = '') {
    return $GLOBALS['_settings'][$key] ?? $default;
}

function saveSetting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
        ->execute([$key, $value, $value]);
    $GLOBALS['_settings'][$key] = $value;
}

define('STORE_NAME',         setting('store_name',             'Zoughaib Market'));
define('EXCHANGE_RATE',      (float) setting('exchange_rate',  89750));
define('AUTO_PRINT',         setting('auto_print_receipt',     '0') === '1');
define('BASE_CURRENCY',      setting('base_currency',          'USD'));
define('CUSTOMER_DISPLAY',   setting('customer_display_enabled','0') === '1');
define('CASH_DRAWER',        setting('cash_drawer_enabled',     '0') === '1');
define('VFD_ENABLED',        setting('vfd_enabled',             '0') === '1');
define('VFD_COM_PORT',       setting('vfd_com_port',            'COM3'));

// ── Auth helpers ─────────────────────────────────────────────────────────────

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? '',
    ];
}

function isLoggedIn(): bool { return !empty($_SESSION['user_id']); }

function userCan(string ...$roles): bool {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, $roles, true);
}

// Call at the top of any page to enforce authentication and optional role.
// Redirects to login if not authenticated, or shows 403 if wrong role.
function requireRole(string ...$roles): void {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/dahdouh/');
        header('Location: /dahdouh/login.php?redirect=' . $redirect); exit;
    }
    checkLicense(); // machine-locked license check (cached in session)
    if (!empty($roles) && !userCan(...$roles)) {
        http_response_code(403);
        $user = currentUser();
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        </head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
        <div class="text-center"><div class="display-1 text-danger fw-bold">403</div>
        <h4>Access Denied</h4>
        <p class="text-muted">Your role (<strong>' . htmlspecialchars($user['role']) . '</strong>) does not have permission to view this page.</p>
        <a href="/dahdouh/" class="btn btn-primary">← Dashboard</a></div></body></html>';
        exit;
    }
}
