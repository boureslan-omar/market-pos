<?php
// Automatic backup — called by Windows Task Scheduler
// Setup command (run once as Administrator in cmd.exe):
// schtasks /create /tn "MarketPOS_Backup" /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\dahdouh\backup_cron.php\"" /sc daily /st 02:00 /ru SYSTEM /f

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/backup_functions.php';

$result = runBackup($pdo);

if ($result['ok']) {
    $line = date('Y-m-d H:i:s') . ' [OK] db=' . $result['sql']
        . ($result['zip']     ? ' | files=' . $result['zip']         : ' | files=skipped')
        . ($result['warning'] ? ' | warning: ' . $result['warning']  : '');
} else {
    $line = date('Y-m-d H:i:s') . ' [ERROR] ' . ($result['error'] ?? 'unknown error');
}

$logFile = __DIR__ . '/backup_cron.log';
file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
echo $line . PHP_EOL;
