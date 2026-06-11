<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin');

// ── Void a sale ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_sale') {
    $vid    = (int)($_POST['sale_id'] ?? 0);
    $reason = trim($_POST['void_reason'] ?? 'Voided by admin');
    $me     = currentUser();

    $sale = $pdo->prepare("SELECT * FROM sales WHERE id=? AND is_void=0");
    $sale->execute([$vid]);
    $sale = $sale->fetch();

    if ($sale) {
        $pdo->beginTransaction();
        try {
            // Mark sale as void
            $pdo->prepare("UPDATE sales SET is_void=1, void_reason=?, voided_at=NOW(), voided_by=? WHERE id=?")
                ->execute([$reason, $me['id'], $vid]);

            // Restore stock for each item
            $items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
            $items->execute([$vid]);
            foreach ($items->fetchAll() as $item) {
                $qty = (float)$item['quantity'];
                if ($item['is_consignment']) {
                    // Simple stock add-back for consignment
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id=?")->execute([$qty, $item['product_id']]);
                    // Reverse consignment ledger entry
                    $pdo->prepare("DELETE FROM consignment_ledger WHERE sale_id=? AND product_id=?")->execute([$vid, $item['product_id']]);
                } elseif ($item['product_type'] === 'bulk') {
                    // Bulk has no batch, nothing to restore
                } else {
                    // Regular: add stock back and restore last batch quantity_remaining
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id=?")->execute([$qty, $item['product_id']]);
                    // Find most recently depleted batch for this product and restore
                    $batch = $pdo->prepare("SELECT id, quantity_remaining, quantity_original FROM batches WHERE product_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
                    $batch->execute([$item['product_id']]);
                    $b = $batch->fetch();
                    if ($b) {
                        $restore = min($qty, (float)$b['quantity_original']);
                        $pdo->prepare("UPDATE batches SET quantity_remaining = LEAST(quantity_original, quantity_remaining + ?) WHERE id=?")->execute([$restore, $b['id']]);
                    }
                }
            }

            // Reverse customer balance if sale had a customer
            if ($sale['customer_id']) {
                $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=?")->execute([$sale['total'], $sale['customer_id']]);
                $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,'adjustment',?,'Void of sale #?')")
                    ->execute([$sale['customer_id'], $vid, $sale['total'], $sale['receipt_no']]);
            }

            // Reverse cash register — undo net effect on each drawer
            if ($sale['payment_method'] === 'cash') {
                $netUSD = (float)$sale['paid_usd'] - (float)$sale['change_usd'];
                $netLBP = (float)$sale['paid_lbp'] - (float)$sale['change_lbp'];
                $cur = ($netUSD != 0 && $netLBP != 0) ? 'BOTH' : ($netLBP != 0 ? 'LBP' : 'USD');
                logCashEntry($pdo, 'void', -$netUSD, 'Void of sale #' . $sale['receipt_no'], $vid, -$netLBP, $cur);
            }

            $pdo->commit();
            $voidMsg = 'success:Sale #' . $sale['receipt_no'] . ' has been voided.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $voidMsg = 'error:' . $e->getMessage();
        }
    } else {
        $voidMsg = 'error:Sale not found or already voided.';
    }
    $redirectFrom = $_POST['from'] ?? date('Y-m-01');
    $redirectTo   = $_POST['to']   ?? date('Y-m-d');
    header("Location: reports.php?from=$redirectFrom&to=$redirectTo&voided=1"); exit;
}

$from    = $_GET['from'] ?? date('Y-m-01');
$to      = $_GET['to']   ?? date('Y-m-d');
$groupBy = $_GET['group'] ?? 'day';
$rate    = EXCHANGE_RATE;

$stats = getStats($pdo, $from, $to);

$groupSQL = match($groupBy) {
    'month' => "DATE_FORMAT(s.sale_date,'%Y-%m')",
    'week'  => "YEARWEEK(s.sale_date,1)",
    default => "DATE(s.sale_date)",
};

$timeline = $pdo->prepare("
    SELECT $groupSQL AS period,
           SUM(s.total) AS revenue,
           COUNT(s.id) AS txns,
           COALESCE(SUM(ci.cogs), 0) AS cogs
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(quantity * unit_cost) AS cogs
        FROM sale_items
        WHERE is_consignment = 0
        GROUP BY sale_id
    ) ci ON ci.sale_id = s.id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY $groupSQL ORDER BY $groupSQL ASC
