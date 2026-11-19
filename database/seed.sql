-- ===================================================
-- Initial Master Data & Realistic Diamond Business Seeds
-- ===================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE activity_logs;
TRUNCATE TABLE account_transactions;
TRUNCATE TABLE salary_payments;
TRUNCATE TABLE outsource_bills;
TRUNCATE TABLE polish_sales;
TRUNCATE TABLE rough_sales;
TRUNCATE TABLE jangad_items;
TRUNCATE TABLE jangad_vouchers;
TRUNCATE TABLE packet_process_history;
TRUNCATE TABLE tops_records;
TRUNCATE TABLE boil_batches;
TRUNCATE TABLE packets;
TRUNCATE TABLE polish_stocks;
TRUNCATE TABLE polish_purchases;
TRUNCATE TABLE kapans;
TRUNCATE TABLE assort_parts;
TRUNCATE TABLE assort_tasks;
TRUNCATE TABLE rough_purchases;
TRUNCATE TABLE bank_accounts;
TRUNCATE TABLE parties;
TRUNCATE TABLE employees;
TRUNCATE TABLE system_settings;
TRUNCATE TABLE users;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed Users (Passwords must be configured during initial deployment)
-- Set must_change_password = 1 to enforce immediate password reset on initial login
INSERT INTO users (id, username, full_name, email, password_hash, role, must_change_password, status) VALUES
(1, 'admin', 'Kasyap Gems Admin', 'admin@kasyapgems.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe8vG5Qe7H61w46lUjLw9sDkg2fLw1uSm', 'admin', 1, 'active'),
(2, 'manager', 'Rajesh Patel (Production Manager)', 'manager@kasyapgems.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe8vG5Qe7H61w46lUjLw9sDkg2fLw1uSm', 'manager', 1, 'active'),
(3, 'accountant', 'Bhavin Shah (Accountant)', 'accounts@kasyapgems.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe8vG5Qe7H61w46lUjLw9sDkg2fLw1uSm', 'accountant', 1, 'active'),
(4, 'operator', 'Chetan Vora (Barcode & Scan)', 'operator@kasyapgems.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe8vG5Qe7H61w46lUjLw9sDkg2fLw1uSm', 'operator', 1, 'active');

-- Bank & Cash Accounts
INSERT INTO bank_accounts (id, account_name, account_type, bank_name, account_number, ifsc_code, opening_balance, current_balance, status) VALUES
(1, 'HDFC Current A/c (Surat)', 'bank', 'HDFC Bank Ltd', '50200049281723', 'HDFC0001024', 4500000.00, 4125000.00, 'active'),
(2, 'ICICI Current A/c (Mumbai)', 'bank', 'ICICI Bank Ltd', '003205008912', 'ICIC0000032', 3200000.00, 3100000.00, 'active'),
(3, 'Surat Office Cash Counter', 'cash', 'Cash In Hand', 'CASH-SURAT-01', NULL, 350000.00, 280000.00, 'active'),
(4, 'Mumbai BDB Cash Counter', 'cash', 'Cash In Hand', 'CASH-BDB-02', NULL, 250000.00, 210000.00, 'active');

