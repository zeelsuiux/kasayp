-- ===================================================
-- Diamond Business Management Software Database Schema
-- Project: kasyap (Diamond ERP)
-- High Security & Enterprise Grade
-- ===================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS account_transactions;
DROP TABLE IF EXISTS salary_payments;
DROP TABLE IF EXISTS outsource_bills;
DROP TABLE IF EXISTS polish_sales;
DROP TABLE IF EXISTS rough_sales;
DROP TABLE IF EXISTS jangad_items;
DROP TABLE IF EXISTS jangad_vouchers;
DROP TABLE IF EXISTS packet_process_history;
DROP TABLE IF EXISTS tops_records;
DROP TABLE IF EXISTS boil_batches;
DROP TABLE IF EXISTS packets;
DROP TABLE IF EXISTS polish_stocks;
DROP TABLE IF EXISTS polish_purchases;
DROP TABLE IF EXISTS kapans;
DROP TABLE IF EXISTS assort_parts;
DROP TABLE IF EXISTS assort_tasks;
DROP TABLE IF EXISTS rough_purchases;
DROP TABLE IF EXISTS bank_accounts;
DROP TABLE IF EXISTS parties;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table (RBAC Authentication)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'accountant', 'operator') NOT NULL DEFAULT 'admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.1 Login Rate Limiting & Brute Force Protection
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    locked_until DATETIME DEFAULT NULL,
    last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_user (ip_address, username),
    INDEX idx_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Parties and Brokers Directory
CREATE TABLE parties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_name VARCHAR(150) NOT NULL,
    party_type ENUM('party', 'broker', 'both', 'outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia') NOT NULL,
    contact_person VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    city VARCHAR(50) DEFAULT 'Surat',
    address TEXT DEFAULT NULL,
    pan_gst VARCHAR(50) DEFAULT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_party_type (party_type),
    INDEX idx_party_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bank and Cash Accounts
CREATE TABLE bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('bank', 'cash') NOT NULL DEFAULT 'bank',
    bank_name VARCHAR(100) DEFAULT NULL,
    account_number VARCHAR(50) DEFAULT NULL,
    ifsc_code VARCHAR(30) DEFAULT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bank & Cash Transactions Ledger
CREATE TABLE account_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    type ENUM('credit', 'debit') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    related_type ENUM('rough_purchase', 'polish_purchase', 'rough_sale', 'polish_sale', 'brokerage', 'salary', 'outsource', 'transfer', 'deposit', 'withdraw', 'other') DEFAULT 'other',
    related_id INT DEFAULT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    payment_mode ENUM('cash', 'cheque', 'neft_rtgs', 'upi', 'other') DEFAULT 'neft_rtgs',
    transaction_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    INDEX idx_txn_date (transaction_date),
    INDEX idx_txn_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Rough Diamond Purchases (સેક્શન ૬.૧ ખરીદી)
