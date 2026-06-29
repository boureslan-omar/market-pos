<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message     = '';
$batchReport = [];

// ─── Delete purchase (reverse stock, supplier balance, and cash register) ─────
if (isset($_GET['delete'])) {
    $pid = (int)$_GET['delete'];

    $purRow = $pdo->prepare("SELECT supplier_id, payment_method FROM purchases WHERE id=?");
    $purRow->execute([$pid]);
    $purRow = $purRow->fetch();
    $purPayMethod = $purRow['payment_method'] ?? 'pay_later';

    // Use pi.product_type — records how the item was purchased, not current product type
    $items = $pdo->prepare("SELECT pi.* FROM purchase_items pi WHERE pi.purchase_id=?");
    $items->execute([$pid]);
    foreach ($items->fetchAll() as $it) {
        $isStocked = in_array($it['product_type'], ['regular', 'consignment']);
        if ($isStocked && $it['batch_id']) {
            $pdo->prepare("UPDATE batches SET quantity_remaining = GREATEST(0, quantity_remaining - ?), quantity_original = GREATEST(0, quantity_original - ?) WHERE id=?")
                ->execute([$it['quantity'], $it['quantity'], $it['batch_id']]);
        }
        if ($isStocked) {
            $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$it['quantity'], $it['product_id']]);
        }
    }

    if ($purRow && $purRow['supplier_id']) {
        // Only reverse non-consignment rows (consignment was never charged to supplier)
        $dueStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_items WHERE purchase_id=? AND product_type != 'consignment'");
        $dueStmt->execute([$pid]);
        $dueAmount = (float)$dueStmt->fetchColumn();
        if ($dueAmount > 0) {
            if ($purPayMethod === 'pay_later') {
                // Was pending — remove from supplier balance
                $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$dueAmount, $purRow['supplier_id']]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
                    ->execute([$purRow['supplier_id'], 'adjustment', -$dueAmount, "Purchase #$pid deleted (was pending)"]);
            } else {
                // Was immediately paid — supplier balance is already 0; log note and reverse cash
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,?,?,?)")
                    ->execute([$purRow['supplier_id'], 'adjustment', 0, "Purchase #$pid deleted (was paid on receipt)"]);
                if ($purPayMethod === 'cash_register') {
                    logCashEntry($pdo, 'deposit', $dueAmount, "Reversal — Purchase #$pid deleted");
                } elseif ($purPayMethod === 'cash_owner') {
                    logCashEntry($pdo, 'withdrawal', -$dueAmount, "Reversal — Purchase #$pid deleted (owner cash returned)");
                } elseif ($purPayMethod === 'cash_register_lbp') {
                    logCashEntry($pdo, 'deposit', 0, "Reversal — Purchase #$pid deleted (LBP returned)", null, round($dueAmount * EXCHANGE_RATE), 'LBP');
                }
            }
        }
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

    if (!$supplierId) {
        $defId = $pdo->query("SELECT id FROM suppliers WHERE name='Default Supplier' LIMIT 1")->fetchColumn();
        if (!$defId) {
            $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute(['Default Supplier']);
            $defId = (int)$pdo->lastInsertId();
        }
        $supplierId = (int)$defId;
    }
    $pids          = $_POST['product_id']          ?? [];
    $qtys          = $_POST['quantity']            ?? [];
    $costs         = $_POST['unit_cost']           ?? [];
    $sellPrices    = $_POST['new_sell_price']      ?? [];
    $sellBoxPrices = $_POST['new_sell_price_box']  ?? [];
    $itemTypes     = $_POST['item_type']           ?? [];  // 'regular' | 'consignment' | 'bulk' per row

    $items = [];
    for ($i = 0; $i < count($pids); $i++) {
        $pid      = (int)$pids[$i];
        $qty      = (float)($qtys[$i] ?? 0);
        $cost     = (float)($costs[$i] ?? 0);
        $sell     = (float)($sellPrices[$i] ?? 0);
        $sellBox  = (float)($sellBoxPrices[$i] ?? 0);
        $itype    = in_array($itemTypes[$i] ?? '', ['regular','consignment','bulk']) ? $itemTypes[$i] : 'regular';
        if ($pid > 0 && $cost > 0) $items[] = [$pid, $qty, $cost, $sell, $itype, $sellBox];
    }

    if (empty($items)) {
        $message = 'error:Add at least one item with a valid cost.';
    } else {
        $totalDue         = 0;
        $totalConsignment = 0;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO purchases (supplier_id, reference, total_amount, payment_method, note, purchase_date) VALUES (?,?,0,?,?,?)")
                ->execute([$supplierId, $reference, $paymentMethod, $note, $date]);
            $purchId = $pdo->lastInsertId();

            foreach ($items as [$pid, $qty, $cost, $newSell, $itype, $newSellBox]) {
                $prod = $pdo->prepare("SELECT product_type, name, sell_price, cost_price FROM products WHERE id=?");
                $prod->execute([$pid]);
                $prod = $prod->fetch();

                if ($itype === 'bulk') {
                    $lineTotal   = $cost;
                    $totalDue   += $lineTotal;
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$purchId, $pid, $prod['name'], 'bulk', 0, $cost, $lineTotal]);
                    if ($newSell > 0 || $newSellBox > 0) {
                        $upd = []; $updP = [];
                        if ($newSell    > 0) { $upd[] = 'sell_price=?';     $updP[] = $newSell; }
                        if ($newSellBox > 0) { $upd[] = 'sell_price_box=?'; $updP[] = $newSellBox; }
                        $updP[] = $pid;
                        $pdo->prepare("UPDATE products SET " . implode(',', $upd) . " WHERE id=?")->execute($updP);
                    }
                    $batchReport[] = "✓ {$prod['name']} — bulk purchase " . fmtUSD($cost) . ($newSell > 0 ? " | Sell → " . fmtUSD($newSell) : "") . ($newSellBox > 0 ? " | Box sell → " . fmtUSD($newSellBox) : "");

                } elseif ($itype === 'consignment') {
                    $lineTotal         = $qty * $cost;
                    $totalConsignment += $lineTotal;
                    $existing = findExistingBatch($pdo, $pid, $cost);
                    if ($existing) {
                        $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+?, quantity_original=quantity_original+? WHERE id=?")
                            ->execute([$qty, $qty, $existing['id']]);
                        $batchId     = $existing['id'];
                        $batchAction = 'merged';
                        $batchReport[] = "↗ {$prod['name']} [consignment] — merged Batch #{$batchId} +" . $qty . " units @ " . fmtUSD($cost) . " — due on sale";
                    } else {
                        $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,?)")
                            ->execute([$pid, $purchId, $cost, $qty, $qty, $date]);
                        $batchId     = $pdo->lastInsertId();
                        $batchAction = 'new';
                        $batchReport[] = "★ {$prod['name']} [consignment] — NEW Batch #{$batchId} @ " . fmtUSD($cost) . " × {$qty} — due on sale";
                    }
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$purchId, $pid, $prod['name'], 'consignment', $qty, $cost, $lineTotal, $batchId, $batchAction]);
                    $updateSell = $newSell > 0 ? $newSell : $prod['sell_price'];
                    // Mark product as consignment — POS will use consignment_cost when selling
                    if ($newSellBox > 0) {
                        $pdo->prepare("UPDATE products SET stock=stock+?, consignment_cost=?, consignment_supplier_id=?, product_source='consignment', sell_price=?, sell_price_box=? WHERE id=?")
                            ->execute([$qty, $cost, $supplierId, $updateSell, $newSellBox, $pid]);
                    } else {
                        $pdo->prepare("UPDATE products SET stock=stock+?, consignment_cost=?, consignment_supplier_id=?, product_source='consignment', sell_price=? WHERE id=?")
                            ->execute([$qty, $cost, $supplierId, $updateSell, $pid]);
                    }

                } else {
                    // regular
                    $lineTotal  = $qty * $cost;
                    $totalDue  += $lineTotal;
                    $existing   = findExistingBatch($pdo, $pid, $cost);
                    if ($existing) {
                        $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+?, quantity_original=quantity_original+? WHERE id=?")
                            ->execute([$qty, $qty, $existing['id']]);
                        $batchId     = $existing['id'];
                        $batchAction = 'merged';
                        $batchReport[] = "↗ {$prod['name']} — merged Batch #{$batchId} (cost " . fmtUSD($cost) . ", now " . ((float)$existing['quantity_remaining'] + $qty) . " units)";
                    } else {
                        $pdo->prepare("INSERT INTO batches (product_id,purchase_id,cost_price,quantity_original,quantity_remaining,purchase_date) VALUES (?,?,?,?,?,?)")
                            ->execute([$pid, $purchId, $cost, $qty, $qty, $date]);
                        $batchId     = $pdo->lastInsertId();
                        $batchAction = 'new';
                        $batchReport[] = "★ {$prod['name']} — NEW Batch #{$batchId} @ " . fmtUSD($cost) . " × {$qty}"
                            . ($newSell > 0 ? " | Sell → " . fmtUSD($newSell) : " ⚠ sell unchanged (" . fmtUSD($prod['sell_price']) . ")");
                    }
                    $pdo->prepare("INSERT INTO purchase_items (purchase_id,product_id,product_name,product_type,quantity,unit_cost,total,batch_id,batch_action) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$purchId, $pid, $prod['name'], 'regular', $qty, $cost, $lineTotal, $batchId, $batchAction]);
                    $updateSell = $newSell > 0 ? $newSell : $prod['sell_price'];
                    if ($newSellBox > 0) {
                        $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=?, sell_price=?, sell_price_box=?, product_source='owned' WHERE id=?")
                            ->execute([$qty, $cost, $updateSell, $newSellBox, $pid]);
                    } else {
                        $pdo->prepare("UPDATE products SET stock=stock+?, cost_price=?, sell_price=?, product_source='owned' WHERE id=?")
                            ->execute([$qty, $cost, $updateSell, $pid]);
                    }
                }
            }

            // Store full received value in purchases
            $pdo->prepare("UPDATE purchases SET total_amount=? WHERE id=?")
                ->execute([$totalDue + $totalConsignment, $purchId]);

            $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id=?");
            $sn->execute([$supplierId]);
            $supName    = (string)$sn->fetchColumn();
            $purchLabel = "Purchase" . ($reference ? " #$reference" : " #$purchId") . ($supName ? " — $supName" : '');

            // Only credit supplier balance for immediately-due (non-consignment) amount
            if ($totalDue > 0) {
                $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$totalDue, $supplierId]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'purchase',?,?)")
                    ->execute([$supplierId, $purchId, $totalDue, $purchLabel]);

                $paidNow = in_array($paymentMethod, ['cash_owner','cash_register','cash_register_lbp']);
                if ($paidNow) {
                    $prefix = $paymentMethod === 'cash_owner' ? 'Owner cash' : 'Cash';
                    $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$totalDue, $supplierId]);
                    $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, purchase_id, type, amount, note) VALUES (?,?,'payment',?,?)")
                        ->execute([$supplierId, $purchId, -$totalDue, "$prefix payment — $purchLabel"]);
                }
            }

            $pdo->commit();

            if ($totalDue > 0) {
                $lbpEquiv = round($totalDue * EXCHANGE_RATE);
                if ($paymentMethod === 'cash_owner') {
                    logCashEntry($pdo, 'deposit', $totalDue, "Owner cash — $purchLabel");
                } elseif ($paymentMethod === 'cash_register') {
                    logCashEntry($pdo, 'withdrawal', -$totalDue, "Cash USD — $purchLabel");
                } elseif ($paymentMethod === 'cash_register_lbp') {
                    logCashEntry($pdo, 'withdrawal', 0, "Cash LBP — $purchLabel", null, -$lbpEquiv, 'LBP');
                }
            }

            $msgParts = [];
            if ($totalDue > 0) {
                $suffix = match($paymentMethod) {
                    'cash_owner'         => ' (owner cash deposited, supplier settled)',
                    'cash_register'      => ' (USD cash withdrawn, supplier settled)',
                    'cash_register_lbp'  => ' (LBP cash withdrawn ≈ LL ' . number_format(round($totalDue * EXCHANGE_RATE)) . ', supplier settled)',
                    'pay_later'          => ' (pending in supplier balance)',
                    default              => '',
                };
                $msgParts[] = 'Due ' . fmtUSD($totalDue) . $suffix;
            }
            if ($totalConsignment > 0) {
                $msgParts[] = 'Consignment ' . fmtUSD($totalConsignment) . ' — paid when sold';
            }
            $message = 'success:Purchase saved — ' . implode(' | ', $msgParts);

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

