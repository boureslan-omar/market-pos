<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','cashier');

$message = '';

function shiftStats($pdo, $since) {
    if ($since) {
        $ss = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as total FROM sales WHERE is_void=0 AND payment_method='cash' AND sale_date > ?");
        $ss->execute([$since]);
        $sm = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN amount_usd>0 THEN amount_usd ELSE 0 END),0) as in_usd,
                   COALESCE(SUM(CASE WHEN amount_usd<0 THEN ABS(amount_usd) ELSE 0 END),0) as out_usd,
                   COALESCE(SUM(CASE WHEN amount_lbp>0 THEN amount_lbp ELSE 0 END),0) as in_lbp,
                   COALESCE(SUM(CASE WHEN amount_lbp<0 THEN ABS(amount_lbp) ELSE 0 END),0) as out_lbp
            FROM cash_register_log WHERE created_at > ?");
        $sm->execute([$since]);
    } else {
        $ss = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as total FROM sales WHERE is_void=0 AND payment_method='cash'");
        $sm = $pdo->query("
            SELECT COALESCE(SUM(CASE WHEN amount_usd>0 THEN amount_usd ELSE 0 END),0) as in_usd,
                   COALESCE(SUM(CASE WHEN amount_usd<0 THEN ABS(amount_usd) ELSE 0 END),0) as out_usd,
                   COALESCE(SUM(CASE WHEN amount_lbp>0 THEN amount_lbp ELSE 0 END),0) as in_lbp,
                   COALESCE(SUM(CASE WHEN amount_lbp<0 THEN ABS(amount_lbp) ELSE 0 END),0) as out_lbp
            FROM cash_register_log");
    }
    return ['sales' => $ss->fetch(), 'movements' => $sm->fetch()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $note     = trim($_POST['note'] ?? '');
    $amtRaw   = (float)($_POST['amount'] ?? 0);
    $currency = $_POST['currency'] ?? 'USD';

    if ($action === 'withdrawal' && $amtRaw > 0) {
        if ($currency === 'USD') {
            logCashEntry($pdo, 'withdrawal', -$amtRaw, $note ?: 'Cash withdrawal', null, 0, 'USD');
            $message = "success:USD withdrawal of " . fmtUSD($amtRaw) . " recorded.";
        } else {
            logCashEntry($pdo, 'withdrawal', 0, $note ?: 'Cash withdrawal', null, -$amtRaw, 'LBP');
            $message = "success:LBP withdrawal of " . fmtLBP($amtRaw) . " recorded.";
        }
    } elseif ($action === 'deposit' && $amtRaw > 0) {
        if ($currency === 'USD') {
            logCashEntry($pdo, 'deposit', $amtRaw, $note ?: 'Cash deposit', null, 0, 'USD');
            $message = "success:USD deposit of " . fmtUSD($amtRaw) . " recorded.";
        } else {
            logCashEntry($pdo, 'deposit', 0, $note ?: 'Cash deposit', null, $amtRaw, 'LBP');
            $message = "success:LBP deposit of " . fmtLBP($amtRaw) . " recorded.";
        }
    } elseif ($action === 'opening_usd') {
        $current = getCashBalance($pdo);
        $diff    = $amtRaw - $current;
        if ($diff != 0) {
            logCashEntry($pdo, 'opening', $diff, 'Opening balance set to ' . fmtUSD($amtRaw), null, 0, 'USD');
        }
        $message = "success:USD register set to " . fmtUSD($amtRaw) . ".";
    } elseif ($action === 'opening_lbp') {
        $current = getCashBalanceLBP($pdo);
        $diff    = $amtRaw - $current;
        if ($diff != 0) {
            logCashEntry($pdo, 'opening', 0, 'Opening balance set to ' . fmtLBP($amtRaw), null, $diff, 'LBP');
        }
        $message = "success:LBP register set to " . fmtLBP($amtRaw) . ".";
    } elseif ($action === 'end_of_shift') {
        $shiftNote = trim($_POST['shift_note'] ?? '');
        $me        = currentUser();
        $snapUSD   = getCashBalance($pdo);
        $snapLBP   = getCashBalanceLBP($pdo);
        $lastClose = $pdo->query("SELECT MAX(closed_at) FROM cash_shifts")->fetchColumn() ?: null;
        $stats     = shiftStats($pdo, $lastClose);
        $pdo->prepare("
            INSERT INTO cash_shifts
                (closed_by, since_datetime, balance_usd, balance_lbp,
                 sales_count, sales_total_usd,
                 cash_in_usd, cash_in_lbp, cash_out_usd, cash_out_lbp, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $me['id'], $lastClose, $snapUSD, $snapLBP,
            $stats['sales']['cnt'],   $stats['sales']['total'],
            $stats['movements']['in_usd'],  $stats['movements']['in_lbp'],
            $stats['movements']['out_usd'], $stats['movements']['out_lbp'],
            $shiftNote
        ]);
        $message = "success:Shift closed at " . date('H:i') . ". Snapshot — USD: " . fmtUSD($snapUSD) . " · LBP: " . fmtLBP($snapLBP) . ".";
    }
}

$balanceUSD = getCashBalance($pdo);
$balanceLBP = getCashBalanceLBP($pdo);

// Current shift stats (since last shift close)
$lastShiftClose = $pdo->query("SELECT MAX(closed_at) FROM cash_shifts")->fetchColumn() ?: null;
$currentShift   = shiftStats($pdo, $lastShiftClose);

// Shift history
$shiftHistory = $pdo->query("
    SELECT cs.*, u.full_name AS closed_by_name
    FROM cash_shifts cs
    LEFT JOIN users u ON u.id = cs.closed_by
    ORDER BY cs.closed_at DESC LIMIT 20
")->fetchAll();

// Log filter
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$logStmt = $pdo->prepare("
    SELECT crl.*, s.receipt_no
    FROM cash_register_log crl
    LEFT JOIN sales s ON s.id = crl.sale_id
    WHERE DATE(crl.created_at) BETWEEN ? AND ?
    ORDER BY crl.created_at DESC
");
$logStmt->execute([$from, $to]);
$log = $logStmt->fetchAll();

$periodInUSD  = array_sum(array_map(fn($r) => $r['amount_usd'] > 0 ? $r['amount_usd'] : 0, $log));
$periodOutUSD = array_sum(array_map(fn($r) => $r['amount_usd'] < 0 ? abs($r['amount_usd']) : 0, $log));
$periodInLBP  = array_sum(array_map(fn($r) => $r['amount_lbp'] > 0 ? $r['amount_lbp'] : 0, $log));
$periodOutLBP = array_sum(array_map(fn($r) => $r['amount_lbp'] < 0 ? abs($r['amount_lbp']) : 0, $log));

renderHead('Cash Register');
renderNav('cash_register');
alertBox($message);
?>
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-cash-coin me-2"></i>Cash Register</h4>
    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#shiftModal">
        <i class="bi bi-stopwatch me-2"></i>End of Shift
    </button>
</div>

<!-- Drawer balance cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card p-4 text-center border-success border-2">
            <div class="text-muted small mb-1"><i class="bi bi-currency-dollar me-1"></i>USD Drawer</div>
            <div class="fw-bold display-6 text-success"><?= fmtUSD($balanceUSD) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 text-center border-warning border-2">
            <div class="text-muted small mb-1"><i class="bi bi-cash me-1"></i>LBP Drawer</div>
            <div class="fw-bold display-6 text-warning"><?= fmtLBP($balanceLBP) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 text-center">
            <div class="text-muted small mb-1">Period In</div>
            <div class="fw-bold fs-3 text-success">+<?= fmtUSD($periodInUSD) ?></div>
            <div class="text-muted small">+<?= fmtLBP($periodInLBP) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-4 text-center">
            <div class="text-muted small mb-1">Period Out</div>
            <div class="fw-bold fs-3 text-danger">-<?= fmtUSD($periodOutUSD) ?></div>
            <div class="text-muted small">-<?= fmtLBP($periodOutLBP) ?></div>
        </div>
    </div>
</div>

<!-- Current shift summary bar -->
<div class="card stat-card p-3 mb-4 border-dark border-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Current Shift</h6>
        <span class="small text-muted">
            <?= $lastShiftClose
                ? 'Since ' . date('d/m/Y H:i', strtotime($lastShiftClose))
                : 'All time — no previous shift logged' ?>
        </span>
    </div>
    <div class="row g-2">
        <?php
        $cs = $currentShift;
        $shiftCols = [
            ['Cash Sales',    $cs['sales']['cnt'] . ' txn',          fmtUSD($cs['sales']['total']), 'text-primary'],
            ['Cash In USD',   fmtUSD($cs['movements']['in_usd']),    '',                             'text-success'],
            ['Cash In LBP',   fmtLBP($cs['movements']['in_lbp']),   '',                             'text-success'],
            ['Cash Out USD',  fmtUSD($cs['movements']['out_usd']),   '',                             'text-danger'],
            ['Cash Out LBP',  fmtLBP($cs['movements']['out_lbp']),  '',                             'text-danger'],
        ];
        foreach ($shiftCols as [$lbl, $val, $sub, $cls]): ?>
        <div class="col-6 col-md">
            <div class="bg-light rounded p-2 text-center">
                <div class="small text-muted"><?= $lbl ?></div>
                <div class="fw-bold <?= $cls ?>"><?= $val ?></div>
                <?php if ($sub): ?><div class="small text-muted"><?= $sub ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Action cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card p-3">
            <h6 class="fw-bold"><i class="bi bi-bank me-2"></i>Set Opening Balance</h6>
            <div class="row g-2">
                <div class="col-6">
                    <form method="POST">
                        <input type="hidden" name="action" value="opening_usd">
                        <label class="form-label small text-success fw-bold">USD Drawer</label>
                        <div class="input-group mb-2">
                            <span class="input-group-text text-success">$</span>
                            <input type="number" name="amount" class="form-control" placeholder="0.00" min="0" step="0.01" required>
                        </div>
                        <button type="submit" class="btn btn-outline-success w-100 btn-sm">Set USD</button>
                    </form>
                </div>
                <div class="col-6">
                    <form method="POST">
                        <input type="hidden" name="action" value="opening_lbp">
                        <label class="form-label small text-warning fw-bold">LBP Drawer</label>
                        <div class="input-group mb-2">
                            <span class="input-group-text text-warning">L£</span>
                            <input type="number" name="amount" class="form-control" placeholder="0" min="0" step="1000" required>
                        </div>
                        <button type="submit" class="btn btn-outline-warning w-100 btn-sm">Set LBP</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card p-3 border-danger">
            <h6 class="fw-bold text-danger"><i class="bi bi-arrow-up-right-circle me-2"></i>Cash Out (Withdrawal)</h6>
            <form method="POST">
                <input type="hidden" name="action" value="withdrawal">
                <div class="input-group mb-2">
                    <input type="number" name="amount" class="form-control" placeholder="Amount" min="0.01" step="0.01" required>
                    <select name="currency" class="form-select" style="max-width:90px">
                        <option value="USD">USD</option>
                        <option value="LBP">LBP</option>
                    </select>
                </div>
                <input type="text" name="note" class="form-control mb-2" placeholder="Reason (e.g. rent, supplies)" required>
                <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Confirm withdrawal?')">Withdraw</button>
            </form>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card p-3 border-success">
            <h6 class="fw-bold text-success"><i class="bi bi-arrow-down-left-circle me-2"></i>Cash In (Deposit)</h6>
            <form method="POST">
                <input type="hidden" name="action" value="deposit">
                <div class="input-group mb-2">
                    <input type="number" name="amount" class="form-control" placeholder="Amount" min="0.01" step="0.01" required>
                    <select name="currency" class="form-select" style="max-width:90px">
                        <option value="USD">USD</option>
                        <option value="LBP">LBP</option>
                    </select>
                </div>
                <input type="text" name="note" class="form-control mb-2" placeholder="Note">
                <button type="submit" class="btn btn-success w-100">Deposit</button>
            </form>
        </div>
    </div>
</div>

<!-- Log filter -->
<form class="row g-2 mb-3" method="GET">
    <div class="col-auto"><label class="form-label small">From</label><input type="date" name="from" class="form-control" value="<?= $from ?>"></div>
    <div class="col-auto"><label class="form-label small">To</label><input type="date" name="to" class="form-control" value="<?= $to ?>"></div>
    <div class="col-auto align-self-end"><button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button></div>
</form>

<div class="card stat-card mb-4">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Date / Time</th>
        <th>Type</th>
        <th>Currency</th>
        <th>USD Amount</th>
        <th>LBP Amount</th>
        <th>USD Balance After</th>
        <th>LBP Balance After</th>
        <th>Note</th>
        <th>Receipt</th>
    </tr></thead>
    <tbody>
    <?php foreach ($log as $row):
        $usdAmt = (float)$row['amount_usd'];
        $lbpAmt = (float)($row['amount_lbp'] ?? 0);
        $cur    = $row['currency'] ?? 'USD';
    ?>
    <tr>
        <td class="small"><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></td>
        <td><?php
            $badges = ['opening'=>'bg-secondary','sale'=>'bg-primary','withdrawal'=>'bg-danger',
                       'deposit'=>'bg-success','void'=>'bg-warning text-dark','expense'=>'bg-warning text-dark',
                       'refund'=>'bg-danger'];
            echo '<span class="badge ' . ($badges[$row['type']] ?? 'bg-secondary') . '">'
               . ucfirst(str_replace('_',' ',$row['type'])) . '</span>';
        ?></td>
        <td><span class="badge <?= $cur==='LBP'?'bg-warning text-dark':($cur==='BOTH'?'bg-info text-dark':'bg-success') ?>">
            <?= htmlspecialchars($cur) ?>
        </span></td>
        <td class="fw-bold <?= $usdAmt >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= $usdAmt != 0 ? ($usdAmt > 0 ? '+' : '') . fmtUSD($usdAmt) : '—' ?>
        </td>
        <td class="<?= $lbpAmt >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= $lbpAmt != 0 ? ($lbpAmt > 0 ? '+' : '') . fmtLBP($lbpAmt) : '—' ?>
        </td>
        <td class="small"><?= fmtUSD($row['balance_after_usd'] ?? 0) ?></td>
        <td class="small"><?= fmtLBP($row['balance_after_lbp'] ?? 0) ?></td>
        <td class="small text-muted"><?= htmlspecialchars($row['note'] ?: '—') ?></td>
        <td class="small"><?= $row['receipt_no'] ? '#'.$row['receipt_no'] : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$log): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No transactions in this period.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

<!-- Shift History -->
<?php if ($shiftHistory): ?>
<h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Shift History</h5>
<div class="card stat-card mb-4">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0 small">
    <thead class="table-dark"><tr>
        <th>Closed At</th>
        <th>Closed By</th>
        <th>Shift Start</th>
        <th>USD Balance</th>
        <th>LBP Balance</th>
        <th>Cash Sales</th>
        <th>Sales Total</th>
        <th>Cash In</th>
        <th>Cash Out</th>
        <th>Note</th>
    </tr></thead>
    <tbody>
    <?php foreach ($shiftHistory as $sh): ?>
    <tr>
        <td class="fw-semibold"><?= date('d/m/Y H:i', strtotime($sh['closed_at'])) ?></td>
        <td><?= htmlspecialchars($sh['closed_by_name'] ?? '—') ?></td>
        <td class="text-muted"><?= $sh['since_datetime'] ? date('d/m/Y H:i', strtotime($sh['since_datetime'])) : 'Beginning' ?></td>
        <td class="text-success fw-bold"><?= fmtUSD($sh['balance_usd']) ?></td>
        <td class="text-warning"><?= fmtLBP($sh['balance_lbp']) ?></td>
        <td class="text-center"><?= $sh['sales_count'] ?></td>
        <td class="text-primary"><?= fmtUSD($sh['sales_total_usd']) ?></td>
        <td class="text-success">
            <?= fmtUSD($sh['cash_in_usd']) ?>
            <?php if ($sh['cash_in_lbp'] > 0): ?>
            <br><span class="text-muted"><?= fmtLBP($sh['cash_in_lbp']) ?></span>
            <?php endif; ?>
        </td>
        <td class="text-danger">
            <?= fmtUSD($sh['cash_out_usd']) ?>
            <?php if ($sh['cash_out_lbp'] > 0): ?>
            <br><span class="text-muted"><?= fmtLBP($sh['cash_out_lbp']) ?></span>
            <?php endif; ?>
        </td>
        <td class="text-muted"><?= htmlspecialchars($sh['note'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>

</div>

<!-- End of Shift Modal -->
<div class="modal fade" id="shiftModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST">
    <input type="hidden" name="action" value="end_of_shift">
    <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-stopwatch me-2"></i>End of Shift — Snapshot</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="alert alert-info py-2 mb-3 small">
            <strong>Shift period:</strong>
            <?= $lastShiftClose
                ? 'From ' . date('d/m/Y H:i', strtotime($lastShiftClose)) . ' to now'
                : 'All time — no previous shift has been logged' ?>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card border-success text-center p-3">
                    <div class="small text-muted">USD Drawer (current)</div>
                    <div class="fw-bold fs-2 text-success"><?= fmtUSD($balanceUSD) ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-warning text-center p-3">
                    <div class="small text-muted">LBP Drawer (current)</div>
                    <div class="fw-bold fs-2 text-warning"><?= fmtLBP($balanceLBP) ?></div>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <?php
            $cs = $currentShift;
            $modalCols = [
                ['Cash Sales',   $cs['sales']['cnt'] . ' txn',         fmtUSD($cs['sales']['total']), 'primary'],
                ['Cash In USD',  fmtUSD($cs['movements']['in_usd']),   '',                             'success'],
                ['Cash In LBP',  fmtLBP($cs['movements']['in_lbp']),  '',                             'success'],
                ['Cash Out USD', fmtUSD($cs['movements']['out_usd']),  '',                             'danger'],
                ['Cash Out LBP', fmtLBP($cs['movements']['out_lbp']), '',                             'danger'],
            ];
            foreach ($modalCols as [$lbl, $val, $sub, $color]): ?>
            <div class="col">
                <div class="card p-2 text-center border-<?= $color ?>">
                    <div class="small text-muted"><?= $lbl ?></div>
                    <div class="fw-bold text-<?= $color ?>"><?= $val ?></div>
                    <?php if ($sub): ?><div class="small text-muted"><?= $sub ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mb-2">
            <label class="form-label fw-semibold">Note <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" name="shift_note" class="form-control" placeholder="e.g. Counted till, no discrepancy">
        </div>
        <div class="text-muted small">Balance is <strong>not reset</strong> — this is a read-only snapshot for your records.</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-dark px-4">
            <i class="bi bi-check2 me-1"></i>Confirm &amp; Log Shift
        </button>
    </div>
</form>
</div>
</div>
</div>

<?php renderFoot(); ?>
