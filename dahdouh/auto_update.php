<?php
// ─── Auto-updater — called by start_pos.bat on every startup ──────────────────
// Runs via PHP CLI (no Apache needed). Outputs plain text to the log.
// php C:\xampp\htdocs\dahdouh\auto_update.php

define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/backup_functions.php';

// Files that must never be overwritten by an update
const PROTECTED_FILES = [
    'includes/config.php',
    'assets/img/logo.png',
    'version.json',
    'backup_cron.log',
    'auto_update.log',
];

function log_msg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
}

// ── 1. Read local version ─────────────────────────────────────────────────────
$versionFile = __DIR__ . '/version.json';
$local = ['version' => '1.0.0', 'installed_upgrades' => []];
if (file_exists($versionFile)) {
    $decoded = json_decode(file_get_contents($versionFile), true);
    if ($decoded) $local = $decoded;
}

// ── 2. Get manifest URL (settings DB first, hardcoded fallback) ──────────────
const DEFAULT_MANIFEST_URL = 'https://raw.githubusercontent.com/boureslan-omar/market-pos/main/dahdouh/manifest.json';
$manifestUrl = setting('update_manifest_url', '') ?: DEFAULT_MANIFEST_URL;

// ── 3. Fetch manifest (short timeout — skip silently if offline) ──────────────
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$raw = @file_get_contents($manifestUrl, false, $ctx);
if (!$raw) {
    log_msg('SKIP — Cannot reach update server (offline or URL unreachable).');
    exit(0);
}

$manifest = json_decode($raw, true);
if (!$manifest || empty($manifest['version']) || empty($manifest['download_url'])) {
    log_msg('ERROR — Invalid manifest format at: ' . $manifestUrl);
    exit(1);
}

// ── 4. Compare versions ───────────────────────────────────────────────────────
if (version_compare($manifest['version'], $local['version'], '<=')) {
    log_msg('OK — Already up to date (v' . $local['version'] . ').');
    exit(0);
}

log_msg('UPDATE FOUND — v' . $local['version'] . ' → v' . $manifest['version']);
if (!empty($manifest['changelog'])) {
    log_msg('Changes: ' . $manifest['changelog']);
}

// ── 5. Auto-backup before updating ───────────────────────────────────────────
log_msg('Running pre-update backup...');
$backup = runBackup($pdo);
if ($backup['ok']) {
    log_msg('BACKUP OK — ' . $backup['sql']);
} else {
    log_msg('BACKUP WARNING — ' . ($backup['error'] ?? 'unknown') . '. Continuing with update anyway.');
}

// ── 6. Download update zip ────────────────────────────────────────────────────
log_msg('Downloading update package...');
$ctx60  = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true]]);
$zipData = @file_get_contents($manifest['download_url'], false, $ctx60);
if (!$zipData || strlen($zipData) < 1000) {
    log_msg('ERROR — Failed to download update package from: ' . $manifest['download_url']);
    exit(1);
}

$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_update_' . time() . '.zip';
file_put_contents($tmpZip, $zipData);
log_msg('Downloaded ' . round(strlen($zipData) / 1024) . ' KB.');

// ── 7. Extract zip (skip protected files) ─────────────────────────────────────
$dest      = __DIR__;
$extracted = 0;
$skipped   = 0;

