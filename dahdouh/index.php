<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
requireRole();

$today    = date('Y-m-d');
$stats    = getStats($pdo, $today, $today);
$lowStock = getLowStock($pdo, 8);
$cashBal  = getCashBalance($pdo);
$rate     = EXCHANGE_RATE;

$topSelling = $pdo->query("
    SELECT p.name, SUM(si.quantity) AS units, SUM(si.total) AS revenue
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.sale_date) = CURDATE()
    GROUP BY p.id ORDER BY units DESC LIMIT 5
")->fetchAll();

$weeklyData = $pdo->query("
    SELECT DATE(sale_date) AS day, SUM(total) AS revenue, COUNT(*) AS txns
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY day ASC
")->fetchAll();

// Outstanding debts
$topDebts = $pdo->query("
    SELECT name, phone, balance FROM customers WHERE balance < 0 ORDER BY balance ASC LIMIT 5
")->fetchAll();

renderHead('Dashboard');
renderNav('dashboard');
?>
<div id="toast-wrap" class="position-fixed top-0 end-0 p-3" style="z-index:9999"></div>

<div class="container-fluid py-4">
<div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
        <?php if (file_exists(__DIR__ . '/assets/img/logo.png')): ?>
        <img src="/dahdouh/assets/img/logo.png" alt="logo" style="height:52px;width:52px;object-fit:contain">
        <?php endif; ?>
        <div>
            <h4 class="mb-0 fw-bold" style="color:var(--brand)"><?= htmlspecialchars(STORE_NAME) ?></h4>
            <div class="text-muted small"><?= htmlspecialchars(setting('store_address','')) ?></div>
        </div>
    </div>
    <span class="text-muted"><?= date('l, d F Y') ?></span>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Today Revenue',    fmtUSD($stats['revenue']),   'bi-cash-stack',        'bg-primary bg-opacity-10',   'text-primary'],
        ['Gross Profit',     fmtUSD($stats['gross']),     'bi-graph-up-arrow',    'bg-success bg-opacity-10',   'text-success'],
        ['Net Profit',       fmtUSD($stats['net']),       'bi-trophy',            'bg-info bg-opacity-10',      'text-info'],
        ['Gross Margin',     $stats['margin'].'%',        'bi-percent',           'bg-warning bg-opacity-10',   'text-warning'],
        ['Transactions',     $stats['tx_count'],          'bi-receipt',           'bg-secondary bg-opacity-10', 'text-secondary'],
        ['Today Expenses',   fmtUSD($stats['expenses']),  'bi-wallet2',           'bg-danger bg-opacity-10',    'text-danger'],
        ['Cash Register',    fmtUSD($cashBal),            'bi-cash-coin',         'bg-success bg-opacity-10',   'text-success'],
    ];
    foreach ($cards as [$label,$val,$icon,$bg,$ic]):
    ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="icon-wrap <?= $bg ?> <?= $ic ?>"><i class="bi <?= $icon ?>"></i></div>
                <div>
                    <div class="small text-muted"><?= $label ?></div>
                    <div class="fw-bold fs-5 <?= $ic ?>"><?= $val ?></div>
                    <?php if (str_contains($label,'Register')): ?>
                    <div class="small text-muted"><?= fmtLBP($cashBal * $rate) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Rate display -->
<div class="alert alert-light border d-flex align-items-center justify-content-between py-2 mb-3">
    <span class="small text-muted"><i class="bi bi-currency-exchange me-2"></i>Exchange Rate: <strong>1 USD = <?= number_format($rate,0) ?> LBP</strong></span>
    <a href="/dahdouh/pages/settings.php" class="btn btn-sm btn-outline-secondary">Update Rate</a>
</div>

<div class="row g-3">

    <!-- Weekly chart -->
    <div class="col-lg-8">
        <div class="card stat-card p-3 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart me-2"></i>Last 7 Days Revenue</h6>
            <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
        </div>
    </div>

    <!-- Top selling + debts -->
    <div class="col-lg-4">
        <div class="card stat-card p-3 mb-3">
            <h6 class="fw-bold mb-3"><i class="bi bi-trophy me-2"></i>Top Sellers Today</h6>
            <?php if ($topSelling): ?>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($topSelling as $t): ?>
                <tr><td class="small"><?= htmlspecialchars($t['name']) ?></td><td><?= (float)$t['units'] ?></td><td class="small"><?= fmtUSD($t['revenue']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: echo '<p class="text-muted small">No sales yet today.</p>'; endif; ?>
        </div>

        <?php if ($topDebts): ?>
        <div class="card stat-card p-3 border-danger">
            <h6 class="fw-bold mb-2 text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Outstanding Debts</h6>
            <?php foreach ($topDebts as $d): ?>
            <div class="d-flex justify-content-between small border-bottom py-1">
                <span><?= htmlspecialchars($d['name']) ?> <?= $d['phone']?"<span class='text-muted'>·{$d['phone']}</span>":'' ?></span>
                <span class="text-danger fw-bold"><?= fmtUSD(abs($d['balance'])) ?></span>
            </div>
            <?php endforeach; ?>
            <a href="/dahdouh/pages/customers.php" class="btn btn-sm btn-outline-danger w-100 mt-2">View All</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Low stock alerts -->
    <?php if ($lowStock): ?>
    <div class="col-12">
        <div class="card stat-card p-3 border-warning">
            <h6 class="fw-bold mb-3 text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts</h6>
            <div class="row g-2">
            <?php foreach ($lowStock as $p): ?>
            <div class="col-6 col-md-3 col-xl-2">
                <div class="border rounded p-2 text-center small">
                    <div class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="<?= $p['stock']==0?'stock-out':'stock-low' ?> fw-bold"><?= (float)$p['stock'] ?> left</div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- row -->
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode(array_column($weeklyData,'day')) ?>;
const data   = <?= json_encode(array_map(fn($r)=>(float)$r['revenue'], $weeklyData)) ?>;
new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Revenue (USD)', data, backgroundColor: 'rgba(26,115,232,.7)', borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});
</script>
<?php if ($_SESSION['role'] === 'admin' && setting('update_manifest_url','')): ?>
<script>
// Silently trigger background update check once per session
if (!sessionStorage.getItem('upd_checked')) {
    sessionStorage.setItem('upd_checked', '1');
    fetch('/dahdouh/pages/api.php?action=trigger_update').catch(()=>{});
}
</script>
<?php endif; ?>
<?php renderFoot(); ?>
