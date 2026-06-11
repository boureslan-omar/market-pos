<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','stock');

// ── EXPORT ────────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $products = $pdo->query("
        SELECT p.barcode, p.name, c.name AS category, s.name AS supplier,
               p.product_type, p.cost_price, p.sell_price, p.stock, p.unit, p.low_stock_alert
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN suppliers s ON s.id=p.supplier_id
        ORDER BY p.name
    ")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['Barcode','Name','Category','Supplier','Type (regular/bulk)','Cost Price','Sell Price','Stock','Unit','Low Stock Alert']);
    foreach ($products as $p) {
        fputcsv($out, [
            $p['barcode'], $p['name'], $p['category'], $p['supplier'],
            $p['product_type'], $p['cost_price'], $p['sell_price'],
            $p['stock'], $p['unit'], $p['low_stock_alert']
        ]);
    }
    fclose($out);
    exit;
}

// ── XLSX / CSV PARSER ─────────────────────────────────────────────────────────
function parseXLSX($path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    // Shared strings
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) foreach ($ss->si as $si) {
            if (isset($si->t)) { $strings[] = (string)$si->t; }
            elseif (isset($si->r)) { $t=''; foreach($si->r as $r) $t.=(string)$r->t; $strings[]=$t; }
            else $strings[] = '';
        }
    }

    // Sheet data — try sheet1 first, then look in workbook for first sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        // Try to find first sheet name from workbook
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml) {
            $wb = @simplexml_load_string($wbXml);
            $wb->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $wb->xpath('//ns:sheet');
            if ($sheets) {
                $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rId = $attrs ? (string)$attrs['id'] : '';
                // Try relsXml
                $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($relsXml) {
                    $rels = @simplexml_load_string($relsXml);
                    if ($rels) foreach ($rels->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rId) {
                            $sheetXml = $zip->getFromName('xl/' . (string)$rel['Target']);
                            break;
                        }
                    }
                }
            }
        }
    }
    $zip->close();
    if (!$sheetXml) return false;

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) return false;
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        $maxCol = 0;
        foreach ($row->c as $cell) {
            preg_match('/([A-Z]+)(\d+)/', (string)$cell['r'], $m);
            $col = 0;
            for ($i = 0; $i < strlen($m[1]); $i++) $col = $col * 26 + (ord($m[1][$i]) - 64);
            $col--;
            $maxCol = max($maxCol, $col);
            $t = (string)$cell['t'];
            if ($t === 's') { $rowData[$col] = $strings[(int)(string)$cell->v] ?? ''; }
            elseif ($t === 'b') { $rowData[$col] = ((string)$cell->v) ? 'TRUE' : 'FALSE'; }
            elseif (isset($cell->v)) { $rowData[$col] = (string)$cell->v; }
            else { $rowData[$col] = ''; }
        }
        // Fill gaps
        for ($i=0; $i<=$maxCol; $i++) if (!isset($rowData[$i])) $rowData[$i]='';
        ksort($rowData);
        $rows[] = array_values($rowData);
    }
    return $rows;
}

function parseCSV($path) {
    $rows = [];
    if (($fh = fopen($path, 'r')) !== false) {
        while (($row = fgetcsv($fh)) !== false) {
            // Strip UTF-8 BOM from first field
            if ($rows === [] && $row[0]) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            $rows[] = $row;
        }
        fclose($fh);
    }
    return $rows;
}

