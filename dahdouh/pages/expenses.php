<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin','cashier');

$message = '';

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([(int)$_GET['delete']]);
    header('Location: expenses.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expenseId = (int)($_POST['expense_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $amt  = (float)($_POST['amount'] ?? 0);
    $cat  = trim($_POST['category'] ?? 'General');
    if (!$cat) $cat = 'General';
    $date = $_POST['expense_date'] ?? date('Y-m-d');
    $note = trim($_POST['note'] ?? '');

    $cashDeduct   = (bool)($_POST['cash_deduct'] ?? false);
    $cashCurrency = $_POST['cash_currency'] ?? 'USD';

    // When LBP is selected, the entered amount is in LBP — convert to USD for storage
    if ($cashCurrency === 'LBP') {
        $amtLBP = $amt;
        $amt    = round($amtLBP / EXCHANGE_RATE, 4);
        if (!$note) $note = number_format($amtLBP, 0, '.', ',') . ' LBP';
        else        $note = number_format($amtLBP, 0, '.', ',') . ' LBP — ' . $note;
    } else {
        $amtLBP = 0;
    }

    if (!$desc || $amt <= 0) {
        $message = 'error:Description and a positive amount are required.';
    } elseif ($expenseId > 0) {
        // Fetch original amount before update
        $origStmt = $pdo->prepare("SELECT amount, description FROM expenses WHERE id=?");
        $origStmt->execute([$expenseId]);
        $origRow = $origStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE expenses SET description=?, amount=?, category=?, expense_date=?, note=? WHERE id=?")
            ->execute([$desc, $amt, $cat, $date, $note, $expenseId]);

        // Adjust cash register if the USD amount changed
        if ($origRow && abs((float)$origRow['amount'] - $amt) > 0.0001) {
            $oldAmt  = (float)$origRow['amount'];
            $oldDesc = $origRow['description'];
            $escaped = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $oldDesc);
            $logStmt = $pdo->prepare("SELECT currency, amount_usd, amount_lbp FROM cash_register_log WHERE type='expense' AND note LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 1");
            $logStmt->execute(["Expense: {$escaped}%"]);
            $logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
            if ($logRow) {
                $adjNote = "Expense adjustment: $desc";
                if ($logRow['currency'] === 'LBP' && (float)$logRow['amount_lbp'] != 0) {
                    $origLBP  = abs((float)$logRow['amount_lbp']);
                    $newLBP   = $oldAmt > 0 ? round($origLBP * ($amt / $oldAmt)) : 0;
                    $deltaLBP = -($newLBP - $origLBP);
                    if (abs($deltaLBP) > 0) logCashEntry($pdo, 'expense', 0, $adjNote, null, $deltaLBP, 'LBP');
                } else {
                    $deltaUSD = -($amt - $oldAmt);
                    logCashEntry($pdo, 'expense', $deltaUSD, $adjNote);
                }
            }
        }
        $message = 'success:Expense updated.';
    } else {
        $pdo->prepare("INSERT INTO expenses (description, amount, category, expense_date, note) VALUES (?,?,?,?,?)")
            ->execute([$desc, $amt, $cat, $date, $note]);
        $expId = $pdo->lastInsertId();
        if ($cashDeduct) {
            $cashNote = "Expense: $desc" . ($note ? " — $note" : '');
            if ($cashCurrency === 'LBP') {
                logCashEntry($pdo, 'expense', 0, $cashNote, null, -$amtLBP, 'LBP');
            } else {
                logCashEntry($pdo, 'expense', -$amt, $cashNote);
            }
        }
        $message = "success:Expense recorded." . ($cashDeduct ? " Cash withdrawn." : "");
    }
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$expenses = $pdo->prepare("SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC, created_at DESC");
$expenses->execute([$from, $to]);
$expenses = $expenses->fetchAll();

$totalExp = array_sum(array_column($expenses, 'amount'));

$byCategory = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$byCategory->execute([$from, $to]);
$byCategory = $byCategory->fetchAll();

