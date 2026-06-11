<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message     = '';
$batchReport = [];

// ─── Delete purchase (reverse stock) ─────────────────────────────────────────
if (isset($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    $items = $pdo->prepare("SELECT pi.*, p.product_type FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?");
    $items->execute([$pid]);
    foreach ($items->fetchAll() as $it) {
        if ($it['product_type'] === 'regular' && $it['batch_id']) {
            // Reverse the batch quantity
            $pdo->prepare("UPDATE batches SET quantity_remaining = GREATEST(0, quantity_remaining - ?), quantity_original = GREATEST(0, quantity_original - ?) WHERE id=?")
                ->execute([$it['quantity'], $it['quantity'], $it['batch_id']]);
        }
        if ($it['product_type'] === 'regular') {
            $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$it['quantity'], $it['product_id']]);
        }
    }
    // Reverse supplier balance
    $purRow = $pdo->prepare("SELECT supplier_id, total_amount FROM purchases WHERE id=?");
    $purRow->execute([$pid]);
    $purRow = $purRow->fetch();
    if ($purRow && $purRow['supplier_id']) {
        $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$purRow['total_amount'], $purRow['supplier_id']]);
        $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
            ->execute([$purRow['supplier_id'], 'adjustment', -$purRow['total_amount'], "Purchase #$pid reversed (deleted)"]);
    }
    $pdo->prepare("DELETE FROM purchases WHERE id=?")->execute([$pid]);
    header('Location: purchases.php'); exit;
}

