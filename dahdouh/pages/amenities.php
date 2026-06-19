<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message = '';
$rate    = EXCHANGE_RATE;

// ── Settle supplier ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle') {
    $supplierId = (int)$_POST['supplier_id'];
    $amount     = (float)$_POST['amount'];
    $note       = trim($_POST['note'] ?? '');
    $payMethod  = trim($_POST['pay_method'] ?? 'cash_register');
    $amountLBP  = (float)($_POST['amount_lbp'] ?? 0);
    // For LBP payment, derive USD equivalent for settlement bookkeeping
    if ($payMethod === 'cash_register_lbp' && $amountLBP > 0) {
        $amount = round($amountLBP / EXCHANGE_RATE, 2);
    }
    if (($amount > 0 || ($payMethod === 'cash_register_lbp' && $amountLBP > 0)) && $supplierId) {
        $supRow = $pdo->prepare("SELECT name FROM suppliers WHERE id=?");
        $supRow->execute([$supplierId]);
        $supName = $supRow->fetchColumn() ?: "Supplier #$supplierId";

        $pdo->prepare("INSERT INTO consignment_settlements (supplier_id, amount_paid, note) VALUES (?,?,?)")
            ->execute([$supplierId, $amount, $note]);
        // FIFO: mark individual ledger rows as settled only up to the amount paid
        $toMark  = $amount;
        $pending = $pdo->prepare("SELECT id, supplier_due FROM consignment_ledger WHERE supplier_id=? AND settled=0 ORDER BY sale_date ASC");
        $pending->execute([$supplierId]);
        foreach ($pending->fetchAll() as $clRow) {
            if ($toMark <= 0) break;
            if ($toMark >= (float)$clRow['supplier_due']) {
                $pdo->prepare("UPDATE consignment_ledger SET settled=1 WHERE id=?")->execute([$clRow['id']]);
                $toMark -= (float)$clRow['supplier_due'];
            }
        }
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'payment',?,?)")
            ->execute([$supplierId, -$amount, "Settlement paid: " . ($note ?: 'Consignment payment')]);

        $cashNote = '';
        if ($payMethod === 'cash_register') {
            logCashEntry($pdo, 'withdrawal', -$amount,
                "Paid consignment supplier: $supName" . ($note ? " — $note" : ''));
            $cashNote = " USD cash withdrawn from register.";
        } elseif ($payMethod === 'cash_register_lbp') {
            logCashEntry($pdo, 'withdrawal', 0,
                "Paid consignment supplier (LBP): $supName" . ($note ? " — $note" : ''), null, -$amountLBP, 'LBP');
            $cashNote = " LBP cash withdrawn from register (≈ " . fmtUSD($amount) . ").";
        } elseif ($payMethod === 'cash_owner') {
            logCashEntry($pdo, 'deposit', $amount,
                "Owner cash — paid consignment supplier: $supName" . ($note ? " — $note" : ''));
            $cashNote = " Owner cash recorded as deposit.";
        }
        $message = "success:Settlement of " . fmtUSD($amount) . " recorded for $supName.$cashNote";
    }
}

// ── Add consignment stock ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    $pid = (int)$_POST['product_id'];
    $qty = (float)$_POST['quantity'];
    if ($pid && $qty > 0) {
        $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ? AND product_source = 'consignment'")
            ->execute([$qty, $pid]);
        $message = "success:Added $qty units to stock.";
    }
}

// ── View / tab ───────────────────────────────────────────────────────────────
$view = $_GET['view'] ?? 'inventory';

// ── Selected supplier (inventory tab) ───────────────────────────────────────
$activeSup = (int)($_GET['supplier'] ?? 0);
$from      = $_GET['from'] ?? date('Y-m-01');
$to        = $_GET['to']   ?? date('Y-m-d');

