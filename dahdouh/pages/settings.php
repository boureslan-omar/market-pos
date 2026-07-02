<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('admin');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Logo upload
    if (!empty($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','webp','gif'])) {
            $dest = __DIR__ . '/../assets/img/logo.png';
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                // Convert to PNG if needed (if GD available and not PNG)
                $message = 'success:Logo uploaded successfully.';
            } else {
                $message = 'error:Failed to upload logo. Check folder permissions.';
            }
        } else {
            $message = 'error:Only PNG, JPG, WEBP images are accepted.';
        }
    }

    $fields = ['store_name','store_address','store_phone','exchange_rate','base_currency','auto_print_receipt','theme_brand_color','theme_accent_color','update_manifest_url','vfd_com_port','vfd_baud'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) saveSetting($pdo, $f, trim($_POST[$f]));
    }
    // Boolean toggles — unchecked checkboxes send nothing, so always save explicitly
    foreach (['customer_display_enabled','cash_drawer_enabled','vfd_enabled'] as $b) {
        saveSetting($pdo, $b, isset($_POST[$b]) ? '1' : '0');
    }
    if (!$message) $message = 'success:Settings saved.';
}

$logoExists = file_exists(__DIR__ . '/../assets/img/logo.png');

renderHead('Settings');
renderNav('settings');
alertBox($message);
?>
<div class="container py-4" style="max-width:700px">
<h4 class="fw-bold mb-4"><i class="bi bi-gear me-2"></i>Settings</h4>

<form method="POST" enctype="multipart/form-data">

<!-- Logo -->
<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">STORE LOGO</h6>
    <div class="d-flex align-items-center gap-4">
        <div>
            <?php if ($logoExists): ?>
            <img src="/dahdouh/assets/img/logo.png?v=<?= filemtime(__DIR__.'/../assets/img/logo.png') ?>" alt="Logo"
                 style="height:90px;width:90px;object-fit:contain;border-radius:12px;border:2px solid #ddd">
            <?php else: ?>
            <div class="d-flex align-items-center justify-content-center bg-light rounded" style="height:90px;width:90px;border:2px dashed #ccc">
                <i class="bi bi-image fs-2 text-muted"></i>
            </div>
            <?php endif; ?>
        </div>
        <div class="flex-grow-1">
            <label class="form-label"><?= $logoExists ? 'Replace Logo' : 'Upload Logo' ?></label>
            <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">PNG recommended. Will be shown in the navbar and on printed receipts.</div>
        </div>
    </div>
</div>

<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">STORE INFORMATION</h6>
    <div class="mb-3">
        <label class="form-label">Store Name</label>
        <input type="text" name="store_name" class="form-control" value="<?= htmlspecialchars(setting('store_name','Zoughaib Market')) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Address (printed on receipts)</label>
        <input type="text" name="store_address" class="form-control" value="<?= htmlspecialchars(setting('store_address','')) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Phone (printed on receipts)</label>
        <input type="text" name="store_phone" class="form-control" value="<?= htmlspecialchars(setting('store_phone','')) ?>">
    </div>
</div>

<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">CURRENCY</h6>
    <div class="mb-3">
        <label class="form-label">Primary Currency</label>
        <select name="base_currency" class="form-select">
            <option value="USD" <?= setting('base_currency')=='USD'?'selected':'' ?>>USD (US Dollar)</option>
            <option value="LBP" <?= setting('base_currency')=='LBP'?'selected':'' ?>>LBP (Lebanese Pound)</option>
        </select>
        <div class="form-text">Prices are entered and stored in this currency.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Exchange Rate — 1 USD = ? LBP</label>
        <input type="number" name="exchange_rate" class="form-control" value="<?= setting('exchange_rate','89750') ?>" min="1" step="1">
        <div class="form-text">Current rate: <strong>1 USD = <?= number_format((float)setting('exchange_rate',89750),0) ?> LBP</strong></div>
    </div>
</div>

