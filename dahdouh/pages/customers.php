<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','cashier');

$message = '';

// Delete customer
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $hasSales = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id=?");
    $hasSales->execute([$did]);
    if ($hasSales->fetchColumn() > 0) {
        $message = 'error:Cannot delete — this customer has sales history. Clear their balance to zero instead.';
    } else {
        $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$did]);
        header('Location: customers.php'); exit;
    }
}

// Record manual payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    $cid       = (int)$_POST['customer_id'];
    $amount    = (float)($_POST['amount'] ?? 0);
    $amountLBP = (float)($_POST['amount_lbp'] ?? 0);
    $payMethod = trim($_POST['pay_method'] ?? 'cash_usd');
    $note      = trim($_POST['note'] ?? '');

    // Determine USD amount and cash register impact
    if ($payMethod === 'cash_lbp' && $amountLBP > 0) {
        $amount = round($amountLBP / EXCHANGE_RATE, 2);
    }

    if ($amount > 0 && $cid) {
        $payNote = $note ?: 'Manual payment';
        $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=?")->execute([$amount, $cid]);
        $pdo->prepare("INSERT INTO customer_ledger (customer_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$cid, 'payment', $amount, $payNote]);

        // Log to cash register for cash payments
        if ($payMethod === 'cash_usd') {
            logCashEntry($pdo, 'sale', $amount, "Customer payment — $payNote");
            $message = "success:Payment of " . fmtUSD($amount) . " recorded — added to USD cash register.";
        } elseif ($payMethod === 'cash_lbp') {
            logCashEntry($pdo, 'sale', 0, "Customer payment — $payNote", null, $amountLBP, 'LBP');
            $message = "success:Payment of LL " . number_format($amountLBP) . " (" . fmtUSD($amount) . ") recorded — added to LBP cash register.";
        } else {
            $message = "success:Payment of " . fmtUSD($amount) . " recorded (no cash register entry).";
        }
    }
}

// Save/edit customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $note    = trim($_POST['note'] ?? '');

    $initialBalance = (float)($_POST['initial_balance'] ?? 0);

    if (!$name) { $message = 'error:Name is required.'; }
    else {
        if ($id) {
            $pdo->prepare("UPDATE customers SET name=?,phone=?,address=?,note=? WHERE id=?")->execute([$name,$phone,$address,$note,$id]);
        } else {
            $pdo->prepare("INSERT INTO customers (name,phone,address,note,balance) VALUES (?,?,?,?,?)")
                ->execute([$name,$phone,$address,$note,$initialBalance]);
            if ($initialBalance != 0) {
                $newId = $pdo->lastInsertId();
                $type  = $initialBalance > 0 ? 'payment' : 'sale';
                $bnote = $initialBalance > 0 ? 'Opening credit (pre-existing)' : 'Opening debt (pre-existing)';
                $pdo->prepare("INSERT INTO customer_ledger (customer_id, type, amount, note) VALUES (?,?,?,?)")
                    ->execute([$newId, $type, $initialBalance, $bnote]);
            }
        }
        $message = 'success:Customer saved.';
    }
}

