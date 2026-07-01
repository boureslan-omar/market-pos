<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
// Fallback constants for installs with older config.php
defined('CUSTOMER_DISPLAY') || define('CUSTOMER_DISPLAY', false);
defined('CASH_DRAWER')       || define('CASH_DRAWER',       false);
defined('VFD_ENABLED')       || define('VFD_ENABLED',       false);
defined('VFD_COM_PORT')      || define('VFD_COM_PORT',      'COM3');
requireRole('admin','cashier');

$message       = '';
$lastSale      = null;
$lastCustomer  = null;

// ─── Process sale ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cartData   = json_decode($_POST['cart_json'] ?? '{}', true) ?: [];
    $discount   = (float)($_POST['discount'] ?? 0);
    $creditUse  = (float)($_POST['credit_use'] ?? 0);
    $paidUSD     = (float)($_POST['paid_usd'] ?? 0);
    $paidLBP     = (float)($_POST['paid_lbp'] ?? 0);
    $method      = $_POST['payment_method'] ?? 'cash';
    $note        = trim($_POST['note'] ?? '');
    $customerId  = (int)($_POST['customer_id'] ?? 0) ?: null;
    $debtPayment = max(0, (float)($_POST['debt_payment'] ?? 0));
    $rate        = EXCHANGE_RATE;

    if (empty($cartData)) {
        $message = 'error:Cart is empty.';
    } else {
        // Calculate totals
        $subtotal = 0;
        foreach ($cartData as $item) {
            $subtotal += (float)$item['price'] * (float)$item['qty'];
        }
        $total      = max(0, $subtotal - $discount - $creditUse);
        $changeCur  = $_POST['change_currency'] ?? 'LBP';
        $totalGiven = $paidUSD + ($paidLBP / $rate);
        $changeAmt  = max(0, $totalGiven - $total - $debtPayment);
        $changeUSD  = $changeCur === 'USD' ? round($changeAmt, 2) : 0;
        $changeLBP  = $changeCur === 'LBP' ? round($changeAmt * $rate) : 0;
        $payCur     = ($paidUSD > 0 && $paidLBP > 0) ? 'BOTH' : ($paidLBP > 0 ? 'LBP' : 'USD');

        $pdo->beginTransaction();
        try {
            $receipt = generateReceiptNo();
            $pdo->prepare("INSERT INTO sales (receipt_no, customer_id, subtotal, discount, credit_used, total, paid_usd, paid_lbp, change_usd, change_lbp, currency_paid, exchange_rate_used, payment_method, note)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$receipt, $customerId, $subtotal, $discount, $creditUse, $total, $paidUSD, $paidLBP, $changeUSD, $changeLBP, $payCur, $rate, $method, $note]);
            $saleId = $pdo->lastInsertId();

            $ins     = $pdo->prepare("INSERT INTO sale_items (sale_id,product_id,product_name,product_type,is_consignment,quantity,unit_price,unit_cost,total) VALUES (?,?,?,?,?,?,?,?,?)");
            $insLedg = $pdo->prepare("INSERT INTO consignment_ledger (sale_id,product_id,supplier_id,quantity,sell_price,consignment_cost,revenue,supplier_due,market_profit) VALUES (?,?,?,?,?,?,?,?,?)");

            foreach ($cartData as $item) {
                $pid   = (int)($item['product_id'] ?? 0);
                $qty   = (float)$item['qty'];
                $price = (float)$item['price'];
                $ptype = $item['type'] ?? 'regular';

                // Check if this product is consignment
                $prow = $pdo->prepare("SELECT product_source, consignment_cost, consignment_supplier_id FROM products WHERE id=?");
                $prow->execute([$pid]);
                $prow = $prow->fetch();
                $isCons = ($prow && $prow['product_source'] === 'consignment') ? 1 : 0;

                if ($isCons) {
                    $consCost = (float)$prow['consignment_cost'];
                    $consSup  = (int)$prow['consignment_supplier_id'];
                    $revenue  = round($qty * $price, 2);
                    $supDue   = round($qty * $consCost, 2);
                    $mktCut   = round($revenue - $supDue, 2);
                    // Deduct consignment stock (no FIFO batches)
                    $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$qty, $pid]);
                    $ins->execute([$saleId, $pid, $item['name'], $ptype, 1, $qty, $price, $consCost, $revenue]);
                    $insLedg->execute([$saleId, $pid, $consSup, $qty, $price, $consCost, $revenue, $supDue, $mktCut]);
                } elseif ($ptype === 'bulk') {
                    $ins->execute([$saleId, $pid, $item['name'], 'bulk', 0, $qty, $price, 0, $qty * $price]);
                } else {
                    $unitCost = deductStockFIFO($pdo, $pid, $qty);
                    $ins->execute([$saleId, $pid, $item['name'], 'regular', 0, $qty, $price, $unitCost, $qty * $price]);
                }
            }

            if ($customerId) {
                $netCashPaid  = $totalGiven - $changeAmt;
                $netBalChange = $total + $creditUse - $netCashPaid;
                if (abs($netBalChange) > 0.001) {
                    $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id=?")->execute([$netBalChange, $customerId]);
                }
                $ledgerNote = "Sale #$receipt — Total: " . fmtUSD($total);
                $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,?,?,?)")
                    ->execute([$customerId, $saleId, 'sale', -$total, $ledgerNote]);
                if ($creditUse > 0) {
                    $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,?,?,?)")
                        ->execute([$customerId, $saleId, 'payment', $creditUse, "Store credit applied — #$receipt"]);
                }
                $cashForSale = $netCashPaid - $debtPayment;
                if ($cashForSale > 0.001) {
                    $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,?,?,?)")
                        ->execute([$customerId, $saleId, 'payment', $cashForSale, "Payment for #$receipt"]);
                }
                if ($debtPayment > 0.001) {
                    $pdo->prepare("INSERT INTO customer_ledger (customer_id, sale_id, type, amount, note) VALUES (?,?,?,?,?)")
                        ->execute([$customerId, $saleId, 'payment', $debtPayment, "Debt settlement — #$receipt"]);
                }
            }

            // Cash register — record whenever physical cash actually changed hands,
            // regardless of payment_method label (handles credit+cash combo sales).
            $netUSD = $paidUSD - $changeUSD;
            $netLBP = $paidLBP - $changeLBP;
            if (abs($netUSD) > 0.001 || abs($netLBP) > 0.001) {
                $cur = ($netUSD != 0 && $netLBP != 0) ? 'BOTH' : ($netLBP != 0 ? 'LBP' : 'USD');
                logCashEntry($pdo, 'sale', $netUSD, "Sale #$receipt", $saleId, $netLBP, $cur);
            }

            $pdo->commit();

            // Fetch sale for receipt
            $lastSale = $pdo->prepare("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?");
            $lastSale->execute([$saleId]);
            $lastSale = $lastSale->fetch();

            $si = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id=?");
            $si->execute([$saleId]);
            $lastSale['items'] = $si->fetchAll();

            // Debt settlement amount for receipt display
            $ds = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE sale_id=? AND note LIKE 'Debt settlement%'");
            $ds->execute([$saleId]);
            $lastSale['debt_settled'] = (float)$ds->fetchColumn();

            if ($customerId) {
                $cs = $pdo->prepare("SELECT * FROM customers WHERE id=?");
                $cs->execute([$customerId]);
                $lastCustomer = $cs->fetch();
            }

            $message = "success:Sale #{$receipt} complete — Total: " . fmtUSD($total);
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'error:' . $e->getMessage();
        }
    }
}