-- Parties, Outsources, Brokers & Labs
INSERT INTO parties (id, party_name, party_type, contact_person, phone, city, address, pan_gst, opening_balance, current_balance, notes) VALUES
(1, 'Antwerp Rough Direct Ltd', 'party', 'Marc De Smet', '+32 3 234 5678', 'Surat', 'Hira Bourse, Mahidharpura', 'AACCA1234F', 0.00, 0.00, 'Direct Rough Importer'),
(2, 'Shree Ramkrishna Rough Supply', 'party', 'Kishorbhai Patel', '9825123456', 'Surat', 'Katargam GIDC, Surat', 'AABCS9876K', 0.00, 0.00, 'Rough Importer & Supplier'),
(3, 'D. Navin & Co. (Broker)', 'broker', 'Navinbhai Doshi', '9898011223', 'Surat', 'Mahidharpura Choksi Bazaar', 'AABFD5544P', 0.00, 0.00, 'Senior Rough Broker (1% standard)'),
(4, 'Pravinbhai Zaveri (Polish Broker)', 'broker', 'Pravin Zaveri', '9824099887', 'Mumbai', 'Bharat Diamond Bourse, BKC, Mumbai', 'AAEFZ9988L', 0.00, 0.00, 'Polish Diamond Broker (0.5%)'),
(5, 'Galaxy 3D Laser Scanning Hub', 'outsource_galaxy', 'Jignesh Mewada', '9879055443', 'Surat', 'Varachha Main Road, Surat', 'GSTIN24AABCX11', 0.00, 0.00, 'Galaxy Scanner Vendor'),
(6, 'Om Laser Sawing (LS) Center', 'outsource_ls', 'Haresh Savani', '9825566778', 'Surat', 'Mini Bazaar, Varachha', 'GSTIN24AABCH22', 0.00, 0.00, 'LS 4P and Sawing Center'),
(7, 'Katargam Diamond Karkhanu Unit 1', 'outsource_factory', 'Rameshbhai Polisher', '9898744332', 'Surat', 'Katargam, Surat', NULL, 0.00, 0.00, 'Internal Labour/Polish Factory'),
(8, 'GIA India Laboratory', 'lab_gia', 'Dr. Mehra', '022 4085 1500', 'Mumbai', 'BDB Tower CE, Bandra Kurla Complex', 'GSTIN27AAACG00', 0.00, 0.00, 'Certification Lab'),
(9, 'K. P. International Polish Buyers', 'party', 'Kanubhai Shah', '9821033445', 'Mumbai', 'Panchratna Building, Opera House', 'AABCK4433M', 0.00, 0.00, 'Polish Diamond Buyer (Surat/Mumbai)'),
(10, 'Venus Jewels Import Export', 'party', 'Sureshbhai', '9825088776', 'Surat', 'Surat SEZ, Sachin', 'AABCV7766R', 0.00, 0.00, 'Polish Diamond Customer');

-- Sample Rough Purchase 1 (Kapan A ready)
INSERT INTO rough_purchases (
    id, purchase_no, purchase_date, party_id, broker_id, pcs, weight, available_weight, available_pcs,
    dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount, brokerage_percent, brokerage_amount,
    ac_gst_amount, net_payment, due_days, due_date, party_payment_status, paid_amount, chukvya_date,
    brokerage_payment_status, brokerage_paid_amount, bank_account_id, product_description, status, created_by
) VALUES
(1, 'R-PUR-2026-001', '2026-10-15', 2, 3, 100, 150.000, 0.000, 0, 320.00, 83.50, 26720.00, 4008000.00, 1.00, 40080.00, 5000.00, 4053080.00, 50, '2026-12-04', 'paid', 4053080.00, '2026-10-25', 'paid', 40080.00, 1, 'Botswana High Yield Rough Lot 150 Cts', 'assorted', 1),
(2, 'R-PUR-2026-002', '2026-10-28', 1, 3, 80, 120.500, 120.500, 80, 410.00, 83.60, 34276.00, 4130258.00, 1.00, 41302.58, 6000.00, 4177560.58, 50, '2026-12-17', 'pending', 0.00, NULL, 'pending', 0.00, 1, 'Angola White Makeables Rough', 'in_stock', 1),
(3, 'R-PUR-2026-110', '2026-11-01', 2, 3, 45, 65.200, 65.200, 45, 290.00, 83.70, 24273.00, 1582600.00, 1.00, 15826.00, 2500.00, 1600926.00, 45, '2026-12-16', 'pending', 0.00, NULL, 'pending', 0.00, 2, 'South African Sawable Rough', 'in_stock', 1);

-- Assort Task for Purchase #1
INSERT INTO assort_tasks (id, task_no, rough_purchase_id, assort_date, total_input_pcs, total_input_weight, total_output_pcs, total_output_weight, weight_difference, status, notes, completed_at, created_by)
VALUES
(1, 'ASRT-2026-001', 1, '2026-10-16', 100, 150.000, 100, 150.000, 0.000, 'completed', 'Assort split into Kapan A for MFG and Out-Sell Part', '2026-10-16 16:30:00', 1);

