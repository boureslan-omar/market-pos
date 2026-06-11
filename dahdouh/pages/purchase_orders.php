<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message = '';

// ── Generate PO Number ────────────────────────────────────────────────────────
function genPONumber($pdo) {
    $year = date('Y');
    $row  = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(po_number,'-',-1) AS UNSIGNED)) FROM purchase_orders WHERE po_number LIKE 'PO-$year-%'")->fetchColumn();
    $next = ($row ?? 0) + 1;
    return "PO-$year-" . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── Create PO ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    $supplierId   = (int)$_POST['supplier_id'];
    $deliveryDate = $_POST['delivery_date'] ?: null;
    $note         = trim($_POST['note'] ?? '');
    $items        = $_POST['items'] ?? [];

    if ($supplierId && !empty($items)) {
        $poNumber = genPONumber($pdo);
        $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, delivery_date, note, status) VALUES (?,?,?,?,'draft')")
            ->execute([$poNumber, $supplierId, $deliveryDate, $note]);
        $poId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, quantity, unit, estimated_price, note, new_product_upb) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $productName = trim($item['product_name'] ?? '');
            if (!$productName) continue;
            $itemUnit = $item['unit'] ?? 'pcs';
            $hasPid   = !empty($item['product_id']);
            $newUpb   = ($itemUnit === 'box' && !$hasPid) ? max(1, (int)($item['new_product_upb'] ?? 1)) : null;
            $stmt->execute([
                $poId,
                $hasPid ? (int)$item['product_id'] : null,
                $productName,
                (float)($item['quantity'] ?? 1),
                $itemUnit,
                (float)($item['estimated_price'] ?? 0),
                trim($item['note'] ?? ''),
                $newUpb,
            ]);
        }
        $message = "success:Purchase Order $poNumber created.";
    } else {
        $message = "error:Please select a supplier and add at least one item.";
    }
}

// ── Update status ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $poId   = (int)$_POST['po_id'];
    $status = $_POST['status'];
    $allowed = ['draft','sent','confirmed','received','cancelled'];
    if (in_array($status, $allowed)) {
        $pdo->prepare("UPDATE purchase_orders SET status=? WHERE id=?")->execute([$status, $poId]);
        $message = "success:Status updated.";
    }
}

// ── Delete PO ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_po') {
    $poId = (int)$_POST['po_id'];
    $pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$poId]);
    $message = "success:Purchase Order deleted.";
}

