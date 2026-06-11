<?php

// ─── Formatting ──────────────────────────────────────────────────────────────

function fmtUSD($v) { return '$' . number_format((float)$v, 2); }
function fmtLBP($v) { return number_format((float)$v, 0) . ' LBP'; }
function fmt($v) { return fmtUSD($v); }
function fmtBoth($usd, $rate = null) {
    $rate = $rate ?? EXCHANGE_RATE;
    return fmtUSD($usd) . ' / ' . fmtLBP($usd * $rate);
}
function pct($val, $base) { return $base > 0 ? round(($val / $base) * 100, 1) : 0; }

// ─── Receipt number ──────────────────────────────────────────────────────────

function generateReceiptNo() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

// ─── Stock deduction (FIFO) ──────────────────────────────────────────────────
// Returns the FIFO average cost per unit

function deductStockFIFO($pdo, $productId, $qty) {
    if ($qty <= 0) return 0;

    $batches = $pdo->prepare("
        SELECT id, cost_price, quantity_remaining
        FROM batches
        WHERE product_id = ? AND quantity_remaining > 0
        ORDER BY created_at ASC, id ASC
    ");
    $batches->execute([$productId]);
    $rows = $batches->fetchAll();

    $remaining  = $qty;
    $totalCost  = 0;

    foreach ($rows as $batch) {
        if ($remaining <= 0) break;
        $take = min((float)$remaining, (float)$batch['quantity_remaining']);
        $totalCost += $take * $batch['cost_price'];
        $pdo->prepare("UPDATE batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?")
            ->execute([$take, $batch['id']]);
        $remaining -= $take;
    }

    // Update product stock field (convenience cache)
    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?")
        ->execute([$qty, $productId]);

    return $qty > 0 ? $totalCost / $qty : 0;
}

// ─── Batch lookup (for purchase UI) ─────────────────────────────────────────

function findExistingBatch($pdo, $productId, $costPrice) {
    $stmt = $pdo->prepare("
        SELECT id, quantity_remaining
        FROM batches
        WHERE product_id = ? AND cost_price = ? AND quantity_remaining > 0
        ORDER BY created_at ASC LIMIT 1
    ");
    $stmt->execute([$productId, $costPrice]);
    return $stmt->fetch();
}

// ─── Cash register (dual-currency: USD + LBP drawers) ────────────────────────

function getCashBalance($pdo): float {
    return (float)$pdo->query("SELECT COALESCE(SUM(amount_usd),0) FROM cash_register_log")->fetchColumn();
}

function getCashBalanceLBP($pdo): float {
    return (float)$pdo->query("SELECT COALESCE(SUM(amount_lbp),0) FROM cash_register_log")->fetchColumn();
}

// Log a cash entry. Pass amounts in the actual currency received/paid.
// $amountUSD: signed USD change (positive=in, negative=out)
// $amountLBP: signed LBP change
// $currency: 'USD', 'LBP', or 'BOTH'
function logCashEntry($pdo, $type, $amountUSD, $note = '', $saleId = null, $amountLBP = 0, $currency = 'USD') {
    $balUSD = getCashBalance($pdo)    + $amountUSD;
    $balLBP = getCashBalanceLBP($pdo) + $amountLBP;
    $pdo->prepare("INSERT INTO cash_register_log (type, currency, amount_usd, amount_lbp, note, sale_id, balance_after_usd, balance_after_lbp) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$type, $currency, $amountUSD, $amountLBP, $note, $saleId, $balUSD, $balLBP]);
    return $balUSD;
}

// ─── Stats (P&L) ─────────────────────────────────────────────────────────────

function getStats($pdo, $from = null, $to = null) {
    $from = $from ?? date('Y-m-d');
    $to   = $to   ?? date('Y-m-d');

    // Revenue + tx count — exclude voided sales
    $r = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS tx_count FROM sales WHERE DATE(sale_date) BETWEEN ? AND ? AND is_void=0");
    $r->execute([$from, $to]);
    $r = $r->fetch();

    // COGS: only owned (non-consignment) items from non-voided sales
    $c = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0) AS cogs
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE DATE(s.sale_date) BETWEEN ? AND ?
          AND si.is_consignment = 0
          AND s.is_void = 0
    ");
    $c->execute([$from, $to]);
    $cogs = (float)$c->fetchColumn();

    $exp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $exp->execute([$from, $to]);
    $expenses = (float)$exp->fetchColumn();

    $revenue = (float)$r['revenue'];
    $gross   = $revenue - $cogs;
    $net     = $gross - $expenses;

    return [
        'revenue'   => $revenue,
        'cogs'      => $cogs,
        'gross'     => $gross,
        'net'       => $net,
        'expenses'  => $expenses,
        'tx_count'  => (int)$r['tx_count'],
        'margin'    => pct($gross, $revenue),
    ];
}

// ─── Barcode generation (EAN-13, internal prefix 200) ───────────────────────

function generateEAN13($pdo) {
    do {
        $body   = '200' . str_pad(mt_rand(1, 999999999), 9, '0', STR_PAD_LEFT);
        $sum    = 0;
        for ($i = 0; $i < 12; $i++) $sum += ($i % 2 === 0 ? 1 : 3) * (int)$body[$i];
        $barcode = $body . ((10 - ($sum % 10)) % 10);
        $exists  = $pdo->prepare("SELECT id FROM products WHERE barcode = ?");
        $exists->execute([$barcode]);
    } while ($exists->fetchColumn());
    return $barcode;
}

function getLowStock($pdo, $limit = 10) {
    return $pdo->query("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.product_type = 'regular' AND p.stock <= p.low_stock_alert
        ORDER BY p.stock ASC
        LIMIT $limit
    ")->fetchAll();
}