-- Assort Parts
INSERT INTO assort_parts (id, assort_task_id, part_name, part_type, pcs, weight, target_kapan_name, status, notes) VALUES
(1, 1, 'Kapan A (Direct MFG)', 'mfg', 70, 110.000, 'Kapan A-2026', 'in_kapan', 'Good makeable pieces for factory'),
(2, 1, 'Part B (Ocha Number)', 'low_number_mfg', 15, 22.000, 'Kapan B-Late', 'available', 'Low clarity to process next batch'),
(3, 1, 'Part C (Rough Out Sell)', 'out_sell', 10, 12.000, NULL, 'sold', 'Sold directly as rough to local buyer'),
(4, 1, 'Part D (Manual Sawing)', 'manual_sawing', 5, 6.000, NULL, 'available', 'Hand cleavage required');

-- Kapan Record
INSERT INTO kapans (
    id, kapan_code, kapan_name, rough_purchase_id, assort_part_id, total_pcs, total_weight,
    rough_cost, galaxy_cost, ls_cost, labour_cost, other_cost, total_cost,
    total_polish_pcs, total_polish_weight, total_tops_weight, yield_percentage,
    cost_per_piece, cost_per_carat, status
) VALUES
(1, 'KPN-A01', 'Kapan A-2026', 1, 1, 70, 110.000, 2972420.00, 14000.00, 28000.00, 42000.00, 5000.00, 3061420.00, 70, 39.600, 8.200, 36.00, 43734.57, 77308.58, 'boil_completed');

-- Boil Batch
INSERT INTO boil_batches (id, batch_no, boil_date, total_pcs, total_weight, status, completed_date, notes, created_by)
VALUES (1, 'BOIL-WK-43', '2026-10-29', 20, 11.350, 'completed', '2026-10-30', 'Weekly acid boil cleaning completed successfully. Diamonds bright and ready for Polish Stock.', 1);

-- Packets under Kapan A-2026
INSERT INTO packets (
    id, kapan_id, packet_no, barcode, pcs, weight, current_stage, current_location,
    assigned_party_id, expected_shape, can_make_second_part, secondary_shape,
    ls_required, ls_status, polish_weight, tops_weight, boil_batch_id, status
) VALUES
(1, 1, 'KPNA-001', 'BAR-KPNA-001', 1, 1.850, 'polish_stock', 'Surat', 7, 'Round', 1, 'Princess', 'yes', 'completed', 0.680, 0.150, 1, 'in_polish_stock'),
(2, 1, 'KPNA-002', 'BAR-KPNA-002', 1, 2.100, 'polish_stock', 'Surat', 7, 'Emerald', 0, NULL, 'yes', 'completed', 0.770, 0.180, 1, 'in_polish_stock'),
(3, 1, 'KPNA-003', 'BAR-KPNA-003', 1, 1.450, 'polish_stock', 'Surat', 7, 'Round', 0, NULL, 'no', 'not_needed', 0.520, 0.000, 1, 'in_polish_stock'),
(4, 1, 'KPNA-004', 'BAR-KPNA-004', 1, 3.200, 'factory', 'Karkhanu', 7, 'Round', 1, 'Pear', 'yes', 'completed', 0.000, 0.320, NULL, 'in_process'),
(5, 1, 'KPNA-005', 'BAR-KPNA-005', 1, 1.950, 'ls', 'LS', 6, 'Princess', 0, NULL, 'yes', 'pending', 0.000, 0.000, NULL, 'in_process'),
(6, 1, 'KPNA-006', 'BAR-KPNA-006', 1, 1.600, 'galaxy', 'Galaxy', 5, 'Oval', 0, NULL, 'no', 'not_needed', 0.000, 0.000, NULL, 'in_process'),
(7, 1, 'KPNA-007', 'BAR-KPNA-007', 1, 2.400, 'sarin_final', 'Surat', NULL, 'Round', 0, NULL, 'yes', 'completed', 0.000, 0.220, NULL, 'in_process'),
(8, 1, 'KPNA-008', 'BAR-KPNA-008', 1, 1.250, 'planner', 'Surat', NULL, 'Cushion', 0, NULL, 'no', 'not_needed', 0.000, 0.000, NULL, 'in_process');

