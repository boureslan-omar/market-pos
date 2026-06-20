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

            // Reverse customer balance if sale had a customer.
            // Must reverse exactly what pos.php did: -(total + creditUse - netCashPaid).
            // Cash register void entry handles physical cash; only balance-based credit/debit here.
            if ($sale['customer_id']) {
                $vNetCashUSD  = (float)$sale['paid_usd'] - (float)$sale['change_usd'];
                $vNetCashLBP  = (float)$sale['paid_lbp'] - (float)$sale['change_lbp'];
                $vRate        = max(1, (float)($sale['exchange_rate_used'] ?? EXCHANGE_RATE));
                $vCreditUsed  = (float)($sale['credit_used'] ?? 0);
                $vNetCashUSD += $vNetCashLBP / $vRate;
                $vBalRestore  = (float)$sale['total'] + $vCreditUsed - $vNetCashUSD;
                if (abs($vBalRestore) > 0.001) {
                    $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=?")->execute([$vBalRestore, $sale['customer_id']]);
                    $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,'adjustment',?,?)")
                        ->execute([$sale['customer_id'], $vid, $vBalRestore, 'Void of sale #' . $sale['receipt_no']]);
                }
            }

            // Reverse cash register — undo net effect on each drawer.
            // Check net cash actually paid, not payment_method label (credit+cash sales
            // may have payment_method='credit' but still have physical cash recorded).
            $netUSD = (float)$sale['paid_usd'] - (float)$sale['change_usd'];
            $netLBP = (float)$sale['paid_lbp'] - (float)$sale['change_lbp'];
            if (abs($netUSD) > 0.001 || abs($netLBP) > 0.001) {
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
        <td class="text-end" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-secondary py-0" type="button"
                    data-bs-toggle="collapse" data-bs-target="#tx-<?= $tx['id'] ?>"
                    title="View items"><i class="bi bi-chevron-down"></i></button>
            <button class="btn btn-sm btn-outline-secondary py-0 ms-1" title="Print receipt"
                    onclick="printReceipt(<?= $tx['id'] ?>)">
                <i class="bi bi-printer"></i>
            </button>
            <?php if (!$isVoid): ?>
            <button class="btn btn-sm btn-outline-primary py-0 ms-1" title="Edit this sale"
                    onclick="editSale(<?= $tx['id'] ?>, '<?= htmlspecialchars(addslashes($tx['receipt_no'])) ?>')">
                <i class="bi bi-pencil"></i>
            </button>
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
<?php
$totTxns  = array_sum(array_column($timeline, 'txns'));
$totRev   = array_sum(array_column($timeline, 'revenue'));
$totCogs  = array_sum(array_column($timeline, 'cogs'));
$totGP    = $totRev - $totCogs;
$totMargin = $totRev > 0 ? round(($totGP / $totRev) * 100, 1) : 0;
?>
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
    <?php if ($timeline): ?>
    <tfoot class="table-secondary fw-bold border-top border-2">
        <tr>
            <td>TOTAL</td>
            <td><?= $totTxns ?></td>
            <td><?= fmtUSD($totRev) ?></td>
            <td class="text-muted"><?= fmtLBP($totRev * $rate) ?></td>
            <td><?= fmtUSD($totCogs) ?></td>
            <td class="text-success"><?= fmtUSD($totGP) ?></td>
            <td><span class="badge <?= $totMargin>20?'bg-success':($totMargin>10?'bg-warning text-dark':'bg-danger') ?>"><?= $totMargin ?>%</span></td>
        </tr>
    </tfoot>
    <?php endif; ?>
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

// ── Edit Sale ─────────────────────────────────────────────────────────────────
let _editSaleId = null, _editItems = [], _editSaleData = null;

function editSale(id, receipt) {
    _editSaleId = id;
    _editItems  = [];
    _editSaleData = null;
    document.getElementById('edit-receipt-label').textContent = '#' + receipt;
    document.getElementById('edit-modal-body').innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div></div>';
    document.getElementById('edit-note').value = '';
    new bootstrap.Modal(document.getElementById('editSaleModal')).show();
    fetch('/dahdouh/pages/api.php?action=get_sale_for_edit&sale_id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { document.getElementById('edit-modal-body').innerHTML = '<div class="alert alert-danger">' + escHtml(data.error) + '</div>'; return; }
            _editItems    = data.items;
            _editSaleData = data;
            renderEditItems(data);
        })
        .catch(() => { document.getElementById('edit-modal-body').innerHTML = '<div class="alert alert-danger">Network error</div>'; });
}

