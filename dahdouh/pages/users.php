<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin');

$message = '';
$me = currentUser();

// ── Delete user ───────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    if ($did === (int)$me['id']) {
        $message = 'error:You cannot delete your own account.';
    } else {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$did]);
        header('Location: users.php'); exit;
    }
}

// ── Toggle active ─────────────────────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $tid = (int)$_GET['toggle'];
    if ($tid === (int)$me['id']) {
        $message = 'error:You cannot deactivate your own account.';
    } else {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?")->execute([$tid]);
        header('Location: users.php'); exit;
    }
}

// ── Save (add / edit) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid      = (int)($_POST['uid'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['admin','cashier','stock']) ? $_POST['role'] : 'cashier';
    $password = trim($_POST['password'] ?? '');

    if (!$username || !$fullName) {
        $message = 'error:Username and full name are required.';
    } else {
        try {
            if ($uid) {
                // Edit existing
                if ($password) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET username=?,full_name=?,role=?,password_hash=? WHERE id=?")
                        ->execute([$username,$fullName,$role,$hash,$uid]);
                } else {
                    $pdo->prepare("UPDATE users SET username=?,full_name=?,role=? WHERE id=?")
                        ->execute([$username,$fullName,$role,$uid]);
                }
                $message = 'success:User updated.';
            } else {
                // New user — password required
                if (!$password) { $message = 'error:Password is required for new users.'; }
                else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)")
                        ->execute([$username,$hash,$fullName,$role]);
                    $message = 'success:User created successfully.';
                }
            }
        } catch (Exception $e) {
            $message = 'error:' . ($e->getCode() == 23000 ? 'That username is already taken.' : $e->getMessage());
        }
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, username")->fetchAll();

renderHead('Users');
renderNav('users');
alertBox($message);
?>
<div class="container py-4" style="max-width:860px">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-people-fill me-2"></i>User Management</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userModal" onclick="clearForm()">
        <i class="bi bi-person-plus me-1"></i>Add User
    </button>
</div>

<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-dark">
        <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $isSelf = ((int)$u['id'] === (int)$me['id']);
        $roleColors = ['admin'=>'danger','cashier'=>'success','stock'=>'primary'];
    ?>
    <tr class="<?= !$u['is_active'] ? 'table-secondary' : '' ?>">
        <td class="fw-semibold">
            <?= htmlspecialchars($u['full_name']) ?>
            <?php if ($isSelf): ?><span class="badge bg-secondary ms-1">You</span><?php endif; ?>
        </td>
        <td class="font-monospace"><?= htmlspecialchars($u['username']) ?></td>
        <td><span class="badge bg-<?= $roleColors[$u['role']] ?? 'secondary' ?>"><?= ucfirst($u['role']) ?></span></td>
        <td>
            <?php if ($u['is_active']): ?>
                <span class="badge bg-success">Active</span>
            <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
            <?php endif; ?>
        </td>
        <td class="small text-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
        <td class="text-end">
            <button class="btn btn-sm btn-outline-primary py-0" onclick='fillForm(<?= json_encode($u) ?>)' data-bs-toggle="modal" data-bs-target="#userModal">
                <i class="bi bi-pencil"></i>
            </button>
            <?php if (!$isSelf): ?>
            <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-outline-<?= $u['is_active']?'warning':'success' ?> py-0"
               title="<?= $u['is_active']?'Deactivate':'Activate' ?>">
                <i class="bi bi-<?= $u['is_active']?'pause':'play' ?>-circle"></i>
            </a>
            <a href="?delete=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger py-0"
               onclick="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                <i class="bi bi-trash"></i>
            </a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<div class="card mt-4 border-info">
<div class="card-body py-3 small">
    <strong>Role permissions:</strong>
    <span class="badge bg-danger ms-2">Admin</span> Full access — all pages, settings, reports, user management.
    <span class="badge bg-primary ms-2">Stock</span> Products, Purchases, Suppliers, Amenities, Purchase Orders.
    <span class="badge bg-success ms-2">Cashier</span> POS Sales, Customers, Cash Register, Expenses.
</div>
</div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
    <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i><span id="modalTitle">Add User</span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<form method="POST">
<div class="modal-body">
    <input type="hidden" name="uid" id="uid" value="0">
    <div class="mb-3">
        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="full_name" id="full_name" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" id="username" class="form-control" required autocomplete="off">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Role</label>
        <select name="role" id="role" class="form-select">
            <option value="cashier">Cashier — POS &amp; daily operations</option>
            <option value="stock">Stock — Products &amp; purchases</option>
            <option value="admin">Admin — Full access</option>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold" id="pwLabel">Password <span class="text-danger">*</span></label>
        <input type="password" name="password" id="password" class="form-control" autocomplete="new-password">
        <div class="form-text" id="pwHint">Required for new users.</div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
</div>
</form>
</div>
</div>
</div>

<script>
function clearForm() {
    document.getElementById('modalTitle').textContent = 'Add User';
    document.getElementById('uid').value = '0';
    document.getElementById('full_name').value = '';
    document.getElementById('username').value = '';
    document.getElementById('role').value = 'cashier';
    document.getElementById('password').value = '';
    document.getElementById('pwLabel').innerHTML = 'Password <span class="text-danger">*</span>';
    document.getElementById('pwHint').textContent = 'Required for new users.';
    document.getElementById('password').required = true;
}
function fillForm(u) {
    document.getElementById('modalTitle').textContent = 'Edit User';
    document.getElementById('uid').value = u.id;
    document.getElementById('full_name').value = u.full_name;
    document.getElementById('username').value = u.username;
    document.getElementById('role').value = u.role;
    document.getElementById('password').value = '';
    document.getElementById('pwLabel').innerHTML = 'New Password';
    document.getElementById('pwHint').textContent = 'Leave blank to keep current password.';
    document.getElementById('password').required = false;
}
</script>
<?php renderFoot(); ?>