$supplierCount = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
if ($supplierCount === 0) {
    $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute(['Default Supplier']);
}
$suppliers  = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

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

<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <div class="input-group" style="max-width:280px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" id="purch-search" class="form-control" placeholder="Search supplier…" oninput="filterPurchases()">
    </div>
    <div class="input-group" style="max-width:340px">
        <span class="input-group-text small">From</span>
        <input type="date" id="purch-date-from" class="form-control" onchange="filterPurchases()">
        <span class="input-group-text small">To</span>
        <input type="date" id="purch-date-to" class="form-control" onchange="filterPurchases()">
    </div>
    <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('purch-search').value='';document.getElementById('purch-date-from').value='';document.getElementById('purch-date-to').value='';filterPurchases()">✕ Clear</button>
</div>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th>Date</th><th>Reference</th><th>Supplier</th><th>Items</th><th>Total</th><th>Note</th><th>Actions</th>
    </tr></thead>
    <tbody id="purch-list-body">
    <?php foreach ($purchases as $p): ?>
    <tr data-supplier="<?= strtolower(htmlspecialchars($p['supplier_name'] ?? '')) ?>" data-date="<?= $p['purchase_date'] ?>">
        <td><?= htmlspecialchars($p['purchase_date']) ?></td>
        <td><?= htmlspecialchars($p['reference'] ?: '—') ?></td>
        <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
        <td><?= $p['item_count'] ?></td>
        <td class="fw-bold"><?= fmtUSD($p['total_amount']) ?></td>
        <td class="small text-muted"><?= htmlspecialchars($p['note'] ?: '—') ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="viewPurchase(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['reference'] ?: '#'.$p['id'])) ?>')" title="View details"><i class="bi bi-eye"></i></button>
            <button class="btn btn-sm btn-outline-warning me-1" onclick="editPurchase(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['reference'] ?: '#'.$p['id'])) ?>')" title="Edit purchase"><i class="bi bi-pencil"></i></button>
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

<!-- ── New Purchase Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<form method="POST" id="purch-form" onsubmit="applyPurchDrawer()">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-truck me-2"></i>New Purchase</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                <div class="input-group">
                    <select name="supplier_id" id="purch-supplier-sel" class="form-select" required>
                        <option value="">— Select Supplier —</option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-success" onclick="openNewSupplierModal()" title="Add new supplier"><i class="bi bi-plus-lg"></i></button>
                </div>
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
                    <th style="width:160px">Cost Price<br><small class="text-muted fw-normal">Total for Bulk</small></th>
                    <th style="width:160px">New Sell Price<br><small class="text-muted fw-normal">0 = keep current</small></th>
                    <th style="width:90px">Line Total</th>
                    <th style="width:130px">Batch Status</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="purch-body"></tbody>
        </table>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addPurchRow()">
            <i class="bi bi-plus"></i> Add Item
        </button>
        <div class="text-end mt-3">
            <div id="cons-total-wrap" style="display:none" class="small text-muted mb-1">
                Consignment (due on sale): <strong class="text-warning" id="purch-cons">0.00</strong> USD
            </div>
            <div class="fw-bold fs-5">
                Due now: <span id="purch-due">0.00</span>
                <span class="text-muted fs-6 ms-2">| Received total: <span id="purch-total">0.00</span></span>
                <span class="text-muted fs-6">USD</span>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <div class="me-auto">
            <label class="form-label small mb-1 fw-semibold">Payment Method</label>
            <select name="payment_method" id="purch-pay-sel" class="form-select form-select-sm" style="min-width:260px" onchange="togglePurchDrawer()">
                <option value="cash_register">Deduct from cash register</option>
                <option value="cash_owner">Cash from owner — deposit to register</option>
                <option value="pay_later">Pay later — show in supplier balance</option>
            </select>
            <div id="purch-drawer-cur" class="btn-group btn-group-sm mt-1">
                <button type="button" class="btn btn-success active" id="pdcur-usd" onclick="setPurchDrawer('usd')">$ USD</button>
                <button type="button" class="btn btn-outline-warning" id="pdcur-lbp" onclick="setPurchDrawer('lbp')">LL LBP</button>
            </div>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary px-4">Save Purchase</button>
    </div>