<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">RECEIPTS</h6>
    <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="auto_print_receipt" id="autoPrint" value="1" <?= setting('auto_print_receipt')=='1'?'checked':'' ?>>
        <label class="form-check-label" for="autoPrint">
            <strong>Auto-print receipts</strong>
            <div class="text-muted small">When ON, the print dialog opens automatically after each sale.</div>
        </label>
    </div>
</div>

<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">HARDWARE</h6>
    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="customer_display_enabled" id="custDisplay" value="1" <?= setting('customer_display_enabled')=='1'?'checked':'' ?>>
        <label class="form-check-label" for="custDisplay">
            <strong>Customer Display</strong>
            <div class="text-muted small">When ON, the POS page automatically opens a second window showing the running total for the customer. Open that window on your second monitor or TV display.</div>
        </label>
    </div>
    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="cash_drawer_enabled" id="cashDrawer" value="1" <?= setting('cash_drawer_enabled')=='1'?'checked':'' ?>>
        <label class="form-check-label" for="cashDrawer">
            <strong>Cash Drawer</strong>
            <div class="text-muted small">When ON, the cash drawer opens automatically after each sale and an "Open Drawer" button appears in the POS page. Requires a cash drawer connected to your thermal receipt printer via RJ11 cable.</div>
        </label>
    </div>
    <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="vfd_enabled" id="vfdEnabled" value="1" <?= setting('vfd_enabled')=='1'?'checked':'' ?>>
        <label class="form-check-label" for="vfdEnabled">
            <strong>VFD / LED Customer Display (COM Port)</strong>
            <div class="text-muted small">When ON, the POS sends price updates to a hardware VFD or LED display via a COM port. The display shows item total on every cart change.</div>
        </label>
    </div>
    <div class="ms-4 mb-2 d-flex gap-3 align-items-end flex-wrap">
        <div>
            <label class="form-label small mb-1">COM Port</label>
            <input type="text" name="vfd_com_port" class="form-control form-control-sm" style="max-width:100px"
                   value="<?= htmlspecialchars(setting('vfd_com_port','COM1')) ?>" placeholder="COM1">
        </div>
        <div>
            <label class="form-label small mb-1">Baud Rate</label>
            <select name="vfd_baud" class="form-select form-select-sm" style="max-width:120px">
                <?php foreach (['9600','4800','19200','38400','2400'] as $b): ?>
                <option value="<?= $b ?>" <?= setting('vfd_baud','9600')===$b?'selected':'' ?>><?= $b ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testVfd()">Test Display</button>
        </div>
    </div>
    <div class="ms-4 mb-1 form-text">Save settings first, then click Test Display to verify the connection.</div>
</div>

<!-- Theme Colors -->
<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">THEME COLORS</h6>
    <div class="row g-4">
        <div class="col-sm-6">
            <label class="form-label fw-semibold">Brand Color</label>
            <div class="d-flex align-items-center gap-3">
                <input type="color" name="theme_brand_color" id="inp-brand"
                       class="form-control form-control-color flex-shrink-0" style="width:54px;height:44px"
                       value="<?= htmlspecialchars(setting('theme_brand_color','#2d5a2d')) ?>"
                       oninput="updateThemePreview()">
                <div>
                    <div class="small fw-semibold">Navbar &amp; Buttons</div>
                    <div class="text-muted" style="font-size:.78rem">Main identity color applied to the navigation bar and all primary buttons.</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <label class="form-label fw-semibold">Accent Color</label>
            <div class="d-flex align-items-center gap-3">
                <input type="color" name="theme_accent_color" id="inp-accent"
                       class="form-control form-control-color flex-shrink-0" style="width:54px;height:44px"
                       value="<?= htmlspecialchars(setting('theme_accent_color','#1a73e8')) ?>"
                       oninput="updateThemePreview()">
                <div>
                    <div class="small fw-semibold">Highlights &amp; Prices</div>
                    <div class="text-muted" style="font-size:.78rem">Used for product prices, form focus outlines, and data highlights.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-4">
        <div class="small text-muted mb-1">Preview</div>
        <div id="theme-preview-bar" class="rounded d-flex align-items-center px-3" style="height:44px;transition:background .2s">
            <span class="text-white fw-semibold small me-3">Navbar</span>
            <span id="theme-preview-btn" class="badge rounded-pill px-3 py-2 small" style="transition:background .2s">Button</span>
            <span id="theme-preview-accent" class="ms-3 fw-bold small" style="transition:color .2s">$12.50</span>
        </div>
    </div>
    <script>
    function darkenHex(hex, f) {
        const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        return '#' + [r,g,b].map(v => Math.round(v*(1-f)).toString(16).padStart(2,'0')).join('');
    }
    function updateThemePreview() {
        const brand  = document.getElementById('inp-brand').value;
        const accent = document.getElementById('inp-accent').value;
        const dark   = darkenHex(brand, 0.38);
        document.getElementById('theme-preview-bar').style.background = `linear-gradient(135deg,${dark} 0%,${brand} 100%)`;
        document.getElementById('theme-preview-btn').style.background  = brand;
        document.getElementById('theme-preview-accent').style.color    = accent;
    }
    updateThemePreview();
    </script>