// ─── Save new purchase ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId    = (int)($_POST['supplier_id'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'pay_later');
    $reference     = trim($_POST['reference'] ?? '');
    $note          = trim($_POST['note'] ?? '');
    $date          = $_POST['purchase_date'] ?? date('Y-m-d');

    // Ensure a supplier is always set — fall back to "Default Supplier"
    if (!$supplierId) {
        $defId = $pdo->query("SELECT id FROM suppliers WHERE name='Default Supplier' LIMIT 1")->fetchColumn();
        if (!$defId) {
            $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute(['Default Supplier']);
            $defId = (int)$pdo->lastInsertId();
        }
        $supplierId = (int)$defId;
    }
    $pids      = $_POST['product_id']    ?? [];
    $qtys      = $_POST['quantity']      ?? [];
    $costs     = $_POST['unit_cost']     ?? [];
    $sellPrices = $_POST['new_sell_price'] ?? [];

    $items = [];
    for ($i = 0; $i < count($pids); $i++) {
        $pid  = (int)$pids[$i];
        $qty  = (float)($qtys[$i] ?? 0);
        $cost = (float)($costs[$i] ?? 0);
        $sell = (float)($sellPrices[$i] ?? 0);
        if ($pid > 0 && $cost > 0) $items[] = [$pid, $qty, $cost, $sell];
    }

    if (empty($items)) {
        $message = 'error:Add at least one item with a valid cost.';
    } else {
        $total = array_sum(array_map(fn($r) => $r[1] > 0 ? $r[1] * $r[2] : $r[2], $items));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO purchases (supplier_id, reference, total_amount, note, purchase_date) VALUES (?,?,?,?,?)")
                ->execute([$supplierId, $reference, $total, $note, $date]);
            $purchId = $pdo->lastInsertId();

            foreach ($items as [$pid, $qty, $cost, $newSell]) {
                // Get product details
                $prod = $pdo->prepare("SELECT product_type, name, sell_price, cost_price FROM products WHERE id=?");
                $prod->execute([$pid]);
                $prod = $prod->fetch();

                if ($prod['product_type'] === 'bulk') {
                    $lineTotal = $cost;
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$purchId, $pid, $prod['name'], 'bulk', 0, $cost, $lineTotal]);
                    if ($newSell > 0) {
                        $pdo->prepare("UPDATE products SET sell_price=? WHERE id=?")->execute([$newSell, $pid]);
                    }
                    $batchReport[] = "✓ {$prod['name']} — bulk purchase " . fmtUSD($cost) . ($newSell > 0 ? " | Sell price updated to " . fmtUSD($newSell) : "");
                } else {
                    $lineTotal = $qty * $cost;
                    $existing  = findExistingBatch($pdo, $pid, $cost);

                    if ($existing) {
                        $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+?, quantity_original=quantity_original+? WHERE id=?")
                            ->execute([$qty, $qty, $existing['id']]);
                        $batchId     = $existing['id'];
                        $batchAction = 'merged';
                        $batchReport[] = "↗ {$prod['name']} — merged into Batch #{$batchId} (cost " . fmtUSD($cost) . ", now " . ((float)$existing['quantity_remaining'] + $qty) . " units)";
                    } else {
                        $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,?)")
                            ->execute([$pid, $purchId, $cost, $qty, $qty, $date]);
                        $batchId     = $pdo->lastInsertId();
                        $batchAction = 'new';
                        $batchReport[] = "★ {$prod['name']} — NEW Batch #{$batchId} at " . fmtUSD($cost) . " × {$qty}"
                            . ($newSell > 0 ? " | Sell price updated: " . fmtUSD($prod['sell_price']) . " → " . fmtUSD($newSell) : " ⚠ sell price unchanged (" . fmtUSD($prod['sell_price']) . ")");
                    }

                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$purchId, $pid, $prod['name'], 'regular', $qty, $cost, $lineTotal, $batchId, $batchAction]);

                    // Always update cost_price; update sell_price only if user provided one
                    $updateSell = $newSell > 0 ? $newSell : $prod['sell_price'];
                    $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=?, sell_price=? WHERE id=?")
                        ->execute([$qty, $cost, $updateSell, $pid]);
                }
            }

            // Supplier ledger — always record purchase debt
            $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id=?");
            $sn->execute([$supplierId]);
            $supName = (string)$sn->fetchColumn();
            $purchLabel = "Purchase" . ($reference ? " #$reference" : " #$purchId") . ($supName ? " — $supName" : '');

            $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$total, $supplierId]);
            $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'purchase',?,?)")
                ->execute([$supplierId, $purchId, $total, $purchLabel]);

            // If paid now (cash_owner or cash_register): also settle supplier balance in same transaction
            if ($paymentMethod === 'cash_owner' || $paymentMethod === 'cash_register') {
                $prefix = $paymentMethod === 'cash_owner' ? 'Owner cash' : 'Cash';
                $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$total, $supplierId]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'payment',?,?)")
                    ->execute([$supplierId, $purchId, -$total, "$prefix payment — $purchLabel"]);
            }

            $pdo->commit();

            // Post-commit: cash register entry
            if ($paymentMethod === 'cash_owner') {
                logCashEntry($pdo, 'deposit', $total, "Owner cash — $purchLabel");
            } elseif ($paymentMethod === 'cash_register') {
                logCashEntry($pdo, 'withdrawal', -$total, "Cash — $purchLabel");
            }

            $msgSuffix = match($paymentMethod) {
                'cash_owner'    => ' — owner cash deposited to register, supplier settled',
                'cash_register' => ' — cash withdrawn from register, supplier settled',
                'pay_later'     => ' — payment pending (visible in supplier balance)',
                default         => '',
            };
            $message = 'success:Purchase saved — ' . fmtUSD($total) . $msgSuffix;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'error:' . $e->getMessage();
        }
    }
}