</form>
</div></div></div>

<!-- ── View Purchase Details Modal ───────────────────────────────────────────── -->
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

<!-- ── Edit Purchase Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="editPurchModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
    <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Purchase <span id="ep-title"></span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-0">
    <div id="ep-body" class="p-3">
        <div class="text-center py-4"><div class="spinner-border" role="status"></div></div>
    </div>
</div>
<div class="modal-footer">
    <span class="me-auto text-muted small">Stock &amp; batch quantities adjust automatically. Costs apply immediately.</span>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-warning px-4" onclick="submitPurchaseEdit()"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
</div>
</div></div></div>

<!-- ── Quick Add New Product Modal ───────────────────────────────────────────── -->
<div class="modal fade" id="quickAddModal" tabindex="-1" style="z-index:1060">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
    <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add New Product</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <!-- Product Source -->
        <div class="mb-3">
            <label class="form-label small fw-bold">Product Source</label>
            <div class="d-flex gap-4">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="qp_source" id="qp_src_owned" value="owned" checked onchange="qpToggleSource()">
                    <label class="form-check-label small" for="qp_src_owned"><strong>Owned</strong> — purchased by the market</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="qp_source" id="qp_src_cons" value="consignment" onchange="qpToggleSource()">
                    <label class="form-check-label small" for="qp_src_cons"><strong>Consignment</strong> — sold on behalf of supplier</label>
                </div>
            </div>
        </div>
        <!-- Consignment fields -->
        <div id="qp_cons_fields" class="alert alert-info py-2 px-3 mb-3 d-none">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small fw-bold mb-1">Consignment Supplier *</label>
                    <select id="qp_cons_sup" class="form-select form-select-sm">
                        <option value="">— Select Supplier —</option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold mb-1">Supplier Cost / Unit *</label>
                    <div class="input-group input-group-sm">
                        <input type="number" id="qp_cons_cost" class="form-control" step="0.0001" min="0" placeholder="Cost per unit" data-cur="usd" oninput="qpUpdateHint('qp_cons_cost')">
                        <button type="button" id="qp_cons_cost_usd" class="btn btn-outline-secondary px-1" style="font-size:.65rem;font-weight:bold;min-width:32px" onclick="qpToggleCur('qp_cons_cost','usd')">USD</button>
                        <button type="button" id="qp_cons_cost_lbp" class="btn btn-outline-secondary px-1 opacity-50" style="font-size:.65rem;min-width:32px" onclick="qpToggleCur('qp_cons_cost','lbp')">LBP</button>
                    </div>
                    <div id="qp_cons_cost_hint" class="text-muted" style="font-size:.65rem"></div>
                </div>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-12">
                <label class="form-label small fw-bold">Product Name *</label>
                <input type="text" id="qp_name" class="form-control form-control-sm" placeholder="Product name">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Barcode</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="qp_barcode" class="form-control" placeholder="Scan or leave blank">
                    <button type="button" class="btn btn-outline-secondary" onclick="qpGenerateBarcode()" title="Auto-generate"><i class="bi bi-upc-scan"></i></button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Unit <span id="qp_type_badge" class="badge bg-secondary ms-1" style="font-size:.6rem">Regular</span></label>
                <select id="qp_unit" class="form-select form-select-sm" onchange="qpOnUnitChange()">
                    <option value="pcs">pcs — pieces</option>
                    <option value="box">box</option>
                    <option value="kg">kg — kilograms</option>
                    <option value="g">g — grams</option>
                    <option value="L">L — litres</option>
                    <option value="mL">mL — millilitres</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Category</label>
                <div class="input-group input-group-sm">
                    <select id="qp_cat" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" onclick="openNewCatModal('qp_cat')" title="New category"><i class="bi bi-plus-lg"></i></button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Supplier</label>
                <select id="qp_sup" class="form-select form-select-sm">
                    <option value="">— None —</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <!-- Cost (hidden for box — auto-calc from cost_box/upb) / Sell always visible except bulk -->
            <div class="col-md-6" id="qp_row_cost">
                <label class="form-label small fw-bold">Cost Price</label>
                <div class="input-group input-group-sm">
                    <input type="number" id="qp_cost" class="form-control" step="0.0001" min="0" data-cur="usd" oninput="qpUpdateHint('qp_cost')">
                    <button type="button" id="qp_cost_usd" class="btn btn-outline-secondary px-1" style="font-size:.65rem;font-weight:bold;min-width:32px" onclick="qpToggleCur('qp_cost','usd')">USD</button>
                    <button type="button" id="qp_cost_lbp" class="btn btn-outline-secondary px-1 opacity-50" style="font-size:.65rem;min-width:32px" onclick="qpToggleCur('qp_cost','lbp')">LBP</button>
                </div>
                <div id="qp_cost_hint" class="text-muted" style="font-size:.65rem"></div>
            </div>
            <div class="col-md-6" id="qp_row_sell">
                <label class="form-label small fw-bold"><span id="qp_sell_label">Sell Price</span> <span id="qp_sell_sublabel" class="text-muted fw-normal">(per unit)</span></label>
                <div class="input-group input-group-sm">
                    <input type="number" id="qp_sell" class="form-control" step="0.0001" min="0" data-cur="usd" oninput="qpUpdateHint('qp_sell')">
                    <button type="button" id="qp_sell_usd" class="btn btn-outline-secondary px-1" style="font-size:.65rem;font-weight:bold;min-width:32px" onclick="qpToggleCur('qp_sell','usd')">USD</button>
                    <button type="button" id="qp_sell_lbp" class="btn btn-outline-secondary px-1 opacity-50" style="font-size:.65rem;min-width:32px" onclick="qpToggleCur('qp_sell','lbp')">LBP</button>
                </div>
                <div id="qp_sell_hint" class="text-muted" style="font-size:.65rem"></div>
            </div>
            <!-- Box details (shown when unit=box) -->
            <div class="col-12" id="qp_box_section" style="display:none">
                <hr class="my-1"><label class="form-label small fw-semibold text-muted">📦 Box Details</label>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Units per Box</label>
                        <input type="number" id="qp_upb" class="form-control form-control-sm" min="1" step="1" value="1" oninput="qpCalcFromBox()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Cost per Box</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="number" id="qp_cost_box" class="form-control" step="0.0001" min="0" placeholder="What you pay" oninput="qpCalcFromBox()">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Sell per Box <span class="text-muted fw-normal">(Wholesale)</span></label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="qp_sell_box" class="form-control" step="0.0001" min="0" data-cur="usd" oninput="qpUpdateHint('qp_sell_box');qpCalcFromBox()">
                            <button type="button" id="qp_sell_box_usd" class="btn btn-outline-secondary px-1" style="font-size:.65rem;font-weight:bold;min-width:32px" onclick="qpToggleCur('qp_sell_box','usd')">USD</button>
                            <button type="button" id="qp_sell_box_lbp" class="btn btn-outline-secondary px-1 opacity-50" style="font-size:.65rem;min-width:32px" onclick="qpToggleCur('qp_sell_box','lbp')">LBP</button>
                        </div>
                        <div id="qp_sell_box_hint" class="text-muted" style="font-size:.65rem"></div>
                    </div>
                    <div class="col-12">
                        <div id="qp_box_summary" class="small text-primary" style="min-height:1.2em"></div>
                    </div>
                </div>
            </div>
            <!-- Stock (hidden for bulk) -->
            <div class="col-md-6 qp_regular_only">
                <label class="form-label small fw-bold">Initial Stock</label>
                <input type="number" id="qp_stock" class="form-control form-control-sm" min="0" step="0.001" value="0">
            </div>
            <div class="col-md-6 qp_regular_only">
                <label class="form-label small fw-bold">Low Stock Alert</label>
                <input type="number" id="qp_alert" class="form-control form-control-sm" min="0" step="0.001" value="5">
            </div>
        </div>
        <div class="text-danger small mt-2" id="qp_error"></div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="saveQuickProduct()"><i class="bi bi-check-lg me-1"></i>Create & Add</button>
    </div>