// ── IMPORT EXECUTE ────────────────────────────────────────────────────────────
$importResults = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $rows   = json_decode($_POST['rows_json'] ?? '[]', true);
    $map    = $_POST['map'] ?? [];
    $onDup  = $_POST['on_duplicate'] ?? 'skip';
    $catCache = $supCache = [];
    $stats  = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>[]];

    $fieldIdx = [];
    foreach ($map as $field => $colIdx) { if ($colIdx !== '') $fieldIdx[$field] = (int)$colIdx; }

    $usedBarcodes = []; // track barcodes generated in this import run to avoid in-batch clashes

    foreach ($rows as $r) {
        try {
            $name = trim($r[$fieldIdx['name'] ?? -1] ?? '');
            if (!$name) { $stats['skipped']++; continue; }

            $rawBarcode = trim($r[$fieldIdx['barcode'] ?? -1] ?? '');
            if ($rawBarcode) {
                $barcode = $rawBarcode;
            } else {
                do { $barcode = generateEAN13($pdo); } while (in_array($barcode, $usedBarcodes));
                $usedBarcodes[] = $barcode;
            }
            $catName  = trim($r[$fieldIdx['category'] ?? -1] ?? '');
            $supName  = trim($r[$fieldIdx['supplier'] ?? -1] ?? '');
            $ptype    = in_array(strtolower(trim($r[$fieldIdx['product_type'] ?? -1] ?? '')), ['bulk']) ? 'bulk' : 'regular';
            $cost     = (float)str_replace(',','',($r[$fieldIdx['cost_price'] ?? -1] ?? ''));
            $sell     = (float)str_replace(',','',($r[$fieldIdx['sell_price'] ?? -1] ?? ''));
            $stock    = (float)str_replace(',','',($r[$fieldIdx['stock'] ?? -1] ?? '0'));
            $unit     = trim($r[$fieldIdx['unit'] ?? -1] ?? '') ?: 'pcs';
            $alert    = (float)str_replace(',','',($r[$fieldIdx['low_stock_alert'] ?? -1] ?? '5'));
            $upb      = max(1, (int)str_replace(',','',($r[$fieldIdx['units_per_box'] ?? -1] ?? '1')));
            $boxPrice = (float)str_replace(',','',($r[$fieldIdx['sell_price_box'] ?? -1] ?? '')) ?: null;

            // Resolve category
            $catId = null;
            if ($catName) {
                if (!isset($catCache[$catName])) {
                    $ex = $pdo->prepare("SELECT id FROM categories WHERE name=?");
                    $ex->execute([$catName]);
                    $cid = $ex->fetchColumn();
                    if (!$cid) { $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$catName]); $cid=$pdo->lastInsertId(); }
                    $catCache[$catName] = $cid;
                }
                $catId = $catCache[$catName];
            }

            // Resolve supplier
            $supId = null;
            if ($supName) {
                if (!isset($supCache[$supName])) {
                    $ex = $pdo->prepare("SELECT id FROM suppliers WHERE name=?");
                    $ex->execute([$supName]);
                    $sid = $ex->fetchColumn();
                    if (!$sid) { $pdo->prepare("INSERT INTO suppliers (name) VALUES (?)")->execute([$supName]); $sid=$pdo->lastInsertId(); }
                    $supCache[$supName] = $sid;
                }
                $supId = $supCache[$supName];
            }

            // Check duplicate by barcode then name
            $existing = null;
            if ($barcode) {
                $ex = $pdo->prepare("SELECT id FROM products WHERE barcode=?");
                $ex->execute([$barcode]);
                $existing = $ex->fetchColumn() ?: null;
            }
            if (!$existing) {
                $ex = $pdo->prepare("SELECT id FROM products WHERE name=?");
                $ex->execute([$name]);
                $existing = $ex->fetchColumn() ?: null;
            }

            if ($existing) {
                if ($onDup === 'skip') { $stats['skipped']++; continue; }
                if ($onDup === 'update') {
                    $pdo->prepare("UPDATE products SET name=?,barcode=?,category_id=?,supplier_id=?,product_type=?,cost_price=?,sell_price=?,stock=?,unit=?,low_stock_alert=?,units_per_box=?,sell_price_box=? WHERE id=?")
                        ->execute([$name,$barcode,$catId,$supId,$ptype,$cost,$sell,$stock,$unit,$alert,$upb,$boxPrice,$existing]);
                    // Create batch only if regular product, has stock/cost, and no batches exist yet
                    if ($ptype === 'regular' && $stock > 0 && $cost > 0) {
                        $hasBatch = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE product_id=?");
                        $hasBatch->execute([$existing]);
                        if ((int)$hasBatch->fetchColumn() === 0) {
                            $pdo->prepare("INSERT INTO batches (product_id,cost_price,quantity_original,quantity_remaining,purchase_date,note) VALUES (?,?,?,?,CURDATE(),'Imported initial stock')")
                                ->execute([$existing,$cost,$stock,$stock]);
                        }
                    }
                    $stats['updated']++;
                }
            } else {
                $pdo->prepare("INSERT INTO products (name,barcode,category_id,supplier_id,product_type,cost_price,sell_price,stock,unit,low_stock_alert,units_per_box,sell_price_box) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$barcode,$catId,$supId,$ptype,$cost,$sell,$stock,$unit,$alert,$upb,$boxPrice]);
                $newId = (int)$pdo->lastInsertId();
                // Always create an initial batch for regular products with stock
                if ($ptype === 'regular' && $stock > 0 && $cost > 0) {
                    $pdo->prepare("INSERT INTO batches (product_id,cost_price,quantity_original,quantity_remaining,purchase_date,note) VALUES (?,?,?,?,CURDATE(),'Imported initial stock')")
                        ->execute([$newId,$cost,$stock,$stock]);
                }
                $stats['imported']++;
            }
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
        }
    }
    $importResults = $stats;
}

