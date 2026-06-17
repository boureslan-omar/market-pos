<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','cashier');

// Recent customer returns for the log
$recentCustReturns = $pdo->query("
    SELECT cr.*, s.receipt_no, c.name AS customer_name
    FROM customer_returns cr
    JOIN sales s ON s.id = cr.sale_id
    LEFT JOIN customers c ON c.id = s.customer_id
    ORDER BY cr.created_at DESC LIMIT 50
")->fetchAll();

// Recent supplier returns for the log
$recentSuppReturns = $pdo->query("
    SELECT sr.*, s.name AS supplier_name
    FROM supplier_returns sr
    LEFT JOIN suppliers s ON s.id = sr.supplier_id
    ORDER BY sr.created_at DESC LIMIT 50
")->fetchAll();

renderHead('Returns');
renderNav('returns');
?>
<div class="container-fluid py-4">
<h4 class="fw-bold mb-4"><i class="bi bi-arrow-return-left me-2"></i>Returns</h4>

<ul class="nav nav-tabs mb-4" id="returnTabs">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-customer-ret">
            <i class="bi bi-person-x me-1"></i>Customer Returns
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-supplier-ret">
            <i class="bi bi-truck me-1"></i>Supplier Returns
        </a>
    </li>
</ul>

<div class="tab-content">

<!-- ── Customer Returns Tab ──────────────────────────────────────────────────── -->
<div class="tab-pane fade show active" id="tab-customer-ret">
<div class="row g-4">

    <!-- Search panel -->
    <div class="col-lg-5">
    <div class="card stat-card p-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-search me-2"></i>Find Receipt</h6>
        <div class="input-group mb-2">
            <input type="text" id="cr-search" class="form-control" placeholder="Receipt number or customer name…">
            <button class="btn btn-primary" onclick="searchReceipts()"><i class="bi bi-search"></i></button>
        </div>
        <div id="cr-results"></div>
    </div>

    <!-- Items from selected receipt -->
    <div class="card stat-card p-3 mt-3" id="cr-items-panel" style="display:none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0" id="cr-receipt-label">Receipt</h6>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearReceiptSelection()">✕ Clear</button>
        </div>
        <div id="cr-items-body"></div>
    </div>
    </div>

    <!-- Return log -->
    <div class="col-lg-7">
    <div class="card stat-card">
        <div class="p-3 border-bottom"><h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Recent Customer Returns</h6></div>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-dark">
                <tr><th>Date</th><th>Customer</th><th>Receipt</th><th>Product</th><th>Qty</th><th>Refund</th><th>Note</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentCustReturns as $r): ?>
            <tr>
                <td class="small"><?= date('d/m/y', strtotime($r['return_date'])) ?></td>
                <td class="small"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
                <td class="small"><?= htmlspecialchars($r['receipt_no'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['product_name']) ?></td>
                <td><?= (float)$r['quantity'] ?></td>
                <td class="fw-bold text-success"><?= fmtUSD($r['refund_amount']) ?></td>
                <td class="small text-muted"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recentCustReturns): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No customer returns yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div>

</div>
</div>

<!-- ── Supplier Returns Tab ──────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="tab-supplier-ret">
<div class="row g-4">

    <!-- Search panel -->
    <div class="col-lg-5">
    <div class="card stat-card p-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-search me-2"></i>Find Batch to Return</h6>
        <div class="input-group mb-2">
            <input type="text" id="sr-search" class="form-control" placeholder="Product name, barcode or purchase reference…">
            <button class="btn btn-primary" onclick="searchBatches()"><i class="bi bi-search"></i></button>
        </div>
        <div id="sr-results"></div>
    </div>

    <!-- Selected batch return form -->
    <div class="card stat-card p-3 mt-3" id="sr-batch-panel" style="display:none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0" id="sr-batch-label">Batch</h6>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearBatchSelection()">✕ Clear</button>
        </div>
        <div id="sr-batch-info" class="mb-3 small text-muted"></div>
        <div class="mb-2">
            <label class="form-label small fw-bold">Quantity to Return</label>
            <input type="number" id="sr-qty" class="form-control form-control-sm" min="0.001" step="0.001" placeholder="How many units to return">
            <div id="sr-qty-hint" class="text-muted" style="font-size:.75rem"></div>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-bold">Note (optional)</label>
            <input type="text" id="sr-note" class="form-control form-control-sm" placeholder="Reason for return">
        </div>
        <div id="sr-credit-preview" class="alert alert-info py-2 small" style="display:none"></div>
        <div class="text-danger small mt-1" id="sr-error"></div>
        <button class="btn btn-warning btn-sm mt-2 w-100" onclick="submitSupplierReturn()">
            <i class="bi bi-arrow-return-left me-1"></i>Process Supplier Return
        </button>
    </div>
    </div>

    <!-- Return log -->
    <div class="col-lg-7">
    <div class="card stat-card">
        <div class="p-3 border-bottom"><h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Recent Supplier Returns</h6></div>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-dark">
                <tr><th>Date</th><th>Supplier</th><th>Product</th><th>Qty</th><th>Credit</th><th>Note</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentSuppReturns as $r): ?>
            <tr>
                <td class="small"><?= date('d/m/y', strtotime($r['return_date'])) ?></td>
                <td class="small"><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['product_name']) ?></td>
                <td><?= (float)$r['quantity'] ?></td>
                <td class="fw-bold text-success"><?= fmtUSD($r['credit_amount']) ?></td>
                <td class="small text-muted"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recentSuppReturns): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No supplier returns yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div>