-- Tops Record
INSERT INTO tops_records (id, kapan_id, packet_id, source_stage, pcs, weight, rate_per_carat, total_value, status, record_date, notes) VALUES
(1, 1, 1, 'ls_sawing', 1, 0.150, 8500.00, 1275.00, 'in_stock', '2026-10-20', 'Top piece from LS Sawing KPNA-001'),
(2, 1, 2, 'ls_sawing', 1, 0.180, 8500.00, 1530.00, 'in_stock', '2026-10-20', 'Top piece from LS Sawing KPNA-002'),
(3, 1, 4, 'ls_sawing', 1, 0.320, 9000.00, 2880.00, 'in_stock', '2026-10-22', 'Clean top piece from KPNA-004');

-- Polish Stock Entries
INSERT INTO polish_stocks (
    id, packet_no, stock_type, gia_number, source_type, kapan_id, shape, clarity, color, cut,
    size_description, total_pcs, total_weight, available_pcs, available_weight, cost_per_carat,
    sale_price_per_carat, current_location, status
) VALUES
(1, 'POL-SNG-1001', 'single', NULL, 'mfg', 1, 'Round', 'VVS2', 'E', 'EX', '0.68 ct Solitaire', 1, 0.680, 1, 0.680, 78000.00, 95000.00, 'Surat', 'available'),
(2, 'POL-GIA-2001', 'gia_single', 'GIA-2194883921', 'mfg', 1, 'Emerald', 'VS1', 'F', 'EX', '0.77 ct GIA Certified', 1, 0.770, 1, 0.770, 82000.00, 105000.00, 'Mumbai', 'available'),
(3, 'POL-PRC-3001', 'parcel', NULL, 'mfg', 1, 'Round', 'VS', 'G-H', 'VG', '+11 to +14 Sieve Parcel', 10, 100.000, 7, 60.000, 48000.00, 58000.00, 'Surat', 'partially_on_jangad'),
(4, 'POL-PRC-3002', 'parcel', NULL, 'polish_purchase', NULL, 'Princess', 'SI1', 'H', 'VG', '-2 Sieve Small Stars', 50, 15.500, 50, 15.500, 32000.00, 39000.00, 'Surat', 'available');

-- Jangad Voucher for Outsource Galaxy & LS and Memo Broker
INSERT INTO jangad_vouchers (
    id, jangad_no, voucher_type, category, party_id, broker_id, issue_date, expected_return_date,
    actual_return_date, total_pcs, total_weight, returned_pcs, returned_weight, status, remarks, created_by
) VALUES
(1, 'JNG-OUT-2026-001', 'issue', 'outsource_galaxy', 5, NULL, '2026-10-18', '2026-10-20', '2026-10-19', 20, 32.500, 20, 32.500, 'completed', 'Galaxy scanning for Kapan A lot 1', 1),
(2, 'JNG-OUT-2026-002', 'issue', 'outsource_ls', 6, NULL, '2026-10-21', '2026-10-24', '2026-10-23', 15, 24.800, 15, 24.800, 'completed', 'Laser sawing outsource', 1),
(3, 'JNG-OUT-2026-003', 'issue', 'outsource_factory', 7, NULL, '2026-10-24', '2026-11-05', NULL, 10, 18.200, 3, 5.400, 'partially_returned', 'Katargam polishing lot', 1),
(4, 'JNG-MEM-2026-010', 'issue', 'memo_broker', 9, 4, '2026-11-01', '2026-11-08', NULL, 3, 40.000, 0, 0.000, 'issued', 'Partial Jangad from Parcel POL-PRC-3001 sent to Mumbai Party for review', 1);