// ─── Load data for POS UI ─────────────────────────────────────────────────────
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$products   = $pdo->query("
    SELECT p.*, c.name AS cat FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    ORDER BY p.product_type, p.name
")->fetchAll();

$rate            = EXCHANGE_RATE;
$autoPrint       = AUTO_PRINT;
$custDisplay     = CUSTOMER_DISPLAY;
$cashDrawer      = CASH_DRAWER;
$vfdEnabled      = VFD_ENABLED;

renderHead('POS — Sale');
renderNav('pos');
?>
<div id="toast-wrap" class="position-fixed top-0 end-0 p-3" style="z-index:9999"></div>

<?php if ($message && !$lastSale): alertBox($message); endif; ?>

<?php if ($lastSale): ?>
<!-- ═══════════ RECEIPT MODAL ═══════════ -->
<div class="modal fade show d-block" id="receiptModal" tabindex="-1" style="background:rgba(0,0,0,.5)">
<div class="modal-dialog modal-md">
<div class="modal-content">
<div class="modal-header border-0">
    <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Sale Complete</h5>
    <?php if ($lastSale['change_usd'] > 0 || $lastSale['change_lbp'] > 0): ?>
    <div class="ms-auto me-3 text-center">
        <div class="small text-muted">Change Due</div>
        <div class="fw-bold text-success fs-5">
            <?php if ($lastSale['change_usd'] > 0): ?>
                <?= fmtUSD($lastSale['change_usd']) ?>
                <div class="small"><?= fmtLBP($lastSale['change_usd']*$rate) ?></div>
            <?php else: ?>
                <?= fmtLBP($lastSale['change_lbp']) ?>
                <div class="small"><?= fmtUSD($lastSale['change_lbp']/$rate) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<div class="modal-body receipt p-4">
    <!-- Receipt content -->
    <div class="text-center mb-3">
        <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/dahdouh/assets/img/logo.png')): ?>
        <img src="/dahdouh/assets/img/logo.png" alt="logo" style="height:72px;width:72px;object-fit:contain;margin-bottom:6px">
        <br>
        <?php endif; ?>
        <div class="fw-bold fs-5" style="color:#2d5a2d"><?= htmlspecialchars(setting('store_name','Zoughaib Market')) ?></div>
        <?php if (setting('store_address')): ?><div class="small text-muted"><?= htmlspecialchars(setting('store_address')) ?></div><?php endif; ?>
        <?php if (setting('store_phone')): ?><div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars(setting('store_phone')) ?></div><?php endif; ?>
        <div class="small text-muted mt-1"><?= date('d/m/Y H:i') ?></div>
        <div class="fw-bold mt-1">Receipt: <?= htmlspecialchars($lastSale['receipt_no']) ?></div>
        <?php if ($lastSale['customer_name']): ?><div class="small">Customer: <strong><?= htmlspecialchars($lastSale['customer_name']) ?></strong></div><?php endif; ?>
    </div>
    <hr>
    <table class="table table-sm mb-0">
        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($lastSale['items'] as $si): ?>
        <?php if ($si['product_type'] === 'bulk'): ?>
        <tr>
            <td colspan="3"><?= htmlspecialchars($si['product_name']) ?></td>
            <td class="text-end"><?= fmtUSD($si['total']) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td><?= htmlspecialchars($si['product_name']) ?></td>
            <td class="text-end"><?= (float)$si['quantity'] ?></td>
            <td class="text-end"><?= fmtUSD($si['unit_price']) ?></td>
            <td class="text-end"><?= fmtUSD($si['total']) ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <hr>
    <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= fmtUSD($lastSale['subtotal']) ?></span></div>
    <?php if ($lastSale['discount']>0): ?><div class="d-flex justify-content-between text-muted"><span>Discount</span><span>-<?= fmtUSD($lastSale['discount']) ?></span></div><?php endif; ?>
    <?php if ($lastSale['credit_used']>0): ?><div class="d-flex justify-content-between text-muted"><span>Credit Applied</span><span>-<?= fmtUSD($lastSale['credit_used']) ?></span></div><?php endif; ?>
    <div class="d-flex justify-content-between fw-bold fs-5 mt-2"><span>TOTAL</span><span><?= fmtUSD($lastSale['total']) ?></span></div>
    <div class="d-flex justify-content-between small text-muted"><span></span><span><?= fmtLBP($lastSale['total'] * $rate) ?></span></div>
    <?php if ($lastSale['paid_usd']>0): ?>
    <div class="d-flex justify-content-between small mt-1"><span>Paid (USD)</span><span><?= fmtUSD($lastSale['paid_usd']) ?></span></div>
    <?php endif; ?>
    <?php if ($lastSale['paid_lbp']>0): ?>
    <div class="d-flex justify-content-between small"><span>Paid (LBP)</span><span><?= fmtLBP($lastSale['paid_lbp']) ?></span></div>
    <?php endif; ?>
    <?php if ($lastSale['change_usd']>0): ?>
    <div class="d-flex justify-content-between text-success fw-bold"><span>Change (USD)</span><span><?= fmtUSD($lastSale['change_usd']) ?></span></div>
    <?php endif; ?>
    <?php if ($lastSale['change_lbp']>0): ?>
    <div class="d-flex justify-content-between text-success fw-bold"><span>Change (LBP)</span><span><?= fmtLBP($lastSale['change_lbp']) ?></span></div>
    <?php endif; ?>
    <?php if (($lastSale['debt_settled'] ?? 0) > 0): ?>
    <div class="d-flex justify-content-between small text-warning fw-semibold">
        <span>Debt Settled</span><span><?= fmtLBP($lastSale['debt_settled'] * EXCHANGE_RATE) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($lastCustomer): ?>
    <hr>
    <div class="small text-center">
        <?php $bal = (float)$lastCustomer['balance']; ?>
        Customer balance after:
        <span class="fw-bold <?= $bal>=0?'text-success':'text-danger' ?>">
            <?= $bal>=0 ? 'Credit '.fmtUSD($bal) : 'Debt '.fmtUSD(abs($bal)) ?>
        </span>
    </div>
    <?php endif; ?>
    <div class="text-center text-muted small mt-3">Thank you!</div>
</div>
<div class="modal-footer no-print">
    <button class="btn btn-outline-secondary" onclick="printPosReceipt()"><i class="bi bi-printer me-1"></i>Print</button>
    <a href="/dahdouh/pages/pos.php" class="btn btn-primary">New Sale</a>
</div>
</div>
</div>
</div>
<script>
function printPosReceipt() {
    const body = document.querySelector('.modal-body.receipt');
    if (!body) return;
    const win = window.open('', '_blank', 'width=320,height=600,scrollbars=yes');
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt</title>' +
        '<style>' +
        '@page{size:80mm auto;margin:3mm 4mm}' +
        '*{color:#000!important;background:transparent!important}' +
        'body{font-family:"Courier New",Courier,monospace;font-size:12px;width:72mm;margin:0;padding:0;font-weight:bold}' +
        'img{max-width:60mm;height:auto;display:block;margin:0 auto 4px}' +
        'table{width:100%;border-collapse:collapse}' +
        'th{font-size:11px;border-bottom:1px dashed #000;padding:2px 0;text-align:left;font-weight:bold}' +
        'td{font-size:11px;padding:2px 0;vertical-align:top;font-weight:bold}' +
        '.text-end{text-align:right}.text-center{text-align:center}' +
        '.fw-bold{font-weight:bold}.fw-semibold{font-weight:600}.fs-5{font-size:13px}' +
        '.d-flex{display:flex}.justify-content-between{justify-content:space-between}' +
        'hr{border:none;border-top:1px dashed #000;margin:4px 0}' +
        '.small{font-size:10px}.mt-1{margin-top:2px}.mt-2{margin-top:4px}.mt-3{margin-top:6px}' +
        '.mb-0{margin-bottom:0}.mb-3{margin-bottom:6px}' +
        '.bi{display:none}' +
        '</style></head><body>' + body.innerHTML +
        '<script>window.onload=function(){window.print();}<\/script>' +
        '</body></html>');
    win.document.close();
}
<?php if ($autoPrint):    ?>window.addEventListener('load', () => setTimeout(printPosReceipt, 500));<?php endif; ?>
<?php if ($cashDrawer):  ?>window.addEventListener('load', () => setTimeout(openCashDrawer, 800));<?php endif; ?>
<?php if ($custDisplay): ?>window.addEventListener('load', () => { localStorage.setItem('posDisplay', JSON.stringify({items:[], total:0})); });<?php endif; ?>
<?php if (!$lastSale && $custDisplay): ?>window.addEventListener('load', openDisplayWindow);<?php endif; ?>
</script>

<?php else: ?>
<!-- ═══════════ POS INTERFACE ═══════════ -->
<style>
/* POS only — body becomes a flex column; content fills what's left below the navbar */
html, body { height:100%; overflow:hidden; margin:0; }
body { display:flex; flex-direction:column; }
#pos-wrap { display:flex; flex:1; min-height:0; gap:.5rem; padding:.5rem .75rem; overflow:hidden; }
/* Left panel */
#pos-left  { display:flex; flex-direction:column; flex:1; min-width:0; min-height:0; overflow:hidden; }
/* Product grid uses CSS Grid — no Bootstrap row negative-margin issues */
#pos-left > .pos-grid-wrap { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; }
#product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.5rem; align-content:start; padding-bottom:.5rem; }
/* Right panel */
#pos-right { display:flex; flex-direction:column; flex:0 0 430px; min-height:0; overflow:hidden; }
#pos-right .pos-card { display:flex; flex-direction:column; flex:1; min-height:0; background:#fff; border-radius:.375rem; box-shadow:0 .125rem .25rem rgba(0,0,0,.075); }
#pos-right .pos-card-body { display:flex; flex-direction:column; flex:1; min-height:0; padding:.5rem; }
#cart-scroll { flex:1; min-height:0; overflow-y:auto; }
</style>

<div id="pos-wrap">