// ── Consignment suppliers ────────────────────────────────────────────────────
$consSup = $pdo->query("
    SELECT s.id, s.name, s.phone,
           COUNT(DISTINCT p.id) AS product_count,
           COALESCE(SUM(p.stock), 0) AS total_stock,
           GREATEST(0,
             COALESCE((SELECT SUM(cl.supplier_due) FROM consignment_ledger cl WHERE cl.supplier_id=s.id), 0)
             - COALESCE((SELECT SUM(cs.amount_paid) FROM consignment_settlements cs WHERE cs.supplier_id=s.id), 0)
           ) AS unsettled_due,
           COALESCE((SELECT SUM(cl.market_profit) FROM consignment_ledger cl WHERE cl.supplier_id=s.id), 0) AS total_market_profit
    FROM suppliers s
    JOIN products p ON p.consignment_supplier_id = s.id AND p.product_source = 'consignment'
    GROUP BY s.id ORDER BY s.name
")->fetchAll();

// ── Active supplier data ──────────────────────────────────────────────────────
$supProducts = $supSales = $settlements = [];
$supData     = null;
$supStats    = ['revenue'=>0,'supplier_due'=>0,'market_profit'=>0,'unsettled'=>0];

if ($activeSup) {
    $st = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $st->execute([$activeSup]);
    $supData = $st->fetch();

    $supProducts = $pdo->prepare("
        SELECT p.*, c.name AS cat_name FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE p.consignment_supplier_id=? AND p.product_source='consignment'
        ORDER BY p.name
    ");
    $supProducts->execute([$activeSup]);
    $supProducts = $supProducts->fetchAll();

    $supSales = $pdo->prepare("
        SELECT cl.*, p.name AS product_name, s.receipt_no, s.sale_date
        FROM consignment_ledger cl
        JOIN products p ON p.id=cl.product_id
        JOIN sales s ON s.id=cl.sale_id
        WHERE cl.supplier_id=? AND DATE(cl.sale_date) BETWEEN ? AND ?
        ORDER BY cl.sale_date DESC
    ");
    $supSales->execute([$activeSup, $from, $to]);
    $supSales = $supSales->fetchAll();

    foreach ($supSales as $sl) {
        $supStats['revenue']       += $sl['revenue'];
        $supStats['supplier_due']  += $sl['supplier_due'];
        $supStats['market_profit'] += $sl['market_profit'];
        if (!$sl['settled']) $supStats['unsettled'] += $sl['supplier_due'];
    }

    $settlements = $pdo->prepare("
        SELECT * FROM consignment_settlements WHERE supplier_id=? ORDER BY settled_at DESC LIMIT 20
    ");
    $settlements->execute([$activeSup]);
    $settlements = $settlements->fetchAll();

    // True outstanding balance: all-time due minus all payments (not date-filtered)
    $toStmt = $pdo->prepare("
        SELECT GREATEST(0,
            COALESCE((SELECT SUM(cl.supplier_due) FROM consignment_ledger cl WHERE cl.supplier_id=?), 0)
            - COALESCE((SELECT SUM(cs.amount_paid) FROM consignment_settlements cs WHERE cs.supplier_id=?), 0)
        )
    ");
    $toStmt->execute([$activeSup, $activeSup]);
    $trueOutstanding = (float)$toStmt->fetchColumn();
}

// ── Overall summary (all-time) ────────────────────────────────────────────────
$overallStats = $pdo->query("
    SELECT COALESCE(SUM(revenue),0) AS revenue,
           COALESCE(SUM(supplier_due),0) AS supplier_due,
           COALESCE(SUM(market_profit),0) AS market_profit,
           GREATEST(0,
             COALESCE(SUM(supplier_due),0)
             - COALESCE((SELECT SUM(amount_paid) FROM consignment_settlements),0)
           ) AS unsettled
    FROM consignment_ledger
")->fetch();

// ── Reports tab data ──────────────────────────────────────────────────────────
$reportFrom = $_GET['rfrom'] ?? date('Y-m-01');
$reportTo   = $_GET['rto']   ?? date('Y-m-d');

$rsStmt = $pdo->prepare("
    SELECT COALESCE(SUM(revenue),0) AS revenue,
           COALESCE(SUM(supplier_due),0) AS supplier_due,
           COALESCE(SUM(market_profit),0) AS market_profit,
           COUNT(*) AS sale_count
    FROM consignment_ledger
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$rsStmt->execute([$reportFrom, $reportTo]);
$reportStats = $rsStmt->fetch();

$rsBySupStmt = $pdo->prepare("
    SELECT s.id, s.name AS supplier_name,
           COALESCE(SUM(cl.revenue),0) AS revenue,
           COALESCE(SUM(cl.supplier_due),0) AS supplier_due,
           COALESCE(SUM(cl.market_profit),0) AS market_profit,
           COUNT(*) AS sale_count
    FROM consignment_ledger cl
    JOIN suppliers s ON s.id = cl.supplier_id
    WHERE DATE(cl.sale_date) BETWEEN ? AND ?
    GROUP BY cl.supplier_id, s.name
    ORDER BY revenue DESC
");
$rsBySupStmt->execute([$reportFrom, $reportTo]);
$reportBySup = $rsBySupStmt->fetchAll();

$rsByProdStmt = $pdo->prepare("
    SELECT p.name AS product_name, s.name AS supplier_name,
           COALESCE(SUM(cl.quantity),0) AS total_qty,
           COALESCE(SUM(cl.revenue),0) AS revenue,
           COALESCE(SUM(cl.supplier_due),0) AS supplier_due,
           COALESCE(SUM(cl.market_profit),0) AS market_profit
    FROM consignment_ledger cl
    JOIN products p ON p.id = cl.product_id
    JOIN suppliers s ON s.id = cl.supplier_id
    WHERE DATE(cl.sale_date) BETWEEN ? AND ?
    GROUP BY cl.product_id, p.name, s.name
    ORDER BY revenue DESC
");
$rsByProdStmt->execute([$reportFrom, $reportTo]);
$reportByProd = $rsByProdStmt->fetchAll();

renderHead('Amenities / Consignment');
renderNav('amenities');
alertBox($message);
?>
<div class="container-fluid py-3">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-boxes me-2"></i>Amenities <small class="text-muted fs-6 fw-normal">(Consignment Inventory)</small></h4>
    <a href="/dahdouh/pages/products.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Add Consignment Product
    </a>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $view !== 'reports' ? 'active' : '' ?>"
           href="?view=inventory<?= $activeSup ? '&supplier='.$activeSup : '' ?>">
            <i class="bi bi-boxes me-1"></i>Inventory
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $view === 'reports' ? 'active' : '' ?>" href="?view=reports">
            <i class="bi bi-bar-chart-line me-1"></i>Reports
        </a>
    </li>
</ul>

<?php if ($view === 'reports'): ?>
<!-- ════════════════════════════════════════════════════════ REPORTS TAB ══ -->

<!-- Date filter -->
<form class="row g-2 mb-4 align-items-end" method="GET">
    <input type="hidden" name="view" value="reports">
    <div class="col-auto">
        <label class="form-label small">From</label>
        <input type="date" name="rfrom" class="form-control" value="<?= $reportFrom ?>">
    </div>
    <div class="col-auto">
        <label class="form-label small">To</label>
        <input type="date" name="rto" class="form-control" value="<?= $reportTo ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button>
    </div>
    <div class="col-auto">
        <a href="?view=reports&rfrom=<?= date('Y-m-d') ?>&rto=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">Today</a>
        <a href="?view=reports&rfrom=<?= date('Y-m-01') ?>&rto=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">This Month</a>
    </div>
</form>

<!-- Period summary cards -->
<div class="row g-3 mb-4">
    <?php
    $rc = [
        ['Period Revenue',      fmtUSD($reportStats['revenue']),       fmtLBP($reportStats['revenue']*$rate),       'bi-cash-stack',   'text-primary'],
        ['Supplier Due',        fmtUSD($reportStats['supplier_due']),  fmtLBP($reportStats['supplier_due']*$rate),  'bi-person-check', 'text-warning'],
        ['Your Market Cut',     fmtUSD($reportStats['market_profit']), fmtLBP($reportStats['market_profit']*$rate), 'bi-graph-up',     'text-success'],
        ['Transactions',        $reportStats['sale_count'] . ' sales', '', 'bi-receipt', 'text-muted'],
    ];
    foreach ($rc as [$label,$val,$sub,$icon,$cls]): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 text-center">
            <i class="bi <?= $icon ?> fs-3 <?= $cls ?>"></i>
            <div class="small text-muted mt-1"><?= $label ?></div>
            <div class="fw-bold fs-5 <?= $cls ?>"><?= $val ?></div>
            <?php if ($sub): ?><div class="text-muted small"><?= $sub ?></div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($reportBySup): ?>
<!-- By Supplier -->
<h5 class="fw-bold mb-2"><i class="bi bi-person-lines-fill me-2"></i>By Supplier</h5>
<div class="card stat-card mb-4">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Supplier</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Revenue</th>
        <th class="text-end">Supplier Due</th>
        <th class="text-end">Your Cut</th>
        <th class="text-end">Margin %</th>
    </tr></thead>
    <tbody>
    <?php foreach ($reportBySup as $rs):
        $margin = $rs['revenue'] > 0 ? round($rs['market_profit'] / $rs['revenue'] * 100, 1) : 0;
    ?>
    <tr>
        <td class="fw-semibold">
            <a href="?view=inventory&supplier=<?= $rs['id'] ?>" class="text-decoration-none">
                <?= htmlspecialchars($rs['supplier_name']) ?>
                <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
            </a>
        </td>
        <td class="text-end"><?= $rs['sale_count'] ?></td>
        <td class="text-end text-primary fw-bold"><?= fmtUSD($rs['revenue']) ?></td>
        <td class="text-end text-warning"><?= fmtUSD($rs['supplier_due']) ?></td>
        <td class="text-end text-success fw-bold"><?= fmtUSD($rs['market_profit']) ?></td>
        <td class="text-end">
            <span class="badge <?= $margin>20?'bg-success':($margin>10?'bg-warning text-dark':'bg-danger') ?>">
                <?= $margin ?>%
            </span>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
    <tr>
        <td>Total</td>
        <td class="text-end"><?= $reportStats['sale_count'] ?></td>
        <td class="text-end text-primary"><?= fmtUSD($reportStats['revenue']) ?></td>
        <td class="text-end text-warning"><?= fmtUSD($reportStats['supplier_due']) ?></td>
        <td class="text-end text-success"><?= fmtUSD($reportStats['market_profit']) ?></td>
        <td></td>
    </tr>
    </tfoot>
</table>
</div>
</div>

<!-- By Product -->
<h5 class="fw-bold mb-2"><i class="bi bi-tag me-2"></i>By Product</h5>
<div class="card stat-card mb-4">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0 small">
    <thead class="table-dark"><tr>
        <th>Product</th>
        <th>Supplier</th>
        <th class="text-end">Qty Sold</th>
        <th class="text-end">Revenue</th>
        <th class="text-end">Supplier Due</th>
        <th class="text-end">Your Cut</th>
        <th class="text-end">Margin %</th>
    </tr></thead>
    <tbody>
    <?php foreach ($reportByProd as $rp):
        $margin = $rp['revenue'] > 0 ? round($rp['market_profit'] / $rp['revenue'] * 100, 1) : 0;
    ?>
    <tr>
        <td class="fw-semibold"><?= htmlspecialchars($rp['product_name']) ?></td>
        <td class="text-muted"><?= htmlspecialchars($rp['supplier_name']) ?></td>
        <td class="text-end"><?= (float)$rp['total_qty'] ?></td>
        <td class="text-end text-primary"><?= fmtUSD($rp['revenue']) ?></td>
        <td class="text-end text-warning"><?= fmtUSD($rp['supplier_due']) ?></td>
        <td class="text-end text-success fw-bold"><?= fmtUSD($rp['market_profit']) ?></td>
        <td class="text-end">
            <span class="badge <?= $margin>20?'bg-success':($margin>10?'bg-warning text-dark':'bg-danger') ?>">
                <?= $margin ?>%
            </span>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php else: ?>
<div class="card stat-card p-5 text-center text-muted">
    <i class="bi bi-bar-chart fs-1 mb-2"></i>
    <div>No consignment sales in this period.</div>
    <div class="small mt-1"><?= date('d/m/Y', strtotime($reportFrom)) ?> – <?= date('d/m/Y', strtotime($reportTo)) ?></div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════ INVENTORY TAB ══ -->

<!-- Overall summary cards (all-time) -->
<div class="row g-3 mb-4">
    <?php
    $oc = [
        ['Total Revenue',        fmtUSD($overallStats['revenue']),       fmtLBP($overallStats['revenue']*$rate),       'bi-cash-stack',   'text-primary'],
        ['Total Supplier Due',   fmtUSD($overallStats['supplier_due']),  fmtLBP($overallStats['supplier_due']*$rate),  'bi-person-check', 'text-warning'],
        ['Your Market Cut',      fmtUSD($overallStats['market_profit']), fmtLBP($overallStats['market_profit']*$rate), 'bi-graph-up',     'text-success'],
        ['Unsettled (Owed)',     fmtUSD($overallStats['unsettled']),     fmtLBP($overallStats['unsettled']*$rate),     'bi-clock-history','text-danger'],
    ];
    foreach ($oc as [$label,$val,$sub,$icon,$cls]): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 text-center">
            <i class="bi <?= $icon ?> fs-3 <?= $cls ?>"></i>
            <div class="small text-muted mt-1"><?= $label ?></div>
            <div class="fw-bold fs-5 <?= $cls ?>"><?= $val ?></div>
            <div class="text-muted small"><?= $sub ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">

<!-- ── Supplier list ── -->
<div class="col-lg-3">
<div class="card stat-card">
<div class="card-header fw-bold small py-2 bg-dark text-white">Consignment Suppliers</div>
<div class="list-group list-group-flush">
<?php if (!$consSup): ?>
<div class="list-group-item text-muted small py-3 text-center">No consignment suppliers yet.<br>Add products with source = Consignment.</div>
<?php endif; ?>
<?php foreach ($consSup as $cs): ?>
<a href="?view=inventory&supplier=<?= $cs['id'] ?>"
   class="list-group-item list-group-item-action <?= $activeSup==$cs['id']?'active':'' ?> py-2">
    <div class="fw-semibold"><?= htmlspecialchars($cs['name']) ?></div>
    <div class="small <?= $activeSup==$cs['id']?'text-white-50':'text-muted' ?>">
        <?= $cs['product_count'] ?> products · <?= (float)$cs['total_stock'] ?> in stock
    </div>
    <?php if ((float)$cs['unsettled_due'] > 0): ?>
    <div class="small <?= $activeSup==$cs['id']?'text-warning':'text-danger' ?>">
        Due: <?= fmtUSD($cs['unsettled_due']) ?>
    </div>
    <?php endif; ?>
</a>
<?php endforeach; ?>
</div>
</div>
</div>

<!-- ── Supplier detail ── -->
<div class="col-lg-9">
<?php if (!$activeSup): ?>
<div class="card stat-card p-5 text-center text-muted">
    <i class="bi bi-arrow-left-circle fs-1 mb-2"></i>
    <div>Select a supplier to view their consignment details.</div>
</div>
<?php else: ?>

<!-- Date filter -->
<form class="d-flex gap-2 mb-3 align-items-end flex-wrap" method="GET">
    <input type="hidden" name="view" value="inventory">
    <input type="hidden" name="supplier" value="<?= $activeSup ?>">
    <div><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>"></div>
    <div><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>"></div>
    <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button></div>
</form>

<!-- Supplier stats -->
<div class="row g-2 mb-3">
<?php
$sc = [
    ['Period Revenue',      fmtUSD($supStats['revenue']),       'text-primary'],
    ['Supplier Due',        fmtUSD($supStats['supplier_due']),  'text-warning'],
    ['Your Cut (Margin)',   fmtUSD($supStats['market_profit']), 'text-success'],
    ['Unsettled Amount',    fmtUSD($trueOutstanding),           'text-danger'],
];
foreach ($sc as [$label,$val,$cls]): ?>
<div class="col-6 col-md-3">
<div class="card stat-card p-2 text-center">
    <div class="small text-muted"><?= $label ?></div>
    <div class="fw-bold <?= $cls ?>"><?= $val ?></div>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="row g-3">

<!-- Products + stock delivery -->
<div class="col-md-5">
<div class="card stat-card p-3 mb-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-box-seam me-2"></i><?= htmlspecialchars($supData['name']) ?>'s Products</h6>
    <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Product</th><th>Sell</th><th>Cost%</th><th>Stock</th><th>+Stock</th></tr></thead>
        <tbody>
        <?php foreach ($supProducts as $pp):
            $margin = $pp['sell_price']>0 ? round((($pp['sell_price']-$pp['consignment_cost'])/$pp['sell_price'])*100,1) : 0;
        ?>
        <tr>
            <td class="small fw-semibold"><?= htmlspecialchars($pp['name']) ?></td>
            <td class="small"><?= fmtUSD($pp['sell_price']) ?></td>
            <td><span class="badge <?= $margin>20?'bg-success':($margin>10?'bg-warning text-dark':'bg-danger') ?>"><?= $margin ?>%</span></td>
            <td class="<?= $pp['stock']<=0?'text-danger fw-bold':($pp['stock']<=$pp['low_stock_alert']?'text-warning':'text-success') ?>"><?= (float)$pp['stock'] ?></td>
            <td>
                <form method="POST" class="d-flex gap-1">
                    <input type="hidden" name="action" value="add_stock">
                    <input type="hidden" name="product_id" value="<?= $pp['id'] ?>">
                    <input type="number" name="quantity" class="form-control form-control-sm" style="width:60px" min="0.001" step="0.001" placeholder="qty">
                    <button type="submit" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-plus"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$supProducts): ?><tr><td colspan="5" class="text-muted text-center">No products.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Settlement -->
<div class="card stat-card p-3 border-warning">
    <h6 class="fw-bold mb-2 text-warning"><i class="bi bi-cash me-2"></i>Pay Supplier</h6>
    <?php if ($trueOutstanding > 0): ?>
    <div class="alert alert-warning py-2 small mb-2">Outstanding: <strong><?= fmtUSD($trueOutstanding) ?></strong></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="action" value="settle">
        <input type="hidden" name="supplier_id" value="<?= $activeSup ?>">
        <div class="input-group input-group-sm mb-2">
            <span class="input-group-text">$</span>
            <input type="number" name="amount" class="form-control" placeholder="Amount paid" min="0.01" step="0.01"
                   value="<?= $trueOutstanding > 0 ? number_format($trueOutstanding,2,'.','') : '' ?>">
        </div>
        <input type="text" name="note" class="form-control form-control-sm mb-2" placeholder="Note (e.g. cash, transfer)">
        <select name="pay_method" id="amenity-pay-method" class="form-select form-select-sm mb-2" onchange="toggleAmenityLBP()">
            <option value="cash_register">Deduct from cash register</option>
            <option value="cash_owner">Owner paid cash (add deposit to register)</option>
            <option value="bank_transfer">Bank transfer (no cash movement)</option>
        </select>
        <div id="amenity-cash-cur" class="btn-group btn-group-sm mb-2">
            <button type="button" class="btn btn-success active" id="amenity-cur-usd" onclick="setAmenityCur('usd')">$ USD</button>
            <button type="button" class="btn btn-outline-warning" id="amenity-cur-lbp" onclick="setAmenityCur('lbp')">LL LBP</button>
        </div>
        <div id="amenity-lbp-row" class="d-none mb-2">
            <input type="number" name="amount_lbp" id="amenity-amount-lbp" class="form-control form-control-sm"
                   placeholder="Amount in LBP (e.g. 50,000,000)" min="0" step="1">
            <div class="form-text text-muted small">Enter LBP amount. USD equivalent will be calculated automatically.</div>
        </div>
        <button type="submit" class="btn btn-warning btn-sm w-100" onclick="return confirm('Record this payment to supplier?')">
            Record Settlement
        </button>
    </form>
    <?php if ($settlements): ?>
    <div class="mt-2">
        <div class="small fw-bold text-muted mb-1">Recent settlements</div>
        <?php foreach (array_slice($settlements,0,4) as $set): ?>
        <div class="d-flex justify-content-between small border-bottom py-1">
            <span><?= date('d/m/Y', strtotime($set['settled_at'])) ?></span>
            <span class="fw-bold text-success"><?= fmtUSD($set['amount_paid']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Sales history -->
<div class="col-md-7">
<div class="card stat-card">
<div class="card-header fw-bold small py-2">Sales of <?= htmlspecialchars($supData['name']) ?>'s Products</div>
<div class="table-responsive" style="max-height:500px;overflow-y:auto">
<table class="table table-sm table-hover mb-0">
    <thead class="table-dark sticky-top"><tr>
        <th>Date</th><th>Product</th><th>Qty</th><th>Revenue</th><th>Supplier Due</th><th>Your Cut</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($supSales as $sl): ?>
    <tr class="<?= $sl['settled']?'table-secondary opacity-75':'' ?>">
        <td class="small"><?= date('d/m/y H:i', strtotime($sl['sale_date'])) ?></td>
        <td class="small"><?= htmlspecialchars($sl['product_name']) ?></td>
        <td><?= (float)$sl['quantity'] ?></td>
        <td class="text-primary"><?= fmtUSD($sl['revenue']) ?></td>
        <td class="text-warning"><?= fmtUSD($sl['supplier_due']) ?></td>
        <td class="text-success fw-bold"><?= fmtUSD($sl['market_profit']) ?></td>
        <td><?= $sl['settled'] ? '<span class="badge bg-secondary">Settled</span>' : '<span class="badge bg-danger">Pending</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$supSales): ?><tr><td colspan="7" class="text-center text-muted py-3">No sales in this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

</div><!-- row -->
<?php endif; // activeSup ?>
</div><!-- col-9 -->

</div><!-- row -->
<?php endif; // view ?>
</div>
<script>
let _amenityCur = 'usd';
function toggleAmenityLBP() {
    const method = document.getElementById('amenity-pay-method')?.value;
    const isCash = method === 'cash_register';
    document.getElementById('amenity-cash-cur').style.display = isCash ? '' : 'none';
    _applyAmenityCur();
}
function setAmenityCur(cur) {
    _amenityCur = cur;
    document.getElementById('amenity-cur-usd').className = 'btn btn-' + (cur==='usd' ? 'success active' : 'outline-success');
    document.getElementById('amenity-cur-lbp').className = 'btn btn-' + (cur==='lbp' ? 'warning active' : 'outline-warning');
    _applyAmenityCur();
}
function _applyAmenityCur() {
    const method  = document.getElementById('amenity-pay-method')?.value;
    const isLBP   = method === 'cash_register' && _amenityCur === 'lbp';
    const sel     = document.getElementById('amenity-pay-method');
    if (method === 'cash_register') sel.value = isLBP ? 'cash_register_lbp' : 'cash_register';
    const row = document.getElementById('amenity-lbp-row');
    const inp = document.getElementById('amenity-amount-lbp');
    if (isLBP) {
        row.classList.remove('d-none'); inp.required = true;
    } else {
        row.classList.add('d-none'); inp.required = false; inp.value = '';
    }
}
</script>
<?php renderFoot(); ?>