</div>
</div>

</div><!-- tab-content -->
</div>

<!-- ── Customer Return: Confirm Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="crConfirmModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header py-2 bg-warning">
        <h6 class="modal-title fw-bold"><i class="bi bi-arrow-return-left me-2"></i>Process Customer Return</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <p class="mb-2 small" id="cr-confirm-label"></p>
        <div class="mb-2">
            <label class="form-label small fw-bold">Quantity to Return</label>
            <input type="number" id="cr-qty" class="form-control form-control-sm" min="0.001" step="0.001">
            <div id="cr-max-hint" class="text-muted" style="font-size:.75rem"></div>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-bold">Note (optional)</label>
            <input type="text" id="cr-note" class="form-control form-control-sm" placeholder="Reason for return">
        </div>
        <div id="cr-refund-preview" class="alert alert-success py-2 small" style="display:none"></div>
        <div class="text-danger small mt-1" id="cr-error"></div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="submitCustReturn()">
            <i class="bi bi-check-lg me-1"></i>Confirm Return
        </button>
    </div>
</div></div></div>

<script>
let _crSaleItemId  = null;
let _crMaxQty      = 0;
let _crUnitPrice   = 0;
let _srBatchId     = null;
let _srBatchCost   = 0;
let _srMaxQty      = 0;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Customer Returns ─────────────────────────────────────────────────────────

function searchReceipts() {
    const q = document.getElementById('cr-search').value.trim();
    if (!q) return;
    const el = document.getElementById('cr-results');
    el.innerHTML = '<div class="text-muted small py-2">Searching…</div>';
    fetch('/dahdouh/pages/api.php?action=search_customer&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(customers => {
            if (!customers.length) {
                // Try searching as receipt number
                fetchReceiptByNo(q);
                return;
            }
            if (customers.length === 1) {
                loadCustomerSales(customers[0]);
                return;
            }
            let html = '<div class="list-group list-group-flush cr-customer-list">';
            customers.forEach((c, i) => {
                html += `<button class="list-group-item list-group-item-action py-1 small" data-cr-idx="${i}">
                    <strong>${escHtml(c.name)}</strong> ${c.phone ? `<span class="text-muted">${escHtml(c.phone)}</span>` : ''}
                </button>`;
            });
            html += '</div>';
            el.innerHTML = html;
            const listEl2 = el.querySelector('.cr-customer-list');
            listEl2._customers = customers;
            listEl2.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-cr-idx]');
                if (!btn) return;
                loadCustomerSales(this._customers[parseInt(btn.dataset.crIdx)]);
            });
        });
}