function buildEditRow(item, i) {
    const sub = ((parseFloat(item.quantity)||0) * (parseFloat(item.unit_price)||0)).toFixed(2);
    const typeBadge = item.is_consignment
        ? '<span class="badge" style="background:#7c3aed;color:#fff">Consign</span>'
        : (item.product_type === 'bulk' ? '<span class="badge bg-warning text-dark">Bulk</span>' : '<span class="badge bg-info text-dark">Owned</span>');
    return `<tr id="edit-row-${i}">
        <td class="small">${escHtml(item.product_name)}</td>
        <td>${typeBadge}</td>
        <td><input type="number" class="form-control form-control-sm" style="width:90px" min="0.001" step="0.001"
            value="${parseFloat(item.quantity)}" id="edit-qty-${i}" onchange="updateEditSubtotal(${i})"></td>
        <td><input type="number" class="form-control form-control-sm" style="width:105px" min="0" step="0.0001"
            value="${parseFloat(item.unit_price).toFixed(4)}" id="edit-price-${i}" onchange="updateEditSubtotal(${i})"></td>
        <td class="text-end fw-bold" id="edit-sub-${i}">$${sub}</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="removeEditRow(${i})" title="Remove"><i class="bi bi-trash"></i></button></td>
    </tr>`;
}

function renderEditItems(data) {
    let html = '<div class="alert alert-warning py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>Qty/price changes adjust stock, batches &amp; totals. Use trash to remove items. Use Add to insert new ones.</div>';
    html += '<table class="table table-sm"><thead><tr><th>Product</th><th>Type</th><th>Qty</th><th>Unit Price ($)</th><th class="text-end">Subtotal</th><th></th></tr></thead><tbody id="edit-items-tbody">';
    data.items.forEach((item, i) => { html += buildEditRow(item, i); });
    html += '</tbody></table>';
    html += `<button type="button" class="btn btn-sm btn-outline-success mb-3" onclick="addEditRow()"><i class="bi bi-plus me-1"></i>Add Product</button>
    <div id="edit-add-search" style="display:none" class="mb-3">
        <div class="input-group input-group-sm">
            <input type="text" id="edit-product-search" class="form-control" placeholder="Type product name or scan barcode…"
                oninput="searchEditProduct()" autocomplete="off">
            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('edit-add-search').style.display='none'">✕</button>
        </div>
        <div id="edit-product-results" class="list-group mt-1" style="max-height:160px;overflow-y:auto"></div>
    </div>`;
    html += `<div class="d-flex justify-content-end gap-3 mt-1 small">
        <span class="text-muted">Discount: -$${parseFloat(data.discount||0).toFixed(2)} &nbsp; Credit: -$${parseFloat(data.credit_used||0).toFixed(2)}</span>
        <span>Original: <strong>$${parseFloat(data.total).toFixed(2)}</strong></span>
        <span class="text-primary">New total: <strong id="edit-new-total">$${parseFloat(data.total).toFixed(2)}</strong></span>
    </div>`;
    document.getElementById('edit-modal-body').innerHTML = html;
}

function removeEditRow(i) {
    _editItems[i]._removed = true;
    const row = document.getElementById('edit-row-' + i);
    if (row) row.remove();
    recalcEditTotal();
}

function addEditRow() {
    document.getElementById('edit-add-search').style.display = '';
    document.getElementById('edit-product-search').value = '';
    document.getElementById('edit-product-results').innerHTML = '';
    document.getElementById('edit-product-search').focus();
}

