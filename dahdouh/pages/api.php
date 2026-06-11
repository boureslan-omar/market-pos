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
               poi.quantity, poi.unit, poi.estimated_price, poi.note, poi.new_product_upb,
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

echo json_encode(['error' => 'Unknown action']);
