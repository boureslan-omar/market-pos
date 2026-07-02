<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
// Fallback constants for installs with older config.php
defined('CUSTOMER_DISPLAY') || define('CUSTOMER_DISPLAY', false);
defined('CASH_DRAWER')       || define('CASH_DRAWER',       false);
defined('VFD_ENABLED')       || define('VFD_ENABLED',       false);
defined('VFD_COM_PORT')      || define('VFD_COM_PORT',      'COM3');
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['error'=>'Not authenticated']); exit; }

$action = $_GET['action'] ?? '';

// ─── VFD / LED display via COM port ──────────────────────────────────────────
if ($action === 'vfd_display') {
    if (!VFD_ENABLED) { echo json_encode(['skip' => 'VFD disabled']); exit; }
    $total = (float)($_POST['total'] ?? 0);
    $line1 = substr(trim($_POST['line1'] ?? ''), 0, 20);
    $line2 = 'Total: $' . number_format($total, 2);
    $port  = VFD_COM_PORT;
    $fp = @fopen($port . ':', 'w');
    if ($fp) {
        fwrite($fp, "\x0C");        // Form feed — clears most VFD displays
        fwrite($fp, str_pad($line1, 20) . "\n");
        fwrite($fp, str_pad($line2, 20));
        fclose($fp);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'Cannot open ' . $port]);
    }
    exit;
}

