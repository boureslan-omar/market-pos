<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message = '';

// ── Delete supplier ───────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $hasPurch = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=?");
    $hasPurch->execute([$did]);
    $hasLedger = $pdo->prepare("SELECT COUNT(*) FROM supplier_ledger WHERE supplier_id=?");
    $hasLedger->execute([$did]);
    if ($hasPurch->fetchColumn() > 0 || $hasLedger->fetchColumn() > 0) {
        $message = 'error:Cannot delete — this supplier has purchase or payment history.';
    } else {
        $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$did]);
        header('Location: suppliers.php'); exit;
    }
}

// ── Save / edit supplier ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;

    if (!$name) { $message = 'error:Name is required.'; }
    else {
        if ($id) {
            $pdo->prepare("UPDATE suppliers SET name=?,phone=?,email=?,address=?,customer_id=? WHERE id=?")
                ->execute([$name,$phone,$email,$address,$customerId,$id]);
        } else {
            $pdo->prepare("INSERT INTO suppliers (name,phone,email,address,customer_id) VALUES (?,?,?,?,?)")
                ->execute([$name,$phone,$email,$address,$customerId]);
        }
        $message = 'success:Supplier saved.';
    }
}

// ── Record supplier payment (we pay them) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    $sid             = (int)$_POST['supplier_id'];
    $amount          = (float)($_POST['amount'] ?? 0);
    $amountLBP       = (float)($_POST['amount_lbp'] ?? 0);
    $method          = trim($_POST['pay_method'] ?? 'other');
    $note            = trim($_POST['note'] ?? '');
    $supName         = '';
    $supRow          = $pdo->prepare("SELECT name FROM suppliers WHERE id=?");
    $supRow->execute([$sid]);
    $supRow          = $supRow->fetch();
    if ($supRow) $supName = $supRow['name'];

    $payNote = ($note ?: "Payment to $supName");

    // Get linked customer_id for this supplier
    $supFull = $pdo->prepare("SELECT customer_id FROM suppliers WHERE id=?");
    $supFull->execute([$sid]);
    $linkedCustId = (int)($supFull->fetchColumn() ?: 0);

    $paidUSD = 0;
    if ($method === 'cash_usd' && $amount > 0 && $sid) {
        logCashEntry($pdo, 'withdrawal', -$amount, $payNote);
        $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$amount, $sid]);
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$sid, 'payment', -$amount, $payNote]);
        $paidUSD = $amount;
        $message = "success:Payment of " . fmtUSD($amount) . " recorded — deducted from USD cash register.";
    } elseif ($method === 'cash_lbp' && $amountLBP > 0 && $sid) {
        // Convert LBP to USD equivalent to update supplier balance (balance stored in USD)
        $amountUSDEquiv = round($amountLBP / EXCHANGE_RATE, 2);
        logCashEntry($pdo, 'withdrawal', 0, $payNote, null, -$amountLBP, 'LBP');
        $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$amountUSDEquiv, $sid]);
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$sid, 'payment', -$amountUSDEquiv, $payNote . " (LL " . number_format($amountLBP) . " = " . fmtUSD($amountUSDEquiv) . ")"]);
        $paidUSD = $amountUSDEquiv;
        $message = "success:LBP payment of LL " . number_format($amountLBP) . " (" . fmtUSD($amountUSDEquiv) . ") recorded — deducted from LBP cash register.";
    } elseif ($amount > 0 && $sid) {
        $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$amount, $sid]);
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$sid, 'payment', -$amount, $payNote]);
        $paidUSD = $amount;
        $message = "success:Payment of " . fmtUSD($amount) . " recorded.";
    }

    // Option A: auto-credit linked customer when supplier is paid
    if ($linkedCustId > 0 && $paidUSD > 0) {
        $custNote = "Supplier credit from payment to $supName";
        $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=?")->execute([$paidUSD, $linkedCustId]);
        $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,NULL,'credit',?,?)")
            ->execute([$linkedCustId, $paidUSD, $custNote]);
        $message .= ' Linked customer credited ' . fmtUSD($paidUSD) . '.';
    }
}