// ── PARSE UPLOADED FILE ───────────────────────────────────────────────────────
$parsedRows = null;
$parseError = '';
$step = 'upload';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'parse') {
    $file = $_FILES['import_file'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $parsedRows = parseCSV($file['tmp_name']);
        } elseif ($ext === 'xlsx') {
            $parsedRows = parseXLSX($file['tmp_name']);
        } else {
            $parseError = 'Only .csv and .xlsx files are supported.';
        }
        if ($parsedRows !== null && $parsedRows !== false && count($parsedRows) > 0) {
            $step = 'map';
        } elseif ($parsedRows !== false) {
            $parseError = 'File appears to be empty.';
        } else {
            $parseError = 'Could not read the file. Make sure it is a valid XLSX.';
        }
    } else {
        $parseError = 'No file uploaded.';
    }
}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$suppliers  = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$productCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

renderHead('Import / Export Products');
renderNav('products');
?>
<div class="container-fluid py-3">
<div class="d-flex align-items-center mb-3">
    <a href="products.php" class="btn btn-outline-secondary btn-sm me-3"><i class="bi bi-arrow-left"></i> Products</a>
    <h4 class="fw-bold mb-0"><i class="bi bi-arrow-left-right me-2"></i>Import / Export Products</h4>
</div>

<div class="row g-4">

<!-- ── Export ── -->
<div class="col-lg-4">
<div class="card stat-card p-4">
    <h5 class="fw-bold mb-1"><i class="bi bi-download me-2 text-success"></i>Export Product List</h5>
    <p class="text-muted small mb-3">Download your <?= $productCount ?> products as a CSV file (opens in Excel).</p>
    <a href="?export=1" class="btn btn-success w-100 fw-bold">
        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Download CSV
    </a>
    <div class="mt-2 text-muted small">
        Exported columns: Barcode, Name, Category, Supplier, Type, Cost, Sell Price, Stock, Unit, Alert
    </div>
</div>
</div>

