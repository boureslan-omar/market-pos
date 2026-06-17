<?php
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
function hexDarken($hex, $factor = 0.38) {
    [$r,$g,$b] = hexToRgb($hex);
    return sprintf('#%02x%02x%02x', (int)($r*(1-$factor)), (int)($g*(1-$factor)), (int)($b*(1-$factor)));
}

function renderHead($title = '') {
    $store  = defined('STORE_NAME') ? STORE_NAME : 'Mini Market POS';
    $brand  = setting('theme_brand_color',  '#2d5a2d');
    $accent = setting('theme_accent_color', '#1a73e8');
    $dark   = hexDarken($brand);
    [$r,$g,$b]    = hexToRgb($brand);
    [$ar,$ag,$ab] = hexToRgb($accent);
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars($title ? "$title — $store" : $store) . '</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="/dahdouh/assets/css/pos.css">
<style>
:root {
  --brand:' . $brand . ';--brand-dark:' . $dark . ';--brand-accent:' . $accent . ';
  --bs-primary:' . $brand . ';--bs-primary-rgb:' . "$r,$g,$b" . ';
  --bs-link-color:' . $accent . ';--bs-link-hover-color:' . hexDarken($accent,0.15) . ';
}
.btn-primary{--bs-btn-bg:' . $brand . ';--bs-btn-border-color:' . $dark . ';--bs-btn-hover-bg:' . $dark . ';--bs-btn-hover-border-color:' . $dark . ';--bs-btn-active-bg:' . $dark . ';}
.btn-outline-primary{--bs-btn-color:' . $brand . ';--bs-btn-border-color:' . $brand . ';--bs-btn-hover-bg:' . $brand . ';--bs-btn-hover-border-color:' . $brand . ';}
</style>
</head>
<body>';
}

function renderNav($active = '') {
    $role = $_SESSION['role'] ?? '';

    // Pages visible per role
    $allPages = [
        'dashboard'      => ['icon'=>'bi-speedometer2',    'label'=>'Dashboard',       'url'=>'/dahdouh/',                              'roles'=>['admin','cashier','stock']],
        'pos'            => ['icon'=>'bi-cart3',            'label'=>'POS Sale',         'url'=>'/dahdouh/pages/pos.php',                 'roles'=>['admin','cashier']],
        'products'       => ['icon'=>'bi-box-seam',         'label'=>'Products',         'url'=>'/dahdouh/pages/products.php',            'roles'=>['admin','stock']],
        'purchases'      => ['icon'=>'bi-truck',            'label'=>'Purchases',        'url'=>'/dahdouh/pages/purchases.php',           'roles'=>['admin','stock']],
        'customers'      => ['icon'=>'bi-person-lines-fill','label'=>'Customers',        'url'=>'/dahdouh/pages/customers.php',           'roles'=>['admin','cashier']],
        'expenses'       => ['icon'=>'bi-wallet2',          'label'=>'Expenses',         'url'=>'/dahdouh/pages/expenses.php',            'roles'=>['admin','cashier']],
        'cash_register'  => ['icon'=>'bi-cash-coin',        'label'=>'Cash Register',    'url'=>'/dahdouh/pages/cash_register.php',       'roles'=>['admin','cashier']],
        'reports'        => ['icon'=>'bi-bar-chart-line',   'label'=>'Reports',          'url'=>'/dahdouh/pages/reports.php',             'roles'=>['admin']],
        'suppliers'      => ['icon'=>'bi-people',           'label'=>'Suppliers',        'url'=>'/dahdouh/pages/suppliers.php',           'roles'=>['admin','stock']],
        'amenities'      => ['icon'=>'bi-boxes',            'label'=>'Amenities',        'url'=>'/dahdouh/pages/amenities.php',           'roles'=>['admin','stock']],
        'purchase_orders'=> ['icon'=>'bi-clipboard-check',  'label'=>'Purchase Orders',  'url'=>'/dahdouh/pages/purchase_orders.php',     'roles'=>['admin','stock']],
        'returns'        => ['icon'=>'bi-arrow-return-left','label'=>'Returns',          'url'=>'/dahdouh/pages/returns.php',             'roles'=>['admin','cashier','stock']],
        'users'          => ['icon'=>'bi-people-fill',      'label'=>'Users',            'url'=>'/dahdouh/pages/users.php',               'roles'=>['admin']],
        'settings'       => ['icon'=>'bi-gear',             'label'=>'Settings',         'url'=>'/dahdouh/pages/settings.php',            'roles'=>['admin']],
        'backup'         => ['icon'=>'bi-cloud-arrow-up',   'label'=>'Backup',           'url'=>'/dahdouh/pages/backup.php',              'roles'=>['admin']],
    ];

    $logo = file_exists($_SERVER['DOCUMENT_ROOT'] . '/dahdouh/assets/img/logo.png')
        ? '<img src="/dahdouh/assets/img/logo.png" alt="logo" style="height:38px;width:38px;object-fit:contain;border-radius:50%;border:2px solid rgba(255,255,255,.3)" class="me-2">'
        : '<i class="bi bi-shop me-2"></i>';
    $name = defined('STORE_NAME') ? STORE_NAME : 'POS';

    $roleBadgeColor = match($role) { 'admin'=>'#dc3545', 'stock'=>'#0d6efd', default=>'#198754' };
    $roleLabel      = match($role) { 'admin'=>'Admin', 'stock'=>'Stock', default=>'Cashier' };
    $fullName = htmlspecialchars($_SESSION['full_name'] ?? '');

    echo '<nav class="navbar navbar-expand-lg navbar-dark px-3 no-print" style="background:linear-gradient(135deg,var(--brand-dark) 0%,var(--brand) 100%);box-shadow:0 2px 12px rgba(0,0,0,.4)">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="/dahdouh/">'
        . $logo
        . '<span style="font-size:.95rem;letter-spacing:.3px">' . htmlspecialchars($name) . '</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav me-auto">';

    foreach ($allPages as $key => $p) {
        if (!in_array($role, $p['roles'])) continue;
        $cls = ($active === $key) ? 'active' : '';
        echo "<li class=\"nav-item\"><a class=\"nav-link $cls\" href=\"{$p['url']}\"><i class=\"{$p['icon']}\"></i> {$p['label']}</a></li>";
    }

    echo '</ul>
        <div class="d-flex align-items-center gap-2 ms-3">
            <span class="badge rounded-pill" style="background:'.$roleBadgeColor.';font-size:.7rem">'.$roleLabel.'</span>
            <span class="text-white-50 small">'.$fullName.'</span>
            <a href="/dahdouh/logout.php" class="btn btn-sm btn-outline-light py-0 px-2" title="Sign out">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        </div></nav>';
}

function renderFoot() {
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/dahdouh/assets/js/pos.js"></script>
</body></html>';
}

function alertBox($message) {
    if (!$message) return;
    [$type, $text] = explode(':', $message, 2);
    $cls = $type === 'success' ? 'success' : 'danger';
    echo "<div class=\"alert alert-$cls alert-dismissible fade show m-3\">
        " . htmlspecialchars($text) . "
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
    </div>";
}