</div></div></div>

<!-- ── New Category Modal (shared) ───────────────────────────────────────────── -->
<div class="modal fade" id="newCatModal" tabindex="-1" style="z-index:1070">
<div class="modal-dialog modal-sm modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-tag me-2"></i>New Category</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="text" id="newCatName" class="form-control" placeholder="Category name" onkeydown="if(event.key==='Enter'){event.preventDefault();saveNewCategory();}">
        <div class="text-danger small mt-1" id="newCatError"></div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="saveNewCategory()">Save</button>
    </div>
</div></div></div>

<script>
const RATE    = <?= EXCHANGE_RATE ?>;
let rowIdx    = 0;
let searchTimers = {};
let _qpRowIdx = null;
let _newCatTarget = null;

// ── Currency helpers ─────────────────────────────────────────────────────────

function toggleCur(prefix, i, newCur) {
    const inp    = document.getElementById(`${prefix}-${i}`);
    if (!inp) return;
    const oldCur = inp.dataset.cur || 'usd';
    if (oldCur === newCur) return;
    const val    = parseFloat(inp.value) || 0;
    if (newCur === 'lbp' && val > 0) { inp.value = Math.round(val * RATE); inp.step = '1'; }
    else if (newCur === 'usd' && val > 0) { inp.value = (val / RATE).toFixed(4); inp.step = '0.0001'; }
    inp.dataset.cur = newCur;
    const uBtn = document.getElementById(`${prefix}-usd-${i}`);
    const lBtn = document.getElementById(`${prefix}-lbp-${i}`);
    if (uBtn) { uBtn.classList.toggle('opacity-50', newCur !== 'usd'); uBtn.style.fontWeight = newCur==='usd'?'bold':''; }
    if (lBtn) { lBtn.classList.toggle('opacity-50', newCur !== 'lbp'); lBtn.style.fontWeight = newCur==='lbp'?'bold':''; }
    updateHint(prefix, i);
    if (prefix === 'pcost') { calcRow(i); checkBatch(i); } else calcRow(i);
}

function updateHint(prefix, i) {
    const inp  = document.getElementById(`${prefix}-${i}`);
    const hint = document.getElementById(`${prefix}-hint-${i}`);
    if (!inp || !hint) return;
    const val = parseFloat(inp.value) || 0;
    const cur = inp.dataset.cur || 'usd';
    if (!val) { hint.textContent = ''; return; }
    hint.textContent = cur === 'lbp'
        ? '≈ $' + (val/RATE).toFixed(2)
        : '≈ L£ ' + Math.round(val*RATE).toLocaleString();
}

function getUsd(prefix, i) {
    const inp = document.getElementById(`${prefix}-${i}`);
    const val = parseFloat(inp?.value) || 0;
    const cur = inp?.dataset?.cur || 'usd';
    return cur === 'lbp' ? val / RATE : val;
}

// ── Row management ───────────────────────────────────────────────────────────

function addPurchRow() {
    const i = rowIdx++;
    const row = `
    <tr id="prow-${i}">
        <td>
            <div class="d-flex gap-1 align-items-start">
                <div class="position-relative flex-grow-1">
                    <input type="text" id="psearch-${i}" class="form-control form-control-sm"
                           placeholder="Type barcode or product name…" autocomplete="off"
                           oninput="searchProduct(${i})" onblur="hideDropDelay(${i})">
                    <ul id="pdrop-${i}" class="list-group position-absolute w-100 shadow"
                        style="z-index:1055;display:none;max-height:220px;overflow-y:auto;top:100%;left:0"></ul>
                    <input type="hidden" name="product_id[]" id="ppid-${i}" value="">
                </div>
                <button type="button" class="btn btn-outline-success btn-sm flex-shrink-0"
                        onclick="openQuickAddModal(${i})" title="Add new product">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </td>
        <td>
            <select name="item_type[]" id="ptype-${i}" class="form-select form-select-sm" onchange="onTypeChange(${i})" style="min-width:105px">
                <option value="regular">Regular</option>
                <option value="consignment">Consign.</option>
                <option value="bulk">Bulk</option>
            </select>
        </td>
        <td>
            <input type="number" name="quantity[]" id="pqty-${i}" class="form-control form-control-sm"
                   value="1" min="0" step="0.001" oninput="calcRow(${i})">
            <div id="pbox-wrap-${i}" style="display:none" class="mt-1">
                <div class="input-group input-group-sm">
                    <input type="number" id="pboxes-${i}" class="form-control" placeholder="# boxes" min="1" step="1" oninput="applyBoxes(${i})">
                    <span class="input-group-text px-1" style="font-size:.7rem">📦</span>
                </div>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" name="unit_cost[]" id="pcost-${i}" class="form-control"
                       value="" min="0" step="0.0001" data-cur="usd"
                       oninput="calcRow(${i}); checkBatch(${i}); updateHint('pcost',${i})">
                <button type="button" id="pcost-usd-${i}" class="btn btn-outline-secondary px-1"
                        style="font-size:.65rem;min-width:32px;font-weight:bold" onclick="toggleCur('pcost',${i},'usd')">USD</button>
                <button type="button" id="pcost-lbp-${i}" class="btn btn-outline-secondary px-1 opacity-50"
                        style="font-size:.65rem;min-width:32px" onclick="toggleCur('pcost',${i},'lbp')">LBP</button>
            </div>
            <div id="pcost-hint-${i}" class="text-muted" style="font-size:.62rem"></div>
            <div id="pboxcost-wrap-${i}" style="display:none" class="mt-1">
                <div class="input-group input-group-sm">
                    <span class="input-group-text px-1" style="font-size:.7rem">$/📦</span>
                    <input type="number" id="pboxcost-${i}" class="form-control" placeholder="Cost/box" min="0" step="0.01" oninput="applyBoxes(${i})">
                </div>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" name="new_sell_price[]" id="psell-${i}" class="form-control"
                       value="" min="0" step="0.0001" data-cur="usd"
                       oninput="calcRow(${i}); updateHint('psell',${i})">
                <button type="button" id="psell-usd-${i}" class="btn btn-outline-secondary px-1"
                        style="font-size:.65rem;min-width:32px;font-weight:bold" onclick="toggleCur('psell',${i},'usd')">USD</button>
                <button type="button" id="psell-lbp-${i}" class="btn btn-outline-secondary px-1 opacity-50"
                        style="font-size:.65rem;min-width:32px" onclick="toggleCur('psell',${i},'lbp')">LBP</button>
            </div>
            <div id="psell-hint-${i}" class="text-muted" style="font-size:.62rem">0 = keep current</div>
            <div id="psell-box-wrap-${i}" style="display:none" class="mt-1">
                <div class="input-group input-group-sm">
                    <span class="input-group-text px-1" style="font-size:.65rem">📦</span>
                    <input type="number" name="new_sell_price_box[]" id="psell-box-${i}" class="form-control"
                           value="" min="0" step="0.01" placeholder="Wholesale/box">
                </div>
                <div class="text-muted" style="font-size:.62rem">0 = keep current</div>
            </div>
        </td>
        <td id="pline-${i}" class="fw-bold small">0.00</td>
        <td id="pbatch-${i}" class="small text-muted">—</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="document.getElementById('prow-${i}').remove();calcTotal()">
            <i class="bi bi-trash"></i></button></td>
    </tr>`;
    document.getElementById('purch-body').insertAdjacentHTML('beforeend', row);
    document.getElementById('psearch-'+i).focus();
}