// ─── Trigger background auto-update check ────────────────────────────────────
if ($action === 'trigger_update') {
    requireRole('admin');
    $manifestUrl = setting('update_manifest_url', '');
    if (!$manifestUrl) { echo json_encode(['skip' => 'no manifest URL']); exit; }
    $script  = realpath(__DIR__ . '/../auto_update.php');
    $logFile = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . 'auto_update.log';
    $php     = 'C:\\xampp\\php\\php.exe';
    if (!file_exists($php)) $php = PHP_BINARY;
    // Launch detached so the request returns immediately
    $cmd = "\"$php\" \"$script\" >> \"$logFile\" 2>&1";
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen("start /B $cmd", "r"));
    } else {
        exec("$cmd &");
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── Search product (barcode or name) ─────────────────────────────────────────
if ($action === 'search_product') {
    $q = trim($_GET['q'] ?? '');
    $stmt = $pdo->prepare("
        SELECT id, name, sell_price, sell_price_box, cost_price, stock, barcode,
               product_type, product_source, consignment_cost, consignment_supplier_id,
               units_per_box
        FROM products
        WHERE barcode = ? OR name LIKE ?
        ORDER BY (barcode = ?) DESC
        LIMIT 1
    ");
    $stmt->execute([$q, "%$q%", $q]);
    $row = $stmt->fetch();
    echo $row ? json_encode($row) : json_encode(['error' => 'Product not found']);
    exit;
}

// ─── Check batch (for purchase form) ─────────────────────────────────────────
if ($action === 'check_batch') {
    $pid  = (int)($_GET['product_id'] ?? 0);
    $cost = (float)($_GET['cost'] ?? 0);
    if (!$pid || $cost <= 0) { echo json_encode(['found' => false]); exit; }

    $batch = findExistingBatch($pdo, $pid, $cost);
    if ($batch) {
        echo json_encode(['found' => true, 'batch_id' => $batch['id'], 'qty_remaining' => $batch['quantity_remaining']]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ─── Search customers ─────────────────────────────────────────────────────────
if ($action === 'search_customer') {
    $q = trim($_GET['q'] ?? '');
    $stmt = $pdo->prepare("SELECT id, name, phone, balance FROM customers WHERE name LIKE ? OR phone LIKE ? LIMIT 10");
    $stmt->execute(["%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── Save setting (used by print toggle) ─────────────────────────────────────
if ($action === 'set_setting') {
    $key = $_GET['key'] ?? '';
    $val = $_GET['val'] ?? '';
    $allowed = ['auto_print_receipt','exchange_rate'];
    if (in_array($key, $allowed)) {
        saveSetting($pdo, $key, $val);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'Not allowed']);
    }
    exit;
}

// ─── Create supplier (quick-add from purchases) ───────────────────────────────
if ($action === 'create_supplier') {
    requireRole('admin','stock');
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if (!$name) { echo json_encode(['error' => 'Name required']); exit; }
    $dup = $pdo->prepare("SELECT id FROM suppliers WHERE name=?");
    $dup->execute([$name]);
    if ($dup->fetch()) { echo json_encode(['error' => 'A supplier with that name already exists']); exit; }
    $pdo->prepare("INSERT INTO suppliers (name, phone) VALUES (?,?)")->execute([$name, $phone]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

// ─── Create customer (quick-add from POS) ────────────────────────────────────
if ($action === 'create_customer') {
    requireRole('admin','cashier');
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $balance = (float)($_POST['balance'] ?? 0);
    if (!$name) { echo json_encode(['error' => 'Name required']); exit; }
    $dup = $pdo->prepare("SELECT id FROM customers WHERE name=?");
    $dup->execute([$name]);
    if ($dup->fetch()) { echo json_encode(['error' => 'A customer with that name already exists']); exit; }
    $pdo->prepare("INSERT INTO customers (name, phone, balance) VALUES (?,?,?)")->execute([$name, $phone, $balance]);
    $id = (int)$pdo->lastInsertId();
    if ($balance != 0) {
        $type = $balance > 0 ? 'credit' : 'payment';
        $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,NULL,?,?,?)")
            ->execute([$id, $type, abs($balance), 'Opening balance']);
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ─── Get customer ─────────────────────────────────────────────────────────────
if ($action === 'get_customer') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name, phone, balance FROM customers WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    echo $row ? json_encode($row) : json_encode(['error' => 'Not found']);
    exit;
}

// ─── Generate unique EAN-13 barcode ──────────────────────────────────────────
if ($action === 'generate_barcode') {
    echo json_encode(['barcode' => generateEAN13($pdo)]);
    exit;
}

// ─── Get batches for a product ────────────────────────────────────────────────
if ($action === 'get_batches') {
    $pid = (int)($_GET['product_id'] ?? 0);
    if (!$pid) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT b.id, b.cost_price, b.quantity_original, b.quantity_remaining,
               b.purchase_date, pu.reference,
               pi.batch_action,
               CASE WHEN b.quantity_remaining <= 0 THEN 'depleted' ELSE 'active' END AS status
        FROM batches b
        LEFT JOIN purchases pu ON pu.id = b.purchase_id
        LEFT JOIN purchase_items pi ON pi.batch_id = b.id
        WHERE b.product_id = ?
        GROUP BY b.id
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$pid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Get PO items ──────────────────────────────────────────────────────────────
if ($action === 'po_items') {
    $poId = (int)($_GET['po_id'] ?? 0);
    if (!$poId) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT poi.id, poi.po_id, poi.product_id, poi.product_name,
               poi.quantity, poi.unit, poi.estimated_price, poi.note, poi.new_product_upb, poi.new_product_source,
               p.name        AS resolved_name,
               p.sell_price,
               p.sell_price_box,
               p.cost_price  AS current_cost,
               p.product_type,
               p.units_per_box,
               p.unit        AS product_unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON p.id = poi.product_id
        WHERE poi.po_id = ?
        ORDER BY poi.id
    ");
    $stmt->execute([$poId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Get Purchase items ────────────────────────────────────────────────────────
if ($action === 'purchase_items') {
    $pid = (int)($_GET['id'] ?? 0);
    if (!$pid) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT product_name, product_type, quantity, unit_cost, total FROM purchase_items WHERE purchase_id=? ORDER BY id");
    $stmt->execute([$pid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Get purchase + items for editing ─────────────────────────────────────────
if ($action === 'get_purchase_for_edit') {
    if (!userCan('admin')) { echo json_encode(['error'=>'No permission']); exit; }
    $pid = (int)($_GET['id'] ?? 0);
    if (!$pid) { echo json_encode(['error'=>'Missing id']); exit; }
    $pur = $pdo->prepare("SELECT pu.*, s.name AS supplier_name FROM purchases pu LEFT JOIN suppliers s ON s.id=pu.supplier_id WHERE pu.id=?");
    $pur->execute([$pid]);
    $pur = $pur->fetch();
    if (!$pur) { echo json_encode(['error'=>'Purchase not found']); exit; }
    $items = $pdo->prepare("
        SELECT pi.*, b.quantity_remaining, b.quantity_original,
               (b.quantity_original - b.quantity_remaining) AS qty_consumed
        FROM purchase_items pi
        LEFT JOIN batches b ON b.id = pi.batch_id
        WHERE pi.purchase_id = ? ORDER BY pi.id
    ");
    $items->execute([$pid]);
    $pur['items'] = $items->fetchAll();
    echo json_encode($pur);
    exit;
}

// ── Edit an existing purchase (adjust cost/qty, reverse & reapply stock) ──────
if ($action === 'edit_purchase') {
    if (!userCan('admin')) { echo json_encode(['error'=>'No permission']); exit; }
    $purId    = (int)($_POST['purchase_id'] ?? 0);
    $itemsRaw = json_decode($_POST['items'] ?? '[]', true);
    if (!$purId || empty($itemsRaw)) { echo json_encode(['error'=>'Invalid data']); exit; }

    $pur = $pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $pur->execute([$purId]);
    $pur = $pur->fetch();
    if (!$pur) { echo json_encode(['error'=>'Purchase not found']); exit; }

    $origItems = $pdo->prepare("
        SELECT pi.*, b.quantity_remaining, b.quantity_original,
               (b.quantity_original - b.quantity_remaining) AS qty_consumed
        FROM purchase_items pi LEFT JOIN batches b ON b.id=pi.batch_id
        WHERE pi.purchase_id=?
    ");
    $origItems->execute([$purId]);
    $origItems = array_column($origItems->fetchAll(), null, 'id');

    $pdo->beginTransaction();
    try {
        $newTotal      = 0;
        $newTotalNocons = 0;

        foreach ($itemsRaw as $ni) {
            $itemId  = (int)($ni['id']   ?? 0);
            $newQty  = max(0, (float)($ni['qty']  ?? 0));
            $newCost = max(0, (float)($ni['cost'] ?? 0));
            if (!isset($origItems[$itemId])) continue;
            $orig = $origItems[$itemId];
            $oldQty  = (float)$orig['quantity'];
            $oldCost = (float)$orig['unit_cost'];
            $isBulk  = $orig['product_type'] === 'bulk';

            if ($isBulk) {
                $newLineTotal = $newCost;
                $newTotalNocons += $newLineTotal;
            } else {
                $newLineTotal = round($newQty * $newCost, 2);
                if ($orig['product_type'] === 'consignment') {
                    // Consignment: not charged to supplier balance
                } else {
                    $newTotalNocons += $newLineTotal;
                }

                // Adjust stock
                $qtyDiff = $newQty - $oldQty;
                if (abs($qtyDiff) > 0.001) {
                    // Validate: cannot reduce qty below consumed amount
                    $consumed = (float)($orig['qty_consumed'] ?? 0);
                    if ($newQty < $consumed) {
                        throw new Exception("Cannot reduce {$orig['product_name']} qty below consumed amount ($consumed already sold/used)");
                    }
                    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock + ?) WHERE id=?")->execute([$qtyDiff, $orig['product_id']]);
                    if ($orig['batch_id']) {
                        $pdo->prepare("UPDATE batches SET quantity_remaining = GREATEST(0, quantity_remaining + ?), quantity_original = GREATEST(0, quantity_original + ?) WHERE id=?")->execute([$qtyDiff, $qtyDiff, $orig['batch_id']]);
                    }
                }
                // Update batch cost_price if changed
                if (abs($newCost - $oldCost) > 0.00001 && $orig['batch_id']) {
                    $pdo->prepare("UPDATE batches SET cost_price=? WHERE id=?")->execute([$newCost, $orig['batch_id']]);
                }
            }

            $pdo->prepare("UPDATE purchase_items SET quantity=?, unit_cost=?, total=? WHERE id=?")->execute([$isBulk ? $oldQty : $newQty, $newCost, $newLineTotal, $itemId]);
            $newTotal += $newLineTotal;
        }

        $oldTotal   = (float)$pur['total_amount'];
        $totalDiff  = round($newTotalNocons - $oldTotal, 2);

        $pdo->prepare("UPDATE purchases SET total_amount=? WHERE id=?")->execute([$newTotal, $purId]);

        // Adjust financial records for non-consignment total change
        if (abs($totalDiff) > 0.001 && $pur['supplier_id']) {
            $payMethod = $pur['payment_method'];
            if ($payMethod === 'pay_later') {
                // Supplier balance needs adjustment
                $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$totalDiff, $pur['supplier_id']]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'adjustment',?,?)")
                    ->execute([$pur['supplier_id'], $totalDiff, "Purchase #{$purId} edited"]);
            } elseif ($payMethod === 'cash_register') {
                logCashEntry($pdo, 'adjustment', -$totalDiff, "Edit of Purchase #{$purId}");
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'adjustment',?,?)")
                    ->execute([$pur['supplier_id'], 0, "Purchase #{$purId} edited (cash adjusted)"]);
            } elseif ($payMethod === 'cash_register_lbp') {
                logCashEntry($pdo, 'adjustment', 0, "Edit of Purchase #{$purId}", null, round(-$totalDiff * EXCHANGE_RATE), 'LBP');
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'adjustment',?,?)")
                    ->execute([$pur['supplier_id'], 0, "Purchase #{$purId} edited (LBP cash adjusted)"]);
            } elseif ($payMethod === 'cash_owner') {
                logCashEntry($pdo, 'adjustment', -$totalDiff, "Edit of Purchase #{$purId} (owner cash)");
            }
        }

        $pdo->commit();
        echo json_encode(['ok'=>true, 'new_total'=>$newTotal, 'diff'=>$totalDiff]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Search products for purchase autocomplete ────────────────────────────────
if ($action === 'search_products_purchase') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.barcode, p.cost_price, p.sell_price, p.unit,
               p.product_type, p.product_source, p.consignment_cost, p.units_per_box, p.stock,
               c.name AS category_name, s.name AS supplier_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.barcode = ? OR p.name LIKE ?
        ORDER BY (p.barcode = ?) DESC, p.name ASC
        LIMIT 15
    ");
    $stmt->execute([$q, "%$q%", $q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Create category ───────────────────────────────────────────────────────────
if ($action === 'create_category') {
    if (!userCan('admin','stock')) { echo json_encode(['error'=>'No permission']); exit; }
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['error'=>'Name required']); exit; }
    $exists = $pdo->prepare("SELECT id FROM categories WHERE name=?");
    $exists->execute([$name]);
    if ($row = $exists->fetch()) {
        echo json_encode(['ok'=>true, 'id'=>$row['id'], 'name'=>$name, 'existed'=>true]);
    } else {
        $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
        echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'name'=>$name]);
    }
    exit;
}

// ── Create product (inline from purchase form) ────────────────────────────────
if ($action === 'create_product_quick') {
    if (!userCan('admin','stock')) { echo json_encode(['error'=>'No permission']); exit; }
    $name          = trim($_POST['name']        ?? '');
    $barcode       = trim($_POST['barcode']     ?? '') ?: null;
    $unit          = trim($_POST['unit']        ?? 'pcs');
    $categoryId    = (int)($_POST['category_id'] ?? 0) ?: null;
    $supplierId    = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $costUsd       = (float)($_POST['cost_usd'] ?? 0);
    $sellUsd       = (float)($_POST['sell_usd'] ?? 0);
    $unitsPerBox   = max(1, (int)($_POST['units_per_box'] ?? 1));
    $sellPriceBox  = ($_POST['sell_price_box'] ?? '') !== '' ? (float)$_POST['sell_price_box'] : null;
    $productType   = in_array($unit, ['kg','g','L','mL']) ? 'bulk' : 'regular';
    $productSource = ($_POST['product_source'] ?? '') === 'consignment' ? 'consignment' : 'owned';
    $consSuppId    = $productSource === 'consignment' ? ((int)($_POST['consignment_supplier_id'] ?? 0) ?: null) : null;
    $consCost      = $productSource === 'consignment' ? (float)($_POST['consignment_cost'] ?? 0) : 0;
    $stock         = max(0, (float)($_POST['stock'] ?? 0));
    $lowStockAlert = max(0, (float)($_POST['low_stock_alert'] ?? 5));
    if (!$name) { echo json_encode(['error'=>'Name required']); exit; }
    $pdo->prepare("INSERT INTO products (name, barcode, unit, category_id, supplier_id, cost_price, sell_price, units_per_box, sell_price_box, product_type, product_source, consignment_supplier_id, consignment_cost, stock, low_stock_alert) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$name, $barcode, $unit, $categoryId, $supplierId, $costUsd, $sellUsd, $unitsPerBox, $sellPriceBox, $productType, $productSource, $consSuppId, $consCost, $stock, $lowStockAlert]);
    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true, 'id'=>$newId, 'name'=>$name, 'cost_price'=>$costUsd, 'sell_price'=>$sellUsd, 'unit'=>$unit, 'units_per_box'=>$unitsPerBox, 'sell_price_box'=>$sellPriceBox, 'product_type'=>$productType, 'product_source'=>$productSource]);
    exit;
}

// ── Customer return ───────────────────────────────────────────────────────────
if ($action === 'process_customer_return') {
    if (!userCan('admin','cashier')) { echo json_encode(['error'=>'No permission']); exit; }
    $saleItemId   = (int)($_POST['sale_item_id'] ?? 0);
    $returnQty    = (float)($_POST['quantity'] ?? 0);
    $note         = trim($_POST['note'] ?? '');
    if (!$saleItemId || $returnQty <= 0) { echo json_encode(['error'=>'Invalid data']); exit; }

    $item = $pdo->prepare("
        SELECT si.*, s.customer_id, s.receipt_no,
               p.product_type, p.product_source
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.id = ?
    ");
    $item->execute([$saleItemId]);
    $item = $item->fetch();
    if (!$item) { echo json_encode(['error'=>'Sale item not found']); exit; }

    // Check not returning more than was sold
    $alreadyReturned = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM customer_returns WHERE sale_item_id=?");
    $alreadyReturned->execute([$saleItemId]);
    $alreadyReturned = (float)$alreadyReturned->fetchColumn();
    $maxReturn = (float)$item['quantity'] - $alreadyReturned;
    if ($returnQty > $maxReturn) { echo json_encode(['error'=>"Max returnable: $maxReturn"]); exit; }

    $refund = round($returnQty * (float)$item['unit_price'], 2);

    $pdo->beginTransaction();
    try {
        // Log return
        $pdo->prepare("INSERT INTO customer_returns (sale_id,sale_item_id,product_id,product_name,quantity,unit_price,refund_amount,note,return_date) VALUES (?,?,?,?,?,?,?,?,CURDATE())")
            ->execute([$item['sale_id'], $saleItemId, $item['product_id'], $item['product_name'], $returnQty, $item['unit_price'], $refund, $note]);

        // Restore stock
        if ($item['product_id']) {
            $pdo->prepare("UPDATE products SET stock=stock+? WHERE id=?")->execute([$returnQty, $item['product_id']]);

            // Restore batch for regular owned products
            if ($item['product_type']==='regular' && $item['product_source']==='owned') {
                // Find most recently depleted batch for this product to restore into
                $batch = $pdo->prepare("SELECT id FROM batches WHERE product_id=? ORDER BY created_at DESC LIMIT 1");
                $batch->execute([$item['product_id']]);
                $batchId = $batch->fetchColumn();
                if ($batchId) {
                    $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining+? WHERE id=?")->execute([$returnQty, $batchId]);
                }
            }
        }

        // Credit customer balance (positive = we owe them / reduces their debt)
        if ($item['customer_id']) {
            $pdo->prepare("UPDATE customers SET balance=balance+? WHERE id=?")->execute([$refund, $item['customer_id']]);
            $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,'refund',?,?)")
                ->execute([$item['customer_id'], $item['sale_id'], $refund, "Return from receipt {$item['receipt_no']}: {$item['product_name']}"]);
        }

        // Cash register — refund is money going out of the drawer
        logCashEntry($pdo, 'refund', -$refund, "Refund #{$item['receipt_no']}: {$item['product_name']} x$returnQty", $item['sale_id']);

        $pdo->commit();
        echo json_encode(['ok'=>true, 'refund'=>$refund]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Supplier return ───────────────────────────────────────────────────────────
if ($action === 'process_supplier_return') {
    if (!userCan('admin','stock')) { echo json_encode(['error'=>'No permission']); exit; }
    $batchId    = (int)($_POST['batch_id']  ?? 0);
    $returnQty  = (float)($_POST['quantity'] ?? 0);
    $note       = trim($_POST['note'] ?? '');
    if (!$batchId || $returnQty <= 0) { echo json_encode(['error'=>'Invalid data']); exit; }

    $batch = $pdo->prepare("
        SELECT b.*, p.name AS product_name, p.product_source,
               pu.supplier_id
        FROM batches b
        JOIN products p ON p.id = b.product_id
        LEFT JOIN purchases pu ON pu.id = b.purchase_id
        WHERE b.id = ?
    ");
    $batch->execute([$batchId]);
    $batch = $batch->fetch();
    if (!$batch) { echo json_encode(['error'=>'Batch not found']); exit; }
    if ($returnQty > (float)$batch['quantity_remaining']) {
        echo json_encode(['error'=>'Cannot return more than remaining: '.$batch['quantity_remaining']]); exit;
    }

    $credit       = round($returnQty * (float)$batch['cost_price'], 2);
    $refundMethod = ($_POST['refund_method'] ?? 'credit') === 'cash' ? 'cash' : 'credit';
    // Prefer supplier_id from the JOIN; fall back to what JS passed (batch search already resolved it)
    $supplierId = (int)($batch['supplier_id'] ?: ($_POST['supplier_id'] ?? 0));

    $pdo->beginTransaction();
    try {
        // Log return
        $pdo->prepare("INSERT INTO supplier_returns (batch_id,product_id,product_name,supplier_id,quantity,unit_cost,credit_amount,note,return_date) VALUES (?,?,?,?,?,?,?,?,CURDATE())")
            ->execute([$batchId, $batch['product_id'], $batch['product_name'], $supplierId, $returnQty, $batch['cost_price'], $credit, $note]);

        // Reduce batch remaining
        $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining-? WHERE id=?")->execute([$returnQty, $batchId]);

        // Reduce product stock
        $pdo->prepare("UPDATE products SET stock=GREATEST(0,stock-?) WHERE id=?")->execute([$returnQty, $batch['product_id']]);

        if ($supplierId) {
            if ($refundMethod === 'cash') {
                // Supplier physically gives cash — deposit to register, no balance change
                logCashEntry($pdo, 'deposit', $credit, "Supplier cash refund: {$batch['product_name']} x$returnQty", null);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'return',?,?)")
                    ->execute([$supplierId, 0, "Return (cash refund): {$batch['product_name']} x$returnQty — \${$credit} received in cash"]);
            } else {
                // Credit: reduce balance owed to supplier
                $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$credit, $supplierId]);
                $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'return',?,?)")
                    ->execute([$supplierId, -$credit, "Supplier return: {$batch['product_name']} x$returnQty"]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok'=>true, 'credit'=>$credit, 'refund_method'=>$refundMethod]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Get sale items for a receipt (for returns) ────────────────────────────────
if ($action === 'sale_items_for_return') {
    $saleId = (int)($_GET['sale_id'] ?? 0);
    if (!$saleId) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT si.id, si.product_id, si.product_name, si.quantity, si.unit_price, si.total,
               COALESCE((SELECT SUM(r.quantity) FROM customer_returns r WHERE r.sale_item_id=si.id),0) AS already_returned
        FROM sale_items si WHERE si.sale_id=?
    ");
    $stmt->execute([$saleId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Get batches for supplier return ──────────────────────────────────────────
if ($action === 'batches_for_supplier_return') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT b.id, b.cost_price, b.quantity_original, b.quantity_remaining, b.purchase_date,
               p.name AS product_name, p.id AS product_id,
               s.name AS supplier_name, s.id AS supplier_id,
               pu.reference
        FROM batches b
        JOIN products p ON p.id = b.product_id
        LEFT JOIN purchases pu ON pu.id = b.purchase_id
        LEFT JOIN suppliers s ON s.id = pu.supplier_id
        WHERE b.quantity_remaining > 0
          AND (p.name LIKE ? OR p.barcode = ? OR pu.reference LIKE ?)
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    $stmt->execute(["%$q%", $q, "%$q%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Customer sales list (for returns search) ─────────────────────────────────
if ($action === 'customer_sales') {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT id, receipt_no, sale_date, total, status
        FROM sales
        WHERE customer_id=? AND status='completed'
        ORDER BY sale_date DESC, id DESC
        LIMIT 50
    ");
    $stmt->execute([$customerId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Get sale items by receipt_no (for returns page) ───────────────────────────
if ($action === 'sale_items_for_return_by_receipt') {
    $receiptNo = trim($_GET['receipt_no'] ?? '');
    if (!$receiptNo) { echo json_encode([]); exit; }
    $sale = $pdo->prepare("SELECT id FROM sales WHERE receipt_no=? LIMIT 1");
    $sale->execute([$receiptNo]);
    $row = $sale->fetch();
    if (!$row) { echo json_encode([]); exit; }
    $saleId = (int)$row['id'];
    $stmt = $pdo->prepare("
        SELECT si.id, si.product_id, si.product_name, si.quantity, si.unit_price, si.total,
               COALESCE((SELECT SUM(r.quantity) FROM customer_returns r WHERE r.sale_item_id=si.id),0) AS already_returned
        FROM sale_items si WHERE si.sale_id=?
    ");
    $stmt->execute([$saleId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Settle owner cash deposit (withdraw from register) ───────────────────────
if ($action === 'settle_owner_cash') {
    $depositId = (int)($_POST['deposit_id'] ?? 0);
    if (!$depositId) { echo json_encode(['ok'=>false,'error'=>'Missing deposit_id']); exit; }
    $dep = $pdo->prepare("SELECT * FROM cash_register_log WHERE id=? AND type='deposit' AND (settled_by IS NULL OR settled_by=0)");
    $dep->execute([$depositId]);
    $dep = $dep->fetch();
    if (!$dep) { echo json_encode(['ok'=>false,'error'=>'Deposit not found or already settled']); exit; }
    // Create matching withdrawal
    $note = 'Owner cash withdrawn — deposit #' . $depositId . ': ' . $dep['note'];
    logCashEntry($pdo, 'withdrawal', -(float)$dep['amount_usd'], $note, null, -(float)($dep['amount_lbp']??0), $dep['currency']??'USD');
    $newId = (int)$pdo->lastInsertId();
    // Mark original deposit as settled
    $pdo->prepare("UPDATE cash_register_log SET settled_by=? WHERE id=?")->execute([$newId, $depositId]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Get sale items for editing ────────────────────────────────────────────────
if ($action === 'get_sale_for_edit') {
    if (!userCan('admin')) { echo json_encode(['error'=>'No permission']); exit; }
    $saleId = (int)($_GET['sale_id'] ?? 0);
    if (!$saleId) { echo json_encode(['error' => 'Missing sale_id']); exit; }
    $stmt = $pdo->prepare("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=? AND s.is_void=0");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) { echo json_encode(['error' => 'Sale not found or already voided']); exit; }
    $si = $pdo->prepare("SELECT id, product_id, product_name, product_type, is_consignment, quantity, unit_price, unit_cost, total FROM sale_items WHERE sale_id=?");
    $si->execute([$saleId]);
    $sale['items'] = $si->fetchAll();
    echo json_encode($sale);
    exit;
}

// ── Edit a sale (adjust items, totals, stock, cash register) ──────────────────
if ($action === 'edit_sale') {
    if (!userCan('admin')) { echo json_encode(['error'=>'No permission']); exit; }
    $saleId   = (int)($_POST['sale_id']   ?? 0);
    $itemsRaw = json_decode($_POST['items'] ?? '[]', true);
    $editNote = trim($_POST['note'] ?? '');
    if (!$saleId || empty($itemsRaw)) { echo json_encode(['error'=>'Invalid data']); exit; }

    $sale = $pdo->prepare("SELECT * FROM sales WHERE id=? AND is_void=0");
    $sale->execute([$saleId]);
    $sale = $sale->fetch();
    if (!$sale) { echo json_encode(['error'=>'Sale not found or voided']); exit; }

    $origItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
    $origItems->execute([$saleId]);
    $origItems = array_column($origItems->fetchAll(), null, 'id');

    // Collect IDs from payload — any orig item NOT in payload gets deleted (removed by user)
    $payloadIds = array_map(fn($ni) => (int)($ni['id'] ?? 0), array_filter($itemsRaw, fn($ni) => !($ni['_new'] ?? false)));

    $pdo->beginTransaction();
    try {
        $newSubtotal = 0;

        // ── Delete removed items (restore their stock) ──────────────────────────
        foreach ($origItems as $origId => $orig) {
            if (in_array($origId, $payloadIds)) continue;
            $oldQty = (float)$orig['quantity'];
            if ($orig['product_type'] !== 'bulk' && $oldQty > 0) {
                $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id=?")->execute([$oldQty, $orig['product_id']]);
                if (!$orig['is_consignment']) {
                    $b = $pdo->prepare("SELECT id, quantity_original FROM batches WHERE product_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
                    $b->execute([$orig['product_id']]);
                    $b = $b->fetch();
                    if ($b) {
                        $pdo->prepare("UPDATE batches SET quantity_remaining = LEAST(quantity_original, quantity_remaining + ?) WHERE id=?")->execute([$oldQty, $b['id']]);
                    }
                }
            }
            $pdo->prepare("DELETE FROM sale_items WHERE id=?")->execute([$origId]);
        }

        // ── Update existing + insert new items ──────────────────────────────────
        foreach ($itemsRaw as $ni) {
            $isNew    = !empty($ni['_new']);
            $newQty   = max(0, (float)($ni['qty']   ?? 0));
            $newPrice = max(0, (float)($ni['price'] ?? 0));
            $newItemTotal = round($newQty * $newPrice, 2);
            $newSubtotal += $newItemTotal;

            if ($isNew) {
                // Insert new sale_item
                $newPid = (int)($ni['product_id'] ?? 0);
                if (!$newPid || $newQty <= 0) continue;
                $prod = $pdo->prepare("SELECT name, product_type, cost_price, product_source, consignment_supplier_id FROM products WHERE id=?");
                $prod->execute([$newPid]);
                $prod = $prod->fetch();
                if (!$prod) continue;
                $isCons   = ($prod['product_source'] ?? '') === 'consignment';
                $unitCost = 0;
                if (!$isCons && $prod['product_type'] !== 'bulk') {
                    // Deduct stock FIFO
                    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$newQty, $newPid]);
                    $b = $pdo->prepare("SELECT id, quantity_remaining, cost_price FROM batches WHERE product_id=? AND quantity_remaining > 0 ORDER BY created_at ASC, id ASC LIMIT 1");
                    $b->execute([$newPid]);
                    $b = $b->fetch();
                    if ($b) {
                        $unitCost = (float)($b['cost_price'] ?? 0);
                        $pdo->prepare("UPDATE batches SET quantity_remaining = GREATEST(0, quantity_remaining - ?) WHERE id=?")->execute([min($newQty, (float)$b['quantity_remaining']), $b['id']]);
                    }
                } elseif ($isCons) {
                    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$newQty, $newPid]);
                }
                $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, product_type, is_consignment, quantity, unit_price, unit_cost, total) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$saleId, $newPid, $prod['name'], $prod['product_type'], $isCons?1:0, $newQty, $newPrice, $unitCost, $newItemTotal]);
                continue;
            }

            $itemId = (int)($ni['id'] ?? 0);
            if (!isset($origItems[$itemId])) continue;
            $orig    = $origItems[$itemId];
            $oldQty  = (float)$orig['quantity'];
            $qtyDiff = $newQty - $oldQty;

            if (abs($qtyDiff) > 0.001 && $orig['product_type'] !== 'bulk') {
                $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$qtyDiff, $orig['product_id']]);
                if (!$orig['is_consignment']) {
                    if ($qtyDiff < 0) {
                        $b = $pdo->prepare("SELECT id, quantity_original FROM batches WHERE product_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
                        $b->execute([$orig['product_id']]);
                        $b = $b->fetch();
                        if ($b) {
                            $pdo->prepare("UPDATE batches SET quantity_remaining = LEAST(quantity_original, quantity_remaining + ?) WHERE id=?")->execute([abs($qtyDiff), $b['id']]);
                        }
                    } else {
                        $b = $pdo->prepare("SELECT id, quantity_remaining FROM batches WHERE product_id=? AND quantity_remaining > 0 ORDER BY created_at ASC, id ASC LIMIT 1");
                        $b->execute([$orig['product_id']]);
                        $b = $b->fetch();
                        if ($b) {
                            $pdo->prepare("UPDATE batches SET quantity_remaining = GREATEST(0, quantity_remaining - ?) WHERE id=?")->execute([min($qtyDiff, (float)$b['quantity_remaining']), $b['id']]);
                        }
                    }
                }
            }
            $pdo->prepare("UPDATE sale_items SET quantity=?, unit_price=?, total=? WHERE id=?")->execute([$newQty, $newPrice, $newItemTotal, $itemId]);
        }

        $oldTotal   = (float)$sale['total'];
        $newTotal   = max(0, round($newSubtotal - (float)$sale['discount'] - (float)$sale['credit_used'], 2));
        $totalDiff  = round($newTotal - $oldTotal, 2);
        $newNote    = ($sale['note'] ? $sale['note'] . ' | ' : '') . 'Edited' . ($editNote ? ': ' . $editNote : '');
        $pdo->prepare("UPDATE sales SET subtotal=?, total=?, note=? WHERE id=?")->execute([$newSubtotal, $newTotal, $newNote, $saleId]);

        if (abs($totalDiff) > 0.001) {
            $regNote = 'Edit of sale #' . $sale['receipt_no'] . ($editNote ? ': ' . $editNote : '');
            logCashEntry($pdo, 'adjustment', $totalDiff, $regNote, $saleId);
            if ($sale['customer_id']) {
                $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id=?")->execute([$totalDiff, $sale['customer_id']]);
                $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,'adjustment',?,?)")
                    ->execute([$sale['customer_id'], $saleId, $totalDiff, 'Sale edit: #' . $sale['receipt_no']]);
            }
        }
        $pdo->commit();
        echo json_encode(['ok'=>true, 'new_total'=>$newTotal, 'diff'=>$totalDiff]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ── Full sale receipt (for printing) ─────────────────────────────────────────
if ($action === 'sale_receipt') {
    $saleId = (int)($_GET['sale_id'] ?? 0);
    if (!$saleId) { echo json_encode(['error' => 'Missing sale_id']); exit; }
    $stmt = $pdo->prepare("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) { echo json_encode(['error' => 'Sale not found']); exit; }
    $si = $pdo->prepare("SELECT product_name, quantity, unit_price, total FROM sale_items WHERE sale_id=?");
    $si->execute([$saleId]);
    $sale['items'] = $si->fetchAll();
    $sale['store_name']    = setting('store_name', 'Market');
    $sale['store_address'] = setting('store_address', '');
    $sale['store_phone']   = setting('store_phone', '');
    $ds = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE sale_id=? AND note LIKE 'Debt settlement%'");
    $ds->execute([$saleId]);
    $sale['debt_settled'] = (float)$ds->fetchColumn();
    echo json_encode($sale);
    exit;
}

// ─── Report analysis — filter by product / category / supplier ───────────────
if ($action === 'report_analysis') {
    $from       = $_GET['from']        ?? date('Y-m-01');
    $to         = $_GET['to']          ?? date('Y-m-d');
    $productId  = (int)($_GET['product_id']  ?? 0);
    $categoryId = (int)($_GET['category_id'] ?? 0);
    $supplierId = (int)($_GET['supplier_id'] ?? 0);

    // ── Sales side ────────────────────────────────────────────────────────────
    $sWhere = ['DATE(s.sale_date) BETWEEN ? AND ?', 's.is_void = 0'];
    $sParams = [$from, $to];
    if ($productId)  { $sWhere[] = 'si.product_id = ?';       $sParams[] = $productId; }
    if ($categoryId) { $sWhere[] = 'p.category_id = ?';       $sParams[] = $categoryId; }
    if ($supplierId) { $sWhere[] = 'p.supplier_id = ?';       $sParams[] = $supplierId; }
    $sJoin = 'JOIN products p ON p.id = si.product_id';
    $salesSQL = "
        SELECT SUM(si.quantity) AS units_sold,
               SUM(si.total) AS revenue,
               SUM(si.quantity * si.unit_cost) AS cogs
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        $sJoin
        WHERE " . implode(' AND ', $sWhere);
    $salesStmt = $pdo->prepare($salesSQL);
    $salesStmt->execute($sParams);
    $salesRow = $salesStmt->fetch();

    // ── Top sold products in filter ───────────────────────────────────────────
    $topSQL = "
        SELECT p.name, SUM(si.quantity) AS units, SUM(si.total) AS revenue
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        $sJoin
        WHERE " . implode(' AND ', $sWhere) . "
        GROUP BY si.product_id ORDER BY revenue DESC LIMIT 10";
    $topStmt = $pdo->prepare($topSQL);
    $topStmt->execute($sParams);
    $topProducts = $topStmt->fetchAll();

    // ── Purchases side ────────────────────────────────────────────────────────
    $pWhere = ['pu.purchase_date BETWEEN ? AND ?'];
    $pParams = [$from, $to];
    if ($productId)  { $pWhere[] = 'pi.product_id = ?';    $pParams[] = $productId; }
    if ($categoryId) { $pWhere[] = 'p.category_id = ?';    $pParams[] = $categoryId; }
    if ($supplierId) { $pWhere[] = 'pu.supplier_id = ?';   $pParams[] = $supplierId; }
    $pJoinProd = ($productId || $categoryId) ? 'JOIN products p ON p.id = pi.product_id' : 'LEFT JOIN products p ON p.id = pi.product_id';
    $purchSQL = "
        SELECT SUM(pi.quantity) AS units_purchased,
               SUM(pi.total) AS purchase_cost
        FROM purchase_items pi
        JOIN purchases pu ON pu.id = pi.purchase_id
        $pJoinProd
        WHERE " . implode(' AND ', $pWhere);
    $purchStmt = $pdo->prepare($purchSQL);
    $purchStmt->execute($pParams);
    $purchRow = $purchStmt->fetch();

    echo json_encode([
        'units_sold'       => (float)($salesRow['units_sold'] ?? 0),
        'revenue'          => (float)($salesRow['revenue']    ?? 0),
        'cogs'             => (float)($salesRow['cogs']       ?? 0),
        'units_purchased'  => (float)($purchRow['units_purchased'] ?? 0),
        'purchase_cost'    => (float)($purchRow['purchase_cost']   ?? 0),
        'top_products'     => $topProducts,
    ]);
    exit;
}

// ── print_receipt: spawn PowerShell WebBrowser.Print() — no dialog, no PDF ──
if ($action === 'print_receipt') {
    ob_start(); // capture any PHP warnings so they don't break the JSON response

    $html = $_POST['html'] ?? '';
    if (!$html) { ob_end_clean(); echo json_encode(['ok' => false, 'error' => 'No HTML']); exit; }

    $base     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_rcpt_' . time() . '_' . rand(1000, 9999);
    $htmlFile = $base . '.html';
    $psFile   = $base . '.ps1';

    // Inline any relative images as base64 so the temp file is self-contained
    $html = preg_replace_callback(
        '/src="(\/[^"]+\.(png|jpg|jpeg|gif|webp|svg))"/i',
        function ($m) {
            $file = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $m[1]);
            if (file_exists($file)) {
                $mime = mime_content_type($file) ?: 'image/png';
                return 'src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($file)) . '"';
            }
            return $m[0];
        },
        $html
    );

    // Add bottom margin so paper advances past the cutter after printing
    $cutterCss = '@page{margin-bottom:35mm}body::after{content:"";display:block;height:35mm}';
    $html = str_replace('</style>', $cutterCss . '</style>', $html);

    file_put_contents($htmlFile, $html);

    $hPs = str_replace('"', '`"', str_replace('\\', '/', $htmlFile));
    $sPs = str_replace('"', '`"', str_replace('\\', '/', $psFile));

    $ps = <<<PS
Add-Type -AssemblyName System.Windows.Forms
\$script:f    = "$hPs"
\$script:s    = "$sPs"
\$script:done = \$false
\$script:frm  = New-Object System.Windows.Forms.Form
\$script:frm.ShowInTaskbar = \$false
\$script:frm.Opacity = 0
\$script:frm.WindowState = "Minimized"
\$script:wb = New-Object System.Windows.Forms.WebBrowser
\$script:wb.Dock = "Fill"
\$script:frm.Controls.Add(\$script:wb)
\$script:wb.Add_DocumentCompleted({
    if (-not \$script:done) {
        \$script:done = \$true
        Start-Sleep -Milliseconds 400
        \$script:wb.Print()
        Start-Sleep -Seconds 3
        Remove-Item \$script:f -ErrorAction SilentlyContinue
        Remove-Item \$script:s -ErrorAction SilentlyContinue
        \$script:frm.Close()
    }
})
\$script:frm.Add_Shown({
    # Clear IE print header/footer so URL, date, page number don't appear on receipt
    \$rk = "HKCU:\Software\Microsoft\Internet Explorer\PageSetup"
    if (Test-Path \$rk) {
        Set-ItemProperty -Path \$rk -Name "header" -Value "" -ErrorAction SilentlyContinue
        Set-ItemProperty -Path \$rk -Name "footer" -Value "" -ErrorAction SilentlyContinue
    }
    \$script:wb.Navigate("file:///\$script:f")
})
[System.Windows.Forms.Application]::Run(\$script:frm)
PS;

    file_put_contents($psFile, $ps);

    // Try multiple exec methods — popen/exec may be restricted in some php.ini configs
    $psExe  = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $psArgs = ' -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File "' . $psFile . '"';
    $cmd    = 'start "" /b "' . $psExe . '"' . $psArgs;

    $launched = false;
    $disabled = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));

    if (!$launched && !in_array('popen', $disabled)) {
        $h = @popen($cmd, 'r');
        if ($h !== false) { @pclose($h); $launched = true; }
    }
    if (!$launched && !in_array('exec', $disabled)) {
        @exec($cmd, $out, $ret);
        $launched = true;
    }
    if (!$launched && !in_array('shell_exec', $disabled)) {
        @shell_exec($cmd);
        $launched = true;
    }

    ob_end_clean();

    if (!$launched) {
        echo json_encode(['ok' => false, 'error' => 'exec_disabled']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