// ── Manual adjustment ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    $sid    = (int)$_POST['supplier_id'];
    $amount = (float)$_POST['amount'];
    $note   = trim($_POST['note'] ?? '—');
    if ($sid) {
        $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$amount, $sid]);
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$sid, 'adjustment', $amount, $note]);
        $message = "success:Adjustment of " . fmtUSD(abs($amount)) . " recorded.";
    }
}

// ── View supplier ledger ──────────────────────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$viewSupplier = null;
$ledger = [];
$supPurchases = [];

if ($viewId) {
    $st = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $st->execute([$viewId]);
    $viewSupplier = $st->fetch();

    if ($viewSupplier) {
        $ls = $pdo->prepare("
            SELECT sl.*, pu.reference AS purchase_ref
            FROM supplier_ledger sl
            LEFT JOIN purchases pu ON pu.id = sl.purchase_id
            WHERE sl.supplier_id=?
            ORDER BY sl.created_at DESC LIMIT 100
        ");
        $ls->execute([$viewId]);
        $ledger = $ls->fetchAll();

        $ps = $pdo->prepare("
            SELECT pu.*, COUNT(pi.id) AS item_count
            FROM purchases pu
            LEFT JOIN purchase_items pi ON pi.purchase_id=pu.id
            WHERE pu.supplier_id=?
            GROUP BY pu.id
            ORDER BY pu.purchase_date DESC LIMIT 50
        ");
        $ps->execute([$viewId]);
        $supPurchases = $ps->fetchAll();
    }
}

// ── Supplier list ─────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$suppliers = $pdo->prepare("
    SELECT s.*,
           COUNT(DISTINCT p.id) AS product_count,
           COUNT(DISTINCT pu.id) AS purchase_count,
           c.name AS linked_customer_name
    FROM suppliers s
    LEFT JOIN products p ON p.supplier_id=s.id
    LEFT JOIN purchases pu ON pu.supplier_id=s.id
    LEFT JOIN customers c ON c.id=s.customer_id
    " . ($search ? "WHERE s.name LIKE ? OR s.phone LIKE ?" : '') . "
    GROUP BY s.id ORDER BY s.name
");
$suppliers->execute($search ? ["%$search%", "%$search%"] : []);
$suppliers = $suppliers->fetchAll();

$allCustomers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();

$totalOwed = array_sum(array_column($suppliers, 'balance'));

renderHead('Suppliers');
renderNav('suppliers');
alertBox($message);
?>
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people me-2"></i>Suppliers</h4>
        <?php if ($totalOwed > 0): ?>
        <small class="text-danger fw-bold">Total owed to suppliers: <?= fmtUSD($totalOwed) ?></small>
        <?php elseif ($totalOwed < 0): ?>
        <small class="text-success fw-bold">Net supplier credit: <?= fmtUSD(abs($totalOwed)) ?></small>
        <?php endif; ?>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supModal" onclick="clearSupForm()">
        <i class="bi bi-plus-lg"></i> Add Supplier
    </button>
</div>

<div class="row g-3">

<?php if ($viewSupplier): ?>
<!-- ── Ledger panel ── -->
<div class="col-lg-5">
<div class="card stat-card p-3">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <h6 class="fw-bold mb-0"><?= htmlspecialchars($viewSupplier['name']) ?></h6>
            <small class="text-muted">
                <?= htmlspecialchars($viewSupplier['phone'] ?: '') ?>
                <?= $viewSupplier['email'] ? ' · '.$viewSupplier['email'] : '' ?>
            </small>
        </div>
        <a href="suppliers.php" class="btn btn-sm btn-outline-secondary">✕ Close</a>
    </div>

    <?php $bal = (float)$viewSupplier['balance']; ?>
    <div class="alert <?= $bal > 0 ? 'alert-danger' : ($bal < 0 ? 'alert-success' : 'alert-secondary') ?> py-2 mb-3">
        <?php if ($bal > 0): ?>
            <i class="bi bi-exclamation-triangle me-1"></i>We owe them: <strong><?= fmtUSD($bal) ?></strong>
        <?php elseif ($bal < 0): ?>
            <i class="bi bi-check-circle me-1"></i>They owe us: <strong><?= fmtUSD(abs($bal)) ?></strong>
        <?php else: ?>
            <i class="bi bi-check-circle me-1"></i>Account is settled
        <?php endif; ?>
    </div>

    <!-- Record payment -->
    <div class="p-2 bg-light rounded mb-3">
        <label class="form-label small fw-bold">Record Payment to Supplier</label>
        <?php
        $supBalUSD = $bal > 0 ? number_format($bal, 2, '.', '') : '';
        $supBalLBP = $bal > 0 ? number_format(round($bal * EXCHANGE_RATE), 0, '.', '') : '';
        $supDefaultNote = 'Payment to ' . htmlspecialchars($viewSupplier['name'], ENT_QUOTES);
        ?>
        <form method="POST">
            <input type="hidden" name="action" value="payment">
            <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Payment Method</label>
                    <select name="pay_method" id="supPayMethod<?= $viewId ?>" class="form-select form-select-sm"
                            onchange="onSupPayChange(<?= $viewId ?>, <?= json_encode($supBalUSD) ?>, <?= json_encode($supBalLBP) ?>, <?= EXCHANGE_RATE ?>)">
                        <option value="cash_usd">Cash Register (USD)</option>
                        <option value="cash_lbp">Cash Register (LBP)</option>
                        <option value="bank">Bank Transfer / Cheque / Other</option>
                    </select>
                    <div id="supCurToggle<?= $viewId ?>" class="btn-group btn-group-sm mt-1">
                        <button type="button" class="btn btn-success active" id="supCurUsd<?= $viewId ?>"
                                onclick="setSupCur(<?= $viewId ?>, 'usd', <?= json_encode($supBalUSD) ?>, <?= json_encode($supBalLBP) ?>, <?= EXCHANGE_RATE ?>)">$ USD</button>
                        <button type="button" class="btn btn-outline-warning" id="supCurLbp<?= $viewId ?>"
                                onclick="setSupCur(<?= $viewId ?>, 'lbp', <?= json_encode($supBalUSD) ?>, <?= json_encode($supBalLBP) ?>, <?= EXCHANGE_RATE ?>)">LL LBP</button>
                    </div>
                </div>
                <div class="col-md-3" id="supUsdBox<?= $viewId ?>">
                    <label class="form-label small">Amount (USD)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" id="supAmtUSD<?= $viewId ?>" class="form-control"
                               placeholder="0.00" min="0.01" step="0.01" value="<?= $supBalUSD ?>">
                    </div>
                </div>
                <div class="col-md-3" id="supLbpBox<?= $viewId ?>" style="display:none">
                    <label class="form-label small">Amount (LBP)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">ل.ل</span>
                        <input type="number" name="amount_lbp" id="supAmtLBP<?= $viewId ?>" class="form-control"
                               placeholder="0" min="1" step="1" value="<?= $supBalLBP ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Note</label>
                    <input type="text" name="note" id="supNote<?= $viewId ?>" class="form-control form-control-sm"
                           value="<?= $supDefaultNote ?>" placeholder="Invoice #, cheque #, etc.">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-success">Record Payment</button>
                </div>
            </div>
        </form>

        <!-- Manual adjustment -->
        <details class="mt-2">
            <summary class="small text-muted" style="cursor:pointer">Manual adjustment</summary>
            <form method="POST" class="d-flex gap-2 mt-1">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" name="amount" class="form-control" placeholder="Positive = add debt, Negative = remove debt" step="0.01">
                    <input type="text" name="note" class="form-control" placeholder="Reason" required>
                    <button type="submit" class="btn btn-warning">Apply</button>
                </div>
            </form>
        </details>
    </div>

    <!-- Ledger history -->
    <ul class="nav nav-tabs nav-sm mb-2" id="supTabs">
        <li class="nav-item"><a class="nav-link active small py-1" data-bs-toggle="tab" href="#tabLedger">Ledger</a></li>
        <li class="nav-item"><a class="nav-link small py-1" data-bs-toggle="tab" href="#tabPurchases">Purchases</a></li>
    </ul>
    <div class="tab-content" style="max-height:380px;overflow-y:auto">
        <!-- Ledger tab -->
        <div class="tab-pane active" id="tabLedger">
        <table class="table table-sm table-hover mb-0">
            <thead class="sticky-top bg-white"><tr><th>Date</th><th>Type</th><th>Amount</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): ?>
            <tr>
                <td class="small"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
                <td>
                    <?php if ($l['type'] === 'payment'): ?>
                        <span class="badge bg-success">Payment</span>
                    <?php elseif ($l['type'] === 'purchase'): ?>
                        <span class="badge bg-danger">Purchase</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Adj</span>
                    <?php endif; ?>
                </td>
                <td class="fw-bold <?= $l['amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= ($l['amount'] > 0 ? '+' : '') . fmtUSD($l['amount']) ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($l['note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$ledger): ?><tr><td colspan="4" class="text-center text-muted py-3">No transactions yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Purchases tab -->
        <div class="tab-pane" id="tabPurchases">
        <table class="table table-sm table-hover mb-0">
            <thead class="sticky-top bg-white"><tr><th>Date</th><th>Ref</th><th>Items</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($supPurchases as $pu): ?>
            <tr>
                <td class="small"><?= $pu['purchase_date'] ?></td>
                <td class="small"><?= htmlspecialchars($pu['reference'] ?: '—') ?></td>
                <td><?= $pu['item_count'] ?></td>
                <td class="fw-bold"><?= fmtUSD($pu['total_amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$supPurchases): ?><tr><td colspan="4" class="text-center text-muted py-3">No purchases yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<!-- ── Supplier list ── -->
<div class="<?= $viewSupplier ? 'col-lg-7' : 'col-12' ?>">

<form class="input-group mb-3" method="GET">
    <input type="text" name="q" class="form-control" placeholder="Search name or phone…" value="<?= htmlspecialchars($search) ?>">
    <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
    <?php if ($search): ?><a href="suppliers.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Name</th><th>Phone</th><th>Balance</th><th>Products</th><th>Purchases</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): $bal = (float)$s['balance']; ?>
    <tr class="<?= $viewId==$s['id']?'table-active':'' ?>">
        <td>
            <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?></div>
            <?php if ($s['address']): ?><div class="small text-muted"><?= htmlspecialchars($s['address']) ?></div><?php endif; ?>
        </td>
        <td>
            <div><?= htmlspecialchars($s['phone'] ?: '—') ?></div>
            <?php if ($s['email']): ?><div class="small text-muted"><?= htmlspecialchars($s['email']) ?></div><?php endif; ?>
            <?php if ($s['linked_customer_name']): ?><div class="small"><i class="bi bi-person-check text-success"></i> <?= htmlspecialchars($s['linked_customer_name']) ?></div><?php endif; ?>
        </td>
        <td>
            <?php if ($bal > 0): ?>
                <span class="badge bg-danger">Owe <?= fmtUSD($bal) ?></span>
            <?php elseif ($bal < 0): ?>
                <span class="badge bg-success">Credit <?= fmtUSD(abs($bal)) ?></span>
            <?php else: ?>
                <span class="badge bg-secondary">Settled</span>
            <?php endif; ?>
        </td>
        <td><span class="badge bg-light text-dark border"><?= $s['product_count'] ?></span></td>
        <td><span class="badge bg-light text-dark border"><?= $s['purchase_count'] ?></span></td>
        <td>
            <a href="?view=<?= $s['id'] ?><?= $search?"&q=".urlencode($search):'' ?>" class="btn btn-sm btn-outline-info" title="View ledger"><i class="bi bi-list-ul"></i></a>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#supModal"
                    onclick='fillSupForm(<?= htmlspecialchars(json_encode($s)) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
            <a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger"
               onclick="return confirm('Delete supplier?')" title="Delete"><i class="bi bi-trash"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$suppliers): ?><tr><td colspan="6" class="text-center text-muted py-4">No suppliers found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div><!-- col -->
</div><!-- row -->
</div>

<!-- Supplier Modal -->
<div class="modal fade" id="supModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="sf_id">
    <div class="modal-header"><h5 class="modal-title">Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" id="sf_name" class="form-control" required></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" id="sf_phone" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" id="sf_email" class="form-control"></div>
        </div>
        <div class="mt-3"><label class="form-label">Address</label><textarea name="address" id="sf_address" class="form-control" rows="2"></textarea></div>
        <div class="mt-3">
            <label class="form-label">Linked Customer <small class="text-muted">(optional — payments auto-credit this customer)</small></label>
            <select name="customer_id" id="sf_customer_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($allCustomers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>
</div></div></div>

<script>
function onSupPayChange(sid, balUSD, balLBP, rate) {
    const method  = document.getElementById('supPayMethod' + sid)?.value;
    const isCash  = method === 'cash_usd' || method === 'cash_lbp';
    const toggle  = document.getElementById('supCurToggle' + sid);
    const usdBox  = document.getElementById('supUsdBox' + sid);
    const lbpBox  = document.getElementById('supLbpBox' + sid);
    if (toggle) toggle.style.display = isCash ? '' : 'none';
    usdBox.style.display = (method === 'cash_lbp') ? 'none' : '';
    lbpBox.style.display = (method === 'cash_lbp') ? '' : 'none';
    if (method === 'cash_lbp') {
        const usdEl = document.getElementById('supAmtUSD' + sid);
        if (usdEl) usdEl.value = '';
        const lbpEl = document.getElementById('supAmtLBP' + sid);
        if (lbpEl) { lbpEl.value = ''; lbpEl.placeholder = balLBP ? 'Balance ≈ LL ' + Number(balLBP).toLocaleString() : '0'; lbpEl.focus(); }
    } else if (isCash) {
        const usdEl = document.getElementById('supAmtUSD' + sid);
        if (usdEl && balUSD) usdEl.value = balUSD;
        const lbpEl = document.getElementById('supAmtLBP' + sid);
        if (lbpEl) lbpEl.value = '';
    }
}

function setSupCur(sid, cur, balUSD, balLBP, rate) {
    const sel = document.getElementById('supPayMethod' + sid);
    if (sel) sel.value = (cur === 'lbp') ? 'cash_lbp' : 'cash_usd';
    document.getElementById('supCurUsd' + sid).className = 'btn btn-' + (cur === 'usd' ? 'success active' : 'outline-success');
    document.getElementById('supCurLbp' + sid).className = 'btn btn-' + (cur === 'lbp' ? 'warning active' : 'outline-warning');
    onSupPayChange(sid, balUSD, balLBP, rate);
}

function clearSupForm() {
    ['sf_id','sf_name','sf_phone','sf_email','sf_address'].forEach(id => { const e=document.getElementById(id); if(e) e.value=''; });
    const csel = document.getElementById('sf_customer_id'); if (csel) csel.value = '';
}
function fillSupForm(s) {
    document.getElementById('sf_id').value      = s.id;
    document.getElementById('sf_name').value    = s.name;
    document.getElementById('sf_phone').value   = s.phone || '';
    document.getElementById('sf_email').value   = s.email || '';
    document.getElementById('sf_address').value = s.address || '';
    const csel = document.getElementById('sf_customer_id');
    if (csel) csel.value = s.customer_id || '';
}
</script>
<?php renderFoot(); ?>