// ── Product search ───────────────────────────────────────────────────────────

function searchProduct(i) {
    const q    = (document.getElementById('psearch-'+i)?.value || '').trim();
    const drop = document.getElementById('pdrop-'+i);
    clearTimeout(searchTimers[i]);
    if (q.length < 1) { drop.style.display = 'none'; return; }
    searchTimers[i] = setTimeout(() => {
        fetch('/dahdouh/pages/api.php?action=search_products_purchase&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(results => {
                if (!results.length) { drop.style.display = 'none'; return; }
                drop.innerHTML = results.map((p, idx) =>
                    `<li class="list-group-item list-group-item-action p-2 small" style="cursor:pointer" data-pidx="${idx}">
                        <span class="fw-semibold">${escHtml(p.name)}</span>
                        ${p.barcode ? `<span class="text-muted ms-1">(${escHtml(p.barcode)})</span>` : ''}
                        <span class="badge bg-secondary ms-1">${escHtml(p.unit||'pcs')}</span>
                        <span class="float-end text-success">$${parseFloat(p.cost_price).toFixed(2)}</span>
                    </li>`
                ).join('');
                drop._results = results;
                drop.style.display = '';
                drop.onmousedown = function(e) {
                    const li = e.target.closest('[data-pidx]');
                    if (!li) return;
                    selectProduct(i, this._results[parseInt(li.dataset.pidx)]);
                };
            })
            .catch(() => { drop.style.display = 'none'; });
    }, 250);
}