CREATE TABLE rough_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(50) UNIQUE NOT NULL,
    purchase_date DATE NOT NULL,
    party_id INT NOT NULL,
    broker_id INT DEFAULT NULL,
    pcs INT NOT NULL DEFAULT 0,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    available_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    available_pcs INT NOT NULL DEFAULT 0,
    dollar_per_carat DECIMAL(12,2) DEFAULT 0.00,
    dollar_rate DECIMAL(10,2) DEFAULT 0.00,
    price_per_carat_inr DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    brokerage_percent DECIMAL(5,2) DEFAULT 0.00,
    brokerage_amount DECIMAL(15,2) DEFAULT 0.00,
    ac_gst_amount DECIMAL(15,2) DEFAULT 0.00,
    net_payment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    due_days INT DEFAULT 50,
    due_date DATE NOT NULL,
    party_payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    paid_amount DECIMAL(15,2) DEFAULT 0.00,
    chukvya_date DATE DEFAULT NULL,
    brokerage_payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    brokerage_paid_amount DECIMAL(15,2) DEFAULT 0.00,
    brokerage_chukvya_date DATE DEFAULT NULL,
    bank_account_id INT DEFAULT NULL,
    product_description TEXT DEFAULT NULL,
    safe VARCHAR(100) DEFAULT 'OFC',
    dabba VARCHAR(100) DEFAULT NULL,
    status ENUM('in_stock', 'in_assort', 'assorted', 'sold') DEFAULT 'in_stock',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (broker_id) REFERENCES parties(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    INDEX idx_due_date (due_date),
    INDEX idx_purchase_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Assort Task (સેક્શન ૬.૨ ભાગ પાડવા)
CREATE TABLE assort_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_no VARCHAR(50) UNIQUE NOT NULL,
    rough_purchase_id INT NOT NULL,
    assort_date DATE NOT NULL,
    total_input_pcs INT NOT NULL,
    total_input_weight DECIMAL(10,3) NOT NULL,
    total_output_pcs INT NOT NULL DEFAULT 0,
    total_output_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    weight_difference DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    status ENUM('pending', 'completed') DEFAULT 'completed',
    notes TEXT DEFAULT NULL,
    completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rough_purchase_id) REFERENCES rough_purchases(id),
    INDEX idx_task_date (assort_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Assort Parts (MFG, Ocha No, Out Sell, Manual Sawing, Custom)
CREATE TABLE assort_parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assort_task_id INT NOT NULL,
    source_type ENUM('rough_purchase','boil_reassort') NOT NULL DEFAULT 'rough_purchase',
    reassort_lot_id INT DEFAULT NULL,
    lot_no VARCHAR(50) DEFAULT NULL,
    assort_number VARCHAR(100) DEFAULT NULL,
    rough_number VARCHAR(100) DEFAULT NULL,
    rough_purchase_id INT DEFAULT NULL,
    part_name VARCHAR(100) NOT NULL,
    part_type VARCHAR(50) NOT NULL DEFAULT 'mfg',
    pcs INT NOT NULL DEFAULT 0,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    cost_per_carat DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    target_kapan_name VARCHAR(100) DEFAULT NULL,
    status ENUM('available', 'in_kapan', 'sold', 'in_sawing') DEFAULT 'available',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assort_task_id) REFERENCES assort_tasks(id) ON DELETE CASCADE,
    INDEX idx_part_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Kapan (સેક્શન ૬.૩ ઉત્પાદન બેચ)