let _editSearchTimer = null;
function searchEditProduct() {
    clearTimeout(_editSearchTimer);
    const q = document.getElementById('edit-product-search')?.value?.trim();
    if (!q || q.length < 2) { document.getElementById('edit-product-results').innerHTML = ''; return; }
    const isBarcode = /^\d{6,}$/.test(q);
    _editSearchTimer = setTimeout(() => {
        fetch('/dahdouh/pages/api.php?action=search_products_purchase&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(results => {
                const el = document.getElementById('edit-product-results');
                if (!el) return;
                if (!results.length) { el.innerHTML = '<div class="list-group-item small text-muted">No products found</div>'; return; }
                el.innerHTML = results.slice(0, 8).map(p => {
                    const barcodeBadge = p.barcode ? `<span class="text-muted ms-2" style="font-size:.7rem">${escHtml(p.barcode)}</span>` : '';
                    const price = parseFloat(p.sell_price||0).toFixed(2);
                    return `<button type="button" class="list-group-item list-group-item-action py-1 small"
                        onclick="pickEditProduct(${p.id}, '${escHtml(p.name).replace(/'/g,"\\'")}', ${parseFloat(p.sell_price)||0})">
                        <strong>${escHtml(p.name)}</strong>${barcodeBadge}
                        <span class="float-end text-success">$${price}</span>
                    </button>`;
                }).join('');
                if (isBarcode && results.length === 1) {
                    pickEditProduct(results[0].id, results[0].name, parseFloat(results[0].sell_price)||0);
                }
            });
    }, isBarcode ? 0 : 300);
}

function pickEditProduct(pid, name, price) {
    document.getElementById('edit-add-search').style.display = 'none';
    const i = _editItems.length;
    _editItems.push({ id: 'new_' + pid, product_id: pid, product_name: name, product_type: 'regular', is_consignment: 0, quantity: 1, unit_price: price, _new: true });
    const tbody = document.getElementById('edit-items-tbody');
    if (tbody) tbody.insertAdjacentHTML('beforeend', buildEditRow(_editItems[i], i));
    recalcEditTotal();
}

function updateEditSubtotal(i) {
    const qty   = parseFloat(document.getElementById('edit-qty-'+i)?.value) || 0;
    const price = parseFloat(document.getElementById('edit-price-'+i)?.value) || 0;
    const el = document.getElementById('edit-sub-'+i);
    if (el) el.textContent = '$' + (qty * price).toFixed(2);
    recalcEditTotal();
}

function recalcEditTotal() {
    let subtotal = 0;
    _editItems.forEach((item, j) => {
        if (item._removed) return;
        subtotal += (parseFloat(document.getElementById('edit-qty-'+j)?.value)||0)
                  * (parseFloat(document.getElementById('edit-price-'+j)?.value)||0);
    });
    const newTotal = Math.max(0, subtotal - (parseFloat(_editSaleData?.discount)||0) - (parseFloat(_editSaleData?.credit_used)||0));
    const el = document.getElementById('edit-new-total');
    if (el) el.textContent = '$' + newTotal.toFixed(2);
}

