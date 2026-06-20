<?php
// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE 16 — v3.4.0
//  • POS cart: clickable $ / LL button on each item to enter price in LBP
//  • Products form: USD/LBP toggle on "Sell Price per Box" field
//  • DB: categories de-duplicated + UNIQUE constraint added
//  • Reports: product search onclick bug fixed (data-pid delegation)
//  • Reports: Period Detail table totals row in tfoot
//  • Products: Low Stock PDF button → order preview modal with qty/cost editor
//  Safe to run multiple times (idempotent).
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403); die('Access denied.');
}

$steps  = [];
$errors = [];

// ── Block 1: pages/pos.php — cart item price currency toggle ─────────────────
try {
    $pf  = __DIR__ . '/pages/pos.php';
    $src = file_get_contents($pf);
    if (strpos($src, 'cartCur') !== false) {
        $steps[] = 'pos.php — already patched, skipped';
    } else {
        $changed = true;

        // 1a: renderCart() price column — add cartCur vars and toggle button
        $src = str_replace(
            '        html += `<tr>
            <td class="small">${item.name}${badge}</td>
            <td style="width:88px">
                <div class="input-group input-group-sm">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty(\'${id}\',${-delta})">-</button>
                    <input type="number" class="form-control form-control-sm text-center px-1" value="${item.qty}" min="0.001" step="${step}"
                        onchange="setQty(\'${id}\',this.value)" style="width:40px;padding:1px">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty(\'${id}\',${delta})">+</button>
                </div>
            </td>
            <td style="width:80px">
                <div class="input-group input-group-sm">
                    <span class="input-group-text px-1 py-0" style="font-size:11px">$</span>
                    <input type="number" class="form-control form-control-sm text-end px-1" value="${item.price.toFixed(2)}"
                           min="0" step="0.01" onchange="setItemPrice(\'${id}\',this.value)" style="width:48px;padding:2px" title="Edit price">
                </div>
            </td>
            <td class="small text-end fw-bold" id="ln-${id}">${formatUSD(line)}</td>
            <td><button class="btn btn-sm text-danger p-0" onclick="removeFromCart(\'${id}\')"><i class="bi bi-x-lg"></i></button></td>
        </tr>`;',
            '        const iCur        = cartCur[id] || \'usd\';
        const iCurLabel   = iCur === \'lbp\' ? \'LL\' : \'$\';
        const iCurStep    = iCur === \'lbp\' ? \'500\' : \'0.01\';
        const iCurDisplay = iCur === \'lbp\' ? Math.round(item.price * EXCHANGE_RATE) : item.price.toFixed(2);
        html += `<tr>
            <td class="small">${item.name}${badge}</td>
            <td style="width:88px">
                <div class="input-group input-group-sm">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty(\'${id}\',${-delta})">-</button>
                    <input type="number" class="form-control form-control-sm text-center px-1" value="${item.qty}" min="0.001" step="${step}"
                        onchange="setQty(\'${id}\',this.value)" style="width:40px;padding:1px">
                    <button class="btn btn-outline-secondary btn-sm px-1 py-0" onclick="changeQty(\'${id}\',${delta})">+</button>
                </div>
            </td>
            <td style="width:${iCur===\'lbp\'?\'120\':\'92\'}px">
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary px-1 py-0" id="cur-btn-${id}"
                            style="font-size:10px;min-width:24px;line-height:1" onclick="toggleItemCur(\'${id}\')"
                            title="Click to switch USD ↔ LBP">${iCurLabel}</button>
                    <input type="number" class="form-control form-control-sm text-end px-1" id="price-inp-${id}"
                           value="${iCurDisplay}" min="0" step="${iCurStep}"
                           onchange="setItemPrice(\'${id}\',this.value)" style="width:${iCur===\'lbp\'?\'90\':\'55\'}px;padding:2px" title="Edit price — click currency to switch">
                </div>
            </td>
            <td class="small text-end fw-bold" id="ln-${id}">${formatUSD(line)}</td>
            <td><button class="btn btn-sm text-danger p-0" onclick="removeFromCart(\'${id}\')"><i class="bi bi-x-lg"></i></button></td>
        </tr>`;',
            $src
        );

        // 1b: remove/clear cart also clears cartCur
        $src = str_replace(
            "function removeFromCart(id) { delete cart[id]; renderCart(); }",
            "function removeFromCart(id) { delete cart[id]; delete cartCur[id]; renderCart(); }",
            $src
        );
        $src = str_replace(
            "function clearCart() { Object.keys(cart).forEach(k => delete cart[k]); renderCart(); }",
            "function clearCart() { Object.keys(cart).forEach(k => { delete cart[k]; delete cartCur[k]; }); renderCart(); }",
            $src
        );

        // 1c: replace setItemPrice + add cartCur + toggleItemCur
        $src = str_replace(
            "function setItemPrice(id, val) {
    val = parseFloat(val);
    if (isNaN(val) || val < 0) return;
    cart[id].price = val;
    const line = parseFloat((cart[id].qty * val).toFixed(2));
    const el = document.getElementById('ln-' + id);
    if (el) el.textContent = formatUSD(line);
    let subtotal = 0;
    Object.values(cart).forEach(item => { subtotal += item.price * item.qty; });
    updateTotals(subtotal);
}",
            "// Per-item price currency state (survives renderCart re-renders)
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
}",
            $src
        );

        file_put_contents($pf, $src);
        $steps[] = 'pos.php — cart price USD/LBP toggle added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 1 (pos.php): ' . $e->getMessage();
}

// ── Block 2: pages/products.php — sell_price_box USD/LBP toggle ──────────────
try {
    $rf  = __DIR__ . '/pages/products.php';
    $src = file_get_contents($rf);
    if (strpos($src, 'f_sell_box_usd') !== false) {
        $steps[] = 'products.php — already patched, skipped';
    } else {

        // 2a: Replace plain f_sell_box input with input-group + toggle buttons
        $src = str_replace(
            '        <div class="col-md-4" id="row-sell-box" style="display:none">
            <label class="form-label d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>Sell Price per Box (USD)</span>
                <span class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="use-margin-box" onchange="toggleBoxMarginMode()">
                    <label class="form-check-label small text-muted fw-normal" for="use-margin-box">Set by margin %</label>
                </span>
            </label>
            <input type="number" name="sell_price_box" id="f_sell_box" class="form-control" step="0.0001" min="0" oninput="calcFromBox()">
        </div>',
            '        <div class="col-md-4" id="row-sell-box" style="display:none">
            <label class="form-label d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>Sell Price per Box</span>
                <span class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="use-margin-box" onchange="toggleBoxMarginMode()">
                    <label class="form-check-label small text-muted fw-normal" for="use-margin-box">Set by margin %</label>
                </span>
            </label>
            <div class="input-group">
                <input type="number" name="sell_price_box" id="f_sell_box" class="form-control" step="0.0001" min="0" data-cur="usd" oninput="calcFromBox(); prodUpdateHint(\'f_sell_box\')">
                <button type="button" id="f_sell_box_usd" class="btn btn-outline-secondary btn-sm px-1" style="font-size:.7rem;min-width:36px;font-weight:bold" onclick="prodToggleCur(\'f_sell_box\',\'usd\')" title="USD">USD</button>
                <button type="button" id="f_sell_box_lbp" class="btn btn-outline-secondary btn-sm px-1 opacity-50" style="font-size:.7rem;min-width:36px" onclick="prodToggleCur(\'f_sell_box\',\'lbp\')" title="LBP">LBP</button>
            </div>
            <div id="f_sell_box_hint" class="form-text text-muted"></div>
        </div>',
            $src
        );

        // 2b: Add sellBoxUSD() helper and update calcFromBox() to use it
        $src = str_replace(
            "function calcFromBox() {
    const upb     = Math.max(1, parseInt(document.getElementById('f_upb').value || 1));
    const costBox = parseFloat(document.getElementById('f_cost_box').value || 0);
    const sellBox = parseFloat(document.getElementById('f_sell_box').value || 0);
    if (costBox > 0) document.getElementById('f_cost').value = (costBox / upb).toFixed(4);
    if (!document.getElementById('use-margin-box')?.checked && sellBox > 0)
        document.getElementById('f_sell').value = (sellBox / upb).toFixed(4);",
            "function sellBoxUSD() {
    const inp = document.getElementById('f_sell_box');
    const raw = parseFloat(inp?.value || 0);
    return (inp?.dataset.cur === 'lbp') ? raw / PROD_RATE : raw;
}

function calcFromBox() {
    const upb     = Math.max(1, parseInt(document.getElementById('f_upb').value || 1));
    const costBox = parseFloat(document.getElementById('f_cost_box').value || 0);
    const sellBox = sellBoxUSD();
    if (costBox > 0) document.getElementById('f_cost').value = (costBox / upb).toFixed(4);
    if (!document.getElementById('use-margin-box')?.checked && sellBox > 0)
        document.getElementById('f_sell').value = (sellBox / upb).toFixed(4);",
            $src
        );

        // 2c: calcBoxPrice() use sellBoxUSD()
        $src = str_replace(
            "function calcBoxPrice() {
    const upb      = parseInt(document.getElementById('f_upb')?.value || 1);
    const boxPrice = parseFloat(document.getElementById('f_sell_box')?.value || 0);
    const cost     = parseFloat(document.getElementById('f_cost')?.value || 0);",
            "function calcBoxPrice() {
    const upb      = parseInt(document.getElementById('f_upb')?.value || 1);
    const boxPrice = sellBoxUSD();
    const cost     = parseFloat(document.getElementById('f_cost')?.value || 0);",
            $src
        );

        // 2d: calcBoxSellFromMargin() resets to USD before writing
        $src = str_replace(
            "    const boxSell = boxCost / (1 - pct / 100);
    document.getElementById('f_sell_box').value = boxSell.toFixed(4);
    preview.textContent = '→ Box sell: \$' + boxSell.toFixed(2) + ' (\$' + (boxSell / upb).toFixed(4) + '/unit)';",
            "    const boxSell = boxCost / (1 - pct / 100);
    if (document.getElementById('f_sell_box')?.dataset.cur === 'lbp') prodToggleCur('f_sell_box', 'usd');
    document.getElementById('f_sell_box').value = boxSell.toFixed(4);
    preview.textContent = '→ Box sell: \$' + boxSell.toFixed(2) + ' (\$' + (boxSell / upb).toFixed(4) + '/unit)';",
            $src
        );

        // 2e: clearForm() and fillForm() — add prodResetCur('f_sell_box')
        $src = str_replace(
            "    prodResetCur('f_cost');
    prodResetCur('f_sell');
    prodResetCur('f_cons_cost');
    toggleType();
    toggleSource();
}

function fillForm(p) {",
            "    prodResetCur('f_cost');
    prodResetCur('f_sell');
    prodResetCur('f_cons_cost');
    prodResetCur('f_sell_box');
    toggleType();
    toggleSource();
}

function fillForm(p) {",
            $src
        );

        $src = str_replace(
            "    resetMarginModes();
    prodResetCur('f_cost');
    prodResetCur('f_sell');
    prodResetCur('f_cons_cost');
    calcMargin();
    calcBoxPrice();
    toggleType();
    toggleSource();
}",
            "    resetMarginModes();
    prodResetCur('f_cost');
    prodResetCur('f_sell');
    prodResetCur('f_cons_cost');
    prodResetCur('f_sell_box');
    calcMargin();
    calcBoxPrice();
    toggleType();
    toggleSource();
}",
            $src
        );

        // 2f: form submit conversion list — add f_sell_box
        $src = str_replace(
            "    ['f_cost','f_sell','f_cons_cost'].forEach(fieldId => {",
            "    ['f_cost','f_sell','f_cons_cost','f_sell_box'].forEach(fieldId => {",
            $src
        );

        file_put_contents($rf, $src);
        $steps[] = 'products.php — sell_price_box USD/LBP toggle added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 2 (products.php): ' . $e->getMessage();
}

// ── Block 3: categories — deduplicate + UNIQUE constraint ────────────────────
try {
    $has = $pdo->query("SHOW INDEX FROM categories WHERE Key_name='uq_cat_name'")->fetch();
    if ($has) {
        $steps[] = 'categories — UNIQUE constraint already present, skipped';
    } else {
        $pdo->exec("UPDATE products p
            JOIN categories dup ON dup.id = p.category_id
            JOIN (SELECT name, MIN(id) AS orig_id FROM categories GROUP BY name) orig ON orig.name = dup.name
            SET p.category_id = orig.orig_id
            WHERE dup.id != orig.orig_id");
        $pdo->exec("DELETE FROM categories WHERE id NOT IN (
            SELECT orig_id FROM (SELECT name, MIN(id) AS orig_id FROM categories GROUP BY name) t)");
        $pdo->exec("ALTER TABLE categories ADD UNIQUE KEY uq_cat_name (name)");
        $steps[] = 'categories — duplicates removed, UNIQUE constraint added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 3 (categories): ' . $e->getMessage();
}

// ── Block 4: pages/reports.php — product search fix + period detail totals ───
try {
    $rf  = __DIR__ . '/pages/reports.php';
    $src = file_get_contents($rf);
    $changed = false;

    // 4a: product search — replace <a onclick> with <button data-pid>
    if (strpos($src, 'data-pid') === false) {
        $src = str_replace(
            "                    drop.innerHTML = res.slice(0,10).map(p =>\n" .
            "                        `<a class=\"list-group-item list-group-item-action p-2 small\" onclick=\"anPickProduct(\${p.id},\${JSON.stringify(p.name)})\">\${escH(p.name)}</a>`\n" .
            "                    ).join('');",
            "                    drop.innerHTML = res.slice(0,10).map(p =>\n" .
            "                        `<button type=\"button\" class=\"list-group-item list-group-item-action p-2 small text-start\"\n" .
            "                                 data-pid=\"\${p.id}\" data-pname=\"\${escH(p.name)}\">\n" .
            "                            \${escH(p.name)}\n" .
            "                        </button>`\n" .
            "                    ).join('');",
            $src
        );
        $src = str_replace(
            "    window.anPickProduct = function(id, name) {\n" .
            "        document.getElementById('an-prod-id').value     = id;\n" .
            "        document.getElementById('an-prod-search').value = name;\n" .
            "        document.getElementById('an-prod-drop').style.display = 'none';\n" .
            "        runAnalysis();\n" .
            "    };",
            "    window.anPickProduct = function(id, name) {\n" .
            "        document.getElementById('an-prod-id').value     = id;\n" .
            "        document.getElementById('an-prod-search').value = name;\n" .
            "        document.getElementById('an-prod-drop').style.display = 'none';\n" .
            "        runAnalysis();\n" .
            "    };\n\n" .
            "    document.getElementById('an-prod-drop').addEventListener('click', function(e) {\n" .
            "        const btn = e.target.closest('[data-pid]');\n" .
            "        if (!btn) return;\n" .
            "        anPickProduct(parseInt(btn.dataset.pid), btn.dataset.pname);\n" .
            "    });",
            $src
        );
        $changed = true;
    }

    // 4b: period detail — add totals PHP vars before the card
    if (strpos($src, 'totTxns') === false) {
        $src = str_replace(
            "<!-- Period Detail -->\n<div class=\"card stat-card p-3\">",
            <<<'EOT'
<!-- Period Detail -->
<?php
$totTxns  = array_sum(array_column($timeline, 'txns'));
$totRev   = array_sum(array_column($timeline, 'revenue'));
$totCogs  = array_sum(array_column($timeline, 'cogs'));
$totGP    = $totRev - $totCogs;
$totMargin = $totRev > 0 ? round(($totGP / $totRev) * 100, 1) : 0;
?>
<div class="card stat-card p-3">
EOT,
            $src
        );
        // Add tfoot before </table> in the period detail section
        $src = str_replace(
            "    <?php if (!\$timeline): ?><tr><td colspan=\"7\" class=\"text-center text-muted\">No data for this period.</td></tr><?php endif; ?>\n    </tbody>\n</table>",
            <<<'EOT'
    <?php if (!$timeline): ?><tr><td colspan="7" class="text-center text-muted">No data for this period.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($timeline): ?>
    <tfoot class="table-secondary fw-bold border-top border-2">
        <tr>
            <td>TOTAL</td>
            <td><?= $totTxns ?></td>
            <td><?= fmtUSD($totRev) ?></td>
            <td class="text-muted"><?= fmtLBP($totRev * $rate) ?></td>
            <td><?= fmtUSD($totCogs) ?></td>
            <td class="text-success"><?= fmtUSD($totGP) ?></td>
            <td><span class="badge <?= $totMargin>20?'bg-success':($totMargin>10?'bg-warning text-dark':'bg-danger') ?>"><?= $totMargin ?>%</span></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
EOT,
            $src
        );
        $changed = true;
    }

    if ($changed) {
        file_put_contents($rf, $src);
        $steps[] = 'reports.php — product search fix + period detail totals applied';
    } else {
        $steps[] = 'reports.php — already patched, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 4 (reports.php): ' . $e->getMessage();
}

// ── Block 5: pages/products.php — low stock order preview modal ───────────────
try {
    $pf  = __DIR__ . '/pages/products.php';
    $src = file_get_contents($pf);
    if (strpos($src, 'openLowStockModal') !== false) {
        $steps[] = 'products.php low-stock modal — already patched, skipped';
    } else {
        // 5a: PDF export query — add cost_price + units_per_box columns
        $src = str_replace(
            "        SELECT p.name, s.name AS sup_name, p.stock, p.low_stock_alert, p.unit\n        FROM products p",
            "        SELECT p.name, s.name AS sup_name, p.stock, p.low_stock_alert, p.unit,\n               p.cost_price, p.units_per_box\n        FROM products p",
            $src
        );

        // 5b: PDF table header — add Last Cost column
        $src = str_replace(
            '<thead><tr><th>#</th><th>Product</th><th>Supplier</th><th>Unit</th><th>In Stock</th><th>Min Level</th></tr></thead>',
            '<thead><tr><th>#</th><th>Product</th><th>Supplier</th><th>Unit</th><th>In Stock</th><th>Min Level</th><th>Last Cost</th></tr></thead>',
            $src
        );

        // 5c: PDF table row — add cost cell
        $src = str_replace(
            "        echo '<tr><td>' . (\$idx+1) . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['name']) . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['sup_name'] ?? '—') . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['unit'] ?? 'pcs') . '</td>'\n" .
            "            . '<td class=\"' . \$cls . '\">' . (float)\$r['stock'] . '</td>'\n" .
            "            . '<td>' . (float)\$r['low_stock_alert'] . '</td></tr>';",
            "        \$costDisp = \$r['cost_price'] > 0 ? '\$' . number_format(\$r['cost_price'], 2) : '—';\n" .
            "        if (\$r['units_per_box'] > 1 && \$r['cost_price'] > 0) {\n" .
            "            \$costDisp .= '/unit (\$' . number_format(\$r['cost_price'] * \$r['units_per_box'], 2) . '/box)';\n" .
            "        }\n" .
            "        echo '<tr><td>' . (\$idx+1) . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['name']) . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['sup_name'] ?? '—') . '</td>'\n" .
            "            . '<td>' . htmlspecialchars(\$r['unit'] ?? 'pcs') . '</td>'\n" .
            "            . '<td class=\"' . \$cls . '\">' . (float)\$r['stock'] . '</td>'\n" .
            "            . '<td>' . (float)\$r['low_stock_alert'] . '</td>'\n" .
            "            . '<td>' . \$costDisp . '</td></tr>';",
            $src
        );

        // 5d: PHP modal query — add $lowStockModal fetch before renderHead
        $src = str_replace(
            '$suppliers  = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();' . "\n\nrenderHead('Products');",
            <<<'EOT'
$suppliers  = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$lowStockModal = $pdo->query("
    SELECT p.id, p.name, s.name AS sup_name, p.stock, p.low_stock_alert,
           p.unit, p.cost_price, p.units_per_box
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.product_type != 'bulk' AND p.stock <= p.low_stock_alert
    ORDER BY p.stock ASC, p.name
")->fetchAll();

renderHead('Products');
EOT,
            $src
        );

        // 5e: button — change <a href> to <button onclick="openLowStockModal()">
        $src = str_replace(
            "        <a href=\"products.php?export=lowstock_pdf\" target=\"_blank\" class=\"btn btn-outline-danger btn-sm\">\n" .
            "            <i class=\"bi bi-file-earmark-pdf\"></i> Low Stock PDF\n" .
            "        </a>",
            "        <button type=\"button\" class=\"btn btn-outline-danger btn-sm\" onclick=\"openLowStockModal()\">\n" .
            "            <i class=\"bi bi-file-earmark-pdf\"></i> Low Stock PDF\n" .
            "        </button>",
            $src
        );

        // 5f: JS — insert Low Stock functions before the Batch viewer section
        $src = str_replace(
            '// ── Batch viewer ──────────────────────────────────────────────────────────────',
            <<<'EOT'
// ── Low Stock Order Preview ───────────────────────────────────────────────────
const LS_ITEMS = <?= json_encode(array_map(fn($r) => [
    'id'      => (int)$r['id'],
    'name'    => $r['name'],
    'sup'     => $r['sup_name'] ?? '',
    'stock'   => (float)$r['stock'],
    'alert'   => (float)$r['low_stock_alert'],
    'unit'    => $r['unit'] ?: 'pcs',
    'cost'    => (float)$r['cost_price'],
    'upb'     => (int)$r['units_per_box'],
], $lowStockModal)) ?>;

let lsRows = [];

function openLowStockModal() {
    lsRows = LS_ITEMS.map(p => {
        const isBox   = p.upb > 1;
        const boxCost = isBox ? p.cost * p.upb : p.cost;
        const gap     = Math.max(0, p.alert - p.stock);
        const qty     = Math.max(1, Math.ceil(isBox ? gap / p.upb : gap));
        return { ...p, boxCost, qty, removed: false };
    });
    renderLsTable();
    new bootstrap.Modal(document.getElementById('lowStockModal')).show();
}

function renderLsTable() {
    const tbody = document.getElementById('ls-tbody');
    tbody.innerHTML = lsRows.map((r, i) => {
        if (r.removed) return '';
        const total    = r.qty * r.boxCost;
        const costCell = r.upb > 1
            ? `$${r.cost.toFixed(2)}/unit &nbsp;<strong>$${r.boxCost.toFixed(2)}/box</strong>`
            : `$${r.cost.toFixed(2)}`;
        const qtyLabel = r.upb > 1 ? 'boxes' : r.unit;
        return `<tr>
            <td>${lsEsc(r.name)}<br><small class="text-muted">${lsEsc(r.sup)}</small></td>
            <td class="text-center"><span class="${r.stock==0?'text-danger fw-bold':'text-warning fw-bold'}">${r.stock}</span> / ${r.alert}</td>
            <td>${costCell}</td>
            <td style="width:110px">
                <div class="input-group input-group-sm">
                    <input type="number" class="form-control form-control-sm" min="0" step="1" value="${r.qty}" onchange="lsSetQty(${i},this.value)">
                    <span class="input-group-text px-1 text-muted" style="font-size:.7rem">${lsEsc(qtyLabel)}</span>
                </div>
            </td>
            <td class="fw-bold text-end" id="ls-tot-${i}">$${total.toFixed(2)}</td>
            <td class="text-center"><button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="lsRemove(${i})"><i class="bi bi-trash"></i></button></td>
        </tr>`;
    }).join('');
    updateLsGrand();
}

function lsSetQty(i, val) {
    lsRows[i].qty = Math.max(0, parseFloat(val) || 0);
    const el = document.getElementById('ls-tot-' + i);
    if (el) el.textContent = '$' + (lsRows[i].qty * lsRows[i].boxCost).toFixed(2);
    updateLsGrand();
}

function lsRemove(i) {
    lsRows[i].removed = true;
    renderLsTable();
}

function updateLsGrand() {
    const grand = lsRows.filter(r => !r.removed).reduce((s, r) => s + r.qty * r.boxCost, 0);
    const el = document.getElementById('ls-grand');
    if (el) el.textContent = '$' + grand.toFixed(2);
}

function lsEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function generateLowStockPDF() {
    const active = lsRows.filter(r => !r.removed && r.qty > 0);
    if (!active.length) { alert('No items to print.'); return; }
    const grand = active.reduce((s, r) => s + r.qty * r.boxCost, 0);
    const rows  = active.map((r, n) => {
        const isBox    = r.upb > 1;
        const costCell = isBox
            ? `$${r.cost.toFixed(2)}/unit ($${r.boxCost.toFixed(2)}/box)`
            : `$${r.cost.toFixed(2)}`;
        const qtyLabel = isBox ? `${r.qty} box${r.qty>1?'es':''}` : `${r.qty} ${lsEsc(r.unit)}`;
        return `<tr>
            <td>${n+1}</td><td>${lsEsc(r.name)}</td><td>${lsEsc(r.sup)}</td>
            <td class="${r.stock==0?'out':'low'}">${r.stock}</td>
            <td>${r.alert}</td>
            <td>${costCell}</td>
            <td>${qtyLabel}</td>
            <td style="text-align:right">$${(r.qty*r.boxCost).toFixed(2)}</td>
        </tr>`;
    }).join('');
    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Low Stock Order Preview</title>
<style>
@page{size:A4 portrait;margin:15mm}body{font-family:Arial,sans-serif;font-size:11px}
h2{margin:0 0 3px;font-size:15px}p.sub{margin:0 0 10px;color:#666;font-size:10px}
table{width:100%;border-collapse:collapse}
th{background:#222;color:#fff;padding:5px 7px;text-align:left;font-size:10px}
td{padding:4px 7px;border-bottom:1px solid #ddd;font-size:10px}
tr:nth-child(even) td{background:#f9f9f9}.out{color:#c00;font-weight:bold}.low{color:#b06000}
tfoot td{background:#eee;font-weight:bold;border-top:2px solid #333}
</style></head><body>
<h2>Low Stock Order Preview</h2>
<p class="sub">Generated: ${new Date().toLocaleString()} &nbsp;|&nbsp; ${active.length} item(s)</p>
<table>
<thead><tr><th>#</th><th>Product</th><th>Supplier</th><th>In Stock</th><th>Min</th><th>Last Cost</th><th>Qty to Order</th><th style="text-align:right">Total</th></tr></thead>
<tbody>${rows}</tbody>
<tfoot><tr><td colspan="7" style="text-align:right">Grand Total</td><td style="text-align:right">$${grand.toFixed(2)}</td></tr></tfoot>
</table>
<script>window.onload=function(){window.print();};<\/script>
</body></html>`;
    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
}

// ── Batch viewer ──────────────────────────────────────────────────────────────
EOT,
            $src
        );

        // 5g: HTML — insert Low Stock modal before Batch Viewer Modal
        $src = str_replace(
            '<!-- Batch Viewer Modal -->',
            <<<'EOT'
<!-- Low Stock Order Preview Modal -->
<div class="modal fade" id="lowStockModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cart-check me-2 text-danger"></i>Low Stock — Order Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-0">
        <?php if (empty($lowStockModal)): ?>
        <p class="text-success text-center py-4"><i class="bi bi-check-circle me-2"></i>No low stock items found — all products are above their alert level.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Product / Supplier</th>
                    <th class="text-center">Stock / Min</th>
                    <th>Last Cost</th>
                    <th style="width:100px">Qty to Order</th>
                    <th class="text-end">Line Total</th>
                    <th style="width:50px"></th>
                </tr>
            </thead>
            <tbody id="ls-tbody"></tbody>
            <tfoot class="table-secondary fw-bold border-top border-2">
                <tr>
                    <td colspan="4" class="text-end pe-3">Grand Total</td>
                    <td class="text-end" id="ls-grand">$0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <?php if (!empty($lowStockModal)): ?>
        <button type="button" class="btn btn-danger" onclick="generateLowStockPDF()">
            <i class="bi bi-file-earmark-pdf me-1"></i>Generate PDF
        </button>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

<!-- Batch Viewer Modal -->
EOT,
            $src
        );

        file_put_contents($pf, $src);
        $steps[] = 'products.php — low stock order preview modal added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 5 (products.php modal): ' . $e->getMessage();
}

// ── Block 6: version.json → v3.4.0 ───────────────────────────────────────────
try {
    $vf = json_decode(file_get_contents(__DIR__ . '/version.json') ?: '{}', true) ?: [];
    if (in_array(16, $vf['installed_upgrades'] ?? [])) {
        $steps[] = 'version.json — already at v3.4.0, skipped';
    } else {
        $installed = $vf['installed_upgrades'] ?? [];
        $installed[] = 16; sort($installed);
        $vf['installed_upgrades'] = $installed;
        $vf['version']      = '3.4.0';
        $vf['last_updated'] = date('Y-m-d');
        file_put_contents(__DIR__ . '/version.json', json_encode($vf, JSON_PRETTY_PRINT));
        $steps[] = 'version.json → v3.4.0, upgrade 16 marked installed';
    }
} catch (Exception $e) {
    $errors[] = 'Block 6 (version.json): ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 16 — v3.4.0</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:680px">
<div class="card shadow-sm p-4">
  <h4 class="fw-bold mb-1"><i class="bi bi-arrow-up-circle me-2 text-success"></i>Upgrade 16 — v3.4.0</h4>
  <p class="text-muted small mb-1">What's new:</p>
  <ul class="small text-muted mb-4">
    <li><strong>POS Cart — LBP Price Entry</strong> — Click the <code>$</code> button beside any item's price to switch to LBP. Type the price in LL and the system converts to USD automatically. Click again to switch back.</li>
    <li><strong>Products — Box Price LBP Toggle</strong> — The "Sell Price per Box" field now has USD/LBP toggle buttons, matching the unit price and cost fields.</li>
    <li><strong>Categories — Deduplication</strong> — Duplicate category entries are removed and a UNIQUE constraint is added to prevent future duplicates.</li>
    <li><strong>Reports — Product Search Fix</strong> — Product filter in Sales &amp; Purchase Analysis now works correctly for all product names.</li>
    <li><strong>Reports — Period Detail Totals</strong> — A TOTAL row now appears at the bottom of the Period Detail table showing summed transactions, revenue, COGS, gross profit, and weighted margin.</li>
    <li><strong>Products — Low Stock Order Preview</strong> — "Low Stock PDF" opens a modal showing low-stock items with editable order quantities, cost per unit/box, live line totals, and a grand total before printing the PDF.</li>
  </ul>
  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong>Errors:</strong>
    <ul class="mb-0 mt-2"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if ($steps): ?>
  <div class="alert alert-success">
    <strong>Steps completed:</strong>
    <ul class="mb-0 mt-2"><?php foreach($steps as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if (!$errors): ?>
  <div class="alert alert-info mb-0"><i class="bi bi-check-circle me-1"></i>Upgrade 16 complete. You may delete <code>upgrade16.php</code>.</div>
  <?php else: ?>
  <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Some steps failed — review errors above.</div>
  <?php endif; ?>
  <a href="/dahdouh/" class="btn btn-primary mt-3">&larr; Dashboard</a>
</div>
</div>
</body>
</html>
