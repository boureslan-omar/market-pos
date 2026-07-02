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
                   COUNT(si.id) AS item_count,
                   GROUP_CONCAT(si.product_name ORDER BY si.id SEPARATOR ', ') AS items_list
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
                <td>
                    <div class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                         title="<?= htmlspecialchars($r['items_list'] ?? '') ?>">
                        <?= (int)$r['item_count'] ?> — <?= htmlspecialchars($r['items_list'] ?? '—') ?>
                    </div>
                </td>
                <td class="fw-bold"><?= fmtUSD($r['total']) ?></td>
                <td>
                    <?php if ($r['is_void']): ?>
                        <span class="badge bg-secondary">Voided</span>
                    <?php else: ?>
                        <span class="badge bg-success">OK</span>
                    <?php endif; ?>
                </td>
                <td class="text-end" style="white-space:nowrap">
                    <button class="btn btn-link btn-sm p-0 me-2 text-secondary" onclick="printReceipt(<?= $r['id'] ?>)" title="Print receipt">
                        <i class="bi bi-printer"></i>
                    </button>
                    <?php if (!$r['is_void']): ?>
                    <button class="btn btn-link btn-sm p-0 me-2 text-primary" onclick="editSale(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['receipt_no'])) ?>')" title="Edit receipt">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
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
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
    const css =
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
        '.small{font-size:10px}.mt-1{margin-top:2px}.mt-2{margin-top:4px}.mt-3{margin-top:6px}.mb-0{margin-bottom:0}.mb-3{margin-bottom:6px}';
    let frame = document.getElementById('receipt-print-frame');
    if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'receipt-print-frame';
        frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:80mm;height:1px;border:0;visibility:hidden';
        document.body.appendChild(frame);
    }
    frame.onload = function() { frame.contentWindow.focus(); frame.contentWindow.print(); frame.onload = null; };
    frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt</title>' +
        '<style>' + css + '</style></head><body>' + html + '</body></html>';
}
</script>

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

<?php renderFoot(); ?>
