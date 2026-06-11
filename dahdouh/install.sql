-- Mini Market POS System — Full Database Schema
CREATE DATABASE IF NOT EXISTS pos_minimarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_minimarket;

-- ─── Settings ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (`key`, value) VALUES
    ('store_name',                'Zoughaib Market'),
    ('store_address',             'Ras El Metn, Lebanon'),
    ('store_phone',               '+961 3 069769'),
    ('exchange_rate',             '89750'),
    ('base_currency',             'USD'),
    ('auto_print_receipt',        '0'),
    ('cash_register_balance_usd', '0');

-- ─── Lookup tables ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── Customers ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address TEXT,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── Products ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(100) UNIQUE,
    name VARCHAR(200) NOT NULL,
    category_id INT,
    supplier_id INT,
    product_type ENUM('regular','bulk') NOT NULL DEFAULT 'regular',
    cost_price DECIMAL(10,4) NOT NULL DEFAULT 0,
    sell_price DECIMAL(10,4) NOT NULL DEFAULT 0,
    stock DECIMAL(10,3) NOT NULL DEFAULT 0,
    low_stock_alert DECIMAL(10,3) NOT NULL DEFAULT 5,
    unit VARCHAR(30) DEFAULT 'pcs',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

-- ─── Purchases ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT,
    reference VARCHAR(100),
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    note TEXT,
    purchase_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

-- ─── Batches (FIFO per-price batch tracking) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    purchase_id INT,
    cost_price DECIMAL(10,4) NOT NULL,
    quantity_original DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantity_remaining DECIMAL(10,3) NOT NULL DEFAULT 0,
    purchase_date DATE NOT NULL,
    note VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
);

-- ─── Purchase Items ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    product_type ENUM('regular','bulk') NOT NULL DEFAULT 'regular',
    quantity DECIMAL(10,3) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,4) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    batch_id INT,
    batch_action ENUM('new','merged') DEFAULT 'new',
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
);

-- ─── Sales ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(50) UNIQUE,
    customer_id INT,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    credit_used DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_usd DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_lbp DECIMAL(10,2) NOT NULL DEFAULT 0,
    change_usd DECIMAL(10,2) NOT NULL DEFAULT 0,
    change_lbp DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency_paid VARCHAR(10) DEFAULT 'USD',
    exchange_rate_used DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash','card','mobile','account') DEFAULT 'cash',
    note TEXT,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- ─── Sale Items ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    product_type ENUM('regular','bulk') NOT NULL DEFAULT 'regular',
    quantity DECIMAL(10,3) NOT NULL,
    unit_price DECIMAL(10,4) NOT NULL,
    unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ─── Customer Ledger ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customer_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    sale_id INT,
    type ENUM('sale','payment','adjustment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
);

-- ─── Expenses ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(200) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    expense_date DATE NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── Cash Register Log ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cash_register_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('opening','sale','withdrawal','deposit') NOT NULL,
    amount_usd DECIMAL(10,2) NOT NULL,
    note TEXT,
    sale_id INT,
    balance_after_usd DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
);

-- ─── Default seed data ───────────────────────────────────────────────────────
INSERT IGNORE INTO categories (name) VALUES
    ('Beverages'),('Snacks'),('Dairy'),('Bakery'),('Cleaning'),
    ('Personal Care'),('Frozen'),('Vegetables & Fruits'),('Other');

INSERT IGNORE INTO suppliers (name, phone) VALUES ('Default Supplier','000-000-0000');
