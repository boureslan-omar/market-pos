<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['error'=>'Not authenticated']); exit; }

$action = $_GET['action'] ?? '';

// ─── Search product (barcode or name) ─────────────────────────────────────────
if ($action === 'search_product') {
    $q = trim($_GET['q'] ?? '');
    $stmt = $pdo->prepare("
        SELECT id, name, sell_price, cost_price, stock, barcode, product_type,
               product_source, consignment_cost, consignment_supplier_id
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
    $name       = trim($_POST['name']        ?? '');
    $barcode    = trim($_POST['barcode']     ?? '') ?: null;
    $unit       = trim($_POST['unit']        ?? 'pcs');
    $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $costUsd    = (float)($_POST['cost_usd'] ?? 0);
    $sellUsd    = (float)($_POST['sell_usd'] ?? 0);
    if (!$name) { echo json_encode(['error'=>'Name required']); exit; }
    $pdo->prepare("INSERT INTO products (name, barcode, unit, category_id, supplier_id, cost_price, sell_price, stock) VALUES (?,?,?,?,?,?,?,0)")
        ->execute([$name, $barcode, $unit, $categoryId, $supplierId, $costUsd, $sellUsd]);
    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true, 'id'=>$newId, 'name'=>$name, 'cost_price'=>$costUsd, 'sell_price'=>$sellUsd, 'unit'=>$unit]);
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

    $credit = round($returnQty * (float)$batch['cost_price'], 2);
    $supplierId = (int)$batch['supplier_id'];

    $pdo->beginTransaction();
    try {
        // Log return
        $pdo->prepare("INSERT INTO supplier_returns (batch_id,product_id,product_name,supplier_id,quantity,unit_cost,credit_amount,note,return_date) VALUES (?,?,?,?,?,?,?,?,CURDATE())")
            ->execute([$batchId, $batch['product_id'], $batch['product_name'], $supplierId, $returnQty, $batch['cost_price'], $credit, $note]);

        // Reduce batch remaining
        $pdo->prepare("UPDATE batches SET quantity_remaining=quantity_remaining-? WHERE id=?")->execute([$returnQty, $batchId]);

        // Reduce product stock
        $pdo->prepare("UPDATE products SET stock=GREATEST(0,stock-?) WHERE id=?")->execute([$returnQty, $batch['product_id']]);

        // Credit supplier (reduce balance owed; can go negative = they owe us)
        if ($supplierId) {
            $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id=?")->execute([$credit, $supplierId]);
            $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, type, amount, note) VALUES (?,'return',?,?)")
                ->execute([$supplierId, -$credit, "Supplier return: {$batch['product_name']} x$returnQty"]);
        }

        $pdo->commit();
        echo json_encode(['ok'=>true, 'credit'=>$credit]);
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

echo json_encode(['error' => 'Unknown action']);