CREATE TABLE kapans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kapan_code VARCHAR(50) UNIQUE NOT NULL,
    kapan_name VARCHAR(100) NOT NULL,
    rough_purchase_id INT DEFAULT NULL,
    assort_part_id INT DEFAULT NULL,
    total_pcs INT NOT NULL DEFAULT 0,
    total_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    rough_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    galaxy_cost DECIMAL(15,2) DEFAULT 0.00,
    ls_cost DECIMAL(15,2) DEFAULT 0.00,
    labour_cost DECIMAL(15,2) DEFAULT 0.00,
    other_cost DECIMAL(15,2) DEFAULT 0.00,
    total_cost DECIMAL(15,2) DEFAULT 0.00,
    total_polish_pcs INT DEFAULT 0,
    total_polish_weight DECIMAL(10,3) DEFAULT 0.000,
    total_tops_weight DECIMAL(10,3) DEFAULT 0.000,
    yield_percentage DECIMAL(6,2) DEFAULT 0.00,
    cost_per_piece DECIMAL(12,2) DEFAULT 0.00,
    cost_per_carat DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('active', 'in_production', 'boil_completed', 'closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rough_purchase_id) REFERENCES rough_purchases(id),
    FOREIGN KEY (assort_part_id) REFERENCES assort_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Boil Batches (સાપ્તાહિક બોઈલ)
CREATE TABLE boil_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_no VARCHAR(50) UNIQUE NOT NULL,
    boil_date DATE NOT NULL,
    total_pcs INT NOT NULL DEFAULT 0,
    total_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    status ENUM('in_boil', 'completed') DEFAULT 'completed',
    completed_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Polish Stocks (Single, Parcel, GIA Single)
CREATE TABLE polish_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    packet_no VARCHAR(50) UNIQUE NOT NULL,
    stock_type ENUM('single', 'parcel', 'gia_single') NOT NULL DEFAULT 'parcel',
    gia_number VARCHAR(50) DEFAULT NULL,
    source_type ENUM('mfg', 'polish_purchase', 'memo_inward') NOT NULL DEFAULT 'mfg',
    kapan_id INT DEFAULT NULL,
    polish_purchase_id INT DEFAULT NULL,
    shape VARCHAR(50) DEFAULT 'Round',
    clarity VARCHAR(50) DEFAULT 'VS1',
    color VARCHAR(50) DEFAULT 'G',
    cut VARCHAR(50) DEFAULT 'EX',
    size_description VARCHAR(100) DEFAULT NULL,
    total_pcs INT NOT NULL DEFAULT 1,
    total_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    available_pcs INT NOT NULL DEFAULT 1,
    available_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    cost_per_carat DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    sale_price_per_carat DECIMAL(14,2) DEFAULT 0.00,
    current_location VARCHAR(100) DEFAULT 'Surat',
    status ENUM('available', 'on_jangad', 'partially_on_jangad', 'sold') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kapan_id) REFERENCES kapans(id),
    INDEX idx_polish_status (status),
    INDEX idx_polish_type (stock_type),
    INDEX idx_polish_location (current_location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Packets (સેક્શન ૬.૩ બારકોડ સાથે પેકેટ)
CREATE TABLE packets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kapan_id INT NOT NULL,
    packet_no VARCHAR(50) UNIQUE NOT NULL,
    barcode VARCHAR(50) UNIQUE NOT NULL,
    pcs INT NOT NULL DEFAULT 1,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    current_stage VARCHAR(50) NOT NULL DEFAULT 'galaxy',
    current_location VARCHAR(100) DEFAULT 'Surat',
    assigned_party_id INT DEFAULT NULL,
    jangad_id INT DEFAULT NULL,
    expected_shape VARCHAR(50) DEFAULT NULL,
    can_make_second_part TINYINT(1) DEFAULT 0,
    secondary_shape VARCHAR(50) DEFAULT NULL,
    ls_required ENUM('yes', 'no') DEFAULT 'no',
    ls_status ENUM('not_needed', 'pending', 'completed', 'kachi_process') DEFAULT 'not_needed',
    polish_weight DECIMAL(10,3) DEFAULT 0.000,
    tops_weight DECIMAL(10,3) DEFAULT 0.000,
    boil_batch_id INT DEFAULT NULL,
    polish_stock_id INT DEFAULT NULL,
    status ENUM('in_process', 'boil_ready', 'in_polish_stock', 'sold') DEFAULT 'in_process',
    diamond_status VARCHAR(50) DEFAULT 'available',
    diamond_no VARCHAR(50) DEFAULT NULL,
    parent_diamond_id INT DEFAULT NULL,
    parent_diamond_no VARCHAR(50) DEFAULT NULL,
    rough_purchase_id INT DEFAULT NULL,
    rough_number VARCHAR(50) DEFAULT NULL,
    assort_task_id INT DEFAULT NULL,
    assort_part_id INT DEFAULT NULL,
    assort_number VARCHAR(50) DEFAULT NULL,
    kapan_number VARCHAR(50) DEFAULT NULL,
    original_weight DECIMAL(10,3) DEFAULT 0.000,
    current_weight DECIMAL(10,3) DEFAULT 0.000,
    split_depth INT DEFAULT 0,
    origin_reassort_lot_id INT DEFAULT NULL,
    origin_reassort_lot_no VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kapan_id) REFERENCES kapans(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_party_id) REFERENCES parties(id),
    FOREIGN KEY (boil_batch_id) REFERENCES boil_batches(id),
    FOREIGN KEY (polish_stock_id) REFERENCES polish_stocks(id),
    INDEX idx_packet_barcode (barcode),
    INDEX idx_packet_stage (current_stage),
    INDEX idx_packet_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Jangad Vouchers (જાંગડ / મેમો અસલ)
CREATE TABLE jangad_vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jangad_no VARCHAR(50) UNIQUE NOT NULL,
    voucher_type ENUM('issue', 'return') NOT NULL DEFAULT 'issue',
    category ENUM('outsource_galaxy', 'outsource_ls', 'outsource_factory', 'memo_broker', 'memo_party', 'lab_gia') NOT NULL,
    party_id INT NOT NULL,
    broker_id INT DEFAULT NULL,
    issue_date DATE NOT NULL,
    expected_return_date DATE DEFAULT NULL,
    actual_return_date DATE DEFAULT NULL,
    total_pcs INT NOT NULL DEFAULT 0,
    total_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    returned_pcs INT NOT NULL DEFAULT 0,
    returned_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    status ENUM('issued', 'partially_returned', 'completed', 'cancelled') DEFAULT 'issued',
    remarks TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (broker_id) REFERENCES parties(id),
    INDEX idx_jangad_party (party_id),
    INDEX idx_jangad_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Jangad Items (જાંગડમાં સામેલ આઇટમ્સ)