<!-- ── Import ── -->
<div class="col-lg-8">
<div class="card stat-card p-4">
    <h5 class="fw-bold mb-3"><i class="bi bi-upload me-2 text-primary"></i>Import Products from File</h5>

    <?php if ($parseError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($parseError) ?></div>
    <?php endif; ?>

    <?php if ($importResults): ?>
    <div class="alert alert-<?= $importResults['errors'] ? 'warning' : 'success' ?>">
        <strong>Import complete!</strong><br>
        ✓ <?= $importResults['imported'] ?> new products added<br>
        <?php if ($importResults['updated']): ?>↻ <?= $importResults['updated'] ?> updated<br><?php endif; ?>
        <?php if ($importResults['skipped']): ?>⊘ <?= $importResults['skipped'] ?> skipped (duplicates)<br><?php endif; ?>
        <?php foreach ($importResults['errors'] as $err): ?><div class="small text-danger mt-1"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($step === 'upload' || $importResults): ?>
    <!-- Step 1: Upload -->
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="parse">
        <div class="mb-3">
            <label class="form-label fw-semibold">Select File</label>
            <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx" required>
            <div class="form-text">Supported: .csv and .xlsx (Excel). First row must be column headers.</div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Read File & Map Columns</button>
    </form>

    <hr>
    <div class="text-muted small">
        <strong>Expected columns (any order, any names — you'll map them):</strong><br>
        Product name (required), Barcode, Category, Supplier, Type (regular/bulk), Cost price, Sell price, Stock, Unit, Low stock alert, Units per Box, Sell Price per Box
    </div>
    <?php endif; ?>

    <?php if ($step === 'map' && $parsedRows): ?>
    <!-- Step 2: Column mapping -->
    <?php
    $headers   = $parsedRows[0];
    $dataRows  = array_slice($parsedRows, 1);
    $colCount  = count($headers);
    $previewRows = array_slice($dataRows, 0, 5);

    $ourFields = [
        'name'            => ['label'=>'Product Name', 'required'=>true],
        'barcode'         => ['label'=>'Barcode', 'required'=>false],
        'category'        => ['label'=>'Category', 'required'=>false],
        'supplier'        => ['label'=>'Supplier', 'required'=>false],
        'product_type'    => ['label'=>'Type (regular/bulk)', 'required'=>false],
        'cost_price'      => ['label'=>'Cost Price', 'required'=>false],
        'sell_price'      => ['label'=>'Sell Price', 'required'=>false],
        'stock'           => ['label'=>'Stock Qty', 'required'=>false],
        'unit'            => ['label'=>'Unit', 'required'=>false],
        'low_stock_alert' => ['label'=>'Low Stock Alert', 'required'=>false],
        'units_per_box'   => ['label'=>'Units per Box', 'required'=>false],
        'sell_price_box'  => ['label'=>'Sell Price per Box', 'required'=>false],
    ];

    // Auto-detect column mapping by header name similarity
    $autoMap = [];
    $keywords = [
        'name'            => ['name','product','item','description','desc','nom'],
        'barcode'         => ['barcode','bar code','ean','upc','code','sku'],
        'category'        => ['category','cat','group','famille','type group'],
        'supplier'        => ['supplier','vendor','fournisseur','brand'],
        'product_type'    => ['type','product type','kind'],
        'cost_price'      => ['cost','purchase price','prix achat','buy price','cogs'],
        'sell_price'      => ['sell','sale','price','prix vente','retail'],
        'stock'           => ['stock','quantity','qty','qte','quantite'],
        'unit'            => ['unit','unite','mesure','measure'],
        'low_stock_alert' => ['alert','min stock','reorder','threshold','low stock'],
        'units_per_box'   => ['units per box','box units','upb','per box','units/box'],
        'sell_price_box'  => ['box price','sell price box','prix boite','box sell','price/box'],
    ];
    foreach ($ourFields as $field => $_) {
        foreach ($headers as $i => $h) {
            $lh = strtolower(trim($h));
            foreach ($keywords[$field] as $kw) {
                if (str_contains($lh, $kw)) { $autoMap[$field] = $i; break 2; }
            }
        }
    }
    ?>
    <div class="alert alert-info py-2 small mb-3">
        <strong><?= count($dataRows) ?> data rows</strong> found (<?= $colCount ?> columns). Map your columns below, then import.
    </div>

    <!-- Preview table -->
    <div class="table-responsive mb-3" style="max-height:200px;overflow-y:auto">
    <table class="table table-sm table-bordered table-hover mb-0">
        <thead class="table-secondary sticky-top">
            <tr><?php for($i=0;$i<$colCount;$i++): ?><th class="small">Col <?= chr(65+$i) ?>: <?= htmlspecialchars($headers[$i]) ?></th><?php endfor; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($previewRows as $row): ?>
        <tr><?php for($i=0;$i<$colCount;$i++): ?><td class="small"><?= htmlspecialchars($row[$i] ?? '') ?></td><?php endfor; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="rows_json" value="<?= htmlspecialchars(json_encode($dataRows)) ?>">

        <div class="row g-2 mb-3">
        <?php foreach ($ourFields as $field => $info): ?>
        <div class="col-md-6">
            <label class="form-label small fw-semibold mb-1">
                <?= $info['label'] ?>
                <?= $info['required'] ? '<span class="text-danger">*</span>' : '' ?>
            </label>
            <select name="map[<?= $field ?>]" class="form-select form-select-sm"
                    <?= $info['required'] ? 'required' : '' ?>>
                <option value="">— Not in file —</option>
                <?php for($i=0;$i<$colCount;$i++): ?>
                <option value="<?= $i ?>" <?= (isset($autoMap[$field]) && $autoMap[$field]===$i) ? 'selected' : '' ?>>
                    Col <?= chr(65+$i) ?>: <?= htmlspecialchars($headers[$i]) ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold small">If product already exists (same barcode or name):</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="on_duplicate" value="skip" id="dupSkip" checked>
                    <label class="form-check-label" for="dupSkip">Skip it (keep current)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="on_duplicate" value="update" id="dupUpdate">
                    <label class="form-check-label" for="dupUpdate">Update it with new data</label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-check2-circle me-1"></i>Import <?= count($dataRows) ?> Rows</button>
            <a href="import_products.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>

</div>
</div>

</div><!-- row -->
</div>
<?php renderFoot(); ?>
