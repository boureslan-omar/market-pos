<?php
// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE 15 — v3.3.0
//  • Customer Display toggle (Settings → Hardware)
//  • Cash Drawer toggle + "Open Drawer" button in POS
//  • Reports: Sales & Purchase Analysis (filter by product/category/supplier)
//  • Products: Low Stock PDF export button
//  • Purchases: Wholesale box sell-price field on new purchase
//  Safe to run multiple times (idempotent).
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403); die('Access denied.');
}

$steps  = [];
$errors = [];

// ── Block 1: Insert default settings for new hardware toggles ─────────────────
try {
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?,?)")->execute(['customer_display_enabled', '0']);
    $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?,?)")->execute(['cash_drawer_enabled', '0']);
    $steps[] = 'Settings — customer_display_enabled and cash_drawer_enabled defaults inserted (IGNORE if already present)';
} catch (Exception $e) {
    $errors[] = 'Block 1 (settings): ' . $e->getMessage();
}

// ── Block 2: includes/config.php — CUSTOMER_DISPLAY + CASH_DRAWER constants ──
try {
    $cf  = __DIR__ . '/includes/config.php';
    $src = file_get_contents($cf);
    if (strpos($src, 'CUSTOMER_DISPLAY') !== false) {
        $steps[] = 'config.php — constants already present, skipped';
    } else {
        $old = "define('AUTO_PRINT',         setting('auto_print_receipt',     '0') === '1');";
        $new = "define('AUTO_PRINT',         setting('auto_print_receipt',     '0') === '1');\n"
             . "define('CUSTOMER_DISPLAY',   setting('customer_display_enabled','0') === '1');\n"
             . "define('CASH_DRAWER',        setting('cash_drawer_enabled',     '0') === '1');";
        $out = str_replace($old, $new, $src);
        if ($out === $src) {
            $old2 = "define('AUTO_PRINT',    setting('auto_print_receipt', '0') === '1');";
            $new2 = "define('AUTO_PRINT',    setting('auto_print_receipt', '0') === '1');\n"
                  . "define('CUSTOMER_DISPLAY', setting('customer_display_enabled','0') === '1');\n"
                  . "define('CASH_DRAWER',      setting('cash_drawer_enabled',     '0') === '1');";
            $out = str_replace($old2, $new2, $src);
        }
        file_put_contents($cf, $out);
        $steps[] = 'config.php — CUSTOMER_DISPLAY and CASH_DRAWER constants added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 2 (config.php): ' . $e->getMessage();
}

// ── Block 3: pages/customer_display.php — create if missing ──────────────────
try {
    $dp = __DIR__ . '/pages/customer_display.php';
    if (file_exists($dp)) {
        $steps[] = 'customer_display.php — already exists, skipped';
    } else {
        file_put_contents($dp, '<?php
require_once __DIR__ . \'/../includes/config.php\';
if (!isLoggedIn()) { header(\'Location: /dahdouh/login.php\'); exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer Display</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0d1117; color: #e6edf3; font-family: \'Segoe UI\', sans-serif;
       height: 100vh; display: flex; flex-direction: column;
       align-items: center; justify-content: center; }
#welcome-msg { font-size: 2rem; color: rgba(255,255,255,.35); font-weight: 300; letter-spacing: 2px; }
#total-usd { font-size: 5rem; font-weight: 800; color: #3fb950; letter-spacing: -2px; line-height: 1; }
#total-lbp { font-size: 1.6rem; color: rgba(255,255,255,.5); margin-top: .4rem; }
</style>
</head>
<body>
<div id="welcome-msg">Welcome!</div>
<div id="total-usd" style="display:none">$0.00</div>
<div id="total-lbp" style="display:none">0 LL</div>
<script>
const RATE = <?= EXCHANGE_RATE ?>;
function applyState(d) {
    const total    = parseFloat(d.total) || 0;
    const hasItems = (d.items || []).length > 0;
    document.getElementById(\'welcome-msg\').style.display = hasItems ? \'none\' : \'\';
    document.getElementById(\'total-usd\').style.display   = hasItems ? \'\' : \'none\';
    document.getElementById(\'total-lbp\').style.display   = hasItems ? \'\' : \'none\';
    document.getElementById(\'total-usd\').textContent = \'$\' + total.toFixed(2);
    document.getElementById(\'total-lbp\').textContent = Math.round(total * RATE).toLocaleString() + \' LL\';
}
window.addEventListener(\'storage\', function(e) {
    if (e.key === \'posDisplay\') try { applyState(JSON.parse(e.newValue || \'{}\')); } catch(x) {}
});
setInterval(function() {
    try { var r = localStorage.getItem(\'posDisplay\'); if (r) applyState(JSON.parse(r)); } catch(x) {}
}, 2000);
try { var r = localStorage.getItem(\'posDisplay\'); if (r) applyState(JSON.parse(r)); } catch(x) {}
</script>
</body>
</html>
');
        $steps[] = 'customer_display.php — created';
    }
} catch (Exception $e) {
    $errors[] = 'Block 3 (customer_display.php): ' . $e->getMessage();
}

// ── Block 4: pages/settings.php — HARDWARE card + boolean toggle saving ───────
try {
    $sf  = __DIR__ . '/pages/settings.php';
    $src = file_get_contents($sf);
    $changed = false;

    if (strpos($src, 'customer_display_enabled') === false) {
        $needle = "    if (!\$message) \$message = 'success:Settings saved.';";
        $replacement =
            "    foreach (['customer_display_enabled','cash_drawer_enabled'] as \$b) {\n" .
            "        saveSetting(\$pdo, \$b, isset(\$_POST[\$b]) ? '1' : '0');\n" .
            "    }\n" .
            "    if (!\$message) \$message = 'success:Settings saved.';";
        $src = str_replace($needle, $replacement, $src);
        $changed = true;
    }

    if (strpos($src, 'HARDWARE') === false) {
        $needle = '<button type="submit" class="btn btn-primary px-5">Save Settings</button>';
        $replacement =
'<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">HARDWARE</h6>
    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="customer_display_enabled" id="custDisplay" value="1" <?= setting(\'customer_display_enabled\')==\'1\'?\'checked\':\'\' ?>>
        <label class="form-check-label" for="custDisplay">
            <strong>Customer Display</strong>
            <div class="text-muted small">Opens a full-screen display for the customer on checkout. Open it on your second monitor.</div>
        </label>
    </div>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="cash_drawer_enabled" id="cashDrawer" value="1" <?= setting(\'cash_drawer_enabled\')==\'1\'?\'checked\':\'\' ?>>
        <label class="form-check-label" for="cashDrawer">
            <strong>Cash Drawer</strong>
            <div class="text-muted small">Auto-opens cash drawer after each sale and shows an "Open Drawer" button in POS. Requires drawer connected to thermal printer via RJ11.</div>
        </label>
    </div>
</div>
<button type="submit" class="btn btn-primary px-5">Save Settings</button>';
        $src = str_replace($needle, $replacement, $src);
        $changed = true;
    }

    if ($changed) {
        file_put_contents($sf, $src);
        $steps[] = 'settings.php — HARDWARE card and boolean toggle handling added';
    } else {
        $steps[] = 'settings.php — already patched, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 4 (settings.php): ' . $e->getMessage();
}

// ── Block 5: pages/pos.php — display sync + cash drawer ──────────────────────
try {
    $pf  = __DIR__ . '/pages/pos.php';
    $src = file_get_contents($pf);

    if (strpos($src, 'CUSTOMER_DISPLAY_ON') !== false) {
        $steps[] = 'pos.php — already patched, skipped';
    } else {
        $src = str_replace(
            '$autoPrint  = AUTO_PRINT;',
            '$autoPrint       = AUTO_PRINT;' . "\n" .
            '$custDisplay     = CUSTOMER_DISPLAY;' . "\n" .
            '$cashDrawer      = CASH_DRAWER;',
            $src
        );

        $jsInsert = "const CUSTOMER_DISPLAY_ON = <?= \$custDisplay  ? 'true' : 'false' ?>;\n"
            . "const CASH_DRAWER_ON      = <?= \$cashDrawer   ? 'true' : 'false' ?>;\n\n"
            . "function openCashDrawer() {\n"
            . "    var win = window.open('', '_blank', 'width=1,height=1,left=-100,top=-100');\n"
            . "    if (!win) return;\n"
            . "    win.document.write('<!DOCTYPE html><html><head><meta charset=\"UTF-8\"></head><body><pre>\\x1B\\x70\\x00\\x19\\xFA</pre><script>window.onload=function(){window.print();setTimeout(function(){window.close();},500);};<\\/script></body></html>');\n"
            . "    win.document.close();\n"
            . "}\n\n"
            . "var _displayWin = null;\n"
            . "function openDisplayWindow() {\n"
            . "    if (!CUSTOMER_DISPLAY_ON) return;\n"
            . "    if (_displayWin && !_displayWin.closed) return;\n"
            . "    _displayWin = window.open('/dahdouh/pages/customer_display.php', 'customerDisplay',\n"
            . "        'width=1024,height=600,menubar=no,toolbar=no,location=no,status=no');\n"
            . "}\n"
            . "function syncDisplay() {\n"
            . "    if (!CUSTOMER_DISPLAY_ON) return;\n"
            . "    var items = Object.values(cart).map(function(it){ return {name:it.name,qty:it.qty,price:it.price}; });\n"
            . "    var totalEl = document.getElementById('total-usd');\n"
            . "    var total   = totalEl ? parseFloat(totalEl.textContent.replace('\\$','').replace(/,/g,'')) || 0 : 0;\n"
            . "    localStorage.setItem('posDisplay', JSON.stringify({items:items, total:total}));\n"
            . "}\n\n";

        $src = str_replace(
            'const EXCHANGE_RATE = <?= $rate ?>;',
            'const EXCHANGE_RATE = <?= $rate ?>;' . "\n" . $jsInsert,
            $src
        );

        $src = str_replace(
            '<button type="button" class="btn btn-outline-warning w-100 mt-1 btn-sm" onclick="holdSale()">
        <i class="bi bi-pause-circle me-1"></i>Hold Sale
    </button>',
            '<button type="button" class="btn btn-outline-warning w-100 mt-1 btn-sm" onclick="holdSale()">
        <i class="bi bi-pause-circle me-1"></i>Hold Sale
    </button>
    <?php if ($cashDrawer): ?>
    <button type="button" class="btn btn-outline-secondary w-100 mt-1 btn-sm" onclick="openCashDrawer()">
        <i class="bi bi-safe me-1"></i>Open Drawer
    </button>
    <?php endif; ?>',
            $src
        );

        if (strpos($src, 'syncDisplay()') === false) {
            $src = str_replace(
                "    updateDiscount();\n}",
                "    updateDiscount();\n    syncDisplay();\n}",
                $src
            );
        }

        if (strpos($src, 'openCashDrawer, 800') === false) {
            $src = str_replace(
                "<?php if (\$autoPrint):    ?>window.addEventListener('load', () => setTimeout(printPosReceipt, 500));<?php endif; ?>",
                "<?php if (\$autoPrint):    ?>window.addEventListener('load', () => setTimeout(printPosReceipt, 500));<?php endif; ?>\n" .
                "<?php if (\$cashDrawer):  ?>window.addEventListener('load', () => setTimeout(openCashDrawer, 800));<?php endif; ?>\n" .
                "<?php if (\$custDisplay): ?>window.addEventListener('load', () => { localStorage.setItem('posDisplay', JSON.stringify({items:[], total:0})); });<?php endif; ?>\n" .
                "<?php if (!\$lastSale && \$custDisplay): ?>window.addEventListener('load', openDisplayWindow);<?php endif; ?>",
                $src
            );
        }

        file_put_contents($pf, $src);
        $steps[] = 'pos.php — customer display sync and cash drawer added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 5 (pos.php): ' . $e->getMessage();
}

// ── Block 6: pages/products.php — Low Stock PDF ───────────────────────────────
try {
    $rf  = __DIR__ . '/pages/products.php';
    $src = file_get_contents($rf);
    if (strpos($src, 'lowstock_pdf') !== false) {
        $steps[] = 'products.php — lowstock_pdf already added, skipped';
    } else {
        $buttonHtml = '<a href="products.php?export=lowstock_pdf" target="_blank" class="btn btn-outline-danger btn-sm">'
            . '<i class="bi bi-file-earmark-pdf"></i> Low Stock PDF</a>' . "\n        ";
        $src = str_replace(
            '<a href="products.php?export=products_pdf"',
            $buttonHtml . '<a href="products.php?export=products_pdf"',
            $src
        );

        $handlerCode = "\n// ─── Low Stock PDF\n"
            . "if ((\$_GET['export'] ?? '') === 'lowstock_pdf') {\n"
            . "    requireRole('admin','owner','manager');\n"
            . "    \$rows = \$pdo->query(\n"
            . "        \"SELECT p.name, COALESCE(s.name,'—') AS supplier, p.unit, p.stock, p.low_stock_alert\"\n"
            . "        .\" FROM products p LEFT JOIN suppliers s ON s.id=p.supplier_id\"\n"
            . "        .\" WHERE p.stock <= p.low_stock_alert AND p.product_type != 'bulk'\"\n"
            . "        .\" ORDER BY p.stock ASC\"\n"
            . "    )->fetchAll();\n"
            . "    \$storeName = STORE_NAME; \$date = date('Y-m-d');\n"
            . "    header('Content-Type: text/html; charset=utf-8');\n"
            . "    echo '<!DOCTYPE html><html><head><meta charset=\"UTF-8\">';\n"
            . "    echo '<style>@page{size:A4 portrait;margin:15mm}body{font-family:Arial,sans-serif;font-size:11pt}';\n"
            . "    echo 'h2{text-align:center;margin-bottom:4px}.sub{text-align:center;color:#666;font-size:9pt;margin-bottom:12px}';\n"
            . "    echo 'table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:5px 8px;text-align:left}';\n"
            . "    echo 'th{background:#f3f3f3}.red{color:#c00;font-weight:bold}.org{color:#e65c00}</style></head><body>';\n"
            . "    echo '<h2>'.htmlspecialchars(\$storeName).' — Low Stock Report</h2>';\n"
            . "    echo \"<div class='sub'>Generated: \$date</div>\";\n"
            . "    echo '<table><tr><th>#</th><th>Product</th><th>Supplier</th><th>Unit</th><th>In Stock</th><th>Min Level</th></tr>';\n"
            . "    foreach (\$rows as \$i => \$r) {\n"
            . "        \$cls = \$r['stock'] == 0 ? 'red' : 'org';\n"
            . "        echo '<tr><td>'.(\$i+1).'</td><td>'.htmlspecialchars(\$r['name']).'</td>'\n"
            . "            .'<td>'.htmlspecialchars(\$r['supplier']).'</td><td>'.htmlspecialchars(\$r['unit']).'</td>'\n"
            . "            .\"<td class='\$cls'>\".htmlspecialchars(\$r['stock']).'</td>'\n"
            . "            .'<td>'.htmlspecialchars(\$r['low_stock_alert']).'</td></tr>';\n"
            . "    }\n"
            . "    echo '</table><script>window.onload=window.print;<\\/script></body></html>';\n"
            . "    exit;\n"
            . "}\n";

        $src = str_replace(
            "requireRole('admin','owner','manager','cashier');",
            "requireRole('admin','owner','manager','cashier');\n" . $handlerCode,
            $src
        );
        file_put_contents($rf, $src);
        $steps[] = 'products.php — Low Stock PDF handler and button added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 6 (products.php): ' . $e->getMessage();
}

// ── Block 7: pages/purchases.php — new_sell_price_box backend ────────────────
try {
    $uf  = __DIR__ . '/pages/purchases.php';
    $src = file_get_contents($uf);
    if (strpos($src, 'new_sell_price_box') !== false) {
        $steps[] = 'purchases.php — new_sell_price_box already added, skipped';
    } else {
        $src = str_replace(
            "\$sellPrices = \$_POST['new_sell_price'] ?? [];",
            "\$sellPrices    = \$_POST['new_sell_price']     ?? [];\n    " .
            "\$sellBoxPrices = \$_POST['new_sell_price_box'] ?? [];",
            $src
        );
        file_put_contents($uf, $src);
        $steps[] = 'purchases.php — new_sell_price_box POST variable added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 7 (purchases.php): ' . $e->getMessage();
}

// ── Block 8: pages/api.php — report_analysis action ──────────────────────────
try {
    $af  = __DIR__ . '/pages/api.php';
    $src = file_get_contents($af);
    if (strpos($src, 'report_analysis') !== false) {
        $steps[] = 'api.php — report_analysis already added, skipped';
    } else {
        $newAction = "\n// ─── Report analysis\n"
            . "if (\$action === 'report_analysis') {\n"
            . "    requireRole('admin','owner','manager');\n"
            . "    \$from=\$_GET['from']??date('Y-m-01'); \$to=\$_GET['to']??date('Y-m-d');\n"
            . "    \$productId=(int)(\$_GET['product_id']??0);\n"
            . "    \$categoryId=(int)(\$_GET['category_id']??0);\n"
            . "    \$supplierId=(int)(\$_GET['supplier_id']??0);\n"
            . "    \$sW=['DATE(s.sale_date) BETWEEN ? AND ?','s.is_void = 0']; \$sP=[\$from,\$to];\n"
            . "    if(\$productId){\$sW[]='si.product_id=?';\$sP[]=\$productId;}\n"
            . "    if(\$categoryId){\$sW[]='p.category_id=?';\$sP[]=\$categoryId;}\n"
            . "    if(\$supplierId){\$sW[]='p.supplier_id=?';\$sP[]=\$supplierId;}\n"
            . "    \$ss=\$pdo->prepare('SELECT SUM(si.quantity) AS units_sold,SUM(si.total) AS revenue,SUM(si.quantity*si.unit_cost) AS cogs FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE '.implode(' AND ',\$sW));\n"
            . "    \$ss->execute(\$sP); \$sr=\$ss->fetch();\n"
            . "    \$tp=\$pdo->prepare('SELECT p.name,SUM(si.quantity) AS units,SUM(si.total) AS revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE '.implode(' AND ',\$sW).' GROUP BY si.product_id ORDER BY revenue DESC LIMIT 10');\n"
            . "    \$tp->execute(\$sP); \$topP=\$tp->fetchAll();\n"
            . "    \$pW=['DATE(pu.purchase_date) BETWEEN ? AND ?']; \$pP=[\$from,\$to];\n"
            . "    if(\$productId){\$pW[]='pi.product_id=?';\$pP[]=\$productId;}\n"
            . "    if(\$categoryId){\$pW[]='p.category_id=?';\$pP[]=\$categoryId;}\n"
            . "    if(\$supplierId){\$pW[]='pu.supplier_id=?';\$pP[]=\$supplierId;}\n"
            . "    \$ps=\$pdo->prepare('SELECT SUM(pi.quantity) AS units_purchased,SUM(pi.total) AS purchase_cost FROM purchase_items pi JOIN purchases pu ON pu.id=pi.purchase_id LEFT JOIN products p ON p.id=pi.product_id WHERE '.implode(' AND ',\$pW));\n"
            . "    \$ps->execute(\$pP); \$pr=\$ps->fetch();\n"
            . "    echo json_encode(['units_sold'=>(float)(\$sr['units_sold']??0),'revenue'=>(float)(\$sr['revenue']??0),'cogs'=>(float)(\$sr['cogs']??0),'units_purchased'=>(float)(\$pr['units_purchased']??0),'purchase_cost'=>(float)(\$pr['purchase_cost']??0),'top_products'=>\$topP]);\n"
            . "    exit;\n}\n";

        $src = str_replace(
            "\necho json_encode(['error' => 'Unknown action']);",
            $newAction . "\necho json_encode(['error' => 'Unknown action']);",
            $src
        );
        file_put_contents($af, $src);
        $steps[] = 'api.php — report_analysis action added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 8 (api.php): ' . $e->getMessage();
}

// ── Block 9: pages/reports.php — Analysis section ────────────────────────────
try {
    $rp  = __DIR__ . '/pages/reports.php';
    $src = file_get_contents($rp);
    if (strpos($src, 'report_analysis') !== false || strpos($src, 'runAnalysis') !== false) {
        $steps[] = 'reports.php — Analysis section already added, skipped';
    } else {
        $analysisBlock = '
<!-- ═══ Sales & Purchase Analysis ═══════════════════════════════════════════ -->
<div class="card stat-card p-4 mb-4">
  <h5 class="fw-bold mb-3"><i class="bi bi-search me-2"></i>Sales &amp; Purchase Analysis</h5>
  <div class="row g-2 mb-3">
    <div class="col-md-4">
      <label class="form-label small text-muted mb-1">Category</label>
      <select id="an-cat" class="form-select form-select-sm" onchange="runAnalysis()">
        <option value="">All Categories</option>
        <?php foreach ($pdo->query("SELECT id,name FROM categories ORDER BY name") as $c): ?>
        <option value="<?= $c[\'id\'] ?>"><?= htmlspecialchars($c[\'name\']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small text-muted mb-1">Supplier</label>
      <select id="an-sup" class="form-select form-select-sm" onchange="runAnalysis()">
        <option value="">All Suppliers</option>
        <?php foreach ($pdo->query("SELECT id,name FROM suppliers ORDER BY name") as $s): ?>
        <option value="<?= $s[\'id\'] ?>"><?= htmlspecialchars($s[\'name\']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small text-muted mb-1">Product</label>
      <div class="position-relative">
        <input type="text" id="an-prod-search" class="form-control form-control-sm" placeholder="Search product&hellip;"
               oninput="anSearchProduct(this.value)" autocomplete="off">
        <div id="an-prod-results" class="dropdown-menu w-100" style="max-height:200px;overflow-y:auto"></div>
        <input type="hidden" id="an-prod-id">
        <button type="button" class="btn btn-link btn-sm p-0 position-absolute end-0 top-50 translate-middle-y me-1"
                onclick="document.getElementById(\'an-prod-search\').value=\'\';document.getElementById(\'an-prod-id\').value=\'\';runAnalysis()">&#x2715;</button>
      </div>
    </div>
  </div>
  <div class="row g-3 mb-3" id="an-results" style="display:none">
    <div class="col-6 col-md-3">
      <div class="card bg-success bg-opacity-10 p-3 text-center">
        <div class="text-muted small">Units Sold</div>
        <div class="fw-bold fs-4" id="an-units-sold">—</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card bg-primary bg-opacity-10 p-3 text-center">
        <div class="text-muted small">Sales Revenue</div>
        <div class="fw-bold fs-4" id="an-revenue">—</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card bg-warning bg-opacity-10 p-3 text-center">
        <div class="text-muted small">Units Purchased</div>
        <div class="fw-bold fs-4" id="an-units-pur">—</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card bg-danger bg-opacity-10 p-3 text-center">
        <div class="text-muted small">Purchase Cost</div>
        <div class="fw-bold fs-4" id="an-pur-cost">—</div>
      </div>
    </div>
  </div>
  <div id="an-top-wrap" style="display:none">
    <h6 class="text-muted small fw-bold mb-2">TOP PRODUCTS</h6>
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Revenue</th></tr></thead>
      <tbody id="an-top-body"></tbody>
    </table>
  </div>
</div>
<script>
window.runAnalysis = function() {
    var FROM = (document.getElementById(\'reportFrom\')||document.getElementById(\'dateFrom\')||{}).value || \'<?= date("Y-m-01") ?>\';
    var TO   = (document.getElementById(\'reportTo\')||document.getElementById(\'dateTo\')||{}).value   || \'<?= date("Y-m-d") ?>\';
    var cat  = document.getElementById(\'an-cat\').value;
    var sup  = document.getElementById(\'an-sup\').value;
    var pid  = document.getElementById(\'an-prod-id\').value;
    var url  = \'/dahdouh/pages/api.php?action=report_analysis&from=\'+FROM+\'&to=\'+TO;
    if(cat) url+=\'&category_id=\'+cat;
    if(sup) url+=\'&supplier_id=\'+sup;
    if(pid) url+=\'&product_id=\'+pid;
    fetch(url).then(function(r){return r.json();}).then(function(d){
        document.getElementById(\'an-results\').style.display=\'\';
        document.getElementById(\'an-units-sold\').textContent=parseFloat(d.units_sold||0).toLocaleString();
        document.getElementById(\'an-revenue\').textContent=\'$\'+parseFloat(d.revenue||0).toFixed(2);
        document.getElementById(\'an-units-pur\').textContent=parseFloat(d.units_purchased||0).toLocaleString();
        document.getElementById(\'an-pur-cost\').textContent=\'$\'+parseFloat(d.purchase_cost||0).toFixed(2);
        var top=d.top_products||[];
        document.getElementById(\'an-top-wrap\').style.display=top.length>1?\'\':\'none\';
        var tb=document.getElementById(\'an-top-body\'); tb.innerHTML=\'\';
        top.forEach(function(r){tb.innerHTML+=\'<tr><td>\'+(r.name||\'\')+\'</td><td class="text-end">\'+parseFloat(r.units||0).toLocaleString()+\'</td><td class="text-end">$\'+parseFloat(r.revenue||0).toFixed(2)+\'</td></tr>\';});
    }).catch(function(){});
};
var _anTimer=null;
function anSearchProduct(q){
    clearTimeout(_anTimer);
    if(!q){document.getElementById(\'an-prod-results\').classList.remove(\'show\');return;}
    _anTimer=setTimeout(function(){
        fetch(\'/dahdouh/pages/api.php?action=search_products_purchase&q=\'+encodeURIComponent(q))
            .then(function(r){return r.json();}).then(function(list){
                var dd=document.getElementById(\'an-prod-results\'); dd.innerHTML=\'\';
                (list||[]).forEach(function(p){
                    var a=document.createElement(\'a\'); a.className=\'dropdown-item\'; a.href=\'#\';
                    a.textContent=p.name;
                    a.onclick=function(e){e.preventDefault();anPickProduct(p.id,p.name);};
                    dd.appendChild(a);
                });
                dd.classList.add(\'show\');
            });
    },300);
}
function anPickProduct(id,name){
    document.getElementById(\'an-prod-search\').value=name;
    document.getElementById(\'an-prod-id\').value=id;
    document.getElementById(\'an-prod-results\').classList.remove(\'show\');
    runAnalysis();
}
document.addEventListener(\'click\',function(e){
    if(!e.target.closest(\'#an-prod-search\')&&!e.target.closest(\'#an-prod-results\'))
        document.getElementById(\'an-prod-results\').classList.remove(\'show\');
});
</script>
';
        $anchor = '<!-- void';
        if (strpos($src, $anchor) !== false) {
            $src = str_replace($anchor, $analysisBlock . $anchor, $src);
        } else {
            $src .= $analysisBlock;
        }
        file_put_contents($rp, $src);
        $steps[] = 'reports.php — Analysis section added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 9 (reports.php): ' . $e->getMessage();
}

// ── Block 10: version.json → v3.3.0 ──────────────────────────────────────────
try {
    $vf = json_decode(file_get_contents(__DIR__ . '/version.json') ?: '{}', true) ?: [];
    if (in_array(15, $vf['installed_upgrades'] ?? [])) {
        $steps[] = 'version.json — already at v3.3.0, skipped';
    } else {
        $installed = $vf['installed_upgrades'] ?? [];
        $installed[] = 15; sort($installed);
        $vf['installed_upgrades'] = $installed;
        $vf['version']      = '3.3.0';
        $vf['last_updated'] = date('Y-m-d');
        file_put_contents(__DIR__ . '/version.json', json_encode($vf, JSON_PRETTY_PRINT));
        $steps[] = 'version.json → v3.3.0, upgrade 15 marked installed';
    }
} catch (Exception $e) {
    $errors[] = 'Block 10 (version.json): ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 15 — v3.3.0</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:680px">
<div class="card shadow-sm p-4">
  <h4 class="fw-bold mb-1"><i class="bi bi-arrow-up-circle me-2 text-success"></i>Upgrade 15 — v3.3.0</h4>
  <p class="text-muted small mb-1">What's new:</p>
  <ul class="small text-muted mb-4">
    <li><strong>Customer Display</strong> — POS syncs running total to a second window for the customer</li>
    <li><strong>Cash Drawer</strong> — auto-opens on checkout, manual button in POS (Settings &rarr; Hardware)</li>
    <li><strong>Reports Analysis</strong> — filter sales &amp; purchases by product, category, or supplier</li>
    <li><strong>Low Stock PDF</strong> — printable PDF from Products page</li>
    <li><strong>Wholesale Box Price</strong> — set new wholesale price per box when cost changes on purchase</li>
  </ul>
  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong>Errors:</strong>
    <ul class="mb-0 mt-2"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if ($steps): ?>
  <div class="alert alert-success">
    <strong>Steps completed:</strong>
    <ul class="mb-0 mt-2"><?php foreach($steps as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if (!$errors): ?>
  <div class="alert alert-info mb-0"><i class="bi bi-check-circle me-1"></i>Upgrade 15 complete. You may delete <code>upgrade15.php</code>.</div>
  <?php else: ?>
  <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Some steps failed — review errors above.</div>
  <?php endif; ?>
  <a href="/dahdouh/" class="btn btn-primary mt-3">&larr; Dashboard</a>
</div>
</div>
</body>
</html>