</div>

<!-- Auto-Update -->
<div class="card stat-card p-4 mb-4">
    <h6 class="fw-bold mb-3 text-muted">AUTO-UPDATE</h6>
    <?php
    $vf = json_decode(@file_get_contents(__DIR__ . '/../version.json') ?: '{}', true);
    $currentVer = $vf['version'] ?? '—';
    $lastUpdated = $vf['last_updated'] ?? '—';
    ?>
    <div class="row g-3 mb-3">
        <div class="col-sm-4">
            <div class="small text-muted">Installed Version</div>
            <div class="fw-bold font-monospace"><?= htmlspecialchars($currentVer) ?></div>
        </div>
        <div class="col-sm-8">
            <div class="small text-muted">Last Updated</div>
            <div class="fw-bold"><?= htmlspecialchars($lastUpdated) ?></div>
        </div>
    </div>
    <div class="mb-0">
        <label class="form-label fw-semibold">Update Manifest URL</label>
        <input type="url" name="update_manifest_url" class="form-control font-monospace"
               value="<?= htmlspecialchars(setting('update_manifest_url','')) ?>"
               placeholder="https://raw.githubusercontent.com/YOU/REPO/main/manifest.json">
        <div class="form-text">
            The URL to your <code>manifest.json</code> hosted on GitHub (or any public URL).
            Leave blank to disable auto-updates. The system checks this on every startup
            and updates silently — log is saved to <code>auto_update.log</code>.
        </div>
    </div>
</div>

<button type="submit" class="btn btn-primary px-5">Save Settings</button>
</form>

<!-- ── Display / UI Scale ─────────────────────────────────────────── -->
<div class="card stat-card p-4 mb-4 mt-4">
    <h6 class="fw-bold mb-3 text-muted">DISPLAY</h6>
    <label class="form-label fw-semibold">Interface Size</label>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted" style="font-size:.85rem;min-width:1.2rem">A</span>
        <input type="range" class="form-range flex-grow-1" id="ui-scale-slider"
               min="0.8" max="1.3" step="0.05" value="1">
        <span class="text-muted fw-bold" style="font-size:1.25rem;min-width:1.5rem">A</span>
    </div>
    <div class="text-center text-muted small mt-1">
        Scale: <span id="ui-scale-val" class="fw-semibold">100%</span>
        <button class="btn btn-link btn-sm py-0 text-muted ms-2" onclick="resetUiScale()">Reset</button>
    </div>
    <div class="form-text mt-2">Scales font size and spacing across all pages. Saved in this browser — each device can have its own setting.</div>
</div>