// ── Receive PO → auto-create Purchase ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_po') {
    $poId   = (int)$_POST['po_id'];
    $note   = trim($_POST['note'] ?? '');
    $pItems = $_POST['pitems'] ?? [];   // [product_id, product_name, quantity, unit_cost]

    $po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
    $po->execute([$poId]);
    $po = $po->fetch();

    if ($po && !empty($pItems)) {
        $pdo->beginTransaction();
        try {
            // Create purchase record
            $ref = $po['po_number'] . '/RCV';
            $totalCost = 0;
            foreach ($pItems as $pi) { $totalCost += (float)$pi['qty'] * (float)$pi['cost']; }

            $pdo->prepare("INSERT INTO purchases (supplier_id, reference, purchase_date, total_amount, note) VALUES (?,?,CURDATE(),?,?)")
                ->execute([$po['supplier_id'], $ref, $totalCost, $note ?: 'Received from PO ' . $po['po_number']]);
            $purchaseId = (int)$pdo->lastInsertId();

            // Update supplier balance + ledger
            $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$totalCost, $po['supplier_id']]);
            $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'purchase',?,?)")
                ->execute([$po['supplier_id'], $purchaseId, $totalCost, "PO received: " . $po['po_number']]);

            // Process each item (same logic as purchases.php)
            foreach ($pItems as $pi) {
                $pid      = (int)($pi['pid'] ?? 0);
                $pname    = trim($pi['name'] ?? '');
                $qty      = (float)$pi['qty'];
                $cost     = (float)$pi['cost'];
                $itemUnit = trim($pi['unit'] ?? 'pcs');
                $newUpb   = max(0, (int)($pi['new_upb'] ?? 0));
                if (!$pname || $qty <= 0) continue;

                $lineTotal = $qty * $cost;
                $prod = $pid ? $pdo->prepare("SELECT product_type, sell_price FROM products WHERE id=?") : null;
                if ($prod) { $prod->execute([$pid]); $prod = $prod->fetch(); }

                if ($prod && $prod['product_type'] === 'bulk') {
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total) VALUES (?,?,?,'bulk',0,?,?)")
                        ->execute([$purchaseId, $pid, $pname, $cost, $lineTotal]);
                } elseif ($pid && $prod) {
                    // FIFO batch logic for existing products
                    $boxUpb = max(0, (int)($pi['box_upb'] ?? 0));
                    if ($itemUnit === 'box' && $boxUpb > 1) {
                        // Cost entered is per-box; convert everything to per-unit / total units
                        $costPerUnit = $cost / $boxUpb;
                        $totalUnits  = $qty * $boxUpb;
                        $lineTotal   = $qty * $cost; // = totalUnits * costPerUnit
                        $existing = findExistingBatch($pdo, $pid, round($costPerUnit, 4));
                        if ($existing) {
                            $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+?, quantity_original=quantity_original+? WHERE id=?")
                                ->execute([$totalUnits, $totalUnits, $existing['id']]);
                            $batchId = $existing['id']; $batchAction = 'merged';
                        } else {
                            $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,CURDATE())")
                                ->execute([$pid, $purchaseId, round($costPerUnit, 4), $totalUnits, $totalUnits]);
                            $batchId = (int)$pdo->lastInsertId(); $batchAction = 'new';
                        }
                        $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,'regular',?,?,?,?,?)")
                            ->execute([$purchaseId,$pid,$pname,$totalUnits,round($costPerUnit,4),round($lineTotal,2),$batchId,$batchAction]);
                        $newSell = (float)($pi['sell'] ?? 0);
                        if ($newSell > 0) {
                            $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=?, sell_price=? WHERE id=?")->execute([$totalUnits,round($costPerUnit,4),$newSell,$pid]);
                        } else {
                            $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=? WHERE id=?")->execute([$totalUnits,round($costPerUnit,4),$pid]);
                        }
                    } else {
                        $existing = findExistingBatch($pdo, $pid, $cost);
                        if ($existing) {
                            $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+?, quantity_original=quantity_original+? WHERE id=?")
                                ->execute([$qty, $qty, $existing['id']]);
                            $batchId = $existing['id']; $batchAction = 'merged';
                        } else {
                            $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,CURDATE())")
                                ->execute([$pid, $purchaseId, $cost, $qty, $qty]);
                            $batchId = (int)$pdo->lastInsertId(); $batchAction = 'new';
                        }
                        $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,'regular',?,?,?,?,?)")
                            ->execute([$purchaseId,$pid,$pname,$qty,$cost,$lineTotal,$batchId,$batchAction]);
                        $newSell = (float)($pi['sell'] ?? 0);
                        if ($newSell > 0) {
                            $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=?, sell_price=? WHERE id=?")->execute([$qty,$cost,$newSell,$pid]);
                        } else {
                            $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=? WHERE id=?")->execute([$qty,$cost,$pid]);
                        }
                    }
                } elseif ($itemUnit === 'box' && $newUpb > 1) {
                    // New box product — auto-create product, batch, and purchase item
                    $costPerUnit = round($cost / $newUpb, 4); // cost entered is per-box
                    $totalUnits  = $qty * $newUpb;
                    $newSell     = (float)($pi['sell'] ?? 0);

                    $pdo->prepare("INSERT INTO products (name, product_type, unit, units_per_box, cost_price, sell_price, stock) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$pname, 'regular', 'box', $newUpb, $costPerUnit, $newSell, $totalUnits]);
                    $newPid = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,CURDATE())")
                        ->execute([$newPid, $purchaseId, $costPerUnit, $totalUnits, $totalUnits]);
                    $batchId = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,'regular',?,?,?,?,'new')")
                        ->execute([$purchaseId, $newPid, $pname, $totalUnits, $costPerUnit, round($totalUnits * $costPerUnit, 2), $batchId]);
                } else {
                    // No product_id — just record the line (no stock update)
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total) VALUES (?,NULL,?,'regular',?,?,?)")
                        ->execute([$purchaseId,$pname,$qty,$cost,$lineTotal]);
                }
            }

            // Mark PO as received and link to the created purchase
            $pdo->prepare("UPDATE purchase_orders SET status='received', received_purchase_id=? WHERE id=?")->execute([$purchaseId, $poId]);

            // Optional: settle payment now
            $settlementMethod    = trim($_POST['settlement_method'] ?? '');
            $settlementAmount    = (float)($_POST['settlement_amount'] ?? 0);
            $settlementAmountLBP = (float)($_POST['settlement_amount_lbp'] ?? 0);
            $settlementNote      = trim($_POST['settlement_note'] ?? '');
            $payNote = "Settled PO {$po['po_number']}" . ($settlementNote ? " — $settlementNote" : '');

            if ($settlementMethod === 'cash_usd' && $settlementAmount > 0) {
                logCashEntry($pdo, 'withdrawal', -$settlementAmount, $payNote); // null sale_id — this is a purchase, not a sale
                $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$settlementAmount, $po['supplier_id']]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'payment',?,?)")
                    ->execute([$po['supplier_id'], $purchaseId, -$settlementAmount, $payNote]);
            } elseif ($settlementMethod === 'cash_lbp' && $settlementAmountLBP > 0) {
                logCashEntry($pdo, 'withdrawal', 0, $payNote, null, -$settlementAmountLBP, 'LBP');
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'payment',?,?)")
                    ->execute([$po['supplier_id'], $purchaseId, 0, $payNote . ' (LBP ' . number_format($settlementAmountLBP) . ')']);
            } elseif ($settlementMethod === 'bank' && $settlementAmount > 0) {
                $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$settlementAmount, $po['supplier_id']]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'payment',?,?)")
                    ->execute([$po['supplier_id'], $purchaseId, -$settlementAmount, $payNote]);
            }

            $pdo->commit();
            $settledMsg = $settlementMethod ? " Payment recorded." : "";
            $message = "success:PO {$po['po_number']} received — Purchase #{$purchaseId} created and stock updated.{$settledMsg}";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "error:" . $e->getMessage();
        }
    } else {
        $message = "error:No items to receive.";
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$products  = $pdo->query("SELECT id, name, barcode FROM products WHERE product_source='owned' ORDER BY name")->fetchAll();

$statusFilter = $_GET['status'] ?? '';
$sqlWhere = $statusFilter ? "WHERE po.status = " . $pdo->quote($statusFilter) : '';
$orders = $pdo->query("
    SELECT po.*, s.name AS supplier_name, s.phone AS supplier_phone,
           COUNT(poi.id) AS item_count
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
    $sqlWhere
    GROUP BY po.id
    ORDER BY po.created_at DESC
")->fetchAll();

$statusColors = [
    'draft'     => 'secondary',
    'sent'      => 'primary',
    'confirmed' => 'info',
    'received'  => 'success',
    'cancelled' => 'danger',
];

renderHead('Purchase Orders');
renderNav('purchase_orders');
alertBox($message);
?>
<div class="container-fluid py-3">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-clipboard-check me-2"></i>Purchase Orders</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newPOModal">
        <i class="bi bi-plus-lg"></i> New Purchase Order
    </button>
</div>

<!-- Status filter tabs -->
<div class="mb-3">
    <?php
    $tabs = [''=>'All', 'draft'=>'Draft', 'sent'=>'Sent', 'confirmed'=>'Confirmed', 'received'=>'Received', 'cancelled'=>'Cancelled'];
    foreach ($tabs as $val => $label):
        $active = $statusFilter === $val ? 'btn-dark' : 'btn-outline-secondary';
    ?>
    <a href="?status=<?= urlencode($val) ?>" class="btn btn-sm <?= $active ?> me-1 mb-1"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- PO Table -->
<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-dark">
        <tr>
            <th>PO #</th>
            <th>Supplier</th>
            <th>Items</th>
            <th>Delivery</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $po): ?>
    <tr>
        <td class="fw-bold font-monospace"><?= htmlspecialchars($po['po_number']) ?></td>
        <td>
            <div class="fw-semibold"><?= htmlspecialchars($po['supplier_name']) ?></div>
            <?php if ($po['supplier_phone']): ?>
            <div class="small text-muted"><?= htmlspecialchars($po['supplier_phone']) ?></div>
            <?php endif; ?>
        </td>
        <td><span class="badge bg-light text-dark border"><?= $po['item_count'] ?> item(s)</span></td>
        <td><?= $po['delivery_date'] ? date('d/m/Y', strtotime($po['delivery_date'])) : '<span class="text-muted">—</span>' ?></td>
        <td>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="width:130px"
                        onfocus="this.dataset.prev=this.value"
                        onchange="onStatusChange(this,<?= $po['id'] ?>,<?= htmlspecialchars(json_encode($po['po_number'])) ?>,<?= $po['supplier_id'] ?>)">
                    <?php foreach ($statusColors as $s => $c): ?>
                    <option value="<?= $s ?>" <?= $po['status']==$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </td>
        <td class="small text-muted"><?= date('d/m/Y', strtotime($po['created_at'])) ?></td>
        <td>
            <a href="po_view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="View / Print">
                <i class="bi bi-printer"></i>
            </a>
            <?php
            $phone = preg_replace('/[^0-9]/', '', $po['supplier_phone'] ?? '');
            if ($phone):
            ?>
            <button type="button" class="btn btn-sm btn-success ms-1" title="Send PDF via WhatsApp"
                    onclick="sendWhatsApp(<?= $po['id'] ?>, '<?= $phone ?>', <?= htmlspecialchars(json_encode($po['po_number'])) ?>)">
                <i class="bi bi-whatsapp"></i>
            </button>
            <?php endif; ?>
            <?php if ($po['status'] !== 'received' && $po['status'] !== 'cancelled'): ?>
            <button class="btn btn-sm btn-warning ms-1" title="Receive — create purchase &amp; update stock"
                    onclick="openReceive(<?= $po['id'] ?>, <?= htmlspecialchars(json_encode($po['po_number'])) ?>, <?= $po['supplier_id'] ?>)">
                <i class="bi bi-box-arrow-in-down"></i> Receive
            </button>
            <?php elseif ($po['received_purchase_id']): ?>
            <a href="purchases.php" class="btn btn-sm btn-outline-success ms-1" title="View linked purchase">
                <i class="bi bi-link-45deg"></i>
            </a>
            <?php endif; ?>
            <form method="POST" class="d-inline ms-1">
                <input type="hidden" name="action" value="delete_po">
                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this PO?')" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No purchase orders yet. Create one to get started.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

</div>

<!-- ── New PO Modal ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="newPOModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<form method="POST" id="poForm">
<input type="hidden" name="action" value="create_po">
<div class="modal-header">
    <h5 class="modal-title fw-bold"><i class="bi bi-clipboard-plus me-2"></i>New Purchase Order</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
            <select name="supplier_id" class="form-select" required id="poSupplier">
                <option value="">— Select Supplier —</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Expected Delivery</label>
            <input type="date" name="delivery_date" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-5">
            <label class="form-label fw-semibold">Note / Remarks</label>
            <input type="text" name="note" class="form-control" placeholder="Optional note for supplier">
        </div>
    </div>

    <hr>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0">Order Items</h6>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="addPORow()">
            <i class="bi bi-plus-lg"></i> Add Item
        </button>
    </div>

    <div class="table-responsive">
    <table class="table table-sm" id="poItemsTable">
        <thead class="table-light">
            <tr>
                <th style="width:35%">Product</th>
                <th style="width:12%">Qty</th>
                <th style="width:12%">Unit</th>
                <th style="width:15%">Est. Price</th>
                <th style="width:20%">Note</th>
                <th style="width:6%"></th>
            </tr>
        </thead>
        <tbody id="poRows">
            <!-- rows added by JS -->
        </tbody>
    </table>
    </div>
    <div id="noRowsMsg" class="text-center text-muted py-2">Add items above.</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save"></i> Create Purchase Order</button>
</div>
</form>
</div>
</div>
</div>

<?php renderFoot(); ?>
<script>
const poProducts = <?= json_encode($products) ?>;
let rowIdx = 0;

function addPORow(name = '', qty = 1, unit = 'pcs', price = '', note = '', productId = '') {
    document.getElementById('noRowsMsg').style.display = 'none';
    const tbody = document.getElementById('poRows');
    const i = rowIdx++;
    const productOptions = poProducts.map(p =>
        `<option value="${p.id}" data-name="${p.name.replace(/"/g,'&quot;')}" ${productId==p.id?'selected':''}>${p.name}${p.barcode?' ('+p.barcode+')':''}</option>`
    ).join('');

    const tr = document.createElement('tr');
    tr.id = 'row' + i;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="items[${i}][product_id]" id="pid${i}" value="${productId}">
            <div class="input-group input-group-sm">
                <input type="text" class="form-control form-control-sm" id="pname${i}"
                       name="items[${i}][product_name]" placeholder="Type product name" value="${name}"
                       list="pdlist${i}" autocomplete="off" required>
                <datalist id="pdlist${i}">
                    ${poProducts.map(p=>`<option value="${p.name}" data-id="${p.id}">`).join('')}
                </datalist>
            </div>
            <!-- shown only when unit=box and product is new (not in DB) -->
            <div id="box-opts-${i}" style="display:none" class="mt-1 p-1 rounded" style="background:#fff8e1">
                <div class="input-group input-group-sm">
                    <span class="input-group-text small" style="font-size:11px">📦 Units/Box</span>
                    <input type="number" name="items[${i}][new_product_upb]" id="upb${i}"
                           class="form-control form-control-sm" value="1" min="1" step="1" placeholder="e.g. 12">
                </div>
                <div class="text-primary mt-1" style="font-size:10px">New product will be auto-created when received</div>
            </div>
        </td>
        <td><input type="number" name="items[${i}][quantity]" class="form-control form-control-sm" value="${qty}" min="0.001" step="0.001" required placeholder="# boxes"></td>
        <td>
            <select name="items[${i}][unit]" id="unit${i}" class="form-select form-select-sm" onchange="toggleBoxOpts(${i})">
                ${['pcs','kg','box','crate','doz','L','pack'].map(u=>`<option ${u===unit?'selected':''}>${u}</option>`).join('')}
            </select>
        </td>
        <td><div class="input-group input-group-sm"><span class="input-group-text">$</span>
            <input type="number" name="items[${i}][estimated_price]" class="form-control form-control-sm" value="${price}" min="0" step="0.01" placeholder="per box">
        </div></td>
        <td><input type="text" name="items[${i}][note]" class="form-control form-control-sm" value="${note}" placeholder="Optional"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow('row${i}')"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(tr);

    // Wire up datalist selection to fill hidden product_id + re-check box opts
    document.getElementById('pname' + i).addEventListener('input', function() {
        const match = poProducts.find(p => p.name === this.value);
        document.getElementById('pid' + i).value = match ? match.id : '';
        toggleBoxOpts(i);
    });

    // Init box opts visibility on load
    toggleBoxOpts(i);
}

function toggleBoxOpts(i) {
    const unit = document.getElementById('unit' + i)?.value;
    const pid  = document.getElementById('pid' + i)?.value;
    const box  = document.getElementById('box-opts-' + i);
    if (box) box.style.display = (unit === 'box' && !pid) ? '' : 'none';
}

function removeRow(id) {
    document.getElementById(id)?.remove();
    if (!document.getElementById('poRows').children.length)
        document.getElementById('noRowsMsg').style.display = '';
}

// Start with 3 empty rows
document.addEventListener('DOMContentLoaded', () => { addPORow(); addPORow(); addPORow(); });

// ── Status dropdown intercept ────────────────────────────────────────────────
function onStatusChange(sel, poId, poNumber, supplierId) {
    if (sel.value === 'received') {
        sel.value = sel.dataset.prev || sel.options[0].value;
        openReceive(poId, poNumber, supplierId);
    } else {
        sel.closest('form').submit();
    }
}

// ── WhatsApp PDF ─────────────────────────────────────────────────────────────
const storeName = <?= json_encode(STORE_NAME) ?>;
function sendWhatsApp(poId, phone, poNumber) {
    window.open('/dahdouh/pages/po_view.php?id=' + poId + '&autoprint=1', '_blank');
    setTimeout(function() {
        var msg = encodeURIComponent(
            'Please find attached Purchase Order ' + poNumber + ' from ' + storeName + '.\nKindly confirm receipt.'
        );
        window.open('https://wa.me/' + phone + '?text=' + msg, '_blank');
    }, 1500);
}

// ── Receive PO ──────────────────────────────────────────────────────────────
function updateRecvTotal() {
    let grand = 0;
    document.querySelectorAll('#recvRows tr').forEach(tr => {
        const qty  = parseFloat(tr.querySelector('.recv-qty')?.value  || 0);
        const cost = parseFloat(tr.querySelector('.recv-cost')?.value || 0);
        const line = qty * cost;
        grand += line;
        const lineEl = tr.querySelector('.recv-line');
        if (lineEl) lineEl.textContent = '$' + line.toFixed(2);
    });
    const el = document.getElementById('recv-grand-total');
    if (el) el.textContent = '$' + grand.toFixed(2);
    // Keep settlement amounts in sync unless user manually changed them
    const method = document.getElementById('settlementMethod')?.value;
    if (method === 'cash_usd' || method === 'bank') {
        const sa = document.getElementById('settlementAmount');
        if (sa && sa.dataset.manual !== '1') sa.value = grand.toFixed(2);
    } else if (method === 'cash_lbp') {
        const la = document.getElementById('settlementAmountLBP');
        if (la && la.dataset.manual !== '1') la.value = Math.round(grand * PO_EXCHANGE_RATE);
    }
}

const PO_EXCHANGE_RATE = <?= EXCHANGE_RATE ?>;

function onSettlementChange() {
    const method  = document.getElementById('settlementMethod').value;
    const usdBox  = document.getElementById('sett-usd-box');
    const lbpBox  = document.getElementById('sett-lbp-box');
    const noteBox = document.getElementById('sett-note-box');
    usdBox.style.display  = (method === 'cash_usd' || method === 'bank') ? '' : 'none';
    lbpBox.style.display  = (method === 'cash_lbp') ? '' : 'none';
    noteBox.style.display = method ? '' : 'none';

    const grand = parseFloat(document.getElementById('recv-grand-total').textContent.replace('$','')) || 0;
    const poLabel = document.getElementById('recvPoLabel')?.textContent || '';

    if (method === 'cash_usd' || method === 'bank') {
        const sa = document.getElementById('settlementAmount');
        if (sa && sa.dataset.manual !== '1') sa.value = grand.toFixed(2);
    } else if (method === 'cash_lbp') {
        const la = document.getElementById('settlementAmountLBP');
        if (la && la.dataset.manual !== '1') la.value = Math.round(grand * PO_EXCHANGE_RATE);
    }
    const sn = document.getElementById('sett-note-input');
    if (sn && !sn.dataset.manual && poLabel) sn.value = 'Settled PO ' + poLabel;
}

function updateUnitCost(i, upb) {
    const cost = parseFloat(document.getElementById('rcost'+i)?.value || 0);
    const el = document.getElementById('ucost'+i);
    if (el) el.textContent = '≈ $' + (cost / upb).toFixed(4) + '/unit';
}

function openReceive(poId, poNumber, supplierId) {
    fetch('/dahdouh/pages/api.php?action=po_items&po_id=' + poId)
        .then(r => r.json())
        .then(items => {
            document.getElementById('recvPoId').value          = poId;
            document.getElementById('recvPoLabel').textContent = poNumber;
            const tbody = document.getElementById('recvRows');
            tbody.innerHTML = '';
            items.forEach((it, i) => {
                const isNewBox      = !it.product_id && it.unit === 'box' && parseInt(it.new_product_upb) > 1;
                const isExistingBox = !!it.product_id && it.unit === 'box' && parseInt(it.units_per_box) > 1;
                const isAnyBox      = isNewBox || isExistingBox;
                const upb = isNewBox ? parseInt(it.new_product_upb) : (isExistingBox ? parseInt(it.units_per_box) : 1);
                // Fallback for existing box: per-unit cost × upb = per-box cost (avoids showing $0.50 for a $6 box)
                const fallbackCost = isExistingBox
                    ? parseFloat(it.current_cost || 0) * upb
                    : parseFloat(it.current_cost || 0);
                const defaultCost = parseFloat(it.estimated_price) > 0
                    ? parseFloat(it.estimated_price)
                    : fallbackCost;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <span class="fw-semibold">${it.product_name}</span>
                        ${isNewBox      ? `<div class="text-primary small mt-1">📦 New product · ${upb} units/box — will be auto-created</div>` : ''}
                        ${isExistingBox ? `<div class="text-info small mt-1">📦 ${upb} units/box · price per box</div>` : ''}
                        <input type="hidden" name="pitems[${i}][pid]"     value="${it.product_id || ''}">
                        <input type="hidden" name="pitems[${i}][name]"    value="${it.product_name.replace(/"/g,'&quot;')}">
                        <input type="hidden" name="pitems[${i}][unit]"    value="${it.unit || 'pcs'}">
                        <input type="hidden" name="pitems[${i}][new_upb]" value="${isNewBox ? upb : 0}">
                        <input type="hidden" name="pitems[${i}][box_upb]" value="${isExistingBox ? upb : 0}">
                    </td>
                    <td class="text-muted small">${it.unit || it.product_unit || ''}</td>
                    <td class="text-muted">${parseFloat(it.quantity)}</td>
                    <td>
                        <input type="number" name="pitems[${i}][qty]" class="form-control form-control-sm recv-qty"
                               value="${parseFloat(it.quantity)}" min="0" step="0.001" oninput="updateRecvTotal()">
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="number" name="pitems[${i}][cost]" id="rcost${i}" class="form-control form-control-sm recv-cost"
                                   value="${defaultCost.toFixed(4)}" min="0" step="0.0001"
                                   oninput="updateRecvTotal()${isAnyBox ? `;updateUnitCost(${i},${upb})` : ''}">
                        </div>
                        ${isAnyBox ? `<div class="text-muted mt-1" style="font-size:10px" id="ucost${i}">≈ $${(defaultCost/upb).toFixed(4)}/unit</div>` : ''}
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="number" name="pitems[${i}][sell]" class="form-control form-control-sm"
                                   value="${it.sell_price ? parseFloat(it.sell_price).toFixed(4) : ''}"
                                   min="0" step="0.0001" placeholder="${isAnyBox ? 'per unit' : 'unchanged'}">
                        </div>
                    </td>
                    <td class="recv-line fw-semibold text-success">$0.00</td>
                `;
                tbody.appendChild(tr);
            });
            updateRecvTotal();
            new bootstrap.Modal(document.getElementById('receiveModal')).show();
        })
        .catch(() => alert('Could not load PO items. Make sure you are logged in.'));
}
</script>

<!-- ── Receive PO Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="receiveModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<form method="POST">
<input type="hidden" name="action" value="receive_po">
<input type="hidden" name="po_id" id="recvPoId">
<div class="modal-header" style="background:#856404;color:#fff">
    <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-in-down me-2"></i>Receive PO — <span id="recvPoLabel"></span></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <p class="text-muted small mb-2">Adjust quantities and costs to match what was actually delivered. Sell Price is optional — leave blank to keep the current price.</p>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Product</th>
                <th style="width:55px">Unit</th>
                <th style="width:110px">Ordered</th>
                <th style="width:115px">Received Qty</th>
                <th style="width:140px">Cost / Unit ($)</th>
                <th style="width:140px">Sell Price / Unit ($)</th>
                <th style="width:100px">Line Total</th>
            </tr>
        </thead>
        <tbody id="recvRows"></tbody>
        <tfoot>
            <tr class="table-success fw-bold">
                <td colspan="6" class="text-end">Grand Total:</td>
                <td id="recv-grand-total">$0.00</td>
            </tr>
        </tfoot>
    </table>
    </div>
    <div class="mt-2">
        <label class="form-label small fw-semibold">Delivery Note</label>
        <input type="text" name="note" class="form-control form-control-sm" placeholder="Optional note for this delivery">
    </div>

    <hr class="my-3">
    <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6">
        <div class="fw-semibold mb-2"><i class="bi bi-cash-coin me-1"></i>Payment Settlement</div>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">How are you paying the supplier?</label>
                <select name="settlement_method" id="settlementMethod" class="form-select form-select-sm" onchange="onSettlementChange()">
                    <option value="">— Record receipt only (pay later) —</option>
                    <option value="cash_usd">Cash Register — USD drawer</option>
                    <option value="cash_lbp">Cash Register — LBP drawer</option>
                    <option value="bank">Bank Transfer / Cheque / Other</option>
                </select>
            </div>
            <div class="col-md-3" id="sett-usd-box" style="display:none">
                <label class="form-label small">Amount (USD)</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" name="settlement_amount" id="settlementAmount"
                           class="form-control" min="0" step="0.01" placeholder="0.00"
                           oninput="this.dataset.manual='1'">
                </div>
            </div>
            <div class="col-md-3" id="sett-lbp-box" style="display:none">
                <label class="form-label small">Amount (LBP)</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">ل.ل</span>
                    <input type="number" name="settlement_amount_lbp" id="settlementAmountLBP"
                           class="form-control" min="0" step="1" placeholder="0" oninput="this.dataset.manual='1'">
                </div>
            </div>
            <div class="col-md-5" id="sett-note-box" style="display:none">
                <label class="form-label small">Payment note (optional)</label>
                <input type="text" name="settlement_note" id="sett-note-input" class="form-control form-control-sm"
                       placeholder="e.g. Invoice #123, cheque #456" oninput="this.dataset.manual='1'">
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn fw-bold" style="background:#856404;color:#fff">
        <i class="bi bi-check2-all me-1"></i>Commit Receipt &amp; Update Stock
    </button>
</div>
</form>
</div>
</div>
</div>
