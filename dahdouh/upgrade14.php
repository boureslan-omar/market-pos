<?php
// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE 14 — v3.2.1: UI Scale Slider + Scrollable Navbar
//  Patches: assets/css/pos.css, includes/layout.php, pages/settings.php
//  Safe to run multiple times (idempotent).
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/includes/config.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403); die('Access denied.');
}

$steps  = [];
$errors = [];

// ── Block 1: pos.css — --ui-scale + html font-size + prod-card padding ────────
try {
    $cssPath = __DIR__ . '/assets/css/pos.css';
    $css     = file_get_contents($cssPath);

    if (strpos($css, '--ui-scale') !== false) {
        $steps[] = 'pos.css — UI scale already applied, skipped';
    } else {
        $old1a = <<<'EOT'
:root {
    --primary: var(--brand-accent, #1a73e8);
    --success: #34a853;
    --danger:  #ea4335;
    --warning: #fbbc04;
    --dark:    #1f1f1f;
}

body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
EOT;
        $new1a = <<<'EOT'
:root {
    --primary: var(--brand-accent, #1a73e8);
    --success: #34a853;
    --danger:  #ea4335;
    --warning: #fbbc04;
    --dark:    #1f1f1f;
    --ui-scale: 1;
}

/* Scaling html font-size causes all Bootstrap rem values to scale proportionally */
html { font-size: calc(16px * var(--ui-scale)); }

body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
EOT;
        $css = str_replace($old1a, $new1a, $css);
        $css = str_replace('    padding: 6px 4px;',
                           '    padding: calc(var(--ui-scale) * 6px) calc(var(--ui-scale) * 4px);', $css);
        file_put_contents($cssPath, $css);
        $steps[] = 'pos.css patched — --ui-scale variable, html font-size, prod-card padding';
    }
} catch (Exception $e) {
    $errors[] = 'Block 1 (pos.css): ' . $e->getMessage();
}

// ── Block 2: layout.php — CSS cache-buster + uiScale script + scrollable nav ──
try {
    $layPath = __DIR__ . '/includes/layout.php';
    $lay     = file_get_contents($layPath);
    $changed = false;

    // 2a: CSS cache-buster on pos.css link
    if (strpos($lay, 'pos.css?v=') === false) {
        $lay = str_replace(
            '<link rel="stylesheet" href="/dahdouh/assets/css/pos.css">',
            '<link rel="stylesheet" href="/dahdouh/assets/css/pos.css?v=\' . filemtime(__DIR__.\'/../assets/css/pos.css\') . \'">',
            $lay
        );
        $changed = true;
    }

    // 2b: early uiScale localStorage script in <head>
    if (strpos($lay, 'uiScale') === false) {
        $lay = str_replace(
            "\n" . '<style>' . "\n" . ':root {',
            "\n" . '<script>' . "\n"
            . '(function(){var s=parseFloat(localStorage.getItem("uiScale")||"1");if(s!==1){document.documentElement.style.setProperty("--ui-scale",s);document.documentElement.style.fontSize=(16*s)+"px";}})();' . "\n"
            . '</script>' . "\n"
            . '<style>' . "\n" . ':root {',
            $lay
        );
        $changed = true;
    }

    // 2c: scrollable navbar — user section moves outside collapse, inline scroll styles
    if (strpos($lay, 'order-lg-last') === false) {

        // Replace the nav header (handles original AND previous navbar-scroll-wrap intermediate state)
        $oldNavHeaders = [
            // Original (no scroll-wrap)
            <<<'EOT'
    echo '<nav class="navbar navbar-expand-lg navbar-dark px-3 no-print" style="background:linear-gradient(135deg,var(--brand-dark) 0%,var(--brand) 100%);box-shadow:0 2px 12px rgba(0,0,0,.4)">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="/dahdouh/">'
        . $logo
        . '<span style="font-size:.95rem;letter-spacing:.3px">' . htmlspecialchars($name) . '</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav me-auto">';
EOT,
            // Intermediate (navbar-scroll-wrap applied by old upgrade14)
            <<<'EOT'
    echo '<nav class="navbar navbar-expand-lg navbar-dark px-3 no-print" style="background:linear-gradient(135deg,var(--brand-dark) 0%,var(--brand) 100%);box-shadow:0 2px 12px rgba(0,0,0,.4)">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="/dahdouh/">'
        . $logo
        . '<span style="font-size:.95rem;letter-spacing:.3px">' . htmlspecialchars($name) . '</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
        <div class="navbar-scroll-wrap">
        <ul class="navbar-nav">';
EOT,
        ];
        $newNavHeader = <<<'EOT'
    echo '<nav class="navbar navbar-expand-lg navbar-dark px-3 no-print" style="background:linear-gradient(135deg,var(--brand-dark) 0%,var(--brand) 100%);box-shadow:0 2px 12px rgba(0,0,0,.4)">
        <a class="navbar-brand fw-bold d-flex align-items-center flex-shrink-0" href="/dahdouh/">'
        . $logo
        . '<span style="font-size:.95rem;letter-spacing:.3px">' . htmlspecialchars($name) . '</span></a>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-auto ms-lg-0 order-lg-last">
            <span class="badge rounded-pill" style="background:'.$roleBadgeColor.';font-size:.7rem">'.$roleLabel.'</span>
            <span class="text-white-50 small d-none d-xl-inline">'.$fullName.'</span>
            <a href="/dahdouh/logout.php" class="btn btn-sm btn-outline-light py-0 px-2" title="Sign out">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        <button class="navbar-toggler ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse flex-grow-1" id="nav"
             style="overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.3) transparent">
        <ul class="navbar-nav" style="flex-wrap:nowrap;min-width:max-content;gap:.2rem;margin-bottom:0">';
EOT;
        foreach ($oldNavHeaders as $oldH) {
            if (strpos($lay, $oldH) !== false) {
                $lay = str_replace($oldH, $newNavHeader, $lay);
                break;
            }
        }

        // Replace nav-item echo line to add white-space:nowrap inline
        $lay = str_replace(
            'echo "<li class=\"nav-item\"><a class=\"nav-link $cls\" href=\"{$p[\'url\']}\"><i class=\"{$p[\'icon\']}\"></i> {$p[\'label\']}</a></li>";',
            'echo "<li class=\"nav-item\"><a class=\"nav-link $cls\" href=\"{$p[\'url\']}\" style=\"white-space:nowrap\"><i class=\"{$p[\'icon\']}\"></i> {$p[\'label\']}</a></li>";',
            $lay
        );

        // Replace nav footers (original and intermediate both end with old user section)
        $oldNavFooters = [
            // Original footer
            <<<'EOT'
    echo '</ul>
        <div class="d-flex align-items-center gap-2 ms-3">
            <span class="badge rounded-pill" style="background:'.$roleBadgeColor.';font-size:.7rem">'.$roleLabel.'</span>
            <span class="text-white-50 small">'.$fullName.'</span>
            <a href="/dahdouh/logout.php" class="btn btn-sm btn-outline-light py-0 px-2" title="Sign out">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        </div></nav>';
EOT,
            // Intermediate footer (navbar-scroll-wrap had extra </div>)
            <<<'EOT'
    echo '</ul>
        </div>
        <div class="navbar-user d-flex align-items-center gap-2">
            <span class="badge rounded-pill" style="background:'.$roleBadgeColor.';font-size:.7rem">'.$roleLabel.'</span>
            <span class="text-white-50 small d-none d-xl-inline">'.$fullName.'</span>
            <a href="/dahdouh/logout.php" class="btn btn-sm btn-outline-light py-0 px-2" title="Sign out">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        </div></nav>';
EOT,
        ];
        $newNavFooter = <<<'EOT'
    echo '</ul>
        </div></nav>';
EOT;
        foreach ($oldNavFooters as $oldF) {
            if (strpos($lay, $oldF) !== false) {
                $lay = str_replace($oldF, $newNavFooter, $lay);
                break;
            }
        }

        $changed = true;
    }

    if ($changed) {
        file_put_contents($layPath, $lay);
        $steps[] = 'layout.php patched — CSS cache-buster, uiScale script, scrollable navbar with inline styles';
    } else {
        $steps[] = 'layout.php — all patches already applied, skipped';
    }
} catch (Exception $e) {
    $errors[] = 'Block 2 (layout.php): ' . $e->getMessage();
}

// ── Block 3: settings.php — add Display / UI Scale card ──────────────────────
try {
    $setPath = __DIR__ . '/pages/settings.php';
    $set     = file_get_contents($setPath);

    if (strpos($set, 'ui-scale-slider') !== false) {
        $steps[] = 'settings.php — Display card already applied, skipped';
    } else {
        $displayCard = <<<'EOT'

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
</script>

EOT;
        $set = str_replace(
            "\n<!-- ── License Info",
            $displayCard . '<!-- ── License Info',
            $set
        );
        file_put_contents($setPath, $set);
        $steps[] = 'settings.php patched — Display / UI Scale card added';
    }
} catch (Exception $e) {
    $errors[] = 'Block 3 (settings.php): ' . $e->getMessage();
}

// ── Block 4: version.json ─────────────────────────────────────────────────────
try {
    $vf = json_decode(@file_get_contents(__DIR__ . '/version.json') ?: '{}', true) ?: [];
    $installed = $vf['installed_upgrades'] ?? [];
    if (!in_array(14, $installed)) {
        $installed[] = 14;
        sort($installed);
        $vf['installed_upgrades'] = $installed;
        $vf['version']      = '3.2.1';
        $vf['last_updated'] = date('Y-m-d');
        file_put_contents(__DIR__ . '/version.json', json_encode($vf, JSON_PRETTY_PRINT));
        $steps[] = 'version.json updated — v3.2.1, upgrade 14 marked installed';
    } else {
        $steps[] = 'version.json — upgrade 14 already marked installed';
    }
} catch (Exception $e) {
    $errors[] = 'Block 4 (version.json): ' . $e->getMessage();
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upgrade 14 — v3.2.1</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:680px">
<div class="card shadow-sm p-4">
  <h4 class="fw-bold mb-1"><i class="bi bi-arrow-up-circle me-2 text-success"></i>Upgrade 14 — v3.2.1</h4>
  <p class="text-muted small mb-1">What's new in this upgrade:</p>
  <ul class="small text-muted mb-4">
    <li>UI Scale Slider in Settings — adjusts font size and spacing across all pages (80%–130%)</li>
    <li>CSS <code>--ui-scale</code> variable; all Bootstrap <code>rem</code> values scale via <code>html { font-size }</code></li>
    <li>Scale persists per-browser via <code>localStorage</code>, applied before first paint — no flash</li>
    <li>Scrollable navbar: user/logout section pinned right, nav items scroll horizontally at large sizes</li>
    <li>Nav buttons have 0.2rem gap and <code>white-space:nowrap</code> — no more two-line labels</li>
    <li>CSS cache-buster on <code>pos.css</code> link — ensures new styles load immediately after updates</li>
  </ul>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <strong>Errors:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($steps): ?>
  <div class="alert alert-success">
    <strong>Completed steps:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($steps as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$errors): ?>
  <div class="alert alert-info mb-0">
    <i class="bi bi-check-circle me-1"></i>
    Upgrade 14 complete. You may delete this file from the server after applying.
  </div>
  <?php else: ?>
  <div class="alert alert-warning mb-0">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Some steps failed. Review errors above, fix manually if needed, then re-run.
  </div>
  <?php endif; ?>

  <a href="/dahdouh/" class="btn btn-primary mt-3">Go to Dashboard</a>
</div>
</div>
</body>
</html>