function submitEditSale() {
    if (!_editSaleId || !_editItems.length) return;
    const items = _editItems
        .filter(item => !item._removed)
        .map((item, _) => {
            const i = _editItems.indexOf(item);
            return {
                id:         item.id,
                qty:        parseFloat(document.getElementById('edit-qty-'+i)?.value) || 0,
                price:      parseFloat(document.getElementById('edit-price-'+i)?.value) || 0,
                product_id: item.product_id || null,
                _new:       item._new || false
            };
        });
    const note = document.getElementById('edit-note').value.trim();
    const body = new URLSearchParams({ sale_id: _editSaleId, items: JSON.stringify(items), note });
    fetch('/dahdouh/pages/api.php?action=edit_sale', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert('Error: ' + (d.error || 'Unknown error')); return; }
            bootstrap.Modal.getInstance(document.getElementById('editSaleModal'))?.hide();
            const diffLabel = parseFloat(d.diff) >= 0 ? '+$' + parseFloat(d.diff).toFixed(2) : '-$' + Math.abs(parseFloat(d.diff)).toFixed(2);
            alert('Sale updated.\nNew total: $' + parseFloat(d.new_total).toFixed(2) + '\nAdjustment: ' + diffLabel);
            location.reload();
        })
        .catch(() => alert('Network error'));
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function printReceipt(saleId) {
    const modal = new bootstrap.Modal(document.getElementById('receiptPrintModal'));
    const body  = document.getElementById('receipt-print-body');
    body.innerHTML = '<div class="text-center py-4 text-muted">Loading…</div>';
    modal.show();
    fetch('/dahdouh/pages/api.php?action=sale_receipt&sale_id=' + saleId)
        .then(r => r.json())
        .then(s => {
            if (s.error) { body.innerHTML = '<p class="text-danger">' + escHtml(s.error) + '</p>'; return; }
            const rate = parseFloat(s.exchange_rate_used) || 1;
            let rows = '';
            (s.items || []).forEach(it => {
                rows += `<tr>
                    <td>${escHtml(it.product_name)}</td>
                    <td class="text-end">${parseFloat(it.quantity)}</td>
                    <td class="text-end">$${parseFloat(it.unit_price).toFixed(2)}</td>
                    <td class="text-end fw-bold">$${parseFloat(it.total).toFixed(2)}</td>
                </tr>`;
            });
            const fUSD = v => '$' + parseFloat(v).toFixed(2);
            const fLBP = v => parseInt(v).toLocaleString() + ' LL';
            const d = new Date(s.sale_date);
            const dateStr = d.toLocaleDateString('en-GB') + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
            body.innerHTML = `
                <div class="text-center mb-3">
                    <div class="fw-bold fs-5" style="color:#2d5a2d">${escHtml(s.store_name)}</div>
                    ${s.store_address ? `<div class="small text-muted">${escHtml(s.store_address)}</div>` : ''}
                    ${s.store_phone ? `<div class="small text-muted">${escHtml(s.store_phone)}</div>` : ''}
                    <div class="small text-muted mt-1">${dateStr}</div>
                    <div class="fw-bold mt-1">Receipt: ${escHtml(s.receipt_no || '—')}</div>
                    ${s.customer_name ? `<div class="small">Customer: <strong>${escHtml(s.customer_name)}</strong></div>` : ''}
                    ${s.is_void == 1 ? '<div class="badge bg-danger mt-1">VOIDED</div>' : ''}
                </div>
                <hr>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                <hr>
                <div class="d-flex justify-content-between"><span>Subtotal</span><span>${fUSD(s.subtotal)}</span></div>
                ${parseFloat(s.discount)>0 ? `<div class="d-flex justify-content-between text-muted"><span>Discount</span><span>-${fUSD(s.discount)}</span></div>` : ''}
                ${parseFloat(s.credit_used)>0 ? `<div class="d-flex justify-content-between text-muted"><span>Credit Applied</span><span>-${fUSD(s.credit_used)}</span></div>` : ''}
                <div class="d-flex justify-content-between fw-bold fs-5 mt-2"><span>TOTAL</span><span>${fUSD(s.total)}</span></div>
                <div class="d-flex justify-content-between small text-muted"><span></span><span>${fLBP(parseFloat(s.total)*rate)}</span></div>
                ${parseFloat(s.paid_usd)>0 ? `<div class="d-flex justify-content-between small mt-1"><span>Paid (USD)</span><span>${fUSD(s.paid_usd)}</span></div>` : ''}
                ${parseFloat(s.paid_lbp)>0 ? `<div class="d-flex justify-content-between small"><span>Paid (LBP)</span><span>${fLBP(s.paid_lbp)}</span></div>` : ''}
                ${parseFloat(s.change_usd)>0 ? `<div class="d-flex justify-content-between text-success fw-bold"><span>Change (USD)</span><span>${fUSD(s.change_usd)}</span></div>` : ''}
                ${parseFloat(s.change_lbp)>0 ? `<div class="d-flex justify-content-between text-success fw-bold"><span>Change (LBP)</span><span>${fLBP(s.change_lbp)}</span></div>` : ''}
                ${parseFloat(s.debt_settled)>0 ? `<div class="d-flex justify-content-between small mt-1 text-danger fw-bold"><span>Debt Settled</span><span>${fLBP(parseFloat(s.debt_settled)*rate)}</span></div>` : ''}
                <div class="text-center text-muted small mt-3">Thank you!</div>`;
        })
        .catch(() => { body.innerHTML = '<p class="text-danger">Failed to load receipt.</p>'; });
}