CREATE TABLE jangad_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jangad_id INT NOT NULL,
    item_type ENUM('rough_packet', 'polish_single', 'polish_parcel', 'polish_gia') NOT NULL,
    packet_id INT DEFAULT NULL,
    polish_stock_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    issued_pcs INT NOT NULL DEFAULT 1,
    issued_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    returned_pcs INT NOT NULL DEFAULT 0,
    returned_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    loss_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    charges DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('issued', 'returned', 'partially_returned') DEFAULT 'issued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (jangad_id) REFERENCES jangad_vouchers(id) ON DELETE CASCADE,
    FOREIGN KEY (packet_id) REFERENCES packets(id),
    FOREIGN KEY (polish_stock_id) REFERENCES polish_stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Packet Process History & Auditing
CREATE TABLE IF NOT EXISTS diamond_stage_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    packet_id INT NOT NULL,
    diamond_no VARCHAR(50) DEFAULT NULL,
    stage_code VARCHAR(50) DEFAULT NULL,
    stage_name VARCHAR(100) DEFAULT NULL,
    party_id INT DEFAULT NULL,
    party_name VARCHAR(150) DEFAULT NULL,
    jangad_id INT DEFAULT NULL,
    jangad_no VARCHAR(50) DEFAULT NULL,
    assignment_id INT DEFAULT NULL,
    given_weight DECIMAL(10,3) DEFAULT 0.000,
    received_weight DECIMAL(10,3) DEFAULT 0.000,
    difference_weight DECIMAL(10,3) DEFAULT 0.000,
    pending_weight DECIMAL(10,3) DEFAULT 0.000,
    diff_classification VARCHAR(50) DEFAULT 'pending',
    loss_weight DECIMAL(10,3) DEFAULT 0.000,
    tops_weight DECIMAL(10,3) DEFAULT 0.000,
    other_weight DECIMAL(10,3) DEFAULT 0.000,
    assigned_date DATE DEFAULT NULL,
    return_date DATE DEFAULT NULL,
    outgoing_location VARCHAR(100) DEFAULT NULL,
    receiving_location VARCHAR(100) DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'in_process',
    notes TEXT DEFAULT NULL,
    logged_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (packet_id),
    KEY (jangad_id),
    KEY (stage_code),
    KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE packet_process_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    packet_id INT NOT NULL,
    stage VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL,
    party_id INT DEFAULT NULL,
    jangad_id INT DEFAULT NULL,
    input_weight DECIMAL(10,3) DEFAULT NULL,
    output_weight DECIMAL(10,3) DEFAULT NULL,
    tops_weight DECIMAL(10,3) DEFAULT NULL,
    cost DECIMAL(10,2) DEFAULT 0.00,
    details TEXT DEFAULT NULL,
    logged_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (packet_id) REFERENCES packets(id) ON DELETE CASCADE,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (jangad_id) REFERENCES jangad_vouchers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Tops (Scrap / Waste) Records
CREATE TABLE tops_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kapan_id INT DEFAULT NULL,
    packet_id INT DEFAULT NULL,
    source_stage VARCHAR(50) DEFAULT 'ls_sawing',
    pcs INT DEFAULT 0,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    rate_per_carat DECIMAL(12,2) DEFAULT 0.00,
    total_value DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('in_stock', 'sold') DEFAULT 'in_stock',
    record_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kapan_id) REFERENCES kapans(id),
    FOREIGN KEY (packet_id) REFERENCES packets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Polish Purchases (પોલિશ ખરીદી)
