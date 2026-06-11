<?php

function backupDefaultFolder() {
    return 'C:\\Users\\Public\\Documents\\MarketBackup';
}

function runBackup($pdo) {
    set_time_limit(300);

    $folder = setting('backup_folder', backupDefaultFolder());
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        return ['ok' => false, 'error' => 'Cannot create backup folder: ' . $folder];
    }

    $stamp   = date('Y-m-d_H-i-s');
    $sqlFile = $folder . DIRECTORY_SEPARATOR . 'db_' . $stamp . '.sql';
    $zipFile = $folder . DIRECTORY_SEPARATOR . 'files_' . $stamp . '.zip';
    $errors  = [];

    // ── 1. Database dump ──────────────────────────────────────────────────────
    $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $cmd = '"' . $mysqldump . '" -u ' . DB_USER;
    if (DB_PASS !== '') $cmd .= ' -p"' . DB_PASS . '"';
    $cmd .= ' --single-transaction ' . DB_NAME;

    $sql = shell_exec($cmd);
    if (!$sql || strlen($sql) < 200) {
        return ['ok' => false, 'error' => 'mysqldump failed or returned empty output. Check that mysqldump.exe exists at C:\\xampp\\mysql\\bin\\'];
    }
    file_put_contents($sqlFile, $sql);

    // ── 2. Files zip ─────────────────────────────────────────────────────────
    $sourceDir   = realpath(__DIR__ . '/..');
    $excludeReal = realpath($folder);

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            zipFolderToArchive($zip, $sourceDir, 'dahdouh', $excludeReal);
            $zip->close();
        } else {
            $errors[] = 'Could not create zip archive';
        }
    } else {
        $errors[] = 'ZipArchive not available — files backup skipped';
    }

    // ── 3. Housekeeping ───────────────────────────────────────────────────────
    cleanOldBackups($folder, (int)(setting('backup_keep', 10)));
    saveSetting($pdo, 'last_backup', date('Y-m-d H:i:s'));

    return [
        'ok'      => true,
        'sql'     => basename($sqlFile),
        'zip'     => file_exists($zipFile) ? basename($zipFile) : null,
        'warning' => $errors ? implode('; ', $errors) : null,
    ];
}

function zipFolderToArchive(ZipArchive $zip, $folder, $zipPrefix, $excludeReal) {
    $rootLen = strlen(realpath($folder));
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $real = realpath($item->getPathname());
        if (!$real) continue;
        // Skip the backup destination folder (avoids recursive zipping)
        if ($excludeReal && str_starts_with($real, $excludeReal)) continue;
        $relative = $zipPrefix . '/' . str_replace('\\', '/', substr($real, $rootLen + 1));
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($real, $relative);
        }
    }
}

function cleanOldBackups($folder, $keep = 10) {
    foreach (['db_*.sql', 'files_*.zip'] as $pattern) {
        $files = glob($folder . DIRECTORY_SEPARATOR . $pattern) ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}

function listBackups($folder) {
    if (!is_dir($folder)) return [];
    $files = array_merge(
        glob($folder . DIRECTORY_SEPARATOR . 'db_*.sql')    ?: [],
        glob($folder . DIRECTORY_SEPARATOR . 'files_*.zip') ?: []
    );
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $out = [];
    foreach ($files as $f) {
        $out[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'date' => date('Y-m-d H:i', filemtime($f)),
            'type' => str_ends_with($f, '.sql') ? 'db' : 'files',
        ];
    }
    return $out;
}