function printReceiptWindow() {
    const html = document.getElementById('receipt-print-body').innerHTML;
    const win = window.open('', '_blank', 'width=320,height=600,scrollbars=yes');
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt</title>' +
        '<style>' +
        '@page{size:80mm auto;margin:3mm 4mm}' +
        '*{color:#000!important;background:transparent!important}' +
        'body{font-family:"Courier New",Courier,monospace;font-size:12px;width:72mm;margin:0;padding:0}' +
        'table{width:100%;border-collapse:collapse}' +
        'th{font-size:11px;border-bottom:1px dashed #000;padding:2px 0;text-align:left}' +
        'td{font-size:11px;padding:2px 0;vertical-align:top}' +
        '.text-end{text-align:right}.text-center{text-align:center}' +
        '.fw-bold{font-weight:bold}.fs-5{font-size:13px}' +
        '.d-flex{display:flex}.justify-content-between{justify-content:space-between}' +
        '.badge{display:inline-block;border:1px solid #000;padding:1px 6px;font-weight:bold;font-size:11px}' +
        'hr{border:none;border-top:1px dashed #000;margin:4px 0}' +
        '.small{font-size:10px}.mt-1{margin-top:2px}.mt-2{margin-top:4px}.mt-3{margin-top:6px}.mb-0{margin-bottom:0}.mb-3{margin-bottom:6px}' +
        '</style></head><body>' + html +
        '<script>window.onload=function(){window.print();}<\/script>' +
        '</body></html>');
    win.document.close();
}
</script>

<!-- Edit Sale Modal -->
<div class="modal fade" id="editSaleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sale <span id="edit-receipt-label"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="edit-modal-body">
        <div class="text-center py-4"><div class="spinner-border" role="status"></div></div>
      </div>
      <div class="modal-footer">
        <input type="text" id="edit-note" class="form-control form-control-sm me-auto" placeholder="Reason for edit (optional)…" style="max-width:300px">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="submitEditSale()"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Receipt Print Modal -->
<div class="modal fade" id="receiptPrintModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2 border-0">
        <h6 class="modal-title"><i class="bi bi-receipt me-2"></i>Receipt</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body receipt p-4" id="receipt-print-body"></div>
    <div class="modal-footer py-2 border-0 no-print">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-sm btn-primary" onclick="printReceiptWindow()"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
</div>
</div>
</div>