CREATE TABLE polish_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(50) UNIQUE NOT NULL,
    purchase_date DATE NOT NULL,
    party_id INT NOT NULL,
    broker_id INT DEFAULT NULL,
    stock_type ENUM('single', 'parcel', 'gia_single') DEFAULT 'parcel',
    gia_number VARCHAR(50) DEFAULT NULL,
    shape VARCHAR(50) DEFAULT 'Round',
    color VARCHAR(50) DEFAULT 'G',
    clarity VARCHAR(50) DEFAULT 'VS1',
    pcs INT NOT NULL DEFAULT 0,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    dollar_per_carat DECIMAL(12,2) DEFAULT 0.00,
    dollar_rate DECIMAL(10,2) DEFAULT 0.00,
    price_per_carat_inr DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    brokerage_percent DECIMAL(5,2) DEFAULT 0.00,
    brokerage_amount DECIMAL(15,2) DEFAULT 0.00,
    ac_gst_amount DECIMAL(15,2) DEFAULT 0.00,
    net_payment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    due_days INT DEFAULT 50,
    due_date DATE NOT NULL,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    paid_amount DECIMAL(15,2) DEFAULT 0.00,
    chukvya_date DATE DEFAULT NULL,
    brokerage_payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    brokerage_chukvya_date DATE DEFAULT NULL,
    bank_account_id INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (broker_id) REFERENCES parties(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Polish Diamond Sales (પોલિશ વેચાણ)
CREATE TABLE polish_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    sale_date DATE NOT NULL,
    party_id INT NOT NULL,
    broker_id INT DEFAULT NULL,
    polish_stock_id INT NOT NULL,
    pcs INT NOT NULL DEFAULT 1,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    price_per_carat_inr DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    brokerage_percent DECIMAL(5,2) DEFAULT 0.00,
    brokerage_amount DECIMAL(15,2) DEFAULT 0.00,
    ac_gst_amount DECIMAL(15,2) DEFAULT 0.00,
    net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    due_days INT DEFAULT 50,
    due_date DATE NOT NULL,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    received_amount DECIMAL(15,2) DEFAULT 0.00,
    received_date DATE DEFAULT NULL,
    brokerage_payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (broker_id) REFERENCES parties(id),
    FOREIGN KEY (polish_stock_id) REFERENCES polish_stocks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Rough Diamond Sales (રફ સીધું વેચાણ અથવા આઉટ સેલ)
CREATE TABLE rough_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    sale_date DATE NOT NULL,
    party_id INT NOT NULL,
    broker_id INT DEFAULT NULL,
    source_type ENUM('direct', 'assort_part') DEFAULT 'direct',
    rough_purchase_id INT DEFAULT NULL,
    assort_part_id INT DEFAULT NULL,
    pcs INT NOT NULL DEFAULT 0,
    weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    price_per_carat_inr DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    payment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    brokerage_percent DECIMAL(5,2) DEFAULT 0.00,
    brokerage_amount DECIMAL(15,2) DEFAULT 0.00,
    ac_gst_amount DECIMAL(15,2) DEFAULT 0.00,
    net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    due_days INT DEFAULT 50,
    due_date DATE NOT NULL,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    received_amount DECIMAL(15,2) DEFAULT 0.00,
    received_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (broker_id) REFERENCES parties(id),
    FOREIGN KEY (rough_purchase_id) REFERENCES rough_purchases(id),
    FOREIGN KEY (assort_part_id) REFERENCES assort_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Employees Directory
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    salary_type ENUM('monthly', 'daily', 'per_piece') DEFAULT 'monthly',
    basic_salary DECIMAL(12,2) DEFAULT 0.00,
    joining_date DATE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Staff Salary Payments
CREATE TABLE salary_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    month INT NOT NULL,
    year INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    bank_account_id INT NOT NULL,
    payment_mode VARCHAR(50) DEFAULT 'bank_transfer',
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Outsource Vendor Bills (Galaxy, LS, Labour Factory)
CREATE TABLE outsource_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_id INT NOT NULL,
    service_type ENUM('galaxy', 'ls', 'factory') NOT NULL,
    bill_no VARCHAR(100) NOT NULL,
    bill_date DATE NOT NULL,
    total_pcs INT DEFAULT 0,
    total_weight DECIMAL(10,3) DEFAULT 0.000,
    rate_per_unit DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) DEFAULT 0.00,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    bank_account_id INT DEFAULT NULL,
    payment_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (party_id) REFERENCES parties(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Security Activity Logs / Audit Trail
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    record_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_module (module),
    INDEX idx_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. System Settings
CREATE TABLE system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