<!-- ══ LEFT: barcode + categories + product grid ══ -->
<div id="pos-left">

    <div class="input-group mb-2">
        <span class="input-group-text bg-dark text-white"><i class="bi bi-upc-scan"></i></span>
        <input type="text" id="barcode-input" class="form-control form-control-lg"
               placeholder="Scan barcode or type product name — press Enter"
               autocomplete="off" autofocus>
        <button class="btn btn-dark" onclick="triggerSearch()"><i class="bi bi-search"></i></button>
        <button class="btn btn-outline-secondary" id="printToggleBtn" title="Toggle auto-print" onclick="toggleAutoPrint()">
            <i class="bi <?= $autoPrint ? 'bi-printer-fill text-success' : 'bi-printer' ?>"></i>
        </button>
    </div>

    <div class="d-flex gap-1 flex-wrap mb-2" style="flex-shrink:0">
        <button class="btn btn-sm btn-dark cat-btn active" data-cat="all">All</button>
        <button class="btn btn-sm btn-outline-dark cat-btn" data-cat="bulk"><i class="bi bi-basket"></i> Bulk</button>
        <button class="btn btn-sm btn-outline-secondary cat-btn" data-cat="consignment" style="color:#7c3aed;border-color:#7c3aed"><i class="bi bi-boxes"></i> Amenities</button>
        <?php foreach ($categories as $cat): ?>
        <button class="btn btn-sm btn-outline-secondary cat-btn" data-cat="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="pos-grid-wrap">
    <div id="product-grid">
    <?php foreach ($products as $p):
        $isBulk  = $p['product_type'] === 'bulk';
        $isCons  = ($p['product_source'] ?? 'owned') === 'consignment';
        $outStock = !$isBulk && $p['stock'] <= 0;
        $lowStock = !$isBulk && !$outStock && $p['stock'] <= $p['low_stock_alert'];
    ?>
    <div class="prod-item"
         data-cat="<?= $p['category_id'] ?>"
         data-bulk="<?= $isBulk?1:0 ?>"
         data-cons="<?= $isCons?1:0 ?>">
        <div class="prod-card <?= $outStock?'prod-card-out':'' ?>"
             <?= $isCons?'style="border-color:#7c3aed"':'' ?>
             onclick="tileClick(this)"
             data-pid="<?= $p['id'] ?>"
             data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
             data-price="<?= $p['sell_price'] ?>"
             data-cost="<?= $p['cost_price'] ?>"
             data-stock="<?= (float)$p['stock'] ?>"
             data-type="<?= $p['product_type'] ?>"
             data-out="<?= $outStock?1:0 ?>"
             data-upb="<?= (int)($p['units_per_box'] ?? 1) ?>"
             data-boxprice="<?= (float)($p['sell_price_box'] ?? 0) ?>">
            <?php if ($isBulk): ?>
                <div style="font-size:.55rem;font-weight:700;color:#856404;background:#fff3cd;border-radius:3px;padding:1px 4px;margin-bottom:3px;display:inline-block">BULK</div>
            <?php elseif ($isCons): ?>
                <div style="font-size:.55rem;font-weight:700;color:#fff;background:#7c3aed;border-radius:3px;padding:1px 4px;margin-bottom:3px;display:inline-block">CONSIGN</div>
            <?php endif; ?>
            <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
            <?php $hasBox = ($p['sell_price_box'] ?? 0) > 0 && ($p['units_per_box'] ?? 1) > 1; ?>
            <div class="prod-price"><?= fmtUSD($p['sell_price']) ?><?php if ($hasBox): ?><span style="font-size:.65rem;font-weight:400;opacity:.65"> /pcs</span><?php endif; ?></div>
            <div class="prod-lbp"><?php if ($hasBox): ?><?= fmtUSD($p['sell_price_box']) ?>/box<?php else: ?><?= fmtLBP($p['sell_price'] * $rate) ?><?php endif; ?></div>
            <?php if ($isBulk): ?>
                <div class="prod-stock" style="color:#6c757d">tap to enter price</div>
            <?php elseif ($outStock): ?>
                <div class="prod-stock" style="color:#dc3545">out of stock</div>
            <?php elseif ($lowStock): ?>
                <div class="prod-stock" style="color:#f59e0b">
                    <?php if (($p['units_per_box'] ?? 1) > 1): ?><?= floor((float)$p['stock'] / (int)$p['units_per_box']) ?> box · <?= (float)$p['stock'] ?> units left<?php else: ?><?= (float)$p['stock'] ?> <?= htmlspecialchars($p['unit']) ?> left<?php endif; ?>
                </div>
            <?php else: ?>
                <div class="prod-stock" style="color:#22c55e">
                    <?php if (($p['units_per_box'] ?? 1) > 1): ?><?= floor((float)$p['stock'] / (int)$p['units_per_box']) ?> box · <?= (float)$p['stock'] ?> units<?php else: ?><?= (float)$p['stock'] ?> <?= htmlspecialchars($p['unit']) ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    </div><!-- /pos-grid-wrap -->

</div><!-- /#pos-left -->

<!-- ══ RIGHT: cart + payment ══ -->
<div id="pos-right">
<div class="pos-card">
<div class="pos-card-body">

<!-- Customer selector -->
<div style="flex-shrink:0" class="mb-2">
    <div class="input-group input-group-sm">
        <button type="button" class="input-group-text btn btn-outline-secondary" onclick="openNewCustModal()" title="Add new customer"><i class="bi bi-person-plus"></i></button>
        <input type="text" id="cust-search" class="form-control" placeholder="Customer (type to search or leave for cash sale)" autocomplete="off">
        <button class="btn btn-outline-secondary" onclick="clearCustomer()" title="Cash sale (no customer)"><i class="bi bi-x"></i></button>
    </div>
    <div id="cust-dropdown" class="list-group position-absolute shadow" style="z-index:9999;display:none;width:250px"></div>
    <div id="cust-info" class="small mt-1 d-none"></div>
    <input type="hidden" id="customer_id_val" value="">
</div>

<!-- Cart items — scrolls independently -->
<div id="cart-scroll">
<table class="table table-sm table-hover mb-0" id="cart-table">
    <thead class="table-dark sticky-top"><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead>
    <tbody id="cart-body"><tr id="empty-row"><td colspan="5" class="text-center text-muted py-3 small">Cart is empty</td></tr></tbody>
</table>
</div>

<!-- Totals -->
<div class="border-top pt-2 mt-1" style="flex-shrink:0">
    <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="subtotal-val">$0.00</span></div>
    <div class="d-flex justify-content-between small mb-1 align-items-center">
        <span id="disc-label">Discount
            <button type="button" id="disc-toggle" class="btn btn-xs btn-outline-secondary py-0 px-1 ms-1" style="font-size:.7rem;line-height:1.2" onclick="toggleDiscMode()" title="Switch between $ and %">$</button>
        </span>
        <input type="number" id="discount-input" class="form-control form-control-sm text-end" style="width:90px" value="0" min="0" step="0.01" oninput="renderCart()">
    </div>
    <div id="credit-row" class="d-flex justify-content-between small mb-1 d-none">
        <span class="text-success">Use Credit ($)</span>
        <input type="number" id="credit-input" class="form-control form-control-sm text-end" style="width:90px" value="0" min="0" step="0.01" oninput="renderCart()">
    </div>
    <div class="d-flex justify-content-between fw-bold">
        <span>TOTAL (USD)</span><span class="fs-5 text-primary" id="total-usd">$0.00</span>
    </div>
    <div class="d-flex justify-content-between text-muted small mb-2">
        <span>TOTAL (LBP)</span><span id="total-lbp">0 LBP</span>
    </div>
</div>

<!-- Checkout -->
<div class="border-top pt-2" style="flex-shrink:0">
    <input type="text" id="sale-note" class="form-control form-control-sm mb-2" placeholder="Note (optional)">
    <button type="button" class="btn btn-success w-100 fw-bold py-2" onclick="openCheckout()">
        <i class="bi bi-bag-check me-2"></i>Checkout
    </button>
    <div class="d-flex gap-1 mt-1">
        <button type="button" class="btn btn-outline-danger btn-sm flex-fill" onclick="clearCart()"><i class="bi bi-trash"></i> Clear</button>
        <button type="button" class="btn btn-outline-warning btn-sm flex-fill" onclick="holdSale()"><i class="bi bi-pause-circle"></i> Hold</button>
        <button type="button" id="held-btn" class="btn btn-warning btn-sm flex-fill" onclick="openHeldSales()" style="display:none"><i class="bi bi-clock-history"></i> <span id="held-badge" class="badge bg-danger">0</span></button>
    </div>
    <?php if ($cashDrawer): ?>
    <button type="button" class="btn btn-outline-secondary w-100 mt-1 btn-sm" onclick="openCashDrawer()"><i class="bi bi-safe me-1"></i>Open Drawer</button>
    <?php endif; ?>
    <form method="POST" id="sale-form" onsubmit="return prepareSubmit()">
        <input type="hidden" name="cart_json"       id="cart-json">
        <input type="hidden" name="discount"        id="hd-discount">
        <input type="hidden" name="credit_use"      id="hd-credit">
        <input type="hidden" name="paid_usd"        id="hd-paid-usd">
        <input type="hidden" name="paid_lbp"        id="hd-paid-lbp">
        <input type="hidden" name="currency_paid"   id="hd-currency">
        <input type="hidden" name="change_currency" id="hd-change-cur">
        <input type="hidden" name="payment_method"  id="hd-method" value="cash">
        <input type="hidden" name="customer_id"     id="hd-customer">
        <input type="hidden" name="note"            id="hd-note">
        <input type="hidden" name="debt_payment"    id="hd-debt" value="0">
    </form>
</div>

</div><!-- /.pos-card-body -->
</div><!-- /.pos-card -->
</div><!-- /#pos-right -->

</div><!-- /#pos-wrap -->