$purchases = $pdo->query("
    SELECT pu.*, s.name AS supplier_name,
           (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=pu.id) AS item_count
    FROM purchases pu
    LEFT JOIN suppliers s ON s.id=pu.supplier_id
    ORDER BY pu.purchase_date DESC, pu.created_at DESC
    LIMIT 150
")->fetchAll();

$products  = $pdo->query("SELECT id, name, cost_price, sell_price, product_type, unit, units_per_box FROM products ORDER BY product_type, name")->fetchAll();

// Ensure at least one supplier exists so the dropdown is never empty
$supplierCount = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
if ($supplierCount === 0) {
    $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute(['Default Supplier']);
}
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();

renderHead('Purchases');
renderNav('purchases');
alertBox($message);
?>

<?php if ($batchReport): ?>
<div class="alert alert-info m-3">
    <strong><i class="bi bi-layers me-2"></i>Batch Report</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($batchReport as $br): ?><li><?= htmlspecialchars($br) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="container-fluid py-4">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-truck me-2"></i>Purchases</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#purchaseModal">
        <i class="bi bi-plus-lg"></i> New Purchase
    </button>
</div>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Date</th><th>Reference</th><th>Supplier</th><th>Items</th><th>Total</th><th>Note</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
    <tr>
        <td><?= htmlspecialchars($p['purchase_date']) ?></td>
        <td><?= htmlspecialchars($p['reference'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
        <td><?= $p['item_count'] ?></td>
        <td class="fw-bold"><?= fmtUSD($p['total_amount']) ?></td>
        <td class="small text-muted"><?= htmlspecialchars($p['note'] ?: '—') ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="viewPurchase(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['reference'] ?: '#'.$p['id'])) ?>')" title="View details"><i class="bi bi-eye"></i></button>
            <a href="?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete? Stock will be reversed.')" title="Delete"><i class="bi bi-trash"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$purchases): ?><tr><td colspan="7" class="text-center text-muted py-4">No purchases yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- New Purchase Modal -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<form method="POST">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-truck me-2"></i>New Purchase</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                <select name="supplier_id" class="form-select" required>
                    <option value="">— Select Supplier —</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Reference / Invoice #</label><input type="text" name="reference" class="form-control" placeholder="Optional"></div>
            <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><label class="form-label">Note</label><input type="text" name="note" class="form-control"></div>
        </div>

        <table class="table table-sm table-bordered" id="purch-table">
            <thead class="table-secondary">
                <tr>
                    <th>Product</th>
                    <th style="width:60px">Type</th>
                    <th style="width:110px">Qty</th>
                    <th style="width:130px">Cost Price (USD)<br><small class="text-muted fw-normal">Total for Bulk</small></th>
                    <th style="width:130px">New Sell Price<br><small class="text-muted fw-normal">Leave 0 to keep current</small></th>
                    <th style="width:100px">Line Total</th>
                    <th style="width:140px">Batch Status</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="purch-body"></tbody>
        </table>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addPurchRow()">
            <i class="bi bi-plus"></i> Add Item
        </button>
        <div class="text-end mt-3 fw-bold fs-5">Total: <span id="purch-total">0.00</span> USD</div>
    </div>
    <div class="modal-footer">
        <div class="me-auto">
            <label class="form-label small mb-1 fw-semibold">Payment Method</label>
            <select name="payment_method" class="form-select form-select-sm" style="min-width:260px">
                <option value="cash_register">Deduct from cash register (USD drawer)</option>
                <option value="cash_owner">Cash from owner — deposit to register</option>
                <option value="pay_later">Pay later — show in supplier balance</option>
            </select>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary px-4">Save Purchase</button>
    </div>
</form>
</div></div></div>

<script>
const PRODUCTS_DATA = <?= json_encode(array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'cost'=>$p['cost_price'],'sell'=>$p['sell_price'],'type'=>$p['product_type'],'unit'=>$p['unit'],'upb'=>(int)($p['units_per_box']??1)], $products)) ?>;
let rowIdx = 0;

function addPurchRow() {
    const i = rowIdx++;
    const opts = PRODUCTS_DATA.map(p => `<option value="${p.id}" data-cost="${p.cost}" data-sell="${p.sell}" data-type="${p.type}" data-unit="${p.unit}" data-upb="${p.upb}">${p.name} (${p.type})</option>`).join('');
    const row = `
    <tr id="prow-${i}">
        <td><select name="product_id[]" class="form-select form-select-sm" onchange="onProductChange(this,${i})">${opts}</select></td>
        <td id="ptype-${i}"><span class="badge bg-info text-dark">Reg</span></td>
        <td>
            <input type="number" name="quantity[]" id="pqty-${i}" class="form-control form-control-sm" value="1" min="0" step="0.001" oninput="calcRow(${i})">
            <div id="pbox-wrap-${i}" style="display:none" class="mt-1">
                <div class="input-group input-group-sm">
                    <input type="number" id="pboxes-${i}" class="form-control" placeholder="# boxes" min="1" step="1" oninput="applyBoxes(${i})">
                    <span class="input-group-text px-1" style="font-size:.7rem">📦</span>
                </div>
            </div>
        </td>
        <td>
            <input type="number" name="unit_cost[]" id="pcost-${i}" class="form-control form-control-sm" value="0" min="0" step="0.0001" oninput="calcRow(${i}); checkBatch(${i}); suggestSell(${i})">
            <div id="pboxcost-wrap-${i}" style="display:none" class="mt-1">
                <div class="input-group input-group-sm">
                    <span class="input-group-text px-1" style="font-size:.7rem">$/📦</span>
                    <input type="number" id="pboxcost-${i}" class="form-control" placeholder="Cost/box" min="0" step="0.01" oninput="applyBoxes(${i})">
                </div>
            </div>
        </td>
        <td>
            <input type="number" name="new_sell_price[]" id="psell-${i}" class="form-control form-control-sm" value="0" min="0" step="0.0001" placeholder="0 = no change" oninput="calcRow(${i})">
            <div id="pmargin-${i}" class="text-muted" style="font-size:.65rem"></div>
        </td>
        <td id="pline-${i}" class="fw-bold">0.00</td>
        <td id="pbatch-${i}" class="small text-muted">—</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="document.getElementById('prow-${i}').remove();calcTotal()"><i class="bi bi-trash"></i></button></td>
    </tr>`;
    document.getElementById('purch-body').insertAdjacentHTML('beforeend', row);
    const sel = document.querySelector(`#prow-${i} select`);
    onProductChange(sel, i);
}

