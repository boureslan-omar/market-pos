<?php
require_once __DIR__ . '/../includes/config.php';
requireRole('admin','stock');

$ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
if (!$ids) { echo '<p>No products selected.</p>'; exit; }

$in   = implode(',', $ids);
$stmt = $pdo->query("SELECT id, name, barcode, sell_price FROM products WHERE id IN ($in) AND barcode IS NOT NULL AND barcode != '' ORDER BY name");
$products = $stmt->fetchAll();
$rate = EXCHANGE_RATE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print Barcodes — <?= htmlspecialchars(STORE_NAME) ?></title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #fff; font-family: Arial, sans-serif; }

.no-print { padding: 12px; background: #f3f4f6; border-bottom: 1px solid #ddd; }
.no-print button { padding: 8px 20px; background: #1a73e8; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-right: 8px; }
.no-print button.secondary { background: #6c757d; }

.label-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 12px;
}
.label {
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 6px 8px;
    text-align: center;
    width: 180px;
    flex-shrink: 0;
    page-break-inside: avoid;
    break-inside: avoid;
}
.label .store { font-size: 9px; color: #555; margin-bottom: 2px; }
.label .pname { font-size: 10px; font-weight: bold; color: #1f2937; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label svg { max-width: 100%; height: 50px; }
.label .price-usd { font-size: 11px; font-weight: bold; color: #1a73e8; margin-top: 2px; }
.label .price-lbp { font-size: 8px; color: #9ca3af; }

@media print {
    @page { margin: 0; size: auto; }
    .no-print { display: none !important; height: 0 !important; overflow: hidden !important; }
    body { margin: 0; padding: 0; }
    .label-grid { padding: 4mm; gap: 4mm; }
    .label { border: 1px solid #999; }
}
</style>
</head>
<body>

<div class="no-print" style="display:flex;align-items:center;flex-wrap:wrap;gap:12px">
    <button onclick="window.print()">🖨️ Print</button>
    <button class="secondary" onclick="window.close()">← Back</button>
    <span style="font-size:13px;color:#555"><?= count($products) ?> product(s) — set quantities below then print</span>
    <label style="font-size:13px;color:#555;margin-left:auto">
        Set all:
        <input type="number" id="setAll" min="1" max="100" value="" style="width:60px;padding:2px 6px;border:1px solid #ccc;border-radius:4px">
        <button onclick="applyAll()" style="padding:4px 10px;font-size:12px">Apply</button>
    </label>
</div>

<div class="no-print" style="padding:8px 12px;background:#fff;border-bottom:1px solid #ddd">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="background:#f8f9fa"><th style="padding:4px 8px;text-align:left">Product</th><th style="padding:4px 8px;width:120px">Labels to print</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
            <td style="padding:4px 8px"><?= htmlspecialchars($p['name']) ?></td>
            <td style="padding:4px 8px"><input type="number" class="qty-input" data-id="<?= $p['id'] ?>" min="1" max="200" value="1" style="width:70px;padding:2px 6px;border:1px solid #ccc;border-radius:4px" onchange="rebuildGrid()"></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="label-grid" id="grid"></div>

<script>
const PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id'        => $p['id'],
    'name'      => $p['name'],
    'barcode'   => $p['barcode'],
    'sell_price'=> $p['sell_price'],
    'format'    => strlen($p['barcode']) === 13 ? 'EAN13' : (strlen($p['barcode']) === 8 ? 'EAN8' : 'CODE128'),
], $products)) ?>;
const STORE   = <?= json_encode(STORE_NAME) ?>;
const RATE    = <?= EXCHANGE_RATE ?>;

function applyAll() {
    const v = parseInt(document.getElementById('setAll').value);
    if (!v || v < 1) return;
    document.querySelectorAll('.qty-input').forEach(el => el.value = v);
    rebuildGrid();
}

let bcIdx = 0;
function rebuildGrid() {
    const grid = document.getElementById('grid');
    grid.innerHTML = '';
    bcIdx = 0;
    PRODUCTS.forEach(p => {
        const qty = parseInt(document.querySelector('.qty-input[data-id="' + p.id + '"]')?.value) || 1;
        for (let i = 0; i < qty; i++) {
            const uid = 'bc-' + p.id + '-' + bcIdx++;
            const div = document.createElement('div');
            div.className = 'label';
            div.innerHTML = `
                <div class="store">${STORE}</div>
                <div class="pname" title="${p.name}">${p.name}</div>
                <svg id="${uid}"></svg>
                <div class="price-usd">$${parseFloat(p.sell_price).toFixed(2)}</div>
                <div class="price-lbp">${Math.round(p.sell_price * RATE).toLocaleString()} LBP</div>
            `;
            grid.appendChild(div);
            JsBarcode('#' + uid, p.barcode, { format: p.format, width: 1.5, height: 45, displayValue: true, fontSize: 9, margin: 2 });
        }
    });
}
document.addEventListener('DOMContentLoaded', rebuildGrid);
</script>
</body>
</html>