<!-- ═══════════ CHECKOUT MODAL ═══════════ -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
    <div class="modal-header text-white" style="background:#1a3a1a">
        <div>
            <h4 class="modal-title fw-bold mb-0"><i class="bi bi-bag-check me-2"></i>Checkout</h4>
        </div>
        <div class="ms-auto me-3 text-center">
            <div class="small opacity-75">TOTAL DUE</div>
            <div class="fw-bold fs-2" id="modal-total-usd">$0.00</div>
            <div class="opacity-75 small" id="modal-total-lbp">0 LBP</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-4">

        <!-- Payment method -->
        <div class="mb-4">
            <label class="form-label fw-semibold text-muted small">PAYMENT METHOD</label>
            <div class="btn-group w-100 btn-group-lg" id="modal-method-switch">
                <button type="button" class="btn btn-success active" data-m="cash" onclick="setModalMethod(this,'cash')"><i class="bi bi-cash me-1"></i>Cash</button>
                <button type="button" class="btn btn-outline-success" data-m="card" onclick="setModalMethod(this,'card')"><i class="bi bi-credit-card me-1"></i>Card</button>
                <button type="button" class="btn btn-outline-success" data-m="mobile" onclick="setModalMethod(this,'mobile')"><i class="bi bi-phone me-1"></i>Mobile</button>
                <button type="button" class="btn btn-outline-success" data-m="account" onclick="setModalMethod(this,'account')"><i class="bi bi-person-check me-1"></i>Account</button>
            </div>
        </div>

        <!-- Debt settlement (shown only when customer has a debt) -->
        <div id="modal-debt-section" class="mb-4 d-none">
            <label class="form-label fw-semibold text-muted small">DEBT SETTLEMENT</label>
            <div class="card border-danger p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Owes:
                            <span id="modal-debt-balance-usd">$0.00</span>
                            <span id="modal-debt-balance-lbp" class="d-none">0 LL</span>
                        </span>
                        <div class="small text-muted">Remaining after: <span id="modal-debt-remaining" class="fw-bold text-warning">$0.00</span></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-success active" id="debt-cur-usd" onclick="setDebtCur('usd')">$ USD</button>
                            <button type="button" class="btn btn-outline-warning" id="debt-cur-lbp" onclick="setDebtCur('lbp')">LL LBP</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="applyMaxDebt()">Pay All</button>
                    </div>
                </div>
                <div class="input-group input-group-sm">
                    <span class="input-group-text text-danger fw-bold" id="debt-input-prefix">$</span>
                    <input type="number" id="modal-debt-input" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcModalChange()">
                    <span class="input-group-text small">applied to debt</span>
                </div>
            </div>
        </div>

        <!-- Cash inputs + embedded numpad side-by-side -->
        <div id="modal-cash-section">
            <div class="row g-2 align-items-start mb-3">
                <!-- Left: amount fields -->
                <div class="col-5 d-flex flex-column gap-2">
                    <div>
                        <label class="form-label small fw-semibold mb-1 text-success">Cash Given (USD)</label>
                        <div class="input-group input-group-lg" onclick="vnumSetField('usd')" style="cursor:pointer">
                            <span class="input-group-text text-success fw-bold">$</span>
                            <input type="number" id="modal-paid-usd" class="form-control text-end fw-bold"
                                   placeholder="0.00" min="0" step="0.01" oninput="calcModalChange()"
                                   onfocus="vnumSetField('usd')" readonly style="cursor:pointer;font-size:1.1rem">
                        </div>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold mb-1" style="color:#b8860b">Cash Given (LBP)</label>
                        <div class="input-group input-group-lg" onclick="vnumSetField('lbp')" style="cursor:pointer">
                            <span class="input-group-text fw-bold" style="color:#b8860b">LL</span>
                            <input type="number" id="modal-paid-lbp" class="form-control text-end fw-bold"
                                   placeholder="0" min="0" step="1000" oninput="calcModalChange()"
                                   onfocus="vnumSetField('lbp')" readonly style="cursor:pointer;font-size:1.1rem">
                        </div>
                    </div>
                </div>
                <!-- Right: numpad -->
                <div class="col-7">
                    <div id="em-numpad" class="p-2 rounded" style="background:#1e293b">
                        <div class="btn-group w-100 mb-2 btn-group-sm">
                            <button type="button" id="em-tab-usd" class="btn btn-success active" onclick="vnumSetField('usd')"><i class="bi bi-currency-dollar"></i> USD</button>
                            <button type="button" id="em-tab-lbp" class="btn btn-outline-warning" onclick="vnumSetField('lbp')">LL LBP</button>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:5px">
                            <?php foreach (['7','8','9','4','5','6','1','2','3','.','0','⌫'] as $k): ?>
                            <button type="button" onclick="vnumPress('<?= $k ?>')"
                                    style="background:<?= $k==='⌫'?'#dc2626':'#334155' ?>;color:#fff;border:none;border-radius:8px;font-size:1.3rem;font-weight:700;padding:10px 0;cursor:pointer;user-select:none;-webkit-user-select:none;touch-action:manipulation"
                                    ontouchstart="this.style.opacity='.6'" ontouchend="this.style.opacity='1'"><?= $k ?></button>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="vnumClear()" style="width:100%;background:#475569;color:#fff;border:none;border-radius:8px;padding:7px;font-size:.85rem;cursor:pointer">C — Clear</button>
                    </div>
                </div>
            </div>

            <!-- Live totals card -->
            <div class="card border-2 p-3 mb-3" id="modal-totals-card">
                <div class="row text-center g-0">
                    <div class="col-4 border-end">
                        <div class="small text-muted">Total Given</div>
                        <div class="fw-bold text-dark" id="modal-given-usd">$0.00</div>
                        <div class="small text-muted" id="modal-given-lbp">0 LBP</div>
                    </div>
                    <div class="col-4 border-end">
                        <div class="small text-muted">Total Due</div>
                        <div class="fw-bold text-primary" id="modal-due-usd2">$0.00</div>
                        <div class="small text-muted" id="modal-due-lbp2">0 LBP</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted" id="modal-change-label">Change Due</div>
                        <div class="fw-bold fs-5" id="modal-change-val">—</div>
                    </div>
                </div>
            </div>

            <!-- Change currency -->
            <div class="d-flex align-items-center gap-2 mb-1 d-none" id="modal-change-cur-row">
                <span class="small text-muted">Give change in:</span>
                <div class="btn-group btn-group-sm" id="modal-changecur-switch">
                    <button type="button" class="btn btn-warning active" data-c="LBP" onclick="setChangeCur(this,'LBP')">LBP (LL)</button>
                    <button type="button" class="btn btn-outline-success" data-c="USD" onclick="setChangeCur(this,'USD')">USD ($)</button>
                </div>
            </div>
        </div>

        <!-- Note -->
        <div>
            <label class="form-label small text-muted">Note (optional)</label>
            <input type="text" id="modal-note" class="form-control" placeholder="Sale note...">
        </div>
    </div>
    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
        <button type="button" class="btn btn-success btn-lg px-5 fw-bold" onclick="confirmCheckout()">
            <i class="bi bi-check2-circle me-2"></i>Confirm Payment
        </button>
    </div>
</div>
</div>
</div>

<!-- Bulk price modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title" id="bulk-modal-title">Enter Amount</h6><button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" id="bulk-id">
        <input type="hidden" id="bulk-name">
        <div class="mb-2">
            <label class="form-label small">Price / Amount (USD)</label>
            <input type="number" id="bulk-price" class="form-control form-control-lg" step="0.01" min="0.01" autofocus>
        </div>
    </div>
    <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="confirmBulkAdd()">Add to Cart</button>
    </div>
</div>
</div>
</div>

<!-- New Customer Modal (from POS) -->
<div class="modal fade" id="newCustPosModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-person-plus me-2"></i>New Customer</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Name <span class="text-danger">*</span></label>
            <input type="text" id="nc-name" class="form-control form-control-sm" placeholder="Full name">
        </div>
        <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Phone</label>
            <input type="text" id="nc-phone" class="form-control form-control-sm" placeholder="Optional">
        </div>
        <div class="mb-1">
            <label class="form-label small fw-semibold mb-1">Opening Balance ($)</label>
            <input type="number" id="nc-balance" class="form-control form-control-sm" value="0" step="0.01" placeholder="0 = no credit/debt">
            <div class="form-text">Positive = credit, negative = debt</div>
        </div>
        <div class="text-danger small mt-1" id="nc-error"></div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="saveNewCustPos()">Save &amp; Select</button>
    </div>
</div>
</div>
</div>

<!-- Held Sales Modal -->
<div class="modal fade" id="heldSalesModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header py-2">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Held Sales</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="held-sales-list"></div>
    <div class="modal-footer py-2">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    </div>
</div>
</div>
</div>

<?php endif; // not lastSale ?>

<script>
const EXCHANGE_RATE       = <?= $rate ?>;
const AUTO_PRINT_SETTING  = <?= $autoPrint    ? 'true' : 'false' ?>;
const CUSTOMER_DISPLAY_ON = <?= $custDisplay  ? 'true' : 'false' ?>;
const CASH_DRAWER_ON      = <?= $cashDrawer   ? 'true' : 'false' ?>;
const VFD_ON              = <?= $vfdEnabled   ? 'true' : 'false' ?>;

// ─── Cash Drawer ──────────────────────────────────────────────────────────────
function openCashDrawer() {
    // Sends the ESC/POS "kick cash drawer" command to the thermal printer
    // by opening a tiny print window. The printer opens the drawer on receipt.
    const win = window.open('', '_blank', 'width=1,height=1,left=-100,top=-100');
    if (!win) return;
    // ESC p 0 25 250 — standard kick-drawer pulse for most thermal printers
    win.document.write(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>' +
        '<body><pre style="font-family:monospace">\x1B\x70\x00\x19\xFA</pre>' +
        '<script>window.onload=function(){window.print();setTimeout(function(){window.close();},500);};<\/script>' +
        '</body></html>'
    );
    win.document.close();
}

// ─── Customer Display ─────────────────────────────────────────────────────────
let _displayWin = null;