$viewId = (int)($_GET['view'] ?? 0);
$viewCustomer = null;
$ledger = [];
$receipts = [];
if ($viewId) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->execute([$viewId]);
    $viewCustomer = $stmt->fetch();
    if ($viewCustomer) {
        $lstmt = $pdo->prepare("
            SELECT cl.*, s.receipt_no FROM customer_ledger cl
            LEFT JOIN sales s ON s.id = cl.sale_id
            WHERE cl.customer_id=? ORDER BY cl.created_at DESC LIMIT 100
        ");
        $lstmt->execute([$viewId]);
        $ledger = $lstmt->fetchAll();

        $rstmt = $pdo->prepare("
            SELECT s.id, s.receipt_no, s.sale_date, s.total, s.subtotal, s.discount,
                   s.payment_method, s.currency_paid, s.paid_usd, s.paid_lbp,
                   s.is_void, s.void_reason,
                   COUNT(si.id) AS item_count
            FROM sales s
            LEFT JOIN sale_items si ON si.sale_id = s.id
            WHERE s.customer_id=?
            GROUP BY s.id
            ORDER BY s.sale_date DESC
            LIMIT 100
        ");
        $rstmt->execute([$viewId]);
        $receipts = $rstmt->fetchAll();
    }
}

$search    = trim($_GET['q'] ?? '');
$customers = $pdo->prepare("SELECT * FROM customers " . ($search ? "WHERE name LIKE ? OR phone LIKE ?" : '') . " ORDER BY name");
$customers->execute($search ? ["%$search%","%$search%"] : []);
$customers = $customers->fetchAll();

renderHead('Customers');
renderNav('customers');
alertBox($message);
?>
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Customers</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#custModal" onclick="clearCustForm()">
        <i class="bi bi-plus-lg"></i> Add Customer
    </button>
</div>

<div class="row g-3">

<?php if ($viewCustomer): ?>
<!-- Customer detail panel -->
<div class="col-lg-6">
<div class="card stat-card p-3">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <h6 class="fw-bold mb-0"><?= htmlspecialchars($viewCustomer['name']) ?></h6>
            <small class="text-muted"><?= htmlspecialchars($viewCustomer['phone'] ?: 'No phone') ?></small>
        </div>
        <a href="customers.php" class="btn btn-sm btn-outline-secondary">✕ Close</a>
    </div>

    <?php $bal = (float)$viewCustomer['balance']; ?>
    <div class="alert <?= $bal >= 0 ? 'alert-success' : 'alert-danger' ?> py-2 mb-2">
        <?php if ($bal > 0): ?>
            <i class="bi bi-arrow-up-circle me-1"></i>Credit: <strong><?= fmtUSD($bal) ?></strong> (we owe them)
        <?php elseif ($bal < 0): ?>
            <i class="bi bi-arrow-down-circle me-1"></i>Debt: <strong><?= fmtUSD(abs($bal)) ?></strong> (they owe us)
        <?php else: ?>
            <i class="bi bi-check-circle me-1"></i>Balance is settled
        <?php endif; ?>
    </div>

    <!-- Record payment -->
    <form method="POST" class="mb-3 p-2 bg-light rounded" id="cust-pay-form">
        <input type="hidden" name="action" value="payment">
        <input type="hidden" name="customer_id" value="<?= $viewId ?>">
        <label class="form-label small fw-bold">Record Payment Received</label>
        <div class="mb-1">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-success active" onclick="setCustPayMethod(this,'cash_usd')">Cash USD</button>
                <button type="button" class="btn btn-outline-success" onclick="setCustPayMethod(this,'cash_lbp')">Cash LBP</button>
                <button type="button" class="btn btn-outline-secondary" onclick="setCustPayMethod(this,'other')">Other</button>
            </div>
        </div>
        <input type="hidden" name="pay_method" id="cust-pay-method" value="cash_usd">
        <div class="input-group input-group-sm mb-1" id="cust-usd-row">
            <span class="input-group-text">$</span>
            <input type="number" name="amount" id="cust-amount-usd" class="form-control" placeholder="Amount (USD)" min="0.01" step="0.01">
        </div>
        <div class="input-group input-group-sm mb-1 d-none" id="cust-lbp-row">
            <span class="input-group-text">LL</span>
            <input type="number" name="amount_lbp" id="cust-amount-lbp" class="form-control" placeholder="Amount (LBP)" min="1" step="1">
        </div>
        <div class="input-group input-group-sm">
            <input type="text" name="note" class="form-control" placeholder="Note (optional)">
            <button type="submit" class="btn btn-success">Record</button>
        </div>
        <div class="form-text">Cash USD/LBP payments are added to the cash register. "Other" records only in the ledger.</div>
    </form>
    <script>
    function setCustPayMethod(btn, method) {
        document.getElementById('cust-pay-method').value = method;
        document.querySelectorAll('#cust-pay-form .btn-group button').forEach(b => {
            b.className = b === btn ? b.className.replace('btn-outline-success','btn-success').replace('btn-outline-secondary','btn-success') : b.className.replace('btn-success','btn-outline-success');
        });
        document.getElementById('cust-usd-row').classList.toggle('d-none', method === 'cash_lbp');
        document.getElementById('cust-lbp-row').classList.toggle('d-none', method !== 'cash_lbp');
        document.getElementById('cust-amount-usd').required = (method !== 'cash_lbp');
        document.getElementById('cust-amount-lbp').required = (method === 'cash_lbp');
    }
    </script>

    <!-- Tabs: Ledger | Receipts -->
    <ul class="nav nav-tabs nav-tabs-sm mb-2" id="custTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-ledger"><i class="bi bi-list-ul me-1"></i>Ledger</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-receipts"><i class="bi bi-receipt me-1"></i>Receipts <span class="badge bg-secondary"><?= count($receipts) ?></span></a></li>
    </ul>
    <div class="tab-content">

        <!-- Ledger tab -->
        <div class="tab-pane fade show active" id="tab-ledger">
        <div style="max-height:380px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top"><tr><th>Date</th><th>Type</th><th>Amount</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): ?>
            <tr>
                <td class="small"><?= date('d/m/y H:i', strtotime($l['created_at'])) ?></td>
                <td>
                    <?php if ($l['type'] === 'payment'): ?>
                        <span class="badge bg-success">Payment</span>
                    <?php elseif ($l['type'] === 'refund'): ?>
                        <span class="badge bg-info">Refund</span>
                    <?php elseif ($l['type'] === 'sale'): ?>
                        <span class="badge bg-danger"><?= $l['receipt_no'] ? '#'.$l['receipt_no'] : 'Sale' ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Adj</span>
                    <?php endif; ?>
                </td>
                <td class="<?= $l['amount'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">
                    <?= ($l['amount'] >= 0 ? '+' : '') . fmtUSD($l['amount']) ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($l['note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$ledger): ?><tr><td colspan="4" class="text-center text-muted py-3">No transactions yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>

        <!-- Receipts tab -->
        <div class="tab-pane fade" id="tab-receipts">
        <div style="max-height:380px;overflow-y:auto">
        <?php if (!$receipts): ?>
            <p class="text-muted text-center py-3 small">No receipts for this customer.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr><th>Date</th><th>Receipt #</th><th>Items</th><th>Total</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($receipts as $r): ?>
            <tr>
                <td class="small"><?= date('d/m/y', strtotime($r['sale_date'])) ?></td>
                <td class="fw-semibold"><?= htmlspecialchars($r['receipt_no'] ?: '—') ?></td>
                <td class="text-muted"><?= $r['item_count'] ?></td>
                <td class="fw-bold"><?= fmtUSD($r['total']) ?></td>
                <td>
                    <?php if ($r['is_void']): ?>
                        <span class="badge bg-secondary">Voided</span>
                    <?php else: ?>
                        <span class="badge bg-success">OK</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-link btn-sm p-0" onclick="toggleReceiptItems(<?= $r['id'] ?>, this)" title="View items">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </td>
            </tr>
            <tr id="rec-items-<?= $r['id'] ?>" style="display:none">
                <td colspan="6" class="p-0">
                    <div id="rec-items-body-<?= $r['id'] ?>" class="p-2 bg-light"></div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
        </div>

    </div><!-- tab-content -->
</div>
</div>
<?php endif; ?>

<!-- Customer list -->
<div class="<?= $viewCustomer ? 'col-lg-6' : 'col-12' ?>">
<form class="input-group mb-3" method="GET">
    <input type="text" name="q" class="form-control" placeholder="Search name or phone…" value="<?= htmlspecialchars($search) ?>">
    <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
    <?php if ($search): ?><a href="customers.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Name</th><th>Phone</th><th>Balance</th><th>Note</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($customers as $c): $bal = (float)$c['balance']; ?>
    <tr class="<?= $viewId==$c['id']?'table-active':'' ?>">
        <td class="fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
        <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
        <td>
            <?php if ($bal > 0): ?>
                <span class="badge bg-success">Credit <?= fmtUSD($bal) ?></span>
            <?php elseif ($bal < 0): ?>
                <span class="badge bg-danger">Debt <?= fmtUSD(abs($bal)) ?></span>
            <?php else: ?>
                <span class="badge bg-secondary">Settled</span>
            <?php endif; ?>
        </td>
        <td class="small text-muted"><?= htmlspecialchars($c['note'] ?: '—') ?></td>
        <td>
            <a href="?view=<?= $c['id'] ?><?= $search?"&q=".urlencode($search):'' ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-list-ul"></i></a>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#custModal" onclick='fillCustForm(<?= htmlspecialchars(json_encode($c)) ?>)'><i class="bi bi-pencil"></i></button>
            <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete customer?')"><i class="bi bi-trash"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="5" class="text-center text-muted py-4">No customers found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

</div><!-- row -->
</div>

<!-- Customer Modal -->
<div class="modal fade" id="custModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="cf_id">
    <div class="modal-header"><h5 class="modal-title">Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" id="cf_name" class="form-control" required></div>
        <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" id="cf_phone" class="form-control"></div>
            <div class="col-md-6">
                <label class="form-label">Note</label>
                <input type="text" name="note" id="cf_note" class="form-control">
            </div>
        </div>
        <div class="mb-3"><label class="form-label">Address</label><textarea name="address" id="cf_address" class="form-control" rows="2"></textarea></div>
        <div id="initial-balance-row" class="mb-3 p-3 bg-light rounded">
            <label class="form-label fw-bold">Opening Balance <span class="text-muted fw-normal small">(new customers only)</span></label>
            <div class="row g-2">
                <div class="col-md-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="balance_type" id="btNone" value="none" checked onchange="updateBalanceField()">
                        <label class="form-check-label" for="btNone">No pre-existing balance</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="balance_type" id="btDebt" value="debt" onchange="updateBalanceField()">
                        <label class="form-check-label text-danger" for="btDebt">Has existing debt</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="balance_type" id="btCredit" value="credit" onchange="updateBalanceField()">
                        <label class="form-check-label text-success" for="btCredit">Has existing credit</label>
                    </div>
                </div>
            </div>
            <div id="balance-input-wrap" class="mt-2 d-none">
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" id="cf_init_balance" class="form-control" placeholder="Amount (USD)" min="0" step="0.01">
                    <span class="input-group-text" id="balance-sign-label">owed by customer</span>
                </div>
                <input type="hidden" name="initial_balance" id="cf_init_balance_hidden" value="0">
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>
</div></div></div>

<script>
function clearCustForm() {
    ['cf_id','cf_name','cf_phone','cf_address','cf_note'].forEach(id => { const e=document.getElementById(id); if(e) e.value=''; });
    document.getElementById('cf_init_balance').value = '';
    document.getElementById('cf_init_balance_hidden').value = '0';
    document.getElementById('btNone').checked = true;
    document.getElementById('balance-input-wrap').classList.add('d-none');
    document.getElementById('initial-balance-row').style.display = '';
}
function fillCustForm(c) {
    document.getElementById('cf_id').value      = c.id;
    document.getElementById('cf_name').value    = c.name;
    document.getElementById('cf_phone').value   = c.phone || '';
    document.getElementById('cf_address').value = c.address || '';
    document.getElementById('cf_note').value    = c.note || '';
    // Hide initial balance section when editing existing customer
    document.getElementById('initial-balance-row').style.display = 'none';
    document.getElementById('cf_init_balance_hidden').value = '0';
}
function updateBalanceField() {
    const type = document.querySelector('input[name="balance_type"]:checked')?.value;
    const wrap  = document.getElementById('balance-input-wrap');
    const label = document.getElementById('balance-sign-label');
    if (type === 'none') {
        wrap.classList.add('d-none');
        document.getElementById('cf_init_balance_hidden').value = '0';
    } else {
        wrap.classList.remove('d-none');
        label.textContent = type === 'debt' ? 'owed by customer' : 'credit for customer';
    }
}
document.getElementById('cf_init_balance')?.addEventListener('input', function() {
    const type = document.querySelector('input[name="balance_type"]:checked')?.value;
    const val = parseFloat(this.value) || 0;
    document.getElementById('cf_init_balance_hidden').value = type === 'debt' ? -val : val;
});

function toggleReceiptItems(saleId, btn) {
    const row  = document.getElementById('rec-items-' + saleId);
    const body = document.getElementById('rec-items-body-' + saleId);
    const icon = btn.querySelector('i');
    if (row.style.display !== 'none') {
        row.style.display = 'none';
        icon.className = 'bi bi-chevron-down';
        return;
    }
    row.style.display = '';
    icon.className = 'bi bi-chevron-up';
    if (body.dataset.loaded) return;
    body.innerHTML = '<div class="text-center py-2 text-muted small">Loading…</div>';
    fetch('/dahdouh/pages/api.php?action=sale_items_for_return&sale_id=' + saleId)
        .then(r => r.json())
        .then(items => {
            if (!items.length) { body.innerHTML = '<p class="text-muted small mb-0 py-1">No items.</p>'; return; }
            let html = '<table class="table table-xs table-sm mb-0"><thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Returned</th></tr></thead><tbody>';
            items.forEach(it => {
                const ret = parseFloat(it.already_returned);
                html += `<tr>
                    <td class="small">${escHtml(it.product_name)}</td>
                    <td>${parseFloat(it.quantity)}</td>
                    <td>$${parseFloat(it.unit_price).toFixed(2)}</td>
                    <td class="fw-bold">$${parseFloat(it.total).toFixed(2)}</td>
                    <td class="small ${ret>0?'text-warning':''}">${ret>0?ret:''}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            body.innerHTML = html;
            body.dataset.loaded = '1';
        })
        .catch(() => { body.innerHTML = '<p class="text-danger small mb-0">Failed to load.</p>'; });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<?php renderFoot(); ?>