function hideDropDelay(i) {
    setTimeout(() => {
        const drop = document.getElementById('pdrop-'+i);
        if (drop) drop.style.display = 'none';
    }, 200);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function selectProduct(i, p) {
    document.getElementById('psearch-'+i).value = p.name;
    document.getElementById('ppid-'+i).value    = p.id;
    document.getElementById('pdrop-'+i).style.display = 'none';

    const cost = parseFloat(p.cost_price) || 0;
    const sell = parseFloat(p.sell_price) || 0;
    const upb  = parseInt(p.units_per_box) || 1;
    const isBulk = p.product_type === 'bulk';

    const costEl = document.getElementById('pcost-'+i);
    costEl.value = cost.toFixed(4); costEl.dataset.cur = 'usd'; costEl.step = '0.0001';
    document.getElementById('pcost-usd-'+i).classList.remove('opacity-50'); document.getElementById('pcost-usd-'+i).style.fontWeight = 'bold';
    document.getElementById('pcost-lbp-'+i).classList.add('opacity-50');   document.getElementById('pcost-lbp-'+i).style.fontWeight = '';
    updateHint('pcost', i);

    const sellEl = document.getElementById('psell-'+i);
    sellEl.value = sell.toFixed(4); sellEl.dataset.cur = 'usd'; sellEl.step = '0.0001';
    document.getElementById('psell-usd-'+i).classList.remove('opacity-50'); document.getElementById('psell-usd-'+i).style.fontWeight = 'bold';
    document.getElementById('psell-lbp-'+i).classList.add('opacity-50');   document.getElementById('psell-lbp-'+i).style.fontWeight = '';
    updateHint('psell', i);

    // Set type select based on product's stored source/type
    const typeEl = document.getElementById('ptype-'+i);
    if (isBulk) typeEl.value = 'bulk';
    else if (p.product_source === 'consignment') typeEl.value = 'consignment';
    else typeEl.value = 'regular';

    // Store upb on row for onTypeChange to reference
    const rowEl = document.getElementById('prow-'+i);
    if (rowEl) { rowEl.dataset.upb = upb; rowEl.dataset.unit = p.unit || 'pcs'; }

    if (upb > 1) {
        document.getElementById('pboxes-'+i).placeholder = `# boxes (1 box = ${upb} ${p.unit||'pcs'}s)`;
    }

    document.getElementById('psearch-'+i).dataset.upb = upb;

    // Show wholesale box price field when product has box pricing
    const sellBoxWrap = document.getElementById('psell-box-wrap-'+i);
    if (sellBoxWrap) {
        sellBoxWrap.style.display = upb > 1 ? '' : 'none';
        const sellBoxEl = document.getElementById('psell-box-'+i);
        if (sellBoxEl) sellBoxEl.value = parseFloat(p.sell_price_box) > 0 ? parseFloat(p.sell_price_box).toFixed(2) : '';
    }

    onTypeChange(i);  // drives qty disabled, box visibility, batch check, calcRow
}

// ── Box helpers ───────────────────────────────────────────────────────────────

function applyBoxes(i) {
    const upb     = parseInt(document.getElementById('psearch-'+i)?.dataset?.upb || 1);
    const boxes   = parseFloat(document.getElementById('pboxes-'+i)?.value || 0);
    const boxCost = parseFloat(document.getElementById('pboxcost-'+i)?.value || 0);
    if (boxes > 0 && upb > 0) document.getElementById('pqty-'+i).value = (boxes * upb).toFixed(0);
    if (boxCost > 0 && upb > 0) {
        const costEl = document.getElementById('pcost-'+i);
        costEl.value = (boxCost / upb).toFixed(4);
        costEl.dataset.cur = 'usd'; costEl.step = '0.0001';
        updateHint('pcost', i);
    }
    calcRow(i); checkBatch(i);
}

// ── Type selector ─────────────────────────────────────────────────────────────

function onTypeChange(i) {
    const type   = document.getElementById('ptype-'+i)?.value || 'regular';
    const isBulk = type === 'bulk';
    const rowEl  = document.getElementById('prow-'+i);
    const upb    = parseInt(rowEl?.dataset?.upb || 1);

    const qtyEl = document.getElementById('pqty-'+i);
    qtyEl.disabled = isBulk;
    if (isBulk) qtyEl.value = '0';

    const showBox = !isBulk && upb > 1;
    document.getElementById('pbox-wrap-'+i).style.display     = showBox ? '' : 'none';
    document.getElementById('pboxcost-wrap-'+i).style.display = showBox ? '' : 'none';

    if (isBulk) {
        document.getElementById('pbatch-'+i).innerHTML = '<span class="text-muted">—</span>';
    } else {
        checkBatch(i);
    }

    calcRow(i);
}

// ── Calculations ─────────────────────────────────────────────────────────────

function calcRow(i) {
    const type    = document.getElementById('ptype-'+i)?.value || 'regular';
    const isBulk  = type === 'bulk';
    const qty     = parseFloat(document.getElementById('pqty-'+i)?.value || 0);
    const costUsd = getUsd('pcost', i);
    const line    = isBulk ? costUsd : qty * costUsd;
    document.getElementById('pline-'+i).textContent = line.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let totalDue = 0, totalCons = 0;
    document.querySelectorAll('[id^="prow-"]').forEach(row => {
        const idx  = row.id.replace('prow-','');
        const type = document.getElementById('ptype-'+idx)?.value || 'regular';
        const line = parseFloat(document.getElementById('pline-'+idx)?.textContent || 0);
        if (type === 'consignment') totalCons += line;
        else totalDue += line;
    });
    document.getElementById('purch-total').textContent = (totalDue + totalCons).toFixed(2);
    document.getElementById('purch-due').textContent   = totalDue.toFixed(2);
    document.getElementById('purch-cons').textContent  = totalCons.toFixed(2);
    const consWrap = document.getElementById('cons-total-wrap');
    if (consWrap) consWrap.style.display = totalCons > 0 ? '' : 'none';
    // Grey out payment selector when nothing is immediately due
    const pmWrap = document.querySelector('[name="payment_method"]')?.closest('.me-auto');
    if (pmWrap) pmWrap.style.opacity = totalDue > 0 ? '1' : '0.4';
}

function checkBatch(i) {
    const pid    = document.getElementById('ppid-'+i)?.value;
    const cost   = getUsd('pcost', i);
    const el     = document.getElementById('pbatch-'+i);
    const isCons = (document.getElementById('ptype-'+i)?.value === 'consignment');
    if (!pid || cost <= 0) { el.innerHTML = '—'; return; }
    fetch(`/dahdouh/pages/api.php?action=check_batch&product_id=${pid}&cost=${cost}`)
        .then(r => r.json())
        .then(d => {
            const suffix = isCons ? '<br><span class="text-warning" style="font-size:.7rem">⏳ due on sale</span>' : '';
            el.innerHTML = d.found
                ? `<span class="text-success">↗ Merge #${d.batch_id}<br>(${d.qty_remaining} rem.)</span>${suffix}`
                : `<span class="text-primary">★ New batch</span>${suffix}`;
        }).catch(() => { el.innerHTML = '—'; });
}

// ── Quick Add Product ─────────────────────────────────────────────────────────

function openQuickAddModal(i) {
    _qpRowIdx = i;
    document.getElementById('qp_name').value    = document.getElementById('psearch-'+i)?.value || '';
    document.getElementById('qp_src_owned').checked = true;
    document.getElementById('qp_cons_fields').classList.add('d-none');
    document.getElementById('qp_cons_sup').value  = '';
    document.getElementById('qp_cons_cost').value = '';
    document.getElementById('qp_barcode').value   = '';
    document.getElementById('qp_unit').value      = 'pcs';
    document.getElementById('qp_cat').value       = '';
    document.getElementById('qp_sup').value       = '';
    document.getElementById('qp_cost').value      = '';
    document.getElementById('qp_sell').value      = '';
    document.getElementById('qp_stock').value     = '0';
    document.getElementById('qp_alert').value     = '5';
    document.getElementById('qp_upb').value       = '1';
    document.getElementById('qp_cost_box').value  = '';
    document.getElementById('qp_sell_box').value  = '';
    ['qp_cost','qp_sell','qp_sell_box','qp_cons_cost'].forEach(fid => {
        const inp = document.getElementById(fid);
        if (!inp) return;
        inp.dataset.cur = 'usd'; inp.step = '0.0001';
        const u = document.getElementById(fid+'_usd'), l = document.getElementById(fid+'_lbp');
        if (u) { u.classList.remove('opacity-50'); u.style.fontWeight = 'bold'; }
        if (l) { l.classList.add('opacity-50');    l.style.fontWeight = ''; }
    });
    ['qp_cost_hint','qp_sell_hint','qp_sell_box_hint','qp_cons_cost_hint','qp_box_summary','qp_error'].forEach(id => {
        const el = document.getElementById(id); if (el) el.textContent = '';
    });
    qpOnUnitChange();
    new bootstrap.Modal(document.getElementById('quickAddModal')).show();
    setTimeout(() => document.getElementById('qp_name').focus(), 300);
}

function qpToggleCur(fieldId, newCur) {
    const inp = document.getElementById(fieldId);
    if (!inp) return;
    const oldCur = inp.dataset.cur || 'usd';
    if (oldCur === newCur) return;
    const val = parseFloat(inp.value) || 0;
    if (newCur === 'lbp' && val > 0) { inp.value = Math.round(val * RATE); inp.step = '1'; }
    else if (newCur === 'usd' && val > 0) { inp.value = (val / RATE).toFixed(4); inp.step = '0.0001'; }
    inp.dataset.cur = newCur;
    const uBtn = document.getElementById(fieldId + '_usd');
    const lBtn = document.getElementById(fieldId + '_lbp');
    if (uBtn) { uBtn.classList.toggle('opacity-50', newCur!=='usd'); uBtn.style.fontWeight = newCur==='usd'?'bold':''; }
    if (lBtn) { lBtn.classList.toggle('opacity-50', newCur!=='lbp'); lBtn.style.fontWeight = newCur==='lbp'?'bold':''; }
    qpUpdateHint(fieldId);
}

function qpUpdateHint(fieldId) {
    const inp  = document.getElementById(fieldId);
    const hint = document.getElementById(fieldId + '_hint');
    if (!inp || !hint) return;
    const val = parseFloat(inp.value) || 0;
    const cur = inp.dataset.cur || 'usd';
    if (!val) { hint.textContent = ''; return; }
    hint.textContent = cur === 'lbp'
        ? '≈ $' + (val/RATE).toFixed(2)
        : '≈ L£ ' + Math.round(val*RATE).toLocaleString();
}

function qpToUsd(fieldId) {
    const inp = document.getElementById(fieldId);
    if (!inp) return 0;
    const val = parseFloat(inp.value) || 0;
    const cur = inp.dataset.cur || 'usd';
    return cur === 'lbp' ? val / RATE : val;
}

function qpGenerateBarcode() {
    fetch('/dahdouh/pages/api.php?action=generate_barcode')
        .then(r => r.json())
        .then(d => { document.getElementById('qp_barcode').value = d.barcode; });
}

function qpToggleSource() {
    const isCons = document.getElementById('qp_src_cons').checked;
    document.getElementById('qp_cons_fields').classList.toggle('d-none', !isCons);
}

function qpOnUnitChange() {
    const unit   = document.getElementById('qp_unit').value;
    const isBulk = ['kg','g','L','mL'].includes(unit);
    const isBox  = unit === 'box';
    const badge  = document.getElementById('qp_type_badge');
    badge.textContent = isBulk ? 'Bulk' : 'Regular';
    badge.className   = 'badge ms-1 ' + (isBulk ? 'bg-warning text-dark' : 'bg-secondary');
    document.getElementById('qp_row_cost').style.display    = isBox ? 'none' : '';
    document.getElementById('qp_row_sell').style.display    = isBulk ? 'none' : '';
    document.getElementById('qp_box_section').style.display = isBox ? ''     : 'none';
    document.querySelectorAll('.qp_regular_only').forEach(el => el.style.display = isBulk ? 'none' : '');
    const sellLabel    = document.getElementById('qp_sell_label');
    const sellSublabel = document.getElementById('qp_sell_sublabel');
    if (sellLabel)    sellLabel.textContent    = isBox ? 'Sell per Unit' : 'Sell Price';
    if (sellSublabel) sellSublabel.textContent = isBox ? '(Retail)'      : '(per unit)';
    if (isBox) qpCalcFromBox();
}

function qpCalcFromBox() {
    const upb        = Math.max(1, parseInt(document.getElementById('qp_upb')?.value || 1));
    const costBox    = parseFloat(document.getElementById('qp_cost_box')?.value || 0);
    const sellBoxUsd = qpToUsd('qp_sell_box');
    const sellUsd    = qpToUsd('qp_sell');
    if (costBox > 0) document.getElementById('qp_cost').value = (costBox / upb).toFixed(4);
    const el = document.getElementById('qp_box_summary');
    if (!el) return;
    const parts = [`${upb} units/box`];
    if (costBox > 0)    parts.push(`cost $${(costBox/upb).toFixed(4)}/unit`);
    if (sellUsd > 0)    parts.push(`retail $${sellUsd.toFixed(4)}/unit`);
    if (sellBoxUsd > 0) parts.push(`wholesale $${sellBoxUsd.toFixed(2)}/box`);
    if (sellBoxUsd > 0 && costBox > 0) parts.push(`${(((sellBoxUsd - costBox) / sellBoxUsd) * 100).toFixed(1)}% margin`);
    el.textContent = parts.length > 1 ? parts.join(' — ') : '';
}

let _qpNameTimer = null;
function qpNameSearch() {
    const q    = document.getElementById('qp_name').value.trim();
    const drop = document.getElementById('qp_name_drop');
    clearTimeout(_qpNameTimer);
    if (!q || q.length < 2) { drop.style.display = 'none'; return; }
    const delay = /^\d{6,}$/.test(q) ? 0 : 300;
    _qpNameTimer = setTimeout(() => {
        fetch(`/dahdouh/pages/api.php?action=search_products_purchase&q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(results => {
                if (!results || !results.length) { drop.style.display = 'none'; return; }
                drop.innerHTML = results.map(p => {
                    const price = parseFloat(p.sell_price||0).toFixed(2);
                    const bar   = p.barcode ? `<span class="text-muted ms-1" style="font-size:.7rem">${p.barcode}</span>` : '';
                    const stock = p.product_type === 'bulk' ? '' : ` · ${parseFloat(p.stock||0)} ${p.unit||''}`;
                    return `<li class="list-group-item list-group-item-action py-1 px-2 small"
                                style="cursor:pointer;font-size:.8rem"
                                onmousedown="qpPickExisting(${JSON.stringify(JSON.stringify(p))})">
                                <strong>${p.name}</strong>${bar}
                                <span class="float-end text-success">$${price}${stock}</span>
                            </li>`;
                }).join('');
                drop.style.display = '';
            })
            .catch(() => { drop.style.display = 'none'; });
    }, delay);
}

function qpPickExisting(pJson) {
    const p = JSON.parse(pJson);
    document.getElementById('qp_name_drop').style.display = 'none';
    bootstrap.Modal.getInstance(document.getElementById('quickAddModal'))?.hide();
    if (_qpRowIdx !== null) {
        selectProduct(_qpRowIdx, {
            id:             p.id,
            name:           p.name,
            cost_price:     p.cost_price,
            sell_price:     p.sell_price,
            sell_price_box: p.sell_price_box || 0,
            unit:           p.unit || 'pcs',
            product_type:   p.product_type || 'regular',
            units_per_box:  p.units_per_box || 1,
            is_consignment: p.product_source === 'consignment' ? 1 : 0
        });
    }
}

function saveQuickProduct() {
    const name   = document.getElementById('qp_name').value.trim();
    const unit   = document.getElementById('qp_unit').value;
    const isBox  = unit === 'box';
    const isBulk = ['kg','g','L','mL'].includes(unit);
    const isCons = document.getElementById('qp_src_cons').checked;
    if (!name) { document.getElementById('qp_error').textContent = 'Name required.'; return; }
    if (isCons && !document.getElementById('qp_cons_sup').value) { document.getElementById('qp_error').textContent = 'Select consignment supplier.'; return; }
    if (isCons && !document.getElementById('qp_cons_cost').value) { document.getElementById('qp_error').textContent = 'Enter consignment cost.'; return; }
    document.getElementById('qp_error').textContent = '';
    const upb        = isBox ? Math.max(1, parseInt(document.getElementById('qp_upb').value) || 1) : 1;
    const costBoxRaw = isBox ? (parseFloat(document.getElementById('qp_cost_box').value) || 0) : 0;
    const costUsd    = isBox ? (costBoxRaw / upb) : qpToUsd('qp_cost');
    const sellUsd    = qpToUsd('qp_sell');
    const sellBoxUsd = isBox ? qpToUsd('qp_sell_box') : 0;
    const body = new URLSearchParams({
        name,
        barcode:                 document.getElementById('qp_barcode').value.trim(),
        unit,
        category_id:             document.getElementById('qp_cat').value,
        supplier_id:             document.getElementById('qp_sup').value,
        cost_usd:                costUsd.toFixed(4),
        sell_usd:                sellUsd.toFixed(4),
        units_per_box:           upb,
        sell_price_box:          sellBoxUsd > 0 ? sellBoxUsd.toFixed(4) : '',
        product_type:            isBulk ? 'bulk' : 'regular',
        product_source:          isCons ? 'consignment' : 'owned',
        consignment_supplier_id: isCons ? document.getElementById('qp_cons_sup').value : '',
        consignment_cost:        isCons ? qpToUsd('qp_cons_cost').toFixed(4) : '',
        stock:                   isBulk ? '0' : (document.getElementById('qp_stock').value || '0'),
        low_stock_alert:         isBulk ? '0' : (document.getElementById('qp_alert').value || '5'),
    });
    fetch('/dahdouh/pages/api.php?action=create_product_quick', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { document.getElementById('qp_error').textContent = d.error||'Error'; return; }
            bootstrap.Modal.getInstance(document.getElementById('quickAddModal'))?.hide();
            if (_qpRowIdx !== null) {
                selectProduct(_qpRowIdx, {
                    id: d.id, name: d.name, cost_price: d.cost_price, sell_price: d.sell_price,
                    sell_price_box: d.sell_price_box || 0,
                    unit: d.unit, product_type: d.product_type, units_per_box: d.units_per_box || 1,
                    is_consignment: d.product_source === 'consignment' ? 1 : 0
                });
            }
        })
        .catch(() => { document.getElementById('qp_error').textContent = 'Network error'; });
}

// ── New Category (shared) ─────────────────────────────────────────────────────

function openNewCatModal(selectId) {
    _newCatTarget = selectId;
    document.getElementById('newCatName').value = '';
    document.getElementById('newCatError').textContent = '';
    new bootstrap.Modal(document.getElementById('newCatModal')).show();
    setTimeout(() => document.getElementById('newCatName').focus(), 300);
}

function saveNewCategory() {
    const name = document.getElementById('newCatName').value.trim();
    if (!name) { document.getElementById('newCatError').textContent = 'Name required.'; return; }
    fetch('/dahdouh/pages/api.php?action=create_category', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'name=' + encodeURIComponent(name)
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) { document.getElementById('newCatError').textContent = d.error||'Error'; return; }
        document.querySelectorAll('select[id="qp_cat"]').forEach(sel => {
            if (!sel.querySelector(`option[value="${d.id}"]`)) sel.appendChild(new Option(d.name, d.id));
            if (sel.id === _newCatTarget) sel.value = d.id;
        });
        if (_newCatTarget) {
            const sel = document.getElementById(_newCatTarget);
            if (sel && !sel.querySelector(`option[value="${d.id}"]`)) sel.appendChild(new Option(d.name, d.id));
            if (sel) sel.value = d.id;
        }
        bootstrap.Modal.getInstance(document.getElementById('newCatModal'))?.hide();
    })
    .catch(() => { document.getElementById('newCatError').textContent = 'Network error'; });
}

// ── Form submit: convert all LBP values to USD ───────────────────────────────

document.getElementById('purch-form').addEventListener('submit', function() {
    document.querySelectorAll('[data-cur="lbp"]').forEach(inp => {
        const val = parseFloat(inp.value) || 0;
        if (val > 0) inp.value = (val / RATE).toFixed(4);
        inp.dataset.cur = 'usd';
    });
});

// ── View Purchase ─────────────────────────────────────────────────────────────

function viewPurchase(id, ref) {
    document.getElementById('vp-title').textContent = 'Purchase ' + ref;
    document.getElementById('vp-body').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>';
    fetch('/dahdouh/pages/api.php?action=purchase_items&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { document.getElementById('vp-body').innerHTML = `<tr><td colspan="5" class="text-danger">${data.error}</td></tr>`; return; }
            let html = '', total = 0;
            data.forEach(it => {
                const line = parseFloat(it.quantity) * parseFloat(it.unit_cost);
                total += line;
                html += `<tr>
                    <td>${escHtml(it.product_name)}</td>
                    <td>${it.product_type}</td>
                    <td>${parseFloat(it.quantity)||'—'}</td>
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

// ── Edit Purchase ─────────────────────────────────────────────────────────────

let _epPurchaseId = null, _epItems = [];

function editPurchase(id, ref) {
    _epPurchaseId = id;
    _epItems = [];
    document.getElementById('ep-title').textContent = ref;
    document.getElementById('ep-body').innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div></div>';
    new bootstrap.Modal(document.getElementById('editPurchModal')).show();
    fetch('/dahdouh/pages/api.php?action=get_purchase_for_edit&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.error) { document.getElementById('ep-body').innerHTML = '<div class="alert alert-danger">' + escHtml(data.error) + '</div>'; return; }
            _epItems = data.items;
            renderEpItems(data);
        })
        .catch(() => { document.getElementById('ep-body').innerHTML = '<div class="alert alert-danger">Network error</div>'; });
}

function renderEpItems(data) {
    let html = `<div class="alert alert-warning py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>
        Qty changes adjust stock &amp; batches. Cannot reduce below consumed qty. Cost changes adjust supplier balance &amp; cash register.</div>`;
    html += '<table class="table table-sm table-bordered"><thead class="table-secondary"><tr><th>Product</th><th>Type</th><th style="width:110px">Qty</th><th style="width:130px">Unit Cost ($)</th><th class="text-end" style="width:100px">Line Total</th></tr></thead><tbody>';
    data.items.forEach((item, i) => {
        const isBulk = item.product_type === 'bulk';
        const consumed = parseFloat(item.qty_consumed) || 0;
        const minQty = isBulk ? 0 : consumed;
        const line = isBulk ? parseFloat(item.unit_cost) : (parseFloat(item.quantity) * parseFloat(item.unit_cost));
        const typeBadge = item.product_type === 'consignment'
            ? '<span class="badge" style="background:#7c3aed;color:#fff">Consign</span>'
            : (item.product_type === 'bulk' ? '<span class="badge bg-warning text-dark">Bulk</span>' : '<span class="badge bg-info text-dark">Regular</span>');
        html += `<tr>
            <td class="small">${escHtml(item.product_name)}${consumed > 0 && !isBulk ? `<br><small class="text-muted">${consumed} consumed</small>` : ''}</td>
            <td>${typeBadge}</td>
            <td>${isBulk ? '<span class="text-muted small">N/A</span>' :
                `<input type="number" class="form-control form-control-sm" id="ep-qty-${i}" min="${minQty}" step="0.001"
                    value="${parseFloat(item.quantity)}" onchange="updateEpLine(${i})">`}</td>
            <td><input type="number" class="form-control form-control-sm" id="ep-cost-${i}" min="0" step="0.0001"
                value="${parseFloat(item.unit_cost).toFixed(4)}" onchange="updateEpLine(${i})"></td>
            <td class="text-end fw-bold" id="ep-line-${i}">$${line.toFixed(2)}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    html += `<div class="text-end fw-bold">Original total: $${parseFloat(data.total_amount).toFixed(2)} &nbsp; New total: <span class="text-warning" id="ep-new-total">$${parseFloat(data.total_amount).toFixed(2)}</span></div>`;
    document.getElementById('ep-body').innerHTML = html;
}

function updateEpLine(i) {
    const item = _epItems[i];
    if (!item) return;
    const isBulk = item.product_type === 'bulk';
    const qty  = isBulk ? 0 : (parseFloat(document.getElementById('ep-qty-'+i)?.value) || 0);
    const cost = parseFloat(document.getElementById('ep-cost-'+i)?.value) || 0;
    const line = isBulk ? cost : qty * cost;
    const el = document.getElementById('ep-line-'+i);
    if (el) el.textContent = '$' + line.toFixed(2);
    let total = 0;
    _epItems.forEach((_, j) => {
        const jBulk = _epItems[j].product_type === 'bulk';
        const jQty  = jBulk ? 0 : (parseFloat(document.getElementById('ep-qty-'+j)?.value) || 0);
        const jCost = parseFloat(document.getElementById('ep-cost-'+j)?.value) || 0;
        total += jBulk ? jCost : jQty * jCost;
    });
    const nt = document.getElementById('ep-new-total');
    if (nt) nt.textContent = '$' + total.toFixed(2);
}

function submitPurchaseEdit() {
    if (!_epPurchaseId || !_epItems.length) return;
    const items = _epItems.map((item, i) => {
        const isBulk = item.product_type === 'bulk';
        return {
            id:   item.id,
            qty:  isBulk ? parseFloat(item.quantity) : (parseFloat(document.getElementById('ep-qty-'+i)?.value) || 0),
            cost: parseFloat(document.getElementById('ep-cost-'+i)?.value) || 0
        };
    });
    const body = new URLSearchParams({ purchase_id: _epPurchaseId, items: JSON.stringify(items) });
    fetch('/dahdouh/pages/api.php?action=edit_purchase', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert('Error: ' + (d.error || 'Unknown')); return; }
            bootstrap.Modal.getInstance(document.getElementById('editPurchModal'))?.hide();
            const diff = parseFloat(d.diff);
            alert('Purchase updated.\nNew total: $' + parseFloat(d.new_total).toFixed(2) +
                  '\nFinancial adjustment: ' + (diff >= 0 ? '+' : '') + '$' + diff.toFixed(2));
            location.reload();
        })
        .catch(() => alert('Network error'));
}

addPurchRow();

let _purchDrawerCur = 'usd';
function togglePurchDrawer() {
    const v = document.getElementById('purch-pay-sel')?.value;
    document.getElementById('purch-drawer-cur').style.display = v === 'cash_register' ? '' : 'none';
}
function setPurchDrawer(cur) {
    _purchDrawerCur = cur;
    document.getElementById('pdcur-usd').className = 'btn btn-' + (cur==='usd' ? 'success active' : 'outline-success');
    document.getElementById('pdcur-lbp').className = 'btn btn-' + (cur==='lbp' ? 'warning active' : 'outline-warning');
}
function applyPurchDrawer() {
    const sel = document.getElementById('purch-pay-sel');
    if (sel && sel.value === 'cash_register' && _purchDrawerCur === 'lbp') sel.value = 'cash_register_lbp';
}
togglePurchDrawer();

// ─── Purchases search / filter ────────────────────────────────────────────────
function filterPurchases() {
    const q    = (document.getElementById('purch-search')?.value || '').toLowerCase().trim();
    const from = (document.getElementById('purch-date-from')?.value || '').trim();
    const to   = (document.getElementById('purch-date-to')?.value || '').trim();
    document.querySelectorAll('#purch-list-body tr').forEach(tr => {
        const supplier = tr.dataset.supplier || '';
        const trDate   = tr.dataset.date || '';
        const matchQ   = !q    || supplier.includes(q);
        const matchD   = (!from || trDate >= from) && (!to || trDate <= to);
        tr.style.display = (matchQ && matchD) ? '' : 'none';
    });
}

// ─── New Supplier quick-create ────────────────────────────────────────────────
function openNewSupplierModal() {
    document.getElementById('ns-name').value  = '';
    document.getElementById('ns-phone').value = '';
    new bootstrap.Modal(document.getElementById('newSupplierModal')).show();
    setTimeout(() => document.getElementById('ns-name').focus(), 300);
}
function saveNewSupplier() {
    const name  = document.getElementById('ns-name').value.trim();
    const phone = document.getElementById('ns-phone').value.trim();
    if (!name) { alert('Supplier name is required.'); return; }
    const fd = new FormData();
    fd.append('name', name);
    fd.append('phone', phone);
    fetch('/dahdouh/pages/api.php?action=create_supplier', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.error) { alert(d.error); return; }
            const sel = document.getElementById('purch-supplier-sel');
            const opt = document.createElement('option');
            opt.value       = d.id;
            opt.textContent = name;
            opt.selected    = true;
            sel.appendChild(opt);
            bootstrap.Modal.getInstance(document.getElementById('newSupplierModal')).hide();
        })
        .catch(() => alert('Failed to create supplier'));
}
</script>

<!-- ── New Supplier Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="newSupplierModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-truck me-2"></i>New Supplier</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label small">Name <span class="text-danger">*</span></label>
            <input type="text" id="ns-name" class="form-control form-control-sm" placeholder="Supplier name">
        </div>
        <div class="mb-2">
            <label class="form-label small">Phone</label>
            <input type="text" id="ns-phone" class="form-control form-control-sm" placeholder="Optional">
        </div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" onclick="saveNewSupplier()">Save</button>
    </div>
</div></div></div>

<?php renderFoot(); ?>