function openDisplayWindow() {
    if (!CUSTOMER_DISPLAY_ON) return;
    if (_displayWin && !_displayWin.closed) return;
    _displayWin = window.open('/dahdouh/pages/customer_display.php', 'customerDisplay',
        'width=1024,height=600,menubar=no,toolbar=no,location=no,status=no');
}

function syncDisplay() {
    if (!CUSTOMER_DISPLAY_ON) return;
    const items = Object.values(cart).map(it => ({name: it.name, qty: it.qty, price: it.price}));
    const total = parseFloat(document.getElementById('total-usd')?.textContent?.replace('$','').replace(/,/g,'') || 0);
    localStorage.setItem('posDisplay', JSON.stringify({items, total}));
}

let _vfdTimer = null;
function syncVFD(total) {
    if (!VFD_ON) return;
    clearTimeout(_vfdTimer);
    _vfdTimer = setTimeout(() => {
        const itemCount = Object.keys(cart).length;
        const line1 = itemCount === 0 ? 'Welcome!' : itemCount + ' item' + (itemCount>1?'s':'');
        const fd = new FormData();
        fd.append('total', total.toFixed(2));
        fd.append('line1', line1);
        fetch('/dahdouh/pages/api.php?action=vfd_display', { method: 'POST', body: fd }).catch(()=>{});
    }, 300);
}

// ─── Category filter ─────────────────────────────────────────────────────────
document.querySelectorAll('.cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-btn').forEach(b => {
            b.className = b.className.replace('btn-dark','btn-outline-secondary').replace('btn-outline-dark','btn-outline-secondary');
            b.classList.remove('active');
        });
        btn.className = btn.className.replace('btn-outline-secondary','btn-dark').replace('btn-outline-dark','btn-dark');
        btn.classList.add('active');

        const cat = btn.dataset.cat;
        document.querySelectorAll('.prod-item').forEach(el => {
            if (cat === 'all')         { el.style.display = ''; return; }
            if (cat === 'bulk')        { el.style.display = el.dataset.bulk==='1' ? '' : 'none'; return; }
            if (cat === 'consignment') { el.style.display = el.dataset.cons==='1' ? '' : 'none'; return; }
            el.style.display = (el.dataset.cat == cat) ? '' : 'none';
        });
    });
});

// ─── Product tile click ───────────────────────────────────────────────────────
function tileClick(el) {
    const type     = el.dataset.type;
    const id       = el.dataset.pid;
    const name     = el.dataset.name;
    const price    = parseFloat(el.dataset.price);
    const cost     = parseFloat(el.dataset.cost);
    const stock    = parseFloat(el.dataset.stock);
    const isOut    = el.dataset.out === '1';
    const upb      = parseInt(el.dataset.upb || 1);
    const boxPrice = parseFloat(el.dataset.boxprice || 0);
    if (type === 'bulk') {
        promptBulkAdd(id, name, price);
    } else if (isOut) {
        showToast('Out of stock', 'danger');
    } else if (upb > 1 && boxPrice > 0) {
        addPcsToCartFlash(el, id, name, boxPrice, price, cost, stock, upb);
    } else {
        addToCartFlash(el, id, name, price, cost, stock, 'regular');
    }
}

function addPcsToCartFlash(el, id, name, boxPrice, unitPrice, cost, stock, upb) {
    el.classList.add('prod-flash');
    setTimeout(() => el.classList.remove('prod-flash'), 300);
    addPcsToCart(id, name, boxPrice, unitPrice, cost, stock, upb);
}

function addPcsToCart(id, name, boxPrice, unitPrice, cost, stock, upb) {
    id = String(id);
    if (cart[id]) {
        if (cart[id].qty >= stock) { showToast('No more stock', 'warning'); return; }
        cart[id].qty = parseFloat((cart[id].qty + 1).toFixed(3));
        // Ensure box fields are always stored so the toggle appears
        cart[id].boxPrice  = boxPrice;
        cart[id].unitPrice = unitPrice;
        cart[id].upb       = upb;
        if (!cart[id].isBox) cart[id].price = unitPrice;
    } else {
        cart[id] = { name, price: unitPrice, boxPrice, unitPrice, cost: parseFloat(cost), qty: 1, stock: parseFloat(stock)||999, type: 'regular', isBox: false, upb };
    }
    renderCart();
}

function addBoxToCartFlash(el, id, name, boxPrice, unitPrice, cost, stock, upb) {
    el.classList.add('prod-flash');
    setTimeout(() => el.classList.remove('prod-flash'), 300);
    addBoxToCart(id, name, boxPrice, unitPrice, cost, stock, upb);
}

function addBoxToCart(id, name, boxPrice, unitPrice, cost, stock, upb) {
    id = String(id);
    if (cart[id]) {
        if (cart[id].isBox) {
            if (cart[id].qty + upb > stock) { showToast('Max stock reached', 'warning'); return; }
            cart[id].qty += upb;
        } else {
            // already as pcs — switch to box mode
            cart[id].isBox     = true;
            cart[id].price     = boxPrice;
            cart[id].boxPrice  = boxPrice;
            cart[id].unitPrice = unitPrice;
            cart[id].upb       = upb;
            cart[id].qty       = Math.max(upb, Math.ceil(cart[id].qty / upb) * upb);
        }
    } else {
        cart[id] = { name, price: boxPrice, boxPrice, unitPrice, cost: parseFloat(cost), qty: upb, stock: parseFloat(stock)||999, type: 'regular', isBox: true, upb };
    }
    renderCart();
}

function toggleBoxMode(id) {
    if (!cart[id] || !cart[id].upb || cart[id].upb <= 1) return;
    const item = cart[id];
    item.isBox = !item.isBox;
    if (item.isBox) {
        item.price = item.boxPrice;
        item.qty   = Math.max(item.upb, Math.ceil(item.qty / item.upb) * item.upb);
    } else {
        item.price = item.unitPrice;
        item.qty   = 1;
    }
    renderCart();
}

// ─── Barcode input ───────────────────────────────────────────────────────────
const barcodeInput = document.getElementById('barcode-input');
if (barcodeInput) {
    barcodeInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); triggerSearch(); }
    });
}