function onProductChange(sel, i) {
    const opt    = sel.options[sel.selectedIndex];
    const type   = opt?.dataset?.type || 'regular';
    const cost   = opt?.dataset?.cost || 0;
    const sell   = opt?.dataset?.sell || 0;
    const unit   = opt?.dataset?.unit || 'pcs';
    const upb    = parseInt(opt?.dataset?.upb || 1);
    const isBulk = type === 'bulk';

    document.getElementById('ptype-'+i).innerHTML = isBulk
        ? '<span class="badge bg-warning text-dark">Bulk</span>'
        : upb > 1
        ? `<span class="badge bg-info text-dark">Reg</span> <span class="badge bg-secondary" title="${upb} units/box">📦×${upb}</span>`
        : unit === 'box'
        ? '<span class="badge bg-info text-dark">Reg</span> <span class="badge bg-secondary">📦 box</span>'
        : '<span class="badge bg-info text-dark">Reg</span>';

    const qtyEl      = document.getElementById('pqty-'+i);
    const costEl     = document.getElementById('pcost-'+i);
    const sellEl     = document.getElementById('psell-'+i);
    const boxWrap    = document.getElementById('pbox-wrap-'+i);
    const boxCostWrap= document.getElementById('pboxcost-wrap-'+i);

    if (isBulk) {
        qtyEl.value = '0'; qtyEl.disabled = true;
        costEl.placeholder = 'Total amount paid';
        sellEl.disabled = false;
        boxWrap.style.display = 'none';
        boxCostWrap.style.display = 'none';
    } else {
        qtyEl.disabled = false;
        qtyEl.placeholder = unit;
        costEl.value = cost;
        sellEl.disabled = false;
        if (parseFloat(sell) > 0) sellEl.value = parseFloat(sell).toFixed(4);
        if (upb > 1 || unit === 'box') {
            boxWrap.style.display = '';
            boxCostWrap.style.display = '';
            const label = upb > 1 ? `# boxes (1 box = ${upb} ${unit}s)` : '# boxes';
            document.getElementById('pboxes-'+i).placeholder = label;
        } else {
            boxWrap.style.display = 'none';
            boxCostWrap.style.display = 'none';
        }
    }
    sel.dataset.currentSell = sell;
    calcRow(i);
    if (!isBulk) { checkBatch(i); suggestSell(i); }
}

function applyBoxes(i) {
    const sel  = document.querySelector(`#prow-${i} select`);
    const upb  = parseInt(sel?.selectedOptions[0]?.dataset?.upb || 1);
    const boxes    = parseFloat(document.getElementById('pboxes-'+i)?.value || 0);
    const boxCost  = parseFloat(document.getElementById('pboxcost-'+i)?.value || 0);
    if (boxes > 0 && upb > 0) {
        document.getElementById('pqty-'+i).value = (boxes * upb).toFixed(0);
    }
    if (boxCost > 0 && upb > 0) {
        document.getElementById('pcost-'+i).value = (boxCost / upb).toFixed(4);
    }
    calcRow(i);
    checkBatch(i);
    suggestSell(i);
}

