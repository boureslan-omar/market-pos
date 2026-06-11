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
    $desc = trim($_POST['description'] ?? '');
    $amt  = (float)($_POST['amount'] ?? 0);
    $cat  = trim($_POST['category'] ?? 'General');
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
        <td><a href="?delete=<?= $e['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')"><i class="bi bi-trash"></i></a></td>
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
    <div class="modal-header"><h5 class="modal-title">Add Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Description *</label><input type="text" name="description" class="form-control" required></div>
        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label">Amount <span id="exp-currency-label" class="text-muted">(USD)</span> *</label>
                <input type="number" name="amount" id="exp-amount" class="form-control" min="0.01" step="0.01" required>
                <div id="exp-usd-equiv" class="form-text text-muted" style="display:none"></div>
            </div>
            <div class="col-6"><label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <?php foreach (['Rent','Utilities','Salaries','Supplies','Maintenance','Transport','Marketing','General'] as $cat): ?>
                    <option><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-3"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
        <div class="border rounded p-2 bg-light">
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
        </script>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Save Expense</button>
    </div>
</form>
</div></div></div>
<?php renderFoot(); ?>