function triggerSearch() {
    const q = barcodeInput?.value?.trim();
    if (!q) return;
    fetch(`/dahdouh/pages/api.php?action=search_product&q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(d => {
            if (d.error) { showToast(d.error, 'danger'); return; }
            if (d.product_type === 'bulk') {
                document.getElementById('bulk-id').value   = d.id;
                document.getElementById('bulk-name').value = d.name;
                document.getElementById('bulk-price').value = d.sell_price || '';
                document.getElementById('bulk-modal-title').textContent = d.name;
                new bootstrap.Modal(document.getElementById('bulkModal')).show();
                setTimeout(() => document.getElementById('bulk-price').focus(), 300);
            } else if (parseInt(d.units_per_box) > 1 && parseFloat(d.sell_price_box) > 0) {
                addPcsToCart(d.id, d.name, parseFloat(d.sell_price_box), parseFloat(d.sell_price), d.cost_price, d.stock, parseInt(d.units_per_box));
            } else {
                addToCart(d.id, d.name, d.sell_price, d.cost_price, d.stock, 'regular');
            }
            barcodeInput.value = '';
            barcodeInput.focus();
        })
        .catch(() => showToast('Search failed','danger'));
}

// ─── Bulk add ─────────────────────────────────────────────────────────────────
function promptBulkAdd(id, name, defaultPrice) {
    document.getElementById('bulk-id').value = id;
    document.getElementById('bulk-name').value = name;
    document.getElementById('bulk-price').value = defaultPrice || '';
    document.getElementById('bulk-modal-title').textContent = name;
    new bootstrap.Modal(document.getElementById('bulkModal')).show();
    setTimeout(() => document.getElementById('bulk-price').focus(), 300);
}

function confirmBulkAdd() {
    const id    = document.getElementById('bulk-id').value;
    const name  = document.getElementById('bulk-name').value;
    const price = parseFloat(document.getElementById('bulk-price').value);
    if (!price || price <= 0) { showToast('Enter a valid price','warning'); return; }
    addToCart(id, name, price, 0, 999, 'bulk', 1);
    bootstrap.Modal.getInstance(document.getElementById('bulkModal')).hide();
    barcodeInput?.focus();
}

// ─── Cart ─────────────────────────────────────────────────────────────────────
const cart = {};

function addToCartFlash(el, id, name, price, cost, stock, type) {
    el.classList.add('prod-flash');
    setTimeout(() => el.classList.remove('prod-flash'), 300);
    addToCart(id, name, price, cost, stock, type);
}

function addToCart(id, name, price, cost, stock, type = 'regular', forceQty = null) {
    id = String(id);
    if (cart[id] && type !== 'bulk') {
        if (type === 'regular' && cart[id].qty >= stock) { showToast('No more stock','warning'); return; }
        cart[id].qty = parseFloat((cart[id].qty + 1).toFixed(3));
    } else {
        if (type === 'regular' && (parseFloat(stock)||999) < 1) { showToast('Out of stock','danger'); return; }
        cart[id] = { name, price: parseFloat(price), cost: parseFloat(cost), qty: forceQty || 1, stock: parseFloat(stock)||999, type };
    }
    if (forceQty !== null) cart[id].qty = forceQty;
    renderCart();
}

function changeQty(id, delta) {
    if (!cart[id]) return;
    const newQty = parseFloat((cart[id].qty + delta).toFixed(3));
    if (newQty <= 0) { delete cart[id]; }
    else if (cart[id].type === 'regular') {
        if (newQty > cart[id].stock) { showToast('Max stock reached','warning'); return; }
        cart[id].qty = newQty;
    }
    else { cart[id].qty = newQty; }
    renderCart();
}

function removeFromCart(id) { delete cart[id]; delete cartCur[id]; renderCart(); }

function clearCart() { Object.keys(cart).forEach(k => { delete cart[k]; delete cartCur[k]; }); renderCart(); }

function renderCart() {
    const tbody = document.getElementById('cart-body');
    if (!tbody) return;

    if (Object.keys(cart).length === 0) {
        tbody.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center text-muted py-3 small">Cart is empty</td></tr>';
        updateTotals(0);
        return;
    }

    let subtotal = 0;
    let html = '';
    Object.entries(cart).forEach(([id, item]) => {
        const line = parseFloat((item.price * item.qty).toFixed(2));
        subtotal += line;
        const step  = item.type === 'bulk' ? 'any' : 1;
        const delta = item.isBox ? item.upb : 1;
        const badge = item.type === 'bulk'
            ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.55rem">BULK</span>'
            : '';
        const boxToggle = (item.upb > 1 && item.unitPrice && item.boxPrice)
            ? `<button type="button" onclick="toggleBoxMode('${id}')" style="display:inline-block;margin-left:4px;font-size:.6rem;padding:1px 5px;border-radius:3px;cursor:pointer;border:1px solid #0d6efd;background:${item.isBox?'#0d6efd':'#e7f1ff'};color:${item.isBox?'#fff':'#0d6efd'}" title="Toggle Box / Piece">${item.isBox?'📦 Box':'🔹 Pcs'}</button>`
            : '';
        const iCur        = cartCur[id] || 'usd';
        const iCurLabel   = iCur === 'lbp' ? 'LL' : '$';
        const iCurStep    = iCur === 'lbp' ? '500' : '0.01';
        const iCurDisplay = iCur === 'lbp' ? Math.round(item.price * EXCHANGE_RATE) : item.price.toFixed(2);
        html += `<tr>
            <td class="small">${item.name}${badge}${boxToggle}</td>
            <td style="width:88px">
                <div class="input-group input-group-sm">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty('${id}',${-delta})">-</button>
                    <input type="number" class="form-control form-control-sm text-center px-1" value="${item.qty}" min="0.001" step="${step}"
                        onchange="setQty('${id}',this.value)" style="width:40px;padding:1px">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty('${id}',${delta})">+</button>
                </div>
            </td>
            <td style="width:${iCur==='lbp'?'120':'92'}px">
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary px-1 py-0" id="cur-btn-${id}"
                            style="font-size:10px;min-width:24px;line-height:1" onclick="toggleItemCur('${id}')"
                            title="Click to switch USD ↔ LBP">${iCurLabel}</button>
                    <input type="number" class="form-control form-control-sm text-end px-1" id="price-inp-${id}"
                           value="${iCurDisplay}" min="0" step="${iCurStep}"
                           onchange="setItemPrice('${id}',this.value)" style="width:${iCur==='lbp'?'90':'55'}px;padding:2px" title="Edit price — click currency to switch">
                </div>
            </td>
            <td class="small text-end fw-bold" id="ln-${id}">${formatUSD(line)}</td>
            <td><button class="btn btn-sm text-danger p-0" onclick="removeFromCart('${id}')"><i class="bi bi-x-lg"></i></button></td>
        </tr>`;
    });
    tbody.innerHTML = html;
    updateTotals(subtotal);
}

function setQty(id, val) {
    val = parseFloat(val);
    if (!val || val <= 0) { delete cart[id]; renderCart(); return; }
    if (cart[id].type === 'regular') {
        if (val > cart[id].stock) { val = cart[id].stock; showToast('Max stock reached','warning'); }
    }
    cart[id].qty = parseFloat(val.toFixed(3));
    renderCart();
}

// Per-item price currency state (survives renderCart re-renders)
const cartCur = {};

function toggleItemCur(id) {
    const cur    = cartCur[id] || 'usd';
    const newCur = cur === 'usd' ? 'lbp' : 'usd';
    cartCur[id]  = newCur;
    const inp = document.getElementById('price-inp-' + id);
    const btn = document.getElementById('cur-btn-' + id);
    const td  = inp ? inp.closest('td') : null;
    if (!inp || !cart[id]) return;
    if (newCur === 'lbp') {
        inp.value    = Math.round(cart[id].price * EXCHANGE_RATE);
        inp.step     = '500';
        inp.style.width = '90px';
        if (btn) btn.textContent = 'LL';
        if (td)  td.style.width  = '120px';
    } else {
        inp.value    = cart[id].price.toFixed(2);
        inp.step     = '0.01';
        inp.style.width = '55px';
        if (btn) btn.textContent = '$';
        if (td)  td.style.width  = '92px';
    }
}

function setItemPrice(id, val) {
    val = parseFloat(val);
    if (isNaN(val) || val < 0) return;
    const cur = cartCur[id] || 'usd';
    cart[id].price = cur === 'lbp' ? val / EXCHANGE_RATE : val;
    const line = parseFloat((cart[id].qty * cart[id].price).toFixed(2));
    const el = document.getElementById('ln-' + id);
    if (el) el.textContent = formatUSD(line);
    let subtotal = 0;
    Object.values(cart).forEach(item => { subtotal += item.price * item.qty; });
    updateTotals(subtotal);
}

let discPctMode = false;
function toggleDiscMode() {
    discPctMode = !discPctMode;
    const btn = document.getElementById('disc-toggle');
    const inp = document.getElementById('discount-input');
    btn.textContent = discPctMode ? '%' : '$';
    btn.classList.toggle('btn-warning', discPctMode);
    btn.classList.toggle('btn-outline-secondary', !discPctMode);
    inp.step = discPctMode ? '0.1' : '0.01';
    inp.max  = discPctMode ? '100' : '';
    inp.value = 0;
    renderCart();
}

function getDiscountUSD(subtotal) {
    const val = parseFloat(document.getElementById('discount-input')?.value || 0);
    return discPctMode ? Math.min(subtotal, subtotal * val / 100) : val;
}

function updateTotals(subtotal) {
    const disc   = getDiscountUSD(subtotal);
    const credit = parseFloat(document.getElementById('credit-input')?.value || 0);
    const maxCred = parseFloat(document.getElementById('credit-input')?.max || 0);
    const creditUsed = Math.min(credit, maxCred, subtotal - disc);
    const total  = Math.max(0, subtotal - disc - creditUsed);

    document.getElementById('subtotal-val').textContent = formatUSD(subtotal);
    document.getElementById('total-usd').textContent    = formatUSD(total);
    document.getElementById('total-lbp').textContent    = formatLBP(total * EXCHANGE_RATE);
    syncDisplay();
    syncVFD(total);
}

// ─── Checkout Modal ───────────────────────────────────────────────────────────
let modalMethod   = 'cash';
let modalChangeCur = 'LBP';
let debtCur       = 'usd';

function openCheckout() {
    if (Object.keys(cart).length === 0) { showToast('Cart is empty','warning'); return; }
    const totalUSD = parseFloat(document.getElementById('total-usd').textContent.replace('$','').replace(/,/g,'')) || 0;
    document.getElementById('modal-total-usd').textContent = formatUSD(totalUSD);
    document.getElementById('modal-total-lbp').textContent = formatLBP(totalUSD * EXCHANGE_RATE);
    document.getElementById('modal-paid-usd').value = '';
    document.getElementById('modal-paid-lbp').value = '';
    document.getElementById('modal-note').value = document.getElementById('sale-note')?.value || '';

    // Debt settlement section
    const custBal = selectedCustomer ? parseFloat(selectedCustomer.balance) : 0;
    const debtSection = document.getElementById('modal-debt-section');
    const debtInput   = document.getElementById('modal-debt-input');
    if (custBal < -0.001) {
        debtSection.classList.remove('d-none');
        document.getElementById('modal-debt-balance-usd').textContent = formatUSD(Math.abs(custBal));
        document.getElementById('modal-debt-balance-lbp').textContent = formatLBP(Math.abs(custBal) * EXCHANGE_RATE);
        debtInput.value = '';
        debtCur = 'usd';
        setDebtCur('usd');
    } else {
        debtSection.classList.add('d-none');
        if (debtInput) debtInput.value = '';
    }

    calcModalChange();
    new bootstrap.Modal(document.getElementById('checkoutModal')).show();
    setTimeout(() => document.getElementById('modal-paid-usd').focus(), 350);
}

function calcModalChange() {
    const totalDue    = parseFloat(document.getElementById('modal-total-usd').textContent.replace('$','').replace(/,/g,'')) || 0;
    const debtRaw     = parseFloat(document.getElementById('modal-debt-input')?.value || 0);
    const debtPayment = debtCur === 'lbp' ? debtRaw / EXCHANGE_RATE : debtRaw;
    const totalReq    = totalDue + debtPayment;
    const paidUSD     = parseFloat(document.getElementById('modal-paid-usd')?.value || 0);
    const paidLBP     = parseFloat(document.getElementById('modal-paid-lbp')?.value || 0);
    const givenUSD    = paidUSD + (paidLBP / EXCHANGE_RATE);
    const remaining   = givenUSD - totalReq;

    // Update due line to show total required (sale + debt)
    document.getElementById('modal-due-usd2').textContent = formatUSD(totalReq);
    document.getElementById('modal-due-lbp2').textContent = formatLBP(totalReq * EXCHANGE_RATE);

    // Update debt remaining preview
    const custBal   = selectedCustomer ? parseFloat(selectedCustomer.balance) : 0;
    const debtRemEl = document.getElementById('modal-debt-remaining');
    if (debtRemEl && custBal < 0) {
        const remDebt = Math.abs(custBal) - debtPayment;
        debtRemEl.textContent = debtCur === 'lbp'
            ? formatLBP(Math.max(0, remDebt) * EXCHANGE_RATE)
            : formatUSD(Math.max(0, remDebt));
        debtRemEl.className  = 'fw-bold ' + (remDebt <= 0.001 ? 'text-success' : 'text-warning');
    }

    document.getElementById('modal-given-usd').textContent = formatUSD(givenUSD);
    document.getElementById('modal-given-lbp').textContent = formatLBP(givenUSD * EXCHANGE_RATE);

    const changeEl    = document.getElementById('modal-change-val');
    const labelEl     = document.getElementById('modal-change-label');
    const changeCurRow = document.getElementById('modal-change-cur-row');
    const card        = document.getElementById('modal-totals-card');

    if (remaining > 0.001) {
        labelEl.textContent = 'Change Due';
        changeEl.textContent = modalChangeCur === 'LBP'
            ? formatLBP(remaining * EXCHANGE_RATE)
            : formatUSD(remaining);
        changeEl.className = 'fw-bold fs-5 text-success';
        card.className = 'card border-success border-2 p-3 mb-3';
        changeCurRow.classList.remove('d-none');
    } else if (remaining < -0.001) {
        labelEl.textContent = 'Still Needed';
        changeEl.textContent = formatUSD(Math.abs(remaining));
        changeEl.className = 'fw-bold fs-5 text-danger';
        card.className = 'card border-danger border-2 p-3 mb-3';
        changeCurRow.classList.add('d-none');
    } else if (paidUSD > 0 || paidLBP > 0) {
        labelEl.textContent = 'Exact';
        changeEl.textContent = '✓ Exact';
        changeEl.className = 'fw-bold fs-5 text-success';
        card.className = 'card border-success border-2 p-3 mb-3';
        changeCurRow.classList.add('d-none');
    } else {
        labelEl.textContent = 'Change Due';
        changeEl.textContent = '—';
        changeEl.className = 'fw-bold fs-5 text-muted';
        card.className = 'card border-2 p-3 mb-3';
        changeCurRow.classList.add('d-none');
    }
}

function setModalMethod(btn, method) {
    modalMethod = method;
    document.querySelectorAll('#modal-method-switch button').forEach(b => {
        b.classList.toggle('btn-success', b === btn);
        b.classList.toggle('btn-outline-success', b !== btn);
        b.classList.toggle('active', b === btn);
    });
    const showCash = method === 'cash';
    document.getElementById('modal-cash-section').style.display = showCash ? '' : 'none';
}

function applyMaxDebt() {
    if (!selectedCustomer) return;
    const custBal = parseFloat(selectedCustomer.balance);
    if (custBal >= 0) return;
    const inp = document.getElementById('modal-debt-input');
    if (!inp) return;
    inp.value = debtCur === 'lbp'
        ? Math.round(Math.abs(custBal) * EXCHANGE_RATE)
        : Math.abs(custBal).toFixed(2);
    calcModalChange();
}

function setDebtCur(cur) {
    debtCur = cur;
    const isLBP = cur === 'lbp';
    document.getElementById('debt-cur-usd').className = isLBP ? 'btn btn-outline-success' : 'btn btn-success active';
    document.getElementById('debt-cur-lbp').className = isLBP ? 'btn btn-warning active' : 'btn btn-outline-warning';
    document.getElementById('debt-input-prefix').textContent = isLBP ? 'LL' : '$';
    const inp = document.getElementById('modal-debt-input');
    if (inp) { inp.step = isLBP ? '1000' : '0.01'; inp.placeholder = isLBP ? '0' : '0.00'; inp.value = ''; }
    document.getElementById('modal-debt-balance-usd').className = isLBP ? 'd-none' : '';
    document.getElementById('modal-debt-balance-lbp').className = isLBP ? '' : 'd-none';
    calcModalChange();
}

function setChangeCur(btn, cur) {
    modalChangeCur = cur;
    document.querySelectorAll('#modal-changecur-switch button').forEach(b => {
        const isSelected = b === btn;
        b.className = isSelected
            ? (cur === 'LBP' ? 'btn btn-warning active' : 'btn btn-success active')
            : (b.dataset.c === 'LBP' ? 'btn btn-outline-warning' : 'btn btn-outline-success');
    });
    calcModalChange();
}

function confirmCheckout() {
    if (Object.keys(cart).length === 0) { showToast('Cart is empty','warning'); return; }
    let paidUSD = parseFloat(document.getElementById('modal-paid-usd')?.value || 0);
    let paidLBP = parseFloat(document.getElementById('modal-paid-lbp')?.value || 0);
    // If cashier left both amounts at zero for a cash sale, auto-fill sale total + any debt being settled
    const debtRaw = parseFloat(document.getElementById('modal-debt-input')?.value || 0);
    const debtUSD = debtCur === 'lbp' ? debtRaw / EXCHANGE_RATE : debtRaw;
    if (modalMethod === 'cash' && paidUSD === 0 && paidLBP === 0) {
        const totalDue = parseFloat(document.getElementById('modal-total-usd').textContent.replace('$','').replace(/,/g,'')) || 0;
        paidUSD = totalDue + debtUSD;
        document.getElementById('modal-paid-usd').value = paidUSD.toFixed(2);
    }
    // Block underpayment for cash sales
    if (modalMethod === 'cash') {
        const totalDue3 = parseFloat(document.getElementById('modal-total-usd').textContent.replace('$','').replace(/,/g,'')) || 0;
        const totalReq3 = totalDue3 + debtUSD;
        const given3    = paidUSD + (paidLBP / EXCHANGE_RATE);
        if (given3 < totalReq3 - 0.005) {
            showToast('Amount given ($' + given3.toFixed(2) + ') is less than total due ($' + totalReq3.toFixed(2) + ')', 'danger');
            return;
        }
    }
    document.getElementById('hd-paid-usd').value   = paidUSD;
    document.getElementById('hd-paid-lbp').value   = paidLBP;
    document.getElementById('hd-method').value     = modalMethod;
    document.getElementById('hd-change-cur').value = modalChangeCur;
    document.getElementById('hd-currency').value   = (paidUSD > 0 && paidLBP > 0) ? 'BOTH' : (paidLBP > 0 ? 'LBP' : 'USD');
    document.getElementById('hd-note').value       = document.getElementById('modal-note')?.value || '';
    document.getElementById('hd-debt').value       = debtUSD.toFixed(2);
    document.getElementById('sale-form').requestSubmit();
}

// ─── Customer search ─────────────────────────────────────────────────────────
const custSearch = document.getElementById('cust-search');
const custDrop   = document.getElementById('cust-dropdown');
let selectedCustomer = null;

if (custSearch) {
    custSearch.addEventListener('input', () => {
        const q = custSearch.value.trim();
        if (q.length < 1) { custDrop.style.display='none'; return; }
        fetch(`/dahdouh/pages/api.php?action=search_customer&q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(list => {
                if (!list.length) { custDrop.style.display='none'; return; }
                custDrop.innerHTML = list.map(c => {
                    const bal = parseFloat(c.balance);
                    const balLabel = bal>0 ? `<span class="text-success">Credit ${formatUSD(bal)}</span>` : bal<0 ? `<span class="text-danger">Debt ${formatUSD(Math.abs(bal))}</span>` : '';
                    return `<button type="button" class="list-group-item list-group-item-action py-1 px-2 small" onclick='selectCustomer(${JSON.stringify(c)})'>
                        <strong>${c.name}</strong> ${c.phone?'· '+c.phone:''} ${balLabel}
                    </button>`;
                }).join('');
                custDrop.style.display = 'block';
            });
    });
    document.addEventListener('click', e => { if (!e.target.closest('#cust-search') && !e.target.closest('#cust-dropdown')) custDrop.style.display='none'; });
}