function fetchReceiptByNo(receiptNo) {
    fetch('/dahdouh/pages/api.php?action=search_customer&q=' + encodeURIComponent(receiptNo))
        .then(r => r.json())
        .then(() => {
            // Fall back: try loading sale by receipt_no via a direct lookup
            loadSaleItems(null, receiptNo);
        });
}

function loadCustomerSales(customer) {
    document.getElementById('cr-results').innerHTML = `
        <div class="alert alert-info py-1 small mt-2">
            Showing sales for <strong>${escHtml(customer.name)}</strong>
            <button class="btn-close float-end" style="font-size:.7rem" onclick="document.getElementById('cr-results').innerHTML=''"></button>
        </div>
        <div id="cr-sales-list"><div class="text-muted small py-2">Loading receipts…</div></div>`;
    fetch('/dahdouh/pages/api.php?action=search_customer&q=' + encodeURIComponent(customer.name))
        .then(() => {
            // Load sales for this customer from our data
            const salesEl = document.getElementById('cr-sales-list');
            if (!salesEl) return;
            salesEl.innerHTML = '<div class="text-muted small py-1">Click a receipt number above to load items, or search by receipt # directly.</div>';
        });
    // Actually load sales by customer_id via a dedicated fetch
    fetch('/dahdouh/pages/api.php?action=customer_sales&customer_id=' + encodeURIComponent(customer.id))
        .then(r => r.json())
        .then(sales => {
            const el = document.getElementById('cr-sales-list');
            if (!el) return;
            if (!sales || !sales.length) { el.innerHTML = '<div class="text-muted small py-1">No sales found.</div>'; return; }
            let html = '<div class="list-group list-group-flush mt-1" style="max-height:200px;overflow-y:auto">';
            sales.forEach(s => {
                html += `<button class="list-group-item list-group-item-action py-1 small d-flex justify-content-between"
                             onclick="loadSaleItems(${s.id}, '${escHtml(s.receipt_no)}')">
                    <span><strong>#${escHtml(s.receipt_no||s.id)}</strong> <span class="text-muted">${s.sale_date?.substring(0,10)||''}</span></span>
                    <span class="text-success">$${parseFloat(s.total).toFixed(2)}</span>
                </button>`;
            });
            html += '</div>';
            el.innerHTML = html;
        })
        .catch(() => {
            const el = document.getElementById('cr-sales-list');
            if (el) el.innerHTML = '<div class="text-muted small py-1">Search by receipt number directly (e.g. R-0001).</div>';
        });
}