-- Jangad Items
INSERT INTO jangad_items (id, jangad_id, item_type, packet_id, polish_stock_id, description, issued_pcs, issued_weight, returned_pcs, returned_weight, loss_weight, charges, status) VALUES
(1, 1, 'rough_packet', 1, NULL, 'KPNA-001 Galaxy Scan', 1, 1.850, 1, 1.850, 0.000, 200.00, 'returned'),
(2, 2, 'rough_packet', 1, NULL, 'KPNA-001 LS Sawing', 1, 1.850, 1, 1.700, 0.000, 450.00, 'returned'),
(3, 4, 'polish_parcel', NULL, 3, 'Partial Memo from POL-PRC-3001 (Round VS G-H)', 3, 40.000, 0, 0.000, 0.000, 0.00, 'issued');

-- Polish Purchase
INSERT INTO polish_purchases (
    id, purchase_no, purchase_date, party_id, broker_id, stock_type, gia_number, shape, color, clarity,
    pcs, weight, dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount, brokerage_percent,
    brokerage_amount, ac_gst_amount, net_payment, due_days, due_date, payment_status, paid_amount,
    bank_account_id, notes, created_by
) VALUES
(1, 'P-PUR-2026-001', '2026-10-25', 10, 4, 'parcel', NULL, 'Princess', 'H', 'SI1', 50, 15.500, 380.00, 83.50, 31730.00, 491815.00, 0.50, 2459.08, 1500.00, 495774.08, 50, '2026-12-14', 'pending', 0.00, 1, 'Princess cut parcel bought for stock', 1);

-- Rough Sale
INSERT INTO rough_sales (
    id, invoice_no, sale_date, party_id, broker_id, source_type, rough_purchase_id, assort_part_id,
    pcs, weight, price_per_carat_inr, payment_amount, brokerage_percent, brokerage_amount, ac_gst_amount,
    net_amount, due_days, due_date, payment_status, received_amount, received_date, notes, created_by
) VALUES
(1, 'R-INV-2026-001', '2026-10-20', 10, 3, 'assort_part', 1, 3, 10, 12.000, 28500.00, 342000.00, 1.00, 3420.00, 0.00, 338580.00, 30, '2026-11-19', 'paid', 338580.00, '2026-10-22', 'Out Sell of Part C Rough', 1);

-- In-house Employees
INSERT INTO employees (id, emp_code, name, designation, phone, salary_type, basic_salary, joining_date, status) VALUES
(1, 'EMP-01', 'Pravin Vaghasiya', 'Head Assorter', '9825100011', 'monthly', 45000.00, '2024-01-01', 'active'),
(2, 'EMP-02', 'Dharmesh Savalia', 'Sarin / Planner Expert', '9825100022', 'monthly', 40000.00, '2024-03-01', 'active'),
(3, 'EMP-03', 'Hitesh Gondaliya', 'Boil & Stock Incharge', '9825100033', 'monthly', 30000.00, '2024-05-15', 'active');

-- Outsource Bill
INSERT INTO outsource_bills (id, party_id, service_type, bill_no, bill_date, total_pcs, total_weight, rate_per_unit, total_amount, paid_amount, payment_status, bank_account_id, payment_date, notes, created_by)
VALUES
(1, 5, 'galaxy', 'GAL-INV-891', '2026-10-20', 20, 32.500, 700.00, 14000.00, 14000.00, 'paid', 1, '2026-10-22', 'Galaxy scan charges batch 1', 1),
(2, 6, 'ls', 'LS-INV-441', '2026-10-24', 15, 24.800, 1866.67, 28000.00, 28000.00, 'paid', 1, '2026-10-25', 'LS Sawing batch 1', 1);

-- System Settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('company_name', 'Kasyap Gems & Diamonds'),
('company_address', 'Tower 3, Office 402, Surat Diamond Bourse (SDB), Surat - 395007'),
('company_phone', '+91 98250 12345 / 0261 2450000'),
('company_email', 'info@kasyapgems.com'),
('company_gst', '24AAECK9876Q1Z5'),
('default_due_days', '50'),
('default_dollar_rate', '83.50'),
('system_version', '1.0 Enterprise');

-- Activity Log Entry
INSERT INTO activity_logs (user_id, action, module, record_id, details, ip_address) VALUES
(1, 'System Installed', 'Auth', 1, 'Diamond ERP Initialized with High Security', '127.0.0.1');