function selectCustomer(c) {
    selectedCustomer = c;
    custSearch.value = c.name + (c.phone ? ' — ' + c.phone : '');
    custDrop.style.display = 'none';
    document.getElementById('customer_id_val').value = c.id;
    const bal = parseFloat(c.balance);
    const info = document.getElementById('cust-info');
    info.classList.remove('d-none');
    info.innerHTML = bal > 0
        ? `<span class="badge bg-success">Credit: ${formatUSD(bal)}</span>`
        : bal < 0
        ? `<span class="badge bg-danger">Debt: ${formatUSD(Math.abs(bal))}</span>`
        : `<span class="badge bg-secondary">Settled</span>`;

    // Show credit row
    const creditRow = document.getElementById('credit-row');
    const creditInput = document.getElementById('credit-input');
    if (bal > 0) {
        creditRow.classList.remove('d-none');
        creditInput.max = bal;
        creditInput.placeholder = `Max ${formatUSD(bal)}`;
    } else {
        creditRow.classList.add('d-none');
        creditInput.value = 0;
    }
}

function clearCustomer() {
    selectedCustomer = null;
    custSearch.value = '';
    document.getElementById('customer_id_val').value = '';
    document.getElementById('cust-info').classList.add('d-none');
    document.getElementById('credit-row').classList.add('d-none');
    document.getElementById('credit-input').value = 0;
    renderCart();
}

