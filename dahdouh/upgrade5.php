<?php
require_once __DIR__ . '/includes/config.php';
requireRole('admin');

$steps = [];

try {
    // Fix cash_register_log type enum (void and expense were missing)
    $pdo->exec("ALTER TABLE cash_register_log MODIFY COLUMN type ENUM('opening','sale','withdrawal','deposit','void','expense') NOT NULL");
    $steps[] = '✓ cash_register_log.type enum updated (void + expense added)';
} catch (PDOException $e) {
    $steps[] = '— cash_register_log enum: ' . $e->getMessage();
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        closed_by INT NULL,
        closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        since_datetime DATETIME NULL,
        balance_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        balance_lbp DECIMAL(12,2) NOT NULL DEFAULT 0,
        sales_count INT NOT NULL DEFAULT 0,
        sales_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        cash_in_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        cash_in_lbp DECIMAL(12,2) NOT NULL DEFAULT 0,
        cash_out_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        cash_out_lbp DECIMAL(12,2) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        CONSTRAINT fk_shift_user FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = '✓ Created cash_shifts table';
} catch (PDOException $e) {
    $steps[] = '— cash_shifts: ' . $e->getMessage();
}

echo "<pre style='font-family:monospace;padding:20px'>";
echo "<strong>Upgrade 5 — End of Shift</strong>\n\n";
foreach ($steps as $s) echo $s . "\n";
echo "\nDone. <a href='/dahdouh/'>Return to dashboard</a></pre>";