function loadSaleItems(saleId, label) {
    document.getElementById('cr-items-panel').style.display = '';
    document.getElementById('cr-receipt-label').textContent = 'Receipt ' + (label || saleId);
    const body = document.getElementById('cr-items-body');
    body.innerHTML = '<div class="text-muted small py-2">Loading items…</div>';

    const url = saleId
        ? '/dahdouh/pages/api.php?action=sale_items_for_return&sale_id=' + saleId
        : '/dahdouh/pages/api.php?action=sale_items_for_return_by_receipt&receipt_no=' + encodeURIComponent(label);

    fetch(url)
        .then(r => r.json())
        .then(items => {
            if (!items || items.error || !items.length) {
                body.innerHTML = '<p class="text-muted small mb-0 py-1">No returnable items found.</p>';
                return;
            }
            let html = '<table class="table table-xs table-sm mb-0"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Returned</th><th></th></tr></thead><tbody>';
            items.forEach(it => {
                const ret     = parseFloat(it.already_returned) || 0;
                const maxRet  = parseFloat(it.quantity) - ret;
                const canRet  = maxRet > 0;
                html += `<tr>
                    <td class="small">${escHtml(it.product_name)}</td>
                    <td>${parseFloat(it.quantity)}</td>
                    <td>$${parseFloat(it.unit_price).toFixed(2)}</td>
                    <td class="small ${ret>0?'text-warning':'text-muted'}">${ret>0?ret:'—'}</td>
                    <td>${canRet
                        ? `<button class="btn btn-sm btn-outline-warning py-0 px-1" style="font-size:.7rem"
                               onclick="openReturnModal(${it.id},${maxRet},${it.unit_price},'${escHtml(it.product_name)}')">Return</button>`
                        : '<span class="text-muted small">Done</span>'}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<p class="text-danger small mb-0">Failed to load.</p>'; });
}

function clearReceiptSelection() {
    document.getElementById('cr-items-panel').style.display = 'none';
    document.getElementById('cr-items-body').innerHTML = '';
}

function openReturnModal(saleItemId, maxQty, unitPrice, productName) {
    _crSaleItemId = saleItemId;
    _crMaxQty     = maxQty;
    _crUnitPrice  = unitPrice;
    document.getElementById('cr-confirm-label').innerHTML = `Return <strong>${escHtml(productName)}</strong> (max ${maxQty} units)`;
    document.getElementById('cr-qty').value = maxQty;
    document.getElementById('cr-qty').max   = maxQty;
    document.getElementById('cr-max-hint').textContent = `Max returnable: ${maxQty}`;
    document.getElementById('cr-note').value = '';
    document.getElementById('cr-error').textContent = '';
    updateCrRefundPreview();
    new bootstrap.Modal(document.getElementById('crConfirmModal')).show();
}

document.getElementById('cr-qty')?.addEventListener('input', updateCrRefundPreview);
function updateCrRefundPreview() {
    const qty     = parseFloat(document.getElementById('cr-qty')?.value) || 0;
    const refund  = qty * _crUnitPrice;
    const preview = document.getElementById('cr-refund-preview');
    if (!preview) return;
    if (qty > 0) {
        preview.style.display = '';
        preview.innerHTML = `Refund: <strong>$${refund.toFixed(2)}</strong> for ${qty} unit(s) × $${parseFloat(_crUnitPrice).toFixed(2)}`;
    } else {
        preview.style.display = 'none';
    }
}

function submitCustReturn() {
    const qty  = parseFloat(document.getElementById('cr-qty').value) || 0;
    const note = document.getElementById('cr-note').value.trim();
    document.getElementById('cr-error').textContent = '';
    if (!qty || qty <= 0) { document.getElementById('cr-error').textContent = 'Enter a valid quantity.'; return; }
    if (qty > _crMaxQty) { document.getElementById('cr-error').textContent = `Max returnable: ${_crMaxQty}`; return; }
    const body = new URLSearchParams({ sale_item_id: _crSaleItemId, quantity: qty, note });
    fetch('/dahdouh/pages/api.php?action=process_customer_return', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { document.getElementById('cr-error').textContent = d.error||'Error'; return; }
            bootstrap.Modal.getInstance(document.getElementById('crConfirmModal'))?.hide();
            alert(`Return processed. Refund: $${parseFloat(d.refund).toFixed(2)}`);
            location.reload();
        })
        .catch(() => { document.getElementById('cr-error').textContent = 'Network error.'; });
}

// ── Supplier Returns ─────────────────────────────────────────────────────────

function searchBatches() {
    const q  = document.getElementById('sr-search').value.trim();
    if (!q) return;
    const el = document.getElementById('sr-results');
    el.innerHTML = '<div class="text-muted small py-2">Searching…</div>';
    fetch('/dahdouh/pages/api.php?action=batches_for_supplier_return&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(batches => {
            if (!batches.length) { el.innerHTML = '<div class="text-muted small py-2">No active batches found.</div>'; return; }
            let html = '<div class="list-group list-group-flush mt-2 sr-batch-list" style="max-height:280px;overflow-y:auto">';
            batches.forEach((b, i) => {
                html += `<button class="list-group-item list-group-item-action py-2 small" data-sr-idx="${i}">
                    <div class="d-flex justify-content-between">
                        <strong>${escHtml(b.product_name)}</strong>
                        <span class="text-success">$${parseFloat(b.cost_price).toFixed(4)}/unit</span>
                    </div>
                    <div class="text-muted">
                        Batch #${b.id} · ${b.purchase_date?.substring(0,10)||''} · ${escHtml(b.supplier_name||'—')}
                        · Remaining: <strong>${parseFloat(b.quantity_remaining)}</strong>
                        ${b.reference ? `· Ref: ${escHtml(b.reference)}` : ''}
                    </div>
                </button>`;
            });
            html += '</div>';
            el.innerHTML = html;
            // Store batch data on the container, attach click handlers
            const listEl = el.querySelector('.sr-batch-list');
            listEl._batches = batches;
            listEl.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-sr-idx]');
                if (!btn) return;
                selectBatch(this._batches[parseInt(btn.dataset.srIdx)]);
            });
        })
        .catch(() => { el.innerHTML = '<div class="text-danger small py-2">Search failed.</div>'; });
}

function selectBatch(b) {
    _srBatchId   = b.id;
    _srBatchCost = parseFloat(b.cost_price);
    _srMaxQty    = parseFloat(b.quantity_remaining);
    document.getElementById('sr-batch-label').textContent = `Batch #${b.id} — ${b.product_name}`;
    document.getElementById('sr-batch-info').innerHTML = `
        Supplier: <strong>${escHtml(b.supplier_name||'—')}</strong> ·
        Cost: <strong>$${_srBatchCost.toFixed(4)}/unit</strong> ·
        Remaining in batch: <strong>${_srMaxQty}</strong>`;
    document.getElementById('sr-qty').value = '';
    document.getElementById('sr-qty').max   = _srMaxQty;
    document.getElementById('sr-qty-hint').textContent = `Max returnable: ${_srMaxQty}`;
    document.getElementById('sr-note').value = '';
    document.getElementById('sr-error').textContent = '';
    document.getElementById('sr-credit-preview').style.display = 'none';
    document.getElementById('sr-batch-panel').style.display = '';
}

document.getElementById('sr-qty')?.addEventListener('input', function() {
    const qty    = parseFloat(this.value) || 0;
    const credit = qty * _srBatchCost;
    const prev   = document.getElementById('sr-credit-preview');
    if (qty > 0) {
        prev.style.display = '';
        prev.innerHTML = `Credit to supplier: <strong>$${credit.toFixed(2)}</strong> (${qty} × $${_srBatchCost.toFixed(4)})`;
    } else {
        prev.style.display = 'none';
    }
});

function clearBatchSelection() {
    document.getElementById('sr-batch-panel').style.display = 'none';
    _srBatchId = null;
}

function submitSupplierReturn() {
    const qty  = parseFloat(document.getElementById('sr-qty').value) || 0;
    const note = document.getElementById('sr-note').value.trim();
    document.getElementById('sr-error').textContent = '';
    if (!_srBatchId) { document.getElementById('sr-error').textContent = 'Select a batch first.'; return; }
    if (!qty || qty <= 0) { document.getElementById('sr-error').textContent = 'Enter a valid quantity.'; return; }
    if (qty > _srMaxQty) { document.getElementById('sr-error').textContent = `Max returnable: ${_srMaxQty}`; return; }
    const body = new URLSearchParams({ batch_id: _srBatchId, quantity: qty, note });
    fetch('/dahdouh/pages/api.php?action=process_supplier_return', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { document.getElementById('sr-error').textContent = d.error||'Error'; return; }
            alert(`Supplier return processed. Credit: $${parseFloat(d.credit).toFixed(2)}`);
            location.reload();
        })
        .catch(() => { document.getElementById('sr-error').textContent = 'Network error.'; });
}
</script>

<?php renderFoot(); ?>