<script>
(function() {
    const slider = document.getElementById('ui-scale-slider');
    const valEl  = document.getElementById('ui-scale-val');

    function applyScale(scale) {
        document.documentElement.style.setProperty('--ui-scale', scale);
        document.documentElement.style.fontSize = (16 * scale) + 'px';
        localStorage.setItem('uiScale', scale);
        valEl.textContent = Math.round(scale * 100) + '%';
    }

    // Init from localStorage
    const saved = parseFloat(localStorage.getItem('uiScale') || '1');
    slider.value = saved;
    valEl.textContent = Math.round(saved * 100) + '%';

    slider.addEventListener('input', function() {
        applyScale(parseFloat(this.value));
    });

    window.resetUiScale = function() {
        slider.value = 1;
        applyScale(1);
    };
})();

function testVfd() {
    fetch('/dahdouh/pages/api.php?action=vfd_test', { method: 'POST' })
        .then(r => r.json())
        .then(d => alert(d.ok ? 'Display responded OK — check the screen!' : ('Error: ' + (d.error || 'unknown'))))
        .catch(() => alert('Request failed — check that Apache is running.'));
}
</script>

<!-- ── License Info ──────────────────────────────────────────────── -->
<div class="card stat-card p-4 mb-4 mt-4">
    <h6 class="fw-bold mb-3 text-muted"><i class="bi bi-shield-lock me-1"></i>SOFTWARE LICENSE</h6>
    <?php
    $licInfo   = getLicenseInfo();
    $licClient = !empty($licInfo['client']) ? htmlspecialchars($licInfo['client']) : null;
    $licType   = $licInfo['type'] ?? 'lifetime';
    $expiresAt = (int)($licInfo['expires_at'] ?? 0);
    $daysLeft  = ($expiresAt > 0) ? (int)ceil(($expiresAt - time()) / 86400) : null;
    ?>
    <?php if ($licClient): ?>
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>ACTIVATED</span>
        <?php if ($licType === 'lifetime'): ?>
            <span class="badge bg-primary">LIFETIME</span>
        <?php elseif ($licType === 'yearly'): ?>
            <?php if ($daysLeft !== null && $daysLeft <= 0): ?>
                <span class="badge bg-danger">EXPIRED</span>
            <?php elseif ($daysLeft !== null && $daysLeft <= 30): ?>
                <span class="badge bg-warning text-dark">YEARLY — <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left</span>
            <?php else: ?>
                <span class="badge bg-info text-dark">YEARLY</span>
            <?php endif; ?>
        <?php endif; ?>
        <span class="fw-bold"><?= $licClient ?></span>
    </div>
    <?php if ($licType === 'yearly' && $expiresAt > 0): ?>
    <div class="text-muted small mb-2">
        <strong>Expires:</strong> <?= date('d M Y', $expiresAt) ?>
        <?php if ($daysLeft !== null && $daysLeft > 0): ?>
            <span class="<?= $daysLeft <= 30 ? 'text-warning fw-semibold' : 'text-success' ?>">
                (<?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining)
            </span>
        <?php elseif ($daysLeft !== null && $daysLeft <= 0): ?>
            <span class="text-danger fw-semibold">(Expired)</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="text-muted small mb-2">
        <strong>Machine ID:</strong> <code><?= htmlspecialchars(formatMachineId()) ?></code>
    </div>
    <div class="text-muted small">License is machine-locked. To transfer to a new machine, contact your developer with the new Machine ID.</div>
    <?php else: ?>
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge bg-danger fs-6"><i class="bi bi-x-circle me-1"></i>NOT ACTIVATED</span>
    </div>
    <div class="text-muted small mb-2">
        <strong>Machine ID:</strong> <code><?= htmlspecialchars(formatMachineId()) ?></code>
    </div>
    <a href="/dahdouh/pages/activate.php" class="btn btn-warning btn-sm">
        <i class="bi bi-key me-1"></i>Activate Now
    </a>
    <?php endif; ?>
</div>

</div>
<?php renderFoot(); ?>
