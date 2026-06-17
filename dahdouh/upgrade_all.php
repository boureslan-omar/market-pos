<?php
require_once __DIR__ . '/includes/config.php';
define('STORE_NAME', setting('store_name', 'Market POS'));

// ── Schema helpers (idempotent) ────────────────────────────────────────────────
function col($pdo, $t, $c) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetchColumn();
}
function tbl($pdo, $t) {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
}

$steps  = [];
$warns  = [];
$errors = [];

// Read installed upgrades from version.json so we can gate one-time data fixes
$vf    = __DIR__ . '/version.json';
$vdata = file_exists($vf) ? (json_decode(file_get_contents($vf), true) ?: []) : [];
$done  = $vdata['installed_upgrades'] ?? [];

if (isset($_POST['upgrade'])) {
    // Pre-flight: base tables must exist (install.sql must have been run first)
    $baseTablesOk = tbl($pdo,'products') && tbl($pdo,'sales') && tbl($pdo,'customers');
    if (!$baseTablesOk) {
        $errors[] = 'Base tables are missing. Please run install.sql in phpMyAdmin first, then return here to run the upgrade.';
    } else
    try {
        // NOTE: DDL (CREATE TABLE, ALTER TABLE) cannot run inside a PDO transaction
        // in MySQL/MariaDB — DDL causes an implicit commit. Each block runs in
        // auto-commit mode; the idempotent guards (col/tbl) make re-runs safe.

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 1 — Consignment fields on products & sale_items
        // ════════════════════════════════════════════════════════════════════════
        if (!col($pdo,'products','product_source')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN product_source ENUM('owned','consignment') NOT NULL DEFAULT 'owned' AFTER product_type");
            $steps[] = '[1] products.product_source added';
        }
        if (!col($pdo,'products','consignment_supplier_id')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN consignment_supplier_id INT NULL AFTER product_source");
            $steps[] = '[1] products.consignment_supplier_id added';
        }
        if (!col($pdo,'products','consignment_cost')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN consignment_cost DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER consignment_supplier_id");
            $steps[] = '[1] products.consignment_cost added';
        }
        if (!col($pdo,'products','units_per_box')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1 AFTER unit");
            $steps[] = '[4] products.units_per_box added';
        }
        if (!col($pdo,'products','sell_price_box')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN sell_price_box DECIMAL(10,4) NULL DEFAULT NULL AFTER units_per_box");
            $steps[] = '[4] products.sell_price_box added';
        }
        if (!col($pdo,'sale_items','is_consignment')) {
            $pdo->exec("ALTER TABLE sale_items ADD COLUMN is_consignment TINYINT(1) NOT NULL DEFAULT 0 AFTER product_type");
            $steps[] = '[1] sale_items.is_consignment added';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 2 — consignment_ledger
        // ════════════════════════════════════════════════════════════════════════
        if (!tbl($pdo,'consignment_ledger')) {
            $pdo->exec("CREATE TABLE consignment_ledger (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                sale_id          INT NOT NULL,
                product_id       INT NOT NULL,
                supplier_id      INT NOT NULL,
                quantity         DECIMAL(10,3) NOT NULL,
                sell_price       DECIMAL(10,4) NOT NULL,
                consignment_cost DECIMAL(10,4) NOT NULL,
                revenue          DECIMAL(10,2) NOT NULL,
                supplier_due     DECIMAL(10,2) NOT NULL,
                market_profit    DECIMAL(10,2) NOT NULL,
                settled          TINYINT(1) NOT NULL DEFAULT 0,
                sale_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sale_id)     REFERENCES sales(id)     ON DELETE CASCADE,
                FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[1] consignment_ledger table created';
        }
        if (!tbl($pdo,'consignment_settlements')) {
            $pdo->exec("CREATE TABLE consignment_settlements (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                amount_paid DECIMAL(10,2) NOT NULL,
                note        TEXT,
                settled_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[1] consignment_settlements table created';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 3 — Supplier tracking
        // ════════════════════════════════════════════════════════════════════════
        if (!col($pdo,'suppliers','email')) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone");
            $steps[] = '[2] suppliers.email added';
        }
        if (!col($pdo,'suppliers','balance')) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0");
            $steps[] = '[2] suppliers.balance added';
        }
        if (!tbl($pdo,'supplier_ledger')) {
            $pdo->exec("CREATE TABLE supplier_ledger (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                purchase_id INT NULL,
                type        ENUM('purchase','payment','adjustment','return') NOT NULL DEFAULT 'purchase',
                amount      DECIMAL(10,2) NOT NULL,
                note        TEXT,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[2] supplier_ledger table created';
        } else {
            $pdo->exec("ALTER TABLE supplier_ledger MODIFY COLUMN type ENUM('purchase','payment','adjustment','return') NOT NULL DEFAULT 'purchase'");
            $steps[] = '[11] supplier_ledger.type — ensured ENUM has return';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 4 — Users + Sales void + Dual-currency cash register
        // ════════════════════════════════════════════════════════════════════════
        if (!tbl($pdo,'users')) {
            $pdo->exec("CREATE TABLE users (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                full_name     VARCHAR(100) NOT NULL,
                role          ENUM('admin','cashier','stock') NOT NULL DEFAULT 'cashier',
                is_active     TINYINT(1) NOT NULL DEFAULT 1,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,'Administrator','admin')")->execute(['admin',$hash]);
            $steps[] = '[3] users table created — default login: admin / admin123';
            $warns[]  = 'Default admin credentials created. Change the password immediately after logging in!';
        }
        foreach (['is_void'=>"TINYINT(1) NOT NULL DEFAULT 0",'void_reason'=>"VARCHAR(255) NULL",'voided_at'=>"TIMESTAMP NULL",'voided_by'=>"INT NULL"] as $c => $def) {
            if (!col($pdo,'sales',$c)) {
                $pdo->exec("ALTER TABLE sales ADD COLUMN $c $def");
                $steps[] = "[3] sales.$c added";
            }
        }
        if (!col($pdo,'cash_register_log','amount_lbp')) {
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN amount_lbp DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER amount_usd");
            $steps[] = '[3] cash_register_log.amount_lbp added';
        }
        if (!col($pdo,'cash_register_log','balance_after_lbp')) {
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN balance_after_lbp DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER balance_after_usd");
            $steps[] = '[3] cash_register_log.balance_after_lbp added';
        }
        if (!col($pdo,'cash_register_log','currency')) {
            $pdo->exec("ALTER TABLE cash_register_log ADD COLUMN currency ENUM('USD','LBP','BOTH') NOT NULL DEFAULT 'USD' AFTER type");
            $steps[] = '[3] cash_register_log.currency added';
        }
        // Always ensure the type enum is complete (opening,sale,withdrawal,deposit,void,expense,refund)
        $pdo->exec("ALTER TABLE cash_register_log MODIFY COLUMN type ENUM('opening','sale','withdrawal','deposit','void','expense','refund') NOT NULL");
        $steps[] = '[5/11] cash_register_log.type — ensured ENUM is complete (includes refund)';

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 5 — End of Shift table
        // ════════════════════════════════════════════════════════════════════════
        if (!tbl($pdo,'cash_shifts')) {
            $pdo->exec("CREATE TABLE cash_shifts (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                closed_by       INT NULL,
                closed_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                since_datetime  DATETIME NULL,
                balance_usd     DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_lbp     DECIMAL(12,2) NOT NULL DEFAULT 0,
                sales_count     INT NOT NULL DEFAULT 0,
                sales_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_in_usd     DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_in_lbp     DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_out_usd    DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_out_lbp    DECIMAL(12,2) NOT NULL DEFAULT 0,
                note            VARCHAR(500) NULL,
                CONSTRAINT fk_shift_user FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[5] cash_shifts table created';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 5b — purchases.payment_method (needed to reverse cash on delete)
        // ════════════════════════════════════════════════════════════════════════
        if (!col($pdo,'purchases','payment_method')) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN payment_method ENUM('pay_later','cash_register','cash_owner','cash_register_lbp') NOT NULL DEFAULT 'pay_later' AFTER total_amount");
            $steps[] = '[12] purchases.payment_method added';
        } else {
            // Ensure cash_register_lbp is in the ENUM (safe idempotent modify)
            $pdo->exec("ALTER TABLE purchases MODIFY COLUMN payment_method ENUM('pay_later','cash_register','cash_owner','cash_register_lbp') NOT NULL DEFAULT 'pay_later'");
            $steps[] = '[12b] purchases.payment_method ENUM updated';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 6 — Purchase items fixes
        // ════════════════════════════════════════════════════════════════════════
        if (!col($pdo,'purchase_items','total')) {
            $pdo->exec("ALTER TABLE purchase_items ADD COLUMN total DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit_cost");
            $pdo->exec("UPDATE purchase_items SET total = quantity * unit_cost WHERE total = 0 AND quantity > 0");
            $steps[] = '[6] purchase_items.total added and backfilled';
        }
        try {
            $pdo->exec("ALTER TABLE purchase_items MODIFY COLUMN product_id INT NULL");
            $steps[] = '[6] purchase_items.product_id — set nullable';
        } catch (PDOException $e) {
            // Already nullable or constraint issue — non-fatal
        }
        // Ensure product_type ENUM on purchase_items includes consignment & bulk
        if (col($pdo,'purchase_items','product_type')) {
            $pdo->exec("ALTER TABLE purchase_items MODIFY COLUMN product_type ENUM('regular','consignment','bulk') NOT NULL DEFAULT 'regular'");
            $steps[] = '[11] purchase_items.product_type — ensured ENUM has consignment + bulk';
        } else {
            $pdo->exec("ALTER TABLE purchase_items ADD COLUMN product_type ENUM('regular','consignment','bulk') NOT NULL DEFAULT 'regular' AFTER product_id");
            $steps[] = '[11] purchase_items.product_type — added';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 7 — Purchase Orders
        // ════════════════════════════════════════════════════════════════════════
        if (!tbl($pdo,'purchase_orders')) {
            $pdo->exec("CREATE TABLE purchase_orders (
                id                   INT AUTO_INCREMENT PRIMARY KEY,
                po_number            VARCHAR(50) UNIQUE,
                supplier_id          INT NOT NULL,
                status               ENUM('draft','sent','confirmed','received','cancelled') DEFAULT 'draft',
                delivery_date        DATE,
                received_purchase_id INT NULL,
                note                 TEXT,
                created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[1] purchase_orders table created';
        } else {
            if (!col($pdo,'purchase_orders','received_purchase_id')) {
                $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN received_purchase_id INT NULL AFTER delivery_date");
                $steps[] = '[3] purchase_orders.received_purchase_id added';
            }
        }
        if (!tbl($pdo,'purchase_order_items')) {
            $pdo->exec("CREATE TABLE purchase_order_items (
                id                 INT AUTO_INCREMENT PRIMARY KEY,
                po_id              INT NOT NULL,
                product_id         INT,
                product_name       VARCHAR(200) NOT NULL,
                quantity           DECIMAL(10,3) NOT NULL,
                unit               VARCHAR(30) DEFAULT 'pcs',
                estimated_price    DECIMAL(10,4) DEFAULT 0,
                note               VARCHAR(200),
                new_product_upb    SMALLINT NULL DEFAULT NULL,
                new_product_source ENUM('regular','consignment') NOT NULL DEFAULT 'regular',
                FOREIGN KEY (po_id)       REFERENCES purchase_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id)  REFERENCES products(id)        ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[1] purchase_order_items table created';
        } else {
            if (!col($pdo,'purchase_order_items','new_product_upb')) {
                $pdo->exec("ALTER TABLE purchase_order_items ADD COLUMN new_product_upb SMALLINT NULL DEFAULT NULL");
                $steps[] = '[6] purchase_order_items.new_product_upb added';
            }
            if (!col($pdo,'purchase_order_items','new_product_source')) {
                $pdo->exec("ALTER TABLE purchase_order_items ADD COLUMN new_product_source ENUM('regular','consignment') NOT NULL DEFAULT 'regular'");
                $steps[] = '[9] purchase_order_items.new_product_source added';
            }
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 8 — Customer ledger ENUM fix
        // ════════════════════════════════════════════════════════════════════════
        if (tbl($pdo,'customer_ledger')) {
            $pdo->exec("ALTER TABLE customer_ledger MODIFY COLUMN type ENUM('sale','payment','adjustment','refund') NOT NULL DEFAULT 'sale'");
            $steps[] = '[11] customer_ledger.type — ensured ENUM has refund';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 9 — Returns tables
        // ════════════════════════════════════════════════════════════════════════
        if (!tbl($pdo,'customer_returns')) {
            $pdo->exec("CREATE TABLE customer_returns (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                sale_id       INT NOT NULL,
                sale_item_id  INT NOT NULL,
                product_id    INT,
                product_name  VARCHAR(200) NOT NULL,
                quantity      DECIMAL(10,3) NOT NULL,
                unit_price    DECIMAL(10,4) NOT NULL,
                refund_amount DECIMAL(10,2) NOT NULL,
                note          TEXT,
                return_date   DATE NOT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cr_sale_id (sale_id),
                KEY idx_cr_item_id (sale_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[10] customer_returns table created';
        }
        if (!tbl($pdo,'supplier_returns')) {
            $pdo->exec("CREATE TABLE supplier_returns (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                batch_id      INT NOT NULL,
                product_id    INT NOT NULL,
                product_name  VARCHAR(200) NOT NULL,
                supplier_id   INT,
                quantity      DECIMAL(10,3) NOT NULL,
                unit_cost     DECIMAL(10,4) NOT NULL,
                credit_amount DECIMAL(10,2) NOT NULL,
                note          TEXT,
                return_date   DATE NOT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sr_batch    (batch_id),
                KEY idx_sr_supplier (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $steps[] = '[10] supplier_returns table created';
        }

        // ════════════════════════════════════════════════════════════════════════
        // BLOCK 10 — Data correction: customer balance creditUse bug (upgrade 11)
        //   This only runs once. Before v3.1.0 the POS added credit_used back to
        //   the customer balance instead of subtracting it. This corrects that.
        // ════════════════════════════════════════════════════════════════════════
        if (!in_array(11, $done)) {
            $corrected = 0;

            // DML-only block — safe to wrap in a real transaction
            $pdo->beginTransaction();
            try {
                // Non-void sales: each had 2×credit_used added too much
                $rows = $pdo->query("
                    SELECT customer_id, SUM(2 * credit_used) AS excess
                    FROM sales
                    WHERE customer_id IS NOT NULL AND credit_used > 0 AND is_void = 0
                    GROUP BY customer_id
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id=?")->execute([$r['excess'], $r['customer_id']]);
                    $pdo->prepare("INSERT INTO customer_ledger (customer_id, type, amount, note) VALUES (?,?,?,?)")
                        ->execute([$r['customer_id'], 'adjustment', -(float)$r['excess'], 'System correction v3.1 — credit-use balance fix']);
                    $corrected++;
                }

                // Voided sales: original (+2×credit) + wrong void (+total instead of +total+credit-netCash)
                // Net overcredit per voided sale = credit_used + net_cash_paid
                $rows = $pdo->query("
                    SELECT customer_id,
                           SUM(credit_used
                               + (paid_usd - change_usd)
                               + (paid_lbp - change_lbp) / GREATEST(1, exchange_rate_used)
                           ) AS excess
                    FROM sales
                    WHERE customer_id IS NOT NULL AND credit_used > 0 AND is_void = 1
                    GROUP BY customer_id
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if ((float)$r['excess'] != 0) {
                        $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id=?")->execute([$r['excess'], $r['customer_id']]);
                        $pdo->prepare("INSERT INTO customer_ledger (customer_id, type, amount, note) VALUES (?,?,?,?)")
                            ->execute([$r['customer_id'], 'adjustment', -(float)$r['excess'], 'System correction v3.1 — credit-use void fix']);
                        $corrected++;
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            if ($corrected > 0) {
                $steps[] = "[11] Customer balance correction applied — $corrected customer record(s) fixed";
            } else {
                $steps[] = '[11] Customer balance check passed — no credit-use records to correct';
            }
        } else {
            $steps[] = '[11] Customer balance correction — already applied, skipped';
        }

        // ════════════════════════════════════════════════════════════════════════
        // Update version.json → v3.1.0
        // ════════════════════════════════════════════════════════════════════════
        $vdata['version']            = '3.1.0';
        $vdata['installed_upgrades'] = [1,2,3,4,5,6,7,8,9,10,11];
        $vdata['last_updated']       = date('Y-m-d');
        $vdata['install_date']       = $vdata['install_date'] ?? date('Y-m-d');
        file_put_contents($vf, json_encode($vdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $steps[] = 'version.json → v3.1.0 (upgrades 1–11 marked complete)';

        if (empty($steps)) $steps[] = 'All already up to date — nothing changed.';

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Upgrade — v3.1.0</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background: #f0f4f0; }
  .brand-card { max-width: 680px; margin: 60px auto; }
  .step-ok   { color: #198754; }
  .step-warn { color: #fd7e14; }
</style>
</head>
<body>
<div class="card shadow-sm brand-card p-4">

  <div class="text-center mb-4">
    <?php if (file_exists(__DIR__.'/assets/img/logo.png')): ?>
    <img src="/dahdouh/assets/img/logo.png" style="height:72px;object-fit:contain"><br>
    <?php endif; ?>
    <h4 class="fw-bold mt-2" style="color:#2d5a2d"><?= htmlspecialchars(STORE_NAME) ?></h4>
    <span class="badge bg-success fs-6 mt-1">Combined Upgrade — v3.1.0</span>
    <p class="text-muted small mt-2">Consolidates all upgrades 1–11. Safe to run on any existing installation.</p>
  </div>

  <?php if ($steps): ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <strong>Errors occurred — some steps may be incomplete:</strong>
      <ul class="mb-0 mt-1"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
    </div>
    <?php endif; ?>

    <?php if ($warns): ?>
    <div class="alert alert-warning">
      <?php foreach ($warns as $w) echo "<div><i class='bi bi-exclamation-triangle me-1'></i>".htmlspecialchars($w)."</div>"; ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-success">
      <strong><i class="bi bi-check-circle me-1"></i>Upgrade complete!</strong>
      <ul class="mb-0 mt-2">
        <?php foreach ($steps as $s): ?>
        <li class="step-ok"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <a href="/dahdouh/" class="btn btn-success w-100">
      <i class="bi bi-house me-2"></i>Back to Dashboard
    </a>

  <?php elseif ($errors): ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="POST">
      <button name="upgrade" class="btn btn-warning w-100">
        <i class="bi bi-arrow-clockwise me-2"></i>Retry
      </button>
    </form>

  <?php else: ?>

    <div class="alert alert-warning py-2 small">
      <strong>Before running:</strong> Make sure <code>install.sql</code> has been imported in phpMyAdmin. This script upgrades an existing database — it does not create the base tables.
    </div>
    <p class="text-muted small mb-3">This single script replaces all previous upgrade scripts (1–10) and adds upgrade 11. It checks each step before running — already-applied changes are skipped automatically.</p>

    <h6 class="fw-bold mb-2">What this upgrade covers:</h6>
    <ul class="small text-muted mb-4">
      <li><strong>Consignment tracking</strong> — product source, consignment cost, consignment ledger</li>
      <li><strong>Supplier balance &amp; ledger</strong> — running balance, full payment history</li>
      <li><strong>User accounts</strong> — admin / cashier / stock roles, secure login</li>
      <li><strong>Sales void</strong> — void any sale, restores stock and cash register</li>
      <li><strong>Dual-currency cash register</strong> — separate USD and LBP drawers</li>
      <li><strong>End-of-shift snapshots</strong> — shift balances, movement summary</li>
      <li><strong>Box/packaging support</strong> — units per box, box sell price</li>
      <li><strong>Purchase items</strong> — per-row type (regular / consignment / bulk), total column</li>
      <li><strong>Purchase Orders</strong> — PO workflow, receive, WhatsApp share</li>
      <li><strong>Customer &amp; supplier returns</strong> — with cash register integration</li>
      <li><strong>Bug fix: customer credit balance</strong> — corrects accounts where store credit was mis-applied</li>
    </ul>

    <form method="POST">
      <button name="upgrade" class="btn btn-success w-100 py-2 fw-bold fs-5">
        <i class="bi bi-database-up me-2"></i>Run Upgrade → v3.1.0
      </button>
    </form>
    <p class="text-center text-muted small mt-3">
      <i class="bi bi-shield-check me-1"></i>All steps are idempotent — safe to run multiple times.
    </p>

  <?php endif; ?>

</div>
</body>
</html>
