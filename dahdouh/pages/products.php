<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

$message = '';

if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $inSales = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id=?");
    $inSales->execute([$did]);
    $inPurch = $pdo->prepare("SELECT COUNT(*) FROM purchase_items WHERE product_id=?");
    $inPurch->execute([$did]);
    if ($inSales->fetchColumn() > 0 || $inPurch->fetchColumn() > 0) {
        $message = 'error:Cannot delete — this product has sales or purchase history. Deactivate it instead by setting stock to 0.';
    } else {
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$did]);
        header('Location: products.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $barcode     = trim($_POST['barcode'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $cat         = (int)($_POST['category_id'] ?? 0) ?: null;
    $sup         = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $type        = in_array($_POST['product_type'] ?? '', ['regular','bulk']) ? $_POST['product_type'] : 'regular';
    $cost        = (float)($_POST['cost_price'] ?? 0);
    $sell        = (float)($_POST['sell_price'] ?? 0);
    $stock       = (float)($_POST['stock'] ?? 0);
    $alert       = (float)($_POST['low_stock_alert'] ?? 5);
    $unit        = trim($_POST['unit'] ?? 'pcs');
    $upb         = max(1, (int)($_POST['units_per_box'] ?? 1));
    $boxPrice    = (float)($_POST['sell_price_box'] ?? 0) ?: null;
    $source      = ($_POST['product_source'] ?? '') === 'consignment' ? 'consignment' : 'owned';
    $consSup     = (int)($_POST['consignment_supplier_id'] ?? 0) ?: null;
    $consCost    = (float)($_POST['consignment_cost'] ?? 0);

    if ($source === 'consignment') {
        $consCost = $consCost ?: $cost;
        $cost = $consCost;
    }

    if (!$name) {
        $message = 'error:Product name is required.';
    } else {
        if (empty($barcode)) {
            $barcode = generateEAN13($pdo);
        }
        if ($id) {
            $pdo->prepare("UPDATE products SET barcode=?,name=?,category_id=?,supplier_id=?,product_type=?,cost_price=?,sell_price=?,stock=?,low_stock_alert=?,unit=?,units_per_box=?,sell_price_box=?,product_source=?,consignment_supplier_id=?,consignment_cost=? WHERE id=?")
                ->execute([$barcode,$name,$cat,$sup,$type,$cost,$sell,$stock,$alert,$unit,$upb,$boxPrice,$source,$consSup,$consCost,$id]);
        } else {
            $pdo->prepare("INSERT INTO products (barcode,name,category_id,supplier_id,product_type,cost_price,sell_price,stock,low_stock_alert,unit,units_per_box,sell_price_box,product_source,consignment_supplier_id,consignment_cost) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$barcode,$name,$cat,$sup,$type,$cost,$sell,$stock,$alert,$unit,$upb,$boxPrice,$source,$consSup,$consCost]);
        }
        $message = 'success:Product saved.';
    }
}

$search    = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$typeFilter = $_GET['type'] ?? '';
$whereSQL  = [];
$params    = [];
if ($search)    { $whereSQL[] = "(p.name LIKE ? OR p.barcode LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $whereSQL[] = "p.category_id = ?"; $params[] = $catFilter; }
if (in_array($typeFilter,['regular','bulk'])) { $whereSQL[] = "p.product_type = ?"; $params[] = $typeFilter; }
$where = $whereSQL ? 'WHERE ' . implode(' AND ', $whereSQL) : '';

$stmt = $pdo->prepare("SELECT p.*, c.name AS cat_name, s.name AS sup_name FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN suppliers s ON s.id=p.supplier_id $where ORDER BY p.product_type, p.name");
$stmt->execute($params);
$products   = $stmt->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$suppliers  = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

renderHead('Products');
renderNav('products');
alertBox($message);
?>
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-box-seam me-2"></i>Products</h4>
    <div class="d-flex gap-2">
        <a href="import_products.php?export=1" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
        <a href="import_products.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-upload"></i> Import XLSX/CSV
        </a>
        <button id="print-barcodes-btn" class="btn btn-outline-secondary d-none" onclick="printSelectedBarcodes()">
            <i class="bi bi-upc-scan"></i> Print Barcodes <span id="sel-count" class="badge bg-secondary ms-1">0</span>
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="clearForm()">
            <i class="bi bi-plus-lg"></i> Add Product
        </button>
    </div>
</div>

<!-- Filters -->
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3"><input type="text" name="q" class="form-control" placeholder="Search name / barcode" value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-2">
        <select name="cat" class="form-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="type" class="form-select">
            <option value="">All Types</option>
            <option value="regular" <?= $typeFilter==='regular'?'selected':'' ?>>Regular (tracked)</option>
            <option value="bulk" <?= $typeFilter==='bulk'?'selected':'' ?>>Bulk (veg/fruits)</option>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button></div>
    <div class="col-auto"><a href="products.php" class="btn btn-outline-secondary">Reset</a></div>
</form>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
        <th>Type</th><th>Barcode</th><th>Name</th><th>Category</th>
        <th>Cost</th><th>Sell Price</th><th>Margin%</th><th>Stock / Batches</th><th>Unit</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($products as $p):
        $margin = $p['sell_price'] > 0 ? round((($p['sell_price']-$p['cost_price'])/$p['sell_price'])*100,1) : 0;
        $isBulk = $p['product_type'] === 'bulk';
    ?>
    <tr class="<?= $isBulk ? 'table-warning bg-opacity-25' : '' ?>">
        <td><input type="checkbox" class="prod-chk" value="<?= $p['id'] ?>" data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>" onchange="updateSelection()"></td>
        <td>
            <?php if ($p['product_source'] === 'consignment'): ?>
            <span class="badge bg-purple text-white" style="background:#7c3aed!important">Consignment</span>
            <?php elseif ($isBulk): ?>
            <span class="badge bg-warning text-dark">Bulk</span>
            <?php else: ?>
            <span class="badge bg-info text-dark">Regular</span>
            <?php endif; ?>
        </td>
        <td class="text-muted small"><?= htmlspecialchars($p['barcode'] ?: '—') ?></td>
        <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
        <td><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
        <td><?= fmtUSD($p['cost_price']) ?></td>
        <td><?= fmtUSD($p['sell_price']) ?></td>
        <td><span class="badge <?= $margin>20?'bg-success':($margin>10?'bg-warning text-dark':'bg-danger') ?>"><?= $margin ?>%</span></td>
        <td>
            <?php if ($isBulk): ?>
                <span class="text-muted">—</span>
            <?php else: ?>
                <span class="<?= $p['stock']==0?'stock-out fw-bold':($p['stock']<=$p['low_stock_alert']?'stock-low':'stock-ok') ?>"><?= (float)$p['stock'] ?></span>
                <button class="btn btn-link btn-sm p-0 ms-1 text-muted" title="View batches" onclick="viewBatches(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'])) ?>)">
                    <i class="bi bi-layers"></i>
                </button>
            <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($p['unit']) ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="fillForm(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil"></i></button>
            <a href="?delete=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this product?')"><i class="bi bi-trash"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="11" class="text-center text-muted py-4">No products found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST">
<input type="hidden" name="id" id="f_id">
<div class="modal-header"><h5 class="modal-title">Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">

    <!-- Source selector -->
    <div class="mb-3">
        <label class="form-label fw-bold">Product Source</label>
        <div class="d-flex gap-4">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="product_source" id="srcOwned" value="owned" checked onchange="toggleSource()">
                <label class="form-check-label" for="srcOwned"><strong>Owned</strong> — purchased by the market</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="product_source" id="srcConsignment" value="consignment" onchange="toggleSource()">
                <label class="form-check-label" for="srcConsignment"><strong>Consignment</strong> — supplier-owned, sold on behalf</label>
            </div>
        </div>
    </div>

    <!-- Consignment fields (shown only when source=consignment) -->
    <div id="consignment-fields" class="alert alert-info py-2 px-3 mb-3 d-none">
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Consignment Supplier *</label>
                <select name="consignment_supplier_id" id="f_cons_sup" class="form-select form-select-sm">
                    <option value="">— Select Supplier —</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Supplier Cost / Unit (USD) *</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" name="consignment_cost" id="f_cons_cost" class="form-control" step="0.0001" min="0" placeholder="What supplier charges per unit">
                </div>
                <div class="form-text">This amount goes to the supplier when sold.</div>
            </div>
        </div>
    </div>

    <!-- Type selector -->
    <div class="mb-3">
        <label class="form-label fw-bold">Product Type</label>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="product_type" id="typeRegular" value="regular" checked onchange="toggleType()">
                <label class="form-check-label" for="typeRegular"><strong>Regular</strong> — tracked stock, batch pricing</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="product_type" id="typeBulk" value="bulk" onchange="toggleType()">
                <label class="form-check-label" for="typeBulk"><strong>Bulk</strong> — vegetables, fruits (no stock count)</label>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Name *</label><input type="text" name="name" id="f_name" class="form-control" required></div>
        <div class="col-md-6">
            <label class="form-label">Barcode</label>
            <div class="input-group">
                <input type="text" name="barcode" id="f_barcode" class="form-control" placeholder="Scan or type">
                <button type="button" class="btn btn-outline-secondary" onclick="generateBarcode()" title="Auto-generate EAN-13 barcode">
                    <i class="bi bi-upc-scan"></i> Generate
                </button>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Category</label>
            <select name="category_id" id="f_cat" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" id="f_sup" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Unit</label>
            <select name="unit" id="f_unit" class="form-select" onchange="onUnitChange()">
                <option value="pcs">pcs — pieces</option>
                <option value="box">box</option>
                <option value="kg">kg — kilograms</option>
                <option value="g">g — grams</option>
                <option value="L">L — litres</option>
                <option value="mL">mL — millilitres</option>
            </select>
        </div>
        <!-- Regular unit pricing (hidden when unit=box) -->
        <div class="col-md-4" id="row-cost"><label class="form-label">Cost Price (USD)</label><input type="number" name="cost_price" id="f_cost" class="form-control" step="0.0001" min="0"></div>
        <div class="col-md-4" id="row-sell">
            <label class="form-label d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span id="sell-label">Sell Price / Unit (USD)</span>
                <span class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="use-margin" onchange="toggleMarginMode()">
                    <label class="form-check-label small text-muted fw-normal" for="use-margin">Set by margin %</label>
                </span>
            </label>
            <input type="number" name="sell_price" id="f_sell" class="form-control" step="0.0001" min="0" oninput="calcMargin()">
            <div id="sell-calc-preview" class="form-text text-muted" style="display:none"></div>
        </div>
        <div class="col-md-4" id="row-margin">
            <label class="form-label">Margin %</label>
            <div class="form-control-plaintext fw-bold" id="margin-preview">—</div>
            <div id="margin-input-wrap" style="display:none">
                <div class="input-group input-group-sm">
                    <input type="number" id="f_margin_pct" class="form-control" step="0.1" min="0" max="99.9" placeholder="e.g. 25" oninput="calcSellFromMargin()">
                    <span class="input-group-text">%</span>
                </div>
                <div id="margin-sell-preview" class="form-text"></div>
            </div>
        </div>
        <div class="col-md-4 regular-only"><label class="form-label">Stock</label><input type="number" name="stock" id="f_stock" class="form-control" min="0" step="0.001" value="0"></div>
        <div class="col-md-4 regular-only"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" id="f_alert" class="form-control" min="0" step="0.001" value="5"></div>
        <!-- Box fields (shown only when unit=box) -->
        <div class="col-12" id="row-box-header" style="display:none"><hr class="my-1"><label class="form-label fw-semibold small text-muted">📦 Box Details</label></div>
        <div class="col-md-4" id="row-upb" style="display:none">
            <label class="form-label">Units per Box</label>
            <input type="number" name="units_per_box" id="f_upb" class="form-control" min="1" step="1" value="1" oninput="calcFromBox()">
        </div>
        <div class="col-md-4" id="row-cost-box" style="display:none">
            <label class="form-label">Cost per Box (USD)</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" id="f_cost_box" class="form-control" step="0.0001" min="0" placeholder="What you pay per box" oninput="calcFromBox()">
            </div>
        </div>
        <div class="col-md-4" id="row-sell-box" style="display:none">
            <label class="form-label d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>Sell Price per Box (USD)</span>
                <span class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="use-margin-box" onchange="toggleBoxMarginMode()">
                    <label class="form-check-label small text-muted fw-normal" for="use-margin-box">Set by margin %</label>
                </span>
            </label>
            <input type="number" name="sell_price_box" id="f_sell_box" class="form-control" step="0.0001" min="0" oninput="calcFromBox()">
        </div>
        <div class="col-md-4" id="row-box-margin" style="display:none">
            <label class="form-label">Box Margin %</label>
            <div class="form-control-plaintext fw-bold text-muted" id="box-margin-preview">—</div>
            <div id="box-margin-input-wrap" style="display:none">
                <div class="input-group input-group-sm">
                    <input type="number" id="f_box_margin_pct" class="form-control" step="0.1" min="0" max="99.9" placeholder="e.g. 25" oninput="calcBoxSellFromMargin()">
                    <span class="input-group-text">%</span>
                </div>
                <div id="box-margin-sell-preview" class="form-text"></div>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary">Save Product</button>
</div>
</form>
</div></div></div>

<script>
function toggleSource() {
    const isCons = document.getElementById('srcConsignment').checked;
    document.getElementById('consignment-fields').classList.toggle('d-none', !isCons);
    document.getElementById('f_cons_cost').required = isCons;
    document.getElementById('f_cons_sup').required  = isCons;
}

function toggleType() {
    const isBulk = document.getElementById('typeBulk').checked;
    document.querySelectorAll('.regular-only').forEach(el => el.style.display = isBulk ? 'none' : '');
    onUnitChange();
}

function onUnitChange() {
    const isBulk = document.getElementById('typeBulk').checked;
    const isBox  = document.getElementById('f_unit').value === 'box';
    const showBox  = !isBulk && isBox;
    const showUnit = !isBulk && !isBox;

    document.getElementById('row-cost').style.display       = showUnit ? '' : 'none';
    document.getElementById('row-sell').style.display       = showUnit ? '' : 'none';
    document.getElementById('row-margin').style.display     = showUnit ? '' : 'none';
    document.getElementById('row-box-header').style.display = showBox  ? '' : 'none';
    document.getElementById('row-upb').style.display        = showBox  ? '' : 'none';
    document.getElementById('row-cost-box').style.display   = showBox  ? '' : 'none';
    document.getElementById('row-sell-box').style.display   = showBox  ? '' : 'none';
    document.getElementById('row-box-margin').style.display = showBox  ? '' : 'none';

    if (showBox) calcFromBox();
}

function calcFromBox() {
    const upb     = Math.max(1, parseInt(document.getElementById('f_upb').value || 1));
    const costBox = parseFloat(document.getElementById('f_cost_box').value || 0);
    const sellBox = parseFloat(document.getElementById('f_sell_box').value || 0);
    if (costBox > 0) document.getElementById('f_cost').value = (costBox / upb).toFixed(4);
    if (!document.getElementById('use-margin-box')?.checked && sellBox > 0)
        document.getElementById('f_sell').value = (sellBox / upb).toFixed(4);
    if (document.getElementById('use-margin-box')?.checked) {
        calcBoxSellFromMargin();
    } else {
        calcBoxPrice();
    }
    if (document.getElementById('use-margin')?.checked) calcSellFromMargin();
    else calcMargin();
}

function calcBoxPrice() {
    const upb      = parseInt(document.getElementById('f_upb')?.value || 1);
    const boxPrice = parseFloat(document.getElementById('f_sell_box')?.value || 0);
    const cost     = parseFloat(document.getElementById('f_cost')?.value || 0);
    const el       = document.getElementById('box-margin-preview');
    if (!el) return;
    if (!boxPrice || upb < 1) { el.textContent = '—'; el.className = 'form-control-plaintext fw-bold text-muted'; return; }
    const boxCost = cost * upb;
    const margin  = boxPrice > 0 ? (((boxPrice - boxCost) / boxPrice) * 100).toFixed(1) : '—';
    el.textContent = margin + '%  — $' + cost.toFixed(4) + '/unit';
    el.className = 'form-control-plaintext fw-bold ' + (boxPrice > boxCost ? 'text-success' : 'text-danger');
}

function clearForm() {
    document.getElementById('f_id').value = '';
    document.getElementById('f_name').value = '';
    document.getElementById('f_barcode').value = '';
    document.getElementById('f_cost').value = '';
    document.getElementById('f_sell').value = '';
    document.getElementById('f_stock').value = '0';
    document.getElementById('f_alert').value = '5';
    document.getElementById('f_unit').value = 'pcs';
    document.getElementById('f_upb').value = '1';
    document.getElementById('f_sell_box').value = '';
    document.getElementById('f_cost_box').value = '';
    document.getElementById('f_cat').value = '';
    document.getElementById('f_sup').value = '';
    document.getElementById('f_cons_sup').value = '';
    document.getElementById('f_cons_cost').value = '';
    document.getElementById('typeRegular').checked = true;
    document.getElementById('srcOwned').checked = true;
    document.getElementById('margin-preview').textContent = '—';
    document.getElementById('box-margin-preview').textContent = '—';
    resetMarginModes();
    toggleType();
    toggleSource();
}

function fillForm(p) {
    document.getElementById('f_id').value      = p.id;
    document.getElementById('f_name').value    = p.name;
    document.getElementById('f_barcode').value = p.barcode || '';
    document.getElementById('f_cat').value     = p.category_id || '';
    document.getElementById('f_sup').value     = p.supplier_id || '';
    document.getElementById('f_unit').value    = p.unit || 'pcs';
    document.getElementById('f_cost').value    = p.cost_price;
    document.getElementById('f_sell').value    = p.sell_price;
    document.getElementById('f_stock').value   = p.stock;
    document.getElementById('f_alert').value   = p.low_stock_alert;
    document.getElementById(p.product_type === 'bulk' ? 'typeBulk' : 'typeRegular').checked = true;
    document.getElementById(p.product_source === 'consignment' ? 'srcConsignment' : 'srcOwned').checked = true;
    document.getElementById('f_cons_sup').value  = p.consignment_supplier_id || '';
    document.getElementById('f_cons_cost').value = p.consignment_cost || '';
    document.getElementById('f_upb').value       = p.units_per_box || 1;
    document.getElementById('f_sell_box').value  = p.sell_price_box || '';
    const upb = parseInt(p.units_per_box || 1);
    document.getElementById('f_cost_box').value  = (p.unit === 'box' && parseFloat(p.cost_price) > 0)
        ? (parseFloat(p.cost_price) * upb).toFixed(4) : '';
    resetMarginModes();
    calcMargin();
    calcBoxPrice();
    toggleType();
    toggleSource();
}

function calcMargin() {
    if (document.getElementById('use-margin')?.checked) { calcSellFromMargin(); return; }
    const cost = parseFloat(document.getElementById('f_cost')?.value || 0);
    const sell = parseFloat(document.getElementById('f_sell')?.value || 0);
    const el = document.getElementById('margin-preview');
    el.textContent = sell > 0 ? (((sell - cost) / sell) * 100).toFixed(1) + '%' : '—';
    el.className = 'form-control-plaintext fw-bold ' + (sell > cost ? 'text-success' : 'text-danger');
}

// ── Margin-mode helpers ───────────────────────────────────────────────────────

function resetMarginModes() {
    document.getElementById('use-margin').checked     = false;
    document.getElementById('use-margin-box').checked = false;
    document.getElementById('f_sell').readOnly        = false;
    document.getElementById('f_sell').style.background = '';
    document.getElementById('f_sell_box').readOnly    = false;
    document.getElementById('f_sell_box').style.background = '';
    document.getElementById('margin-preview').style.display      = '';
    document.getElementById('margin-input-wrap').style.display   = 'none';
    document.getElementById('box-margin-preview').style.display  = '';
    document.getElementById('box-margin-input-wrap').style.display = 'none';
    document.getElementById('f_margin_pct').value       = '';
    document.getElementById('f_box_margin_pct').value   = '';
    document.getElementById('margin-sell-preview').textContent     = '';
    document.getElementById('box-margin-sell-preview').textContent = '';
}

function toggleMarginMode() {
    const on = document.getElementById('use-margin').checked;
    document.getElementById('f_sell').readOnly        = on;
    document.getElementById('f_sell').style.background = on ? '#f8f9fa' : '';
    document.getElementById('margin-preview').style.display    = on ? 'none' : '';
    document.getElementById('margin-input-wrap').style.display = on ? ''     : 'none';
    if (on) {
        // pre-fill margin from current sell price if possible
        const cost = parseFloat(document.getElementById('f_cost').value || 0);
        const sell = parseFloat(document.getElementById('f_sell').value || 0);
        if (sell > 0 && cost >= 0) {
            document.getElementById('f_margin_pct').value = ((sell - cost) / sell * 100).toFixed(1);
        }
        calcSellFromMargin();
    } else {
        document.getElementById('f_sell').value = '';
        document.getElementById('margin-sell-preview').textContent = '';
        calcMargin();
    }
}

function calcSellFromMargin() {
    const cost    = parseFloat(document.getElementById('f_cost').value || 0);
    const pct     = parseFloat(document.getElementById('f_margin_pct').value);
    const preview = document.getElementById('margin-sell-preview');
    if (isNaN(pct) || pct === 0) {
        preview.textContent = ''; document.getElementById('f_sell').value = ''; return;
    }
    if (pct >= 100) {
        preview.textContent = 'Invalid — must be less than 100%';
        preview.className   = 'form-text text-danger fw-bold';
        document.getElementById('f_sell').value = ''; return;
    }
    if (cost <= 0) {
        preview.textContent = 'Enter cost price first';
        preview.className   = 'form-text text-muted'; return;
    }
    const sell = cost / (1 - pct / 100);
    document.getElementById('f_sell').value = sell.toFixed(4);
    preview.textContent = '→ Sell price: $' + sell.toFixed(2);
    preview.className   = 'form-text text-success fw-bold';
}

function toggleBoxMarginMode() {
    const on = document.getElementById('use-margin-box').checked;
    document.getElementById('f_sell_box').readOnly        = on;
    document.getElementById('f_sell_box').style.background = on ? '#f8f9fa' : '';
    document.getElementById('box-margin-preview').style.display     = on ? 'none' : '';
    document.getElementById('box-margin-input-wrap').style.display  = on ? ''     : 'none';
    if (on) {
        const upb     = Math.max(1, parseInt(document.getElementById('f_upb').value || 1));
        const cost    = parseFloat(document.getElementById('f_cost').value || 0);
        const boxSell = parseFloat(document.getElementById('f_sell_box').value || 0);
        const boxCost = cost * upb;
        if (boxSell > 0 && boxCost > 0) {
            document.getElementById('f_box_margin_pct').value = ((boxSell - boxCost) / boxSell * 100).toFixed(1);
        }
        calcBoxSellFromMargin();
    } else {
        document.getElementById('f_sell_box').value = '';
        document.getElementById('box-margin-sell-preview').textContent = '';
        calcBoxPrice();
    }
}

function calcBoxSellFromMargin() {
    const upb     = Math.max(1, parseInt(document.getElementById('f_upb').value || 1));
    const cost    = parseFloat(document.getElementById('f_cost').value || 0);
    const boxCost = cost * upb;
    const pct     = parseFloat(document.getElementById('f_box_margin_pct').value);
    const preview = document.getElementById('box-margin-sell-preview');
    if (isNaN(pct) || pct === 0) {
        preview.textContent = ''; document.getElementById('f_sell_box').value = ''; return;
    }
    if (pct >= 100) {
        preview.textContent = 'Invalid — must be less than 100%';
        preview.className   = 'form-text text-danger fw-bold';
        document.getElementById('f_sell_box').value = ''; return;
    }
    if (boxCost <= 0) {
        preview.textContent = 'Enter cost per box first';
        preview.className   = 'form-text text-muted'; return;
    }
    const boxSell = boxCost / (1 - pct / 100);
    document.getElementById('f_sell_box').value = boxSell.toFixed(4);
    preview.textContent = '→ Box sell: $' + boxSell.toFixed(2) + ' ($' + (boxSell / upb).toFixed(4) + '/unit)';
    preview.className   = 'form-text text-success fw-bold';
}

document.getElementById('f_cost')?.addEventListener('input', () => {
    calcMargin();
    if (document.getElementById('use-margin-box')?.checked) calcBoxSellFromMargin();
});
document.getElementById('f_sell')?.addEventListener('input', calcMargin);

// ── Generate barcode ─────────────────────────────────────────────────────────
function generateBarcode() {
    fetch('/dahdouh/pages/api.php?action=generate_barcode')
        .then(r => r.json())
        .then(d => {
            document.getElementById('f_barcode').value = d.barcode;
        });
}

// ── Selection & print ─────────────────────────────────────────────────────────
function updateSelection() {
    const checked = document.querySelectorAll('.prod-chk:checked');
    const btn     = document.getElementById('print-barcodes-btn');
    const cnt     = document.getElementById('sel-count');
    cnt.textContent = checked.length;
    btn.classList.toggle('d-none', checked.length === 0);
}

function toggleAll(master) {
    document.querySelectorAll('.prod-chk').forEach(c => c.checked = master.checked);
    updateSelection();
}

function printSelectedBarcodes() {
    const ids = [...document.querySelectorAll('.prod-chk:checked')]
        .map(c => c.value).join(',');
    window.open('/dahdouh/pages/print_barcodes.php?ids=' + ids, '_blank');
}

// ── Batch viewer ──────────────────────────────────────────────────────────────
function viewBatches(productId, productName) {
    document.getElementById('batch-modal-title').textContent = productName + ' — Batches';
    const body = document.getElementById('batch-modal-body');
    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    const modal = new bootstrap.Modal(document.getElementById('batchModal'));
    modal.show();

    fetch(`/dahdouh/pages/api.php?action=get_batches&product_id=${productId}`)
        .then(r => r.json())
        .then(batches => {
            if (!batches.length) { body.innerHTML = '<p class="text-muted text-center py-3">No batches found.</p>'; return; }
            const rows = batches.map(b => {
                const pct = b.quantity_original > 0 ? ((b.quantity_remaining / b.quantity_original) * 100).toFixed(0) : 0;
                const cls = b.status === 'active' ? 'success' : 'secondary';
                return `<tr class="${b.status === 'depleted' ? 'table-secondary opacity-75' : ''}">
                    <td><span class="badge bg-${cls}">#${b.id}</span></td>
                    <td>${b.purchase_date}</td>
                    <td class="fw-bold">$${parseFloat(b.cost_price).toFixed(4)}</td>
                    <td>${parseFloat(b.quantity_original)}</td>
                    <td class="${b.quantity_remaining <= 0 ? 'text-danger fw-bold' : 'text-success fw-bold'}">${parseFloat(b.quantity_remaining)}</td>
                    <td>
                        <div class="progress" style="height:8px;min-width:60px">
                            <div class="progress-bar bg-${cls}" style="width:${pct}%"></div>
                        </div>
                        <div style="font-size:.7rem">${pct}% left</div>
                    </td>
                    <td class="small text-muted">${b.reference || '—'}</td>
                </tr>`;
            }).join('');
            body.innerHTML = `
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark"><tr>
                    <th>Batch</th><th>Date</th><th>Cost Price</th><th>Orig Qty</th><th>Remaining</th><th>Progress</th><th>Ref</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        })
        .catch(() => { body.innerHTML = '<p class="text-danger">Failed to load batches.</p>'; });
}
</script>

<!-- Batch Viewer Modal -->
<div class="modal fade" id="batchModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title" id="batch-modal-title"><i class="bi bi-layers me-2"></i>Batches</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-0" id="batch-modal-body"></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div>
</div>
</div>

<?php renderFoot(); ?>