function suggestSell(i) {
    const sel    = document.querySelector(`#prow-${i} select`);
    const isBulk = sel?.selectedOptions[0]?.dataset?.type === 'bulk';
    if (isBulk) return;
    const currentSell = parseFloat(sel?.dataset?.currentSell || 0);
    const newCost     = parseFloat(document.getElementById('pcost-'+i)?.value || 0);
    const el          = document.getElementById('psell-'+i);
    const marginEl    = document.getElementById('pmargin-'+i);
    if (!newCost || !currentSell) { if(marginEl) marginEl.textContent=''; return; }
    const margin = currentSell > 0 ? (currentSell - parseFloat(sel?.selectedOptions[0]?.dataset?.cost || 0)) / currentSell : 0.3;
    const suggested = newCost / (1 - margin);
    if (parseFloat(el.value) === 0) el.value = suggested.toFixed(4);
    if (marginEl) {
        const m = el.value > 0 ? (((parseFloat(el.value) - newCost) / parseFloat(el.value)) * 100).toFixed(1) : '—';
        marginEl.textContent = `Margin: ${m}%`;
    }
}

function calcRow(i) {
    const qty    = parseFloat(document.getElementById('pqty-'+i)?.value || 0);
    const cost   = parseFloat(document.getElementById('pcost-'+i)?.value || 0);
    const isBulk = document.querySelector(`#prow-${i} select`)?.selectedOptions[0]?.dataset?.type === 'bulk';
    const line   = isBulk ? cost : qty * cost;
    document.getElementById('pline-'+i).textContent = line.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="pline-"]').forEach(el => total += parseFloat(el.textContent || 0));
    document.getElementById('purch-total').textContent = total.toFixed(2);
}

function checkBatch(i) {
    const sel  = document.querySelector(`#prow-${i} select`);
    const pid  = sel?.value;
    const cost = document.getElementById('pcost-'+i)?.value;
    const el   = document.getElementById('pbatch-'+i);
    if (!pid || !cost || cost <= 0) { el.innerHTML = '—'; return; }

    fetch(`/dahdouh/pages/api.php?action=check_batch&product_id=${pid}&cost=${cost}`)
        .then(r => r.json())
        .then(d => {
            if (d.found) {
                el.innerHTML = `<span class="text-success">↗ Merge into Batch #${d.batch_id}<br>(${d.qty_remaining} remaining)</span>`;
            } else {
                el.innerHTML = '<span class="text-primary">★ New batch</span>';
            }
        }).catch(() => { el.innerHTML = '—'; });
}

addPurchRow();

function viewPurchase(id, ref) {
    document.getElementById('vp-title').textContent = 'Purchase ' + ref;
    document.getElementById('vp-body').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>';
    fetch('/dahdouh/pages/api.php?action=purchase_items&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { document.getElementById('vp-body').innerHTML = '<tr><td colspan="5" class="text-danger">' + data.error + '</td></tr>'; return; }
            let html = '';
            let total = 0;
            data.forEach(it => {
                const line = parseFloat(it.quantity) * parseFloat(it.unit_cost);
                total += line;
                html += `<tr>
                    <td>${it.product_name}</td>
                    <td>${it.product_type}</td>
                    <td>${parseFloat(it.quantity) || '—'}</td>
                    <td>$${parseFloat(it.unit_cost).toFixed(4)}</td>
                    <td class="fw-bold">$${line.toFixed(2)}</td>
                </tr>`;
            });
            html += `<tr class="table-success fw-bold"><td colspan="4" class="text-end">Total:</td><td>$${total.toFixed(2)}</td></tr>`;
            document.getElementById('vp-body').innerHTML = html;
        })
        .catch(() => { document.getElementById('vp-body').innerHTML = '<tr><td colspan="5" class="text-danger">Failed to load.</td></tr>'; });
    new bootstrap.Modal(document.getElementById('viewPurchModal')).show();
}
</script>

<!-- View Purchase Modal -->
<div class="modal fade" id="viewPurchModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title fw-bold" id="vp-title">Purchase Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover mb-0">
    <thead class="table-dark"><tr><th>Product</th><th>Type</th><th>Qty</th><th>Unit Cost</th><th>Line Total</th></tr></thead>
    <tbody id="vp-body"></tbody>
</table>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

<?php renderFoot(); ?>