<!-- ═══════════ SALES & PURCHASE ANALYSIS ═══════════ -->
<div class="card stat-card p-4 mt-4 no-print">
<h5 class="fw-bold mb-3"><i class="bi bi-search me-2"></i>Sales &amp; Purchase Analysis</h5>
<p class="text-muted small mb-3">Filter within the date range above by product, category, or supplier to see totals.</p>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Category</label>
        <select id="an-cat" class="form-select form-select-sm" onchange="runAnalysis()">
            <option value="">— All Categories —</option>
            <?php foreach ($pdo->query("SELECT id,name FROM categories ORDER BY name") as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Supplier</label>
        <select id="an-sup" class="form-select form-select-sm" onchange="runAnalysis()">
            <option value="">— All Suppliers —</option>
            <?php foreach ($pdo->query("SELECT id,name FROM suppliers ORDER BY name") as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Product</label>
        <div class="position-relative">
            <input type="text" id="an-prod-search" class="form-control form-control-sm" placeholder="Search product…" autocomplete="off" oninput="anSearchProduct()">
            <input type="hidden" id="an-prod-id" value="">
            <div id="an-prod-drop" class="list-group shadow position-absolute w-100" style="z-index:300;display:none;max-height:200px;overflow-y:auto"></div>
        </div>
    </div>
</div>

<!-- Results -->
<div id="an-results" style="display:none">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card p-3 text-center border-0 bg-light">
                <div class="small text-muted">Units Sold</div>
                <div class="fw-bold fs-5" id="an-units-sold">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3 text-center border-0 bg-light">
                <div class="small text-muted">Sales Revenue</div>
                <div class="fw-bold fs-5 text-primary" id="an-revenue">$0.00</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3 text-center border-0 bg-light">
                <div class="small text-muted">Units Purchased</div>
                <div class="fw-bold fs-5" id="an-units-purch">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3 text-center border-0 bg-light">
                <div class="small text-muted">Purchase Cost</div>
                <div class="fw-bold fs-5 text-danger" id="an-purch-cost">$0.00</div>
            </div>
        </div>
    </div>
    <div id="an-top-wrap" style="display:none">
        <div class="small fw-semibold text-muted mb-1">Top Products by Revenue</div>
        <table class="table table-sm table-hover">
            <thead><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Revenue</th></tr></thead>
            <tbody id="an-top-body"></tbody>
        </table>
    </div>
</div>
<div id="an-loading" style="display:none" class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>
<div id="an-empty" style="display:none" class="text-muted small">No data for this selection.</div>
</div>

<script>
(function() {
    const FROM = <?= json_encode($from) ?>;
    const TO   = <?= json_encode($to) ?>;
    let anTimer = null;

    window.runAnalysis = function() {
        const cat  = document.getElementById('an-cat').value;
        const sup  = document.getElementById('an-sup').value;
        const prod = document.getElementById('an-prod-id').value;
        document.getElementById('an-results').style.display = 'none';
        document.getElementById('an-empty').style.display   = 'none';

        clearTimeout(anTimer);
        anTimer = setTimeout(() => {
            document.getElementById('an-loading').style.display = '';
            let url = `/dahdouh/pages/api.php?action=report_analysis&from=${FROM}&to=${TO}`;
            if (cat)  url += `&category_id=${cat}`;
            if (sup)  url += `&supplier_id=${sup}`;
            if (prod) url += `&product_id=${prod}`;

            fetch(url).then(r => r.json()).then(d => {
                document.getElementById('an-loading').style.display = 'none';
                if (d.error) return;
                const hasData = d.revenue > 0 || d.purchase_cost > 0;
                if (!hasData) { document.getElementById('an-empty').style.display = ''; return; }

                document.getElementById('an-units-sold').textContent  = parseFloat(d.units_sold).toFixed(0);
                document.getElementById('an-revenue').textContent     = '$' + parseFloat(d.revenue).toFixed(2);
                document.getElementById('an-units-purch').textContent = parseFloat(d.units_purchased).toFixed(0);
                document.getElementById('an-purch-cost').textContent  = '$' + parseFloat(d.purchase_cost).toFixed(2);

                const topWrap = document.getElementById('an-top-wrap');
                const topBody = document.getElementById('an-top-body');
                if (d.top_products && d.top_products.length > 1) {
                    topBody.innerHTML = d.top_products.map(p =>
                        `<tr><td>${escH(p.name)}</td><td class="text-end">${parseFloat(p.units).toFixed(0)}</td><td class="text-end">$${parseFloat(p.revenue).toFixed(2)}</td></tr>`
                    ).join('');
                    topWrap.style.display = '';
                } else {
                    topWrap.style.display = 'none';
                }
                document.getElementById('an-results').style.display = '';
            }).catch(() => { document.getElementById('an-loading').style.display = 'none'; });
        }, 300);
    };

    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    let anSearchTimer = null;
    window.anSearchProduct = function() {
        const q = document.getElementById('an-prod-search').value.trim();
        document.getElementById('an-prod-id').value = '';
        const drop = document.getElementById('an-prod-drop');
        clearTimeout(anSearchTimer);
        if (q.length < 1) { drop.style.display = 'none'; return; }
        anSearchTimer = setTimeout(() => {
            fetch(`/dahdouh/pages/api.php?action=search_products_purchase&q=${encodeURIComponent(q)}`)
                .then(r => r.json()).then(res => {
                    if (!res.length) { drop.style.display = 'none'; return; }
                    drop.innerHTML = res.slice(0,10).map(p =>
                        `<button type="button" class="list-group-item list-group-item-action p-2 small text-start"
                                 data-pid="${p.id}" data-pname="${escH(p.name)}">
                            ${escH(p.name)}
                        </button>`
                    ).join('');
                    drop.style.display = '';
                });
        }, 250);
    };

    window.anPickProduct = function(id, name) {
        document.getElementById('an-prod-id').value     = id;
        document.getElementById('an-prod-search').value = name;
        document.getElementById('an-prod-drop').style.display = 'none';
        runAnalysis();
    };

    document.getElementById('an-prod-drop').addEventListener('click', function(e) {
        const btn = e.target.closest('[data-pid]');
        if (!btn) return;
        anPickProduct(parseInt(btn.dataset.pid), btn.dataset.pname);
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#an-prod-search') && !e.target.closest('#an-prod-drop')) {
            document.getElementById('an-prod-drop').style.display = 'none';
        }
    });
})();
</script>

<form id="voidForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="void_sale">
    <input type="hidden" name="sale_id" id="voidSaleId">
    <input type="hidden" name="void_reason" id="voidReason">
    <input type="hidden" name="from" id="voidFrom">
    <input type="hidden" name="to" id="voidTo">
</form>
<?php renderFoot(); ?>