if (class_exists('ZipArchive')) {
    // ── Path A: native ZipArchive ─────────────────────────────────────────────
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        log_msg('ERROR — Cannot open downloaded zip file.');
        @unlink($tmpZip);
        exit(1);
    }

    // Detect common top-level prefix (PowerShell zip may use backslashes — normalise first)
    $prefix = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', $zip->getNameIndex($i));
        $slash = strpos($name, '/');
        if ($slash !== false && $slash > 0) {
            $cand = substr($name, 0, $slash + 1);
            if ($prefix === '') { $prefix = $cand; }
            elseif ($prefix !== $cand) { $prefix = ''; break; }
        }
    }
    log_msg('Zip prefix detected: "' . $prefix . '"');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        // Normalise backslashes → forward slashes (PowerShell zip quirk)
        $entry = str_replace('\\', '/', $zip->getNameIndex($i));

        // Strip common top-level prefix
        if ($prefix !== '' && strncmp($entry, $prefix, strlen($prefix)) === 0) {
            $rel = substr($entry, strlen($prefix));
        } else {
            $rel = $entry;
        }

        if ($rel === '' || $rel === false) continue;
        if (substr($rel, -1) === '/') continue; // skip directory entries

        if (in_array($rel, PROTECTED_FILES)) { $skipped++; continue; }

        $target = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $dir    = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($target, $zip->getFromIndex($i));
        $extracted++;
    }
    $zip->close();
    @unlink($tmpZip);

} else {
    // ── Path B: PowerShell Expand-Archive fallback (Windows) ─────────────────
    log_msg('ZipArchive not available — using PowerShell fallback.');
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_upd_' . time();
    $psCmd  = 'powershell -NoProfile -NonInteractive -Command '
            . '"Expand-Archive -LiteralPath \'' . str_replace("'", "''", $tmpZip) . '\''
            . ' -DestinationPath \'' . str_replace("'", "''", $tmpDir) . '\' -Force"';
    exec($psCmd, $psOut, $psRet);

    if ($psRet !== 0 || !is_dir($tmpDir)) {
        log_msg('ERROR — Extraction failed. Enable php_zip in php.ini or check PowerShell access.');
        @unlink($tmpZip);
        exit(1);
    }

    // Top-level folder inside the zip (e.g. "dahdouh/") — use scandir, glob+GLOB_ONLYDIR unreliable on Windows
    $srcRoot = $tmpDir;
    foreach (scandir($tmpDir) as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($tmpDir . DIRECTORY_SEPARATOR . $item)) {
            $srcRoot = $tmpDir . DIRECTORY_SEPARATOR . $item;
            break;
        }
    }

    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rit as $fileInfo) {
        $rel     = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($srcRoot) + 1));
        $relNorm = ltrim($rel, '/');
        if (in_array($relNorm, PROTECTED_FILES)) { $skipped++; continue; }
        $target  = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relNorm);
        if ($fileInfo->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            copy($fileInfo->getPathname(), $target);
            $extracted++;
        }
    }

    // Clean up temp dir
    $cleanup = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($cleanup as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($tmpDir);
    @unlink($tmpZip);
}

log_msg('Extracted ' . $extracted . ' files (' . $skipped . ' protected files preserved).');

// ── 8. Run pending migrations ─────────────────────────────────────────────────
$installedUpgrades = $local['installed_upgrades'] ?? [];
$pendingUpgrades   = array_diff($manifest['upgrades'] ?? [], $installedUpgrades);
sort($pendingUpgrades);

if (empty($pendingUpgrades)) {
    log_msg('No new migrations to run.');
} else {
    log_msg('Running ' . count($pendingUpgrades) . ' migration(s): ' . implode(', ', $pendingUpgrades));
    foreach ($pendingUpgrades as $num) {
        $migFile = __DIR__ . '/migrations/migration_' . $num . '.php';
        if (!file_exists($migFile)) {
            log_msg('WARN — Migration file not found: migrations/migration_' . $num . '.php');
            continue;
        }
        try {
            $result = (include $migFile);
            if ($result !== false) {
                $installedUpgrades[] = (int)$num;
                log_msg('Migration ' . $num . ' — OK');
            } else {
                log_msg('Migration ' . $num . ' — FAILED (returned false)');
            }
        } catch (Throwable $e) {
            log_msg('Migration ' . $num . ' — ERROR: ' . $e->getMessage());
        }
    }
}

// ── 9. Write updated version.json ────────────────────────────────────────────
$newVersion = [
    'version'              => $manifest['version'],
    'installed_upgrades'   => array_values(array_unique($installedUpgrades)),
    'last_updated'         => date('Y-m-d H:i:s'),
    'install_date'         => $local['install_date'] ?? date('Y-m-d'),
];
file_put_contents($versionFile, json_encode($newVersion, JSON_PRETTY_PRINT) . PHP_EOL);

log_msg('DONE — System updated to v' . $manifest['version']);