// ─── Form submission ──────────────────────────────────────────────────────────
function prepareSubmit() {
    if (Object.keys(cart).length === 0) { showToast('Cart is empty','warning'); return false; }
    const cartPayload = Object.entries(cart).map(([k, v]) => ({
        product_id: v.pid || k,
        name: v.name, price: v.price, qty: v.qty, cost: v.cost, type: v.type
    }));
    document.getElementById('cart-json').value   = JSON.stringify(cartPayload);
    // Always submit dollar discount, even when input is in % mode
    const subtotalNow = Object.values(cart).reduce((s, i) => s + i.price * i.qty, 0);
    document.getElementById('hd-discount').value = getDiscountUSD(subtotalNow).toFixed(2);
    document.getElementById('hd-credit').value   = document.getElementById('credit-input')?.value || 0;
    document.getElementById('hd-customer').value = document.getElementById('customer_id_val')?.value || '';
    return true;
}

// ─── Print toggle ─────────────────────────────────────────────────────────────
function toggleAutoPrint() {
    const newVal = AUTO_PRINT_SETTING ? '0' : '1';
    fetch(`/dahdouh/pages/api.php?action=set_setting&key=auto_print_receipt&val=${newVal}`)
        .then(() => location.reload());
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function formatUSD(v) { return '$' + parseFloat(v).toFixed(2); }
function formatLBP(v) { return parseInt(v).toLocaleString() + ' LBP'; }

function showToast(msg, type = 'info') {
    const wrap = document.getElementById('toast-wrap');
    if (!wrap) return;
    const id = 'toast-' + Date.now();
    wrap.insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-bg-${type} border-0 show" role="alert">
        <div class="d-flex"><div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>`);
    setTimeout(() => document.getElementById(id)?.remove(), 3000);
}

// ─── Hold / Resume ────────────────────────────────────────────────────────────
function buildHoldSnapshot() {
    return {
        id: Date.now(),
        time: new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}),
        cart: JSON.parse(JSON.stringify(cart)),
        customer: selectedCustomer,
        customerText: document.getElementById('cust-search').value,
        discount: document.getElementById('discount-input').value,
        discPctMode,
        note: document.getElementById('sale-note').value,
        credit: document.getElementById('credit-input').value
    };
}

function holdSale(silent = false) {
    if (Object.keys(cart).length === 0) { if (!silent) showToast('Cart is empty', 'warning'); return false; }
    const held = JSON.parse(localStorage.getItem('heldSales') || '[]');
    if (held.length >= 5) { showToast('Maximum 5 held sales — resume or discard one first', 'warning'); return false; }
    held.push(buildHoldSnapshot());
    localStorage.setItem('heldSales', JSON.stringify(held));
    Object.keys(cart).forEach(k => delete cart[k]);
    clearCustomer();
    document.getElementById('discount-input').value = 0;
    if (discPctMode) toggleDiscMode();
    document.getElementById('sale-note').value = '';
    renderCart();
    updateHeldBadge();
    if (!silent) showToast('Sale held — cart ready for next customer', 'success');
    return true;
}

function updateHeldBadge() {
    const held = JSON.parse(localStorage.getItem('heldSales') || '[]');
    const btn  = document.getElementById('held-btn');
    const badge = document.getElementById('held-badge');
    if (!btn) return;
    if (held.length > 0) {
        badge.textContent = held.length;
        btn.style.display = '';
    } else {
        btn.style.display = 'none';
    }
}

function openHeldSales() {
    const held = JSON.parse(localStorage.getItem('heldSales') || '[]');
    if (!held.length) { showToast('No held sales', 'info'); return; }
    const listEl = document.getElementById('held-sales-list');
    listEl.innerHTML = held.map(h => {
        const names = Object.values(h.cart).map(i => i.name).join(', ');
        const sub   = Object.values(h.cart).reduce((s, i) => s + i.price * i.qty, 0);
        const label = h.customer ? h.customer.name : 'Cash Sale';
        return `<div class="card mb-2">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div style="min-width:0">
                        <div class="fw-bold">${h.time} — ${label}</div>
                        <div class="small text-muted" style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis">${names}</div>
                        <div class="small text-primary fw-bold mt-1">${formatUSD(sub)}</div>
                    </div>
                    <div class="d-flex flex-column gap-1 flex-shrink-0">
                        <button class="btn btn-sm btn-success" onclick="resumeSale(${h.id})"><i class="bi bi-play-fill me-1"></i>Resume</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="discardHeld(${h.id})">Discard</button>
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');
    new bootstrap.Modal(document.getElementById('heldSalesModal')).show();
}

function resumeSale(holdId) {
    const held = JSON.parse(localStorage.getItem('heldSales') || '[]');
    const snapshot = held.find(h => h.id === holdId);
    if (!snapshot) return;

    if (Object.keys(cart).length > 0) {
        const othersCount = held.filter(h => h.id !== holdId).length;
        if (othersCount < 5) {
            if (confirm('Hold current cart and resume the held sale?\n\nOK = Hold current\nCancel = Discard current')) {
                holdSale(true);
            } else {
                if (!confirm('Discard current cart and resume?')) return;
            }
        } else {
            if (!confirm('Discard current cart and resume held sale?')) return;
        }
    }

    bootstrap.Modal.getInstance(document.getElementById('heldSalesModal'))?.hide();
    Object.keys(cart).forEach(k => delete cart[k]);
    Object.assign(cart, snapshot.cart);

    if (snapshot.customer) {
        selectCustomer(snapshot.customer);
    } else {
        clearCustomer();
    }

    document.getElementById('discount-input').value = snapshot.discount || 0;
    if (!!snapshot.discPctMode !== discPctMode) toggleDiscMode();
    document.getElementById('sale-note').value = snapshot.note || '';
    if (parseFloat(snapshot.credit) > 0) document.getElementById('credit-input').value = snapshot.credit;

    const newHeld = JSON.parse(localStorage.getItem('heldSales') || '[]').filter(h => h.id !== holdId);
    localStorage.setItem('heldSales', JSON.stringify(newHeld));
    updateHeldBadge();
    renderCart();
    showToast('Sale resumed', 'success');
}

function discardHeld(holdId) {
    if (!confirm('Discard this held sale?')) return;
    bootstrap.Modal.getInstance(document.getElementById('heldSalesModal'))?.hide();
    const newHeld = JSON.parse(localStorage.getItem('heldSales') || '[]').filter(h => h.id !== holdId);
    localStorage.setItem('heldSales', JSON.stringify(newHeld));
    updateHeldBadge();
    if (newHeld.length > 0) setTimeout(openHeldSales, 300);
}

renderCart();
updateHeldBadge();

// ── New Customer from POS ─────────────────────────────────────────────────────
function openNewCustModal() {
    document.getElementById('nc-name').value    = '';
    document.getElementById('nc-phone').value   = '';
    document.getElementById('nc-balance').value = '0';
    document.getElementById('nc-error').textContent = '';
    new bootstrap.Modal(document.getElementById('newCustPosModal')).show();
    setTimeout(() => document.getElementById('nc-name').focus(), 300);
}

function saveNewCustPos() {
    const name    = document.getElementById('nc-name').value.trim();
    const phone   = document.getElementById('nc-phone').value.trim();
    const balance = parseFloat(document.getElementById('nc-balance').value) || 0;
    const errEl   = document.getElementById('nc-error');
    if (!name) { errEl.textContent = 'Name is required.'; return; }
    errEl.textContent = '';
    fetch('/dahdouh/pages/api.php?action=create_customer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `name=${encodeURIComponent(name)}&phone=${encodeURIComponent(phone)}&balance=${balance}`
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) { errEl.textContent = d.error || 'Error saving customer.'; return; }
        bootstrap.Modal.getInstance(document.getElementById('newCustPosModal'))?.hide();
        selectCustomer({ id: d.id, name, phone, balance: balance.toFixed(2) });
        showToast('Customer "' + name + '" created and selected', 'success');
    })
    .catch(() => { errEl.textContent = 'Network error.'; });
}
</script>

<script>
let _vnumField = 'usd';

function vnumSetField(which) {
    _vnumField = which;
    document.getElementById('em-tab-usd').className = which === 'usd' ? 'btn btn-success active' : 'btn btn-outline-success';
    document.getElementById('em-tab-lbp').className = which === 'lbp' ? 'btn btn-warning active' : 'btn btn-outline-warning';
}

function vnumPress(k) {
    const inp = document.getElementById(_vnumField === 'usd' ? 'modal-paid-usd' : 'modal-paid-lbp');
    if (!inp) return;
    let v = inp.value || '';
    if (k === '⌫') {
        v = v.slice(0, -1);
    } else if (k === '.') {
        if (!v.includes('.')) v += '.';
    } else {
        if (v === '0') v = k; else v += k;
    }
    inp.value = v;
    calcModalChange();
}

function vnumClear() {
    const inp = document.getElementById(_vnumField === 'usd' ? 'modal-paid-usd' : 'modal-paid-lbp');
    if (inp) { inp.value = ''; calcModalChange(); }
}
</script>

<?php renderFoot(); ?>