");
$timeline->execute([$from, $to]);
$timeline = $timeline->fetchAll();

$topProducts = $pdo->prepare("
    SELECT p.name, p.product_type, SUM(si.quantity) AS units,
           SUM(si.total) AS revenue,
           SUM(si.quantity * si.unit_cost) AS cogs,
           SUM(si.total) - SUM(si.quantity * si.unit_cost) AS profit
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY p.id ORDER BY profit DESC LIMIT 20
");
$topProducts->execute([$from, $to]);
$topProducts = $topProducts->fetchAll();

// Bulk purchases in period (for bulk product COGS reference)
$bulkPurchases = $pdo->prepare("
    SELECT p.name, SUM(pi.total) AS purchase_cost
    FROM purchase_items pi
    JOIN products p ON p.id = pi.product_id
    JOIN purchases pu ON pu.id = pi.purchase_id
    WHERE pi.product_type = 'bulk' AND pu.purchase_date BETWEEN ? AND ?
    GROUP BY p.id ORDER BY purchase_cost DESC
");
$bulkPurchases->execute([$from, $to]);
$bulkPurchases = $bulkPurchases->fetchAll();

$byMethod = $pdo->prepare("
    SELECT payment_method, COUNT(*) AS count, SUM(total) AS total
    FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?
    GROUP BY payment_method
");
$byMethod->execute([$from, $to]);
$byMethod = $byMethod->fetchAll();

$transactions = $pdo->prepare("
    SELECT s.*,
           c.name AS customer_name,
           (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) AS item_count
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$transactions->execute([$from, $to]);
$transactions = $transactions->fetchAll();

$expByCategory = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM expenses WHERE expense_date BETWEEN ? AND ?
    GROUP BY category ORDER BY total DESC
");
$expByCategory->execute([$from, $to]);
$expByCategory = $expByCategory->fetchAll();

renderHead('Reports');
renderNav('reports');
?>
<div class="container-fluid py-4">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-bar-chart-line me-2"></i>Reports</h4>
    <button class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- Filters -->
<form class="row g-2 mb-3 no-print" method="GET">
    <div class="col-auto"><label class="form-label small">From</label><input type="date" name="from" class="form-control" value="<?= $from ?>"></div>
    <div class="col-auto"><label class="form-label small">To</label><input type="date" name="to" class="form-control" value="<?= $to ?>"></div>
    <div class="col-auto">
        <label class="form-label small">Group by</label>
        <select name="group" class="form-select">
            <option value="day" <?= $groupBy==='day'?'selected':'' ?>>Day</option>
            <option value="week" <?= $groupBy==='week'?'selected':'' ?>>Week</option>
            <option value="month" <?= $groupBy==='month'?'selected':'' ?>>Month</option>
        </select>
    </div>
    <div class="col-auto align-self-end"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Generate</button></div>
    <div class="col-12">
        <div class="btn-group btn-group-sm flex-wrap">
            <?php
            $ranges = [
                'Today'      => [date('Y-m-d'), date('Y-m-d')],
                'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                'This Month' => [date('Y-m-01'), date('Y-m-d')],
                'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                'This Year'  => [date('Y-01-01'), date('Y-m-d')],
            ];
            foreach ($ranges as $label => [$f,$t]):
            ?>
            <a href="?from=<?= $f ?>&to=<?= $t ?>&group=<?= $groupBy ?>" class="btn btn-outline-primary <?= ($from==$f&&$to==$t)?'active':'' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<!-- Summary cards -->
<div class="row g-3 mb-4">
<?php
$summaryCards = [
    ['Revenue',         fmtUSD($stats['revenue']),  fmtLBP($stats['revenue']*$rate),  'bi-cash-stack',     'text-primary'],
    ['COGS',            fmtUSD($stats['cogs']),     fmtLBP($stats['cogs']*$rate),     'bi-boxes',          'text-secondary'],
    ['Gross Profit',    fmtUSD($stats['gross']),    fmtLBP($stats['gross']*$rate),    'bi-graph-up-arrow', 'text-success'],
    ['Gross Margin',    $stats['margin'].'%',       '',                               'bi-percent',        'text-info'],
    ['Expenses',        fmtUSD($stats['expenses']), fmtLBP($stats['expenses']*$rate), 'bi-wallet2',        'text-danger'],
    ['Net Profit',      fmtUSD($stats['net']),      fmtLBP($stats['net']*$rate),      'bi-trophy',         'text-success fw-bold'],
    ['Transactions',    $stats['tx_count'],         '',                               'bi-receipt',        'text-dark'],
];
foreach ($summaryCards as [$label,$val,$sub,$icon,$cls]):
?>
<div class="col-6 col-md-3 col-xl">
    <div class="card stat-card p-3 text-center">
        <i class="bi <?= $icon ?> fs-4 <?= $cls ?>"></i>
        <div class="small text-muted mt-1"><?= $label ?></div>
        <div class="fw-bold <?= $cls ?>"><?= $val ?></div>
        <?php if ($sub): ?><div class="text-muted" style="font-size:.7rem"><?= $sub ?></div><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Charts row -->
<div class="row g-3 mb-4">
<div class="col-lg-8">
    <div class="card stat-card p-3">
        <h6 class="fw-bold mb-3">Revenue vs Gross Profit</h6>
        <div class="chart-wrap" style="height:280px"><canvas id="timelineChart"></canvas></div>
    </div>
</div>
<div class="col-lg-4">
    <div class="card stat-card p-3">
        <h6 class="fw-bold mb-3">P&amp;L Breakdown</h6>
        <div class="chart-wrap" style="height:280px"><canvas id="breakdownChart"></canvas></div>
    </div>
</div>
</div>

<div class="row g-3 mb-4">

<!-- Top products -->
<div class="col-lg-8">
<div class="card stat-card p-3">
    <h6 class="fw-bold mb-3">Top Products by Profit</h6>
    <div class="table-responsive">
    <table class="table table-sm table-hover">
        <thead><tr><th>#</th><th>Product</th><th>Type</th><th>Units</th><th>Revenue</th><th>COGS</th><th>Profit</th><th>Margin%</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $i => $p):
            $m = $p['revenue']>0 ? round(($p['profit']/$p['revenue'])*100,1) : 0;
        ?>
        <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= $p['product_type']==='bulk' ? '<span class="badge bg-warning text-dark">Bulk</span>' : '<span class="badge bg-info text-dark">Regular</span>' ?></td>
            <td><?= (float)$p['units'] ?></td>
            <td><?= fmtUSD($p['revenue']) ?></td>
            <td><?= $p['product_type']==='bulk' ? '<span class="text-muted small">see below</span>' : fmtUSD($p['cogs']) ?></td>
            <td class="fw-bold <?= $p['product_type']==='bulk'?'text-muted':($p['profit']>=0?'text-success':'text-danger') ?>">
                <?= $p['product_type']==='bulk' ? '—' : fmtUSD($p['profit']) ?>
            </td>
            <td>
                <?php if ($p['product_type']==='bulk'): ?>
                    <span class="badge bg-secondary">Bulk</span>
                <?php else: ?>
                    <span class="badge <?= $m>20?'bg-success':($m>10?'bg-warning text-dark':'bg-danger') ?>"><?= $m ?>%</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$topProducts): ?><tr><td colspan="8" class="text-center text-muted py-3">No sales data.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<!-- Right column -->
<div class="col-lg-4">
    <?php if ($bulkPurchases): ?>
    <div class="card stat-card p-3 mb-3 border-warning">
        <h6 class="fw-bold mb-2"><i class="bi bi-basket me-2"></i>Bulk/Produce Purchases (period)</h6>
        <table class="table table-sm mb-0">
            <thead><tr><th>Product</th><th>Purchase Cost</th></tr></thead>
            <tbody>
            <?php foreach ($bulkPurchases as $b): ?>
            <tr><td><?= htmlspecialchars($b['name']) ?></td><td class="fw-bold"><?= fmtUSD($b['purchase_cost']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card stat-card p-3 mb-3">
        <h6 class="fw-bold mb-2">Expenses by Category</h6>
        <?php if ($expByCategory): ?>
        <div style="height:200px"><canvas id="expChart"></canvas></div>
        <?php else: echo '<p class="text-muted small">No expenses.</p>'; endif; ?>
    </div>

    <div class="card stat-card p-3">
        <h6 class="fw-bold mb-2">Payment Methods</h6>
        <table class="table table-sm mb-0">
            <thead><tr><th>Method</th><th>Count</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($byMethod as $m): ?>
            <tr><td class="text-capitalize"><?= $m['payment_method'] ?></td><td><?= $m['count'] ?></td><td><?= fmtUSD($m['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byMethod): ?><tr><td colspan="3" class="text-muted">No data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Transaction List -->
<div class="card stat-card p-3 mb-4">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-receipt me-2"></i>All Transactions</h6>
    <span class="badge bg-secondary"><?= count($transactions) ?> sales</span>
</div>
<div class="table-responsive" style="max-height:460px;overflow-y:auto">
<table class="table table-sm table-hover mb-0">
    <thead class="table-dark sticky-top">
        <tr>
            <th>Date & Time</th>
            <th>Receipt</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Method</th>
            <th>Total</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (isset($_GET['voided'])): ?>
    <tr><td colspan="7"><div class="alert alert-success py-2 mb-0 small"><i class="bi bi-check-circle me-1"></i>Sale voided successfully. Stock has been restored.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($transactions as $tx):
        $isVoid = !empty($tx['is_void']);
    ?>
    <tr class="<?= $isVoid ? 'table-secondary text-decoration-line-through opacity-75' : '' ?>">
        <td class="small"><?= date('d/m/Y H:i', strtotime($tx['sale_date'])) ?></td>
        <td class="font-monospace small">
            <?= htmlspecialchars($tx['receipt_no']) ?>
            <?php if ($isVoid): ?><span class="badge bg-danger ms-1" style="font-size:.6rem">VOID</span><?php endif; ?>
        </td>
        <td class="small"><?= $tx['customer_name'] ? htmlspecialchars($tx['customer_name']) : '<span class="text-muted">Cash</span>' ?></td>
        <td><span class="badge bg-light text-dark border"><?= $tx['item_count'] ?></span></td>
        <td>
            <span class="badge bg-<?= match($tx['payment_method']) { 'cash'=>'success', 'card'=>'primary', 'mobile'=>'info', 'account'=>'warning', default=>'secondary' } ?>">
                <?= ucfirst($tx['payment_method']) ?>
            </span>
        </td>
        <td class="fw-bold <?= $isVoid ? 'text-muted' : 'text-primary' ?>"><?= fmtUSD($tx['total']) ?></td>
        <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary py-0" type="button"
                    data-bs-toggle="collapse" data-bs-target="#tx-<?= $tx['id'] ?>"
                    title="View items"><i class="bi bi-chevron-down"></i></button>
            <?php if (!$isVoid): ?>
            <button class="btn btn-sm btn-outline-danger py-0 ms-1" title="Void this sale"
                    onclick="voidSale(<?= $tx['id'] ?>, '<?= htmlspecialchars(addslashes($tx['receipt_no'])) ?>')">
                <i class="bi bi-x-circle"></i>
            </button>
            <?php endif; ?>
        </td>
    </tr>
    <tr class="collapse" id="tx-<?= $tx['id'] ?>">
        <td colspan="7" class="p-0 bg-light">
            <?php
            $txItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
            $txItems->execute([$tx['id']]);
            $txItems = $txItems->fetchAll();
            ?>
            <table class="table table-sm mb-0 ms-3" style="width:calc(100% - 1.5rem)">
                <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Type</th></tr></thead>
                <tbody>
                <?php foreach ($txItems as $ti): ?>
                <tr>
                    <td><?= htmlspecialchars($ti['product_name']) ?></td>
                    <td><?= (float)$ti['quantity'] ?></td>
                    <td><?= fmtUSD($ti['unit_price']) ?></td>
                    <td><?= fmtUSD($ti['total']) ?></td>
                    <td>
                        <?php if ($ti['is_consignment']): ?>
                            <span class="badge" style="background:#7c3aed">Consign</span>
                        <?php elseif ($ti['product_type']==='bulk'): ?>
                            <span class="badge bg-warning text-dark">Bulk</span>
                        <?php else: ?>
                            <span class="badge bg-info text-dark">Owned</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="3" class="text-end small text-muted">
                            <?= $tx['discount']>0 ? 'Discount: -'.fmtUSD($tx['discount']).' · ' : '' ?>
                            <?= $tx['credit_used']>0 ? 'Credit: -'.fmtUSD($tx['credit_used']).' · ' : '' ?>
                            <?= $tx['note'] ? 'Note: '.htmlspecialchars($tx['note']).' · ' : '' ?>
                        </td>
                        <td class="fw-bold">TOTAL: <?= fmtUSD($tx['total']) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$transactions): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No transactions in this period.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

<!-- Period Detail -->
<div class="card stat-card p-3">
<h6 class="fw-bold mb-3">Period Detail</h6>
<div class="table-responsive">
<table class="table table-hover table-sm">
    <thead class="table-dark">
        <tr><th>Period</th><th>Transactions</th><th>Revenue</th><th>Revenue (LBP)</th><th>COGS</th><th>Gross Profit</th><th>Margin%</th></tr>
    </thead>
    <tbody>
    <?php foreach ($timeline as $row):
        $gp = $row['revenue'] - $row['cogs'];
        $m  = $row['revenue']>0 ? round(($gp/$row['revenue'])*100,1) : 0;
    ?>
    <tr>
        <td><?= $row['period'] ?></td>
        <td><?= $row['txns'] ?></td>
        <td><?= fmtUSD($row['revenue']) ?></td>
        <td class="text-muted small"><?= fmtLBP($row['revenue'] * $rate) ?></td>
        <td><?= fmtUSD($row['cogs']) ?></td>
        <td class="text-success fw-bold"><?= fmtUSD($gp) ?></td>
        <td><span class="badge <?= $m>20?'bg-success':($m>10?'bg-warning text-dark':'bg-danger') ?>"><?= $m ?>%</span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$timeline): ?><tr><td colspan="7" class="text-center text-muted">No data for this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const tLabels = <?= json_encode(array_column($timeline,'period')) ?>;
const tRev    = <?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $timeline)) ?>;
const tGP     = <?= json_encode(array_map(fn($r)=>(float)($r['revenue']-$r['cogs']), $timeline)) ?>;

new Chart(document.getElementById('timelineChart'), {
    type: 'line',
    data: { labels: tLabels, datasets: [
        { label: 'Revenue ($)', data: tRev, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,.1)', fill: true, tension: .3 },
        { label: 'Gross Profit ($)', data: tGP, borderColor: '#34a853', backgroundColor: 'rgba(52,168,83,.1)', fill: true, tension: .3 },
    ]},
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('breakdownChart'), {
    type: 'doughnut',
    data: {
        labels: ['COGS','Gross Profit','Expenses','Net Profit'],
        datasets: [{ data: [<?= max(0,$stats['cogs']) ?>,<?= max(0,$stats['gross']) ?>,<?= max(0,$stats['expenses']) ?>,<?= max(0,$stats['net']) ?>], backgroundColor: ['#ea4335','#34a853','#fbbc04','#1a73e8'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

<?php if ($expByCategory): ?>
new Chart(document.getElementById('expChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($expByCategory,'category')) ?>,
        datasets: [{ data: <?= json_encode(array_column($expByCategory,'total')) ?>, backgroundColor: ['#ea4335','#fbbc04','#34a853','#1a73e8','#9c27b0','#ff9800','#00bcd4','#795548'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});
<?php endif; ?>
function voidSale(id, receipt) {
    const reason = prompt('Void sale ' + receipt + '?\nEnter reason (or OK for default):', 'Voided by admin');
    if (reason === null) return;
    const f = document.getElementById('voidForm');
    document.getElementById('voidSaleId').value = id;
    document.getElementById('voidReason').value = reason || 'Voided by admin';
    document.getElementById('voidFrom').value = '<?= $from ?>';
    document.getElementById('voidTo').value   = '<?= $to ?>';
    f.submit();
}
</script>
<form id="voidForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="void_sale">
    <input type="hidden" name="sale_id" id="voidSaleId">
    <input type="hidden" name="void_reason" id="voidReason">
    <input type="hidden" name="from" id="voidFrom">
    <input type="hidden" name="to" id="voidTo">
</form>
<?php renderFoot(); ?>