$defaultCats = ['Rent','Utilities','Salaries','Supplies','Maintenance','Transport','Marketing','General'];
$savedCats   = $pdo->query("SELECT DISTINCT category FROM expenses WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$allCats     = array_unique(array_merge($defaultCats, $savedCats));
sort($allCats);

renderHead('Expenses');
renderNav('expenses');
?>
<div class="container-fluid py-4">

<?php if ($message): [$t,$m] = explode(':',$message,2); ?>
<div class="alert alert-<?= $t==='success'?'success':'danger' ?> alert-dismissible fade show">
    <?= htmlspecialchars($m) ?> <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="bi bi-wallet2 me-2"></i>Expenses</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expModal">
        <i class="bi bi-plus-lg"></i> Add Expense
    </button>
</div>

<!-- Date filter -->
<form class="row g-2 mb-3" method="GET">
    <div class="col-auto"><label class="form-label small">From</label><input type="date" name="from" class="form-control" value="<?= $from ?>"></div>
    <div class="col-auto"><label class="form-label small">To</label><input type="date" name="to" class="form-control" value="<?= $to ?>"></div>
    <div class="col-auto align-self-end"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small">Total Expenses</div>
            <div class="fw-bold fs-4 text-danger"><?= fmt($totalExp) ?></div>
        </div>
    </div>
    <?php foreach ($byCategory as $bc): ?>
    <div class="col-md-2">
        <div class="card stat-card p-3">
            <div class="text-muted small"><?= htmlspecialchars($bc['category']) ?></div>
            <div class="fw-bold"><?= fmt($bc['total']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card stat-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th><th>Note</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($expenses as $e): ?>
    <tr>
        <td><?= $e['expense_date'] ?></td>
        <td><?= htmlspecialchars($e['description']) ?></td>
        <td><span class="badge bg-secondary"><?= htmlspecialchars($e['category']) ?></span></td>
        <td class="fw-bold text-danger"><?= fmt($e['amount']) ?></td>
        <td class="small text-muted"><?= htmlspecialchars($e['note'] ?: '—') ?></td>
        <td>
            <button class="btn btn-sm btn-outline-warning me-1"
                onclick="editExpense(<?= $e['id'] ?>,<?= htmlspecialchars(json_encode($e['description']),ENT_QUOTES) ?>,<?= $e['amount'] ?>,<?= htmlspecialchars(json_encode($e['category']),ENT_QUOTES) ?>,'<?= $e['expense_date'] ?>',<?= htmlspecialchars(json_encode($e['note']),ENT_QUOTES) ?>)"
                title="Edit"><i class="bi bi-pencil"></i></button>
            <a href="?delete=<?= $e['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')" title="Delete"><i class="bi bi-trash"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$expenses): ?><tr><td colspan="6" class="text-center text-muted py-4">No expenses in this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

<!-- Modal -->
<div class="modal fade" id="expModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST">
    <input type="hidden" name="expense_id" id="exp-id-hidden" value="0">
    <div class="modal-header">
        <h5 class="modal-title" id="exp-modal-title">Add Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Description *</label><input type="text" name="description" id="exp-desc" class="form-control" required></div>
        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label">Amount <span id="exp-currency-label" class="text-muted">(USD)</span> *</label>
                <input type="number" name="amount" id="exp-amount" class="form-control" min="0.01" step="0.01" required>
                <div id="exp-usd-equiv" class="form-text text-muted" style="display:none"></div>
            </div>
            <div class="col-6"><label class="form-label">Category</label>
                <input name="category" id="exp-cat" class="form-control" list="exp-cat-list" placeholder="General" autocomplete="off">
                <datalist id="exp-cat-list">
                    <?php foreach ($allCats as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" name="expense_date" id="exp-date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-3"><label class="form-label">Note</label><textarea name="note" id="exp-note" class="form-control" rows="2"></textarea></div>
        <div id="exp-cash-section" class="border rounded p-2 bg-light">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="cash_deduct" value="1" id="ck-cash-exp" checked>
                <label class="form-check-label" for="ck-cash-exp">Deduct from cash register</label>
            </div>
            <div class="d-flex gap-2 ms-4">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="cash_currency" value="USD" id="exp-usd" checked onchange="onExpCurrChange()">
                    <label class="form-check-label" for="exp-usd">USD drawer</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="cash_currency" value="LBP" id="exp-lbp" onchange="onExpCurrChange()">
                    <label class="form-check-label" for="exp-lbp">LBP drawer</label>
                </div>
            </div>
        </div>
        <script>
        const EXP_RATE = <?= EXCHANGE_RATE ?>;
        function onExpCurrChange() {
            const isLBP = document.getElementById('exp-lbp').checked;
            document.getElementById('exp-currency-label').textContent = isLBP ? '(LBP)' : '(USD)';
            document.getElementById('exp-amount').step = isLBP ? '1' : '0.01';
            document.getElementById('exp-amount').min  = isLBP ? '1' : '0.01';
            updateExpEquiv();
        }
        function updateExpEquiv() {
            const isLBP  = document.getElementById('exp-lbp').checked;
            const equiv  = document.getElementById('exp-usd-equiv');
            const amt    = parseFloat(document.getElementById('exp-amount').value) || 0;
            if (isLBP && amt > 0) {
                equiv.style.display = '';
                equiv.textContent   = '≈ $' + (amt / EXP_RATE).toFixed(2) + ' USD';
            } else {
                equiv.style.display = 'none';
            }
        }
        document.getElementById('exp-amount').addEventListener('input', updateExpEquiv);
        function editExpense(id, desc, amt, cat, date, note) {
            document.getElementById('exp-id-hidden').value = id;
            document.getElementById('exp-modal-title').textContent = 'Edit Expense';
            document.getElementById('exp-desc').value  = desc;
            document.getElementById('exp-amount').value = parseFloat(amt).toFixed(2);
            document.getElementById('exp-cat').value   = cat;
            document.getElementById('exp-date').value  = date;
            document.getElementById('exp-note').value  = note || '';
            document.getElementById('exp-cash-section').style.display = 'none';
            new bootstrap.Modal(document.getElementById('expModal')).show();
        }
        document.getElementById('expModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('exp-id-hidden').value = '0';
            document.getElementById('exp-modal-title').textContent = 'Add Expense';
            document.getElementById('exp-cash-section').style.display = '';
        });
        </script>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger" id="exp-save-btn">Save Expense</button>
    </div>
</form>
</div></div></div>
<?php renderFoot(); ?>
