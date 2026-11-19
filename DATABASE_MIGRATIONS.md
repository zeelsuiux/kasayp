# DATABASE MIGRATIONS: Kasyap Diamond ERP

> **Database:** `diamond_erp` | **Engine:** MySQL 8.0+ / MariaDB | **Charset:** `utf8mb4` | **Collation:** `utf8mb4_unicode_ci`  
> **Schema Tracker Table:** `schema_migrations`

---

## 1. Migration Protocol & Engineering Standards
1. **Never execute ad-hoc DDL directly on production** without logging it in this register.
2. **Pre-Migration Backup:** Always take a mysqldump or full table export (`modules/admin/backup.php`) before altering tables.
3. **Backward Compatibility:** When adding columns, always define sensible defaults (`DEFAULT NULL` or `DEFAULT '0'`) so running queries and old code paths do not break.
4. **Idempotence:** Migration scripts must use `IF NOT EXISTS` or check column existence prior to `ADD COLUMN` or `ADD INDEX`.
5. **Audit Registration:** Every migration must register its version string in `schema_migrations`.

---

## 2. Applied Schema Migrations Log

| Migration ID | Version Identifier | Applied Date | Description & Changes |
|:---:|:---|:---:|:---|
| **001** | `001_phase1_security_hardening` | 2026-11-18 19:28:50 | Added `login_attempts` table (IP, attempts, locked_until), user `must_change_password` flag, and session activity tracking. |
| **002** | `002_polish_sales_bulk_upload_fields` | 2026-11-19 10:42:43 | Added `color_tag`, `product_tag`, `details`, `dollar_per_carat`, `dollar_rate` columns to `polish_sales` for Excel/CSV bulk import. |

---

## 3. Core Database Table Dictionary

### 3.1 `rough_purchases` (રફ ખરીદી રજિસ્ટર)
Primary ledger of all rough diamond lot acquisitions.
- `id` (INT PK Auto): Unique lot identifier.
- `purchase_no` (VARCHAR 50): Purchase voucher code (e.g. `R-PUR-2026-0001`).
- `purchase_date` (DATE): Acquisition date.
- `party_id` (INT FK -> `parties.id`): Vendor / Supplier.
- `broker_id` (INT FK -> `parties.id`): Purchasing broker.
- `pcs` (INT): Total stones in lot.
- `weight` (DECIMAL 10,3): Total weight in carats (Ct).
- `available_weight` (DECIMAL 10,3): Remaining un-allocated weight.
- `available_pcs` (INT): Remaining un-allocated pieces.
- `price_per_carat_inr` (DECIMAL 12,2): Price per carat in INR.
- `payment_amount` (DECIMAL 14,2): Gross purchase amount.
- `net_payment` (DECIMAL 14,2): Net payment after terms and brokerages.
- `due_days` (INT): Payment credit period in days.
- `due_date` (DATE): Calculated payment due date.
- `party_payment_status` (ENUM `'pending'`, `'partial'`, `'paid'`): Payment settlement status.
- `status` (ENUM `'in_stock'`, `'in_process'`, `'partially_assorted'`, `'completed'`, `'merged'`): **CRITICAL:** Lots with `merged` represent absorbed parent lots and must be excluded from live stock calculations.

### 3.2 `assort_tasks` & `assort_parts` (રફ ભાગ પાડવા / એસોર્ટ)
Tracks the breakdown of rough lots into manufacturing or sales categories.
- `assort_tasks.id` (INT PK): Task voucher.
- `assort_tasks.task_no` (VARCHAR 50): Code (e.g. `ASRT-2026-0001`).
- `assort_tasks.rough_purchase_id` (INT FK): Source rough purchase.
- `assort_tasks.input_weight` (DECIMAL 10,3): Starting rough weight.
- `assort_tasks.output_weight` (DECIMAL 10,3): Sum of all created parts.
- `assort_tasks.weight_loss` (DECIMAL 10,3): Ghat / Loss difference.
- `assort_parts.id` (INT PK): Sub-divided part.
- `assort_parts.assort_task_id` (INT FK -> `assort_tasks.id`).
- `assort_parts.tag` (ENUM `'mfg'`, `'mfg_later'`, `'sale'`): Destination tag (Manufacturing, Hold, or Direct Out-Sale).
- `assort_parts.weight` (DECIMAL 10,3): Carats allocated to this part.
- `assort_parts.status` (ENUM `'pending'`, `'kapan_created'`, `'sold'`): Downstream disposition.

### 3.3 `kapans` & `packets` (કાપન અને હીરા પેકેટ્સ)
Core manufacturing entities.
- `kapans.id` (INT PK): Unique Kapan.
- `kapans.kapan_code` (VARCHAR 50): Kapan identifier (e.g. `KPN-2026-01`).
- `kapans.total_weight` (DECIMAL 10,3): Carats issued into Kapan.
- `kapans.status` (ENUM `'planning'`, `'in_process'`, `'boiling'`, `'completed'`, `'closed'`).
- `packets.id` (INT PK): Individual stone or manufacturing lot packet.
- `packets.kapan_id` (INT FK -> `kapans.id`).
- `packets.packet_no` (VARCHAR 50): Unique packet number.
- `packets.barcode` (VARCHAR 50): Barcode string for high-speed scanner dispatch.
- `packets.current_stage_id` (INT): Active factory stage (Laser, 4P, Table, Bottom, Polish, etc.).
- `packets.status` (ENUM `'in_process'`, `'completed'`, `'broken'`, `'returned'`).

### 3.4 `polish_stocks`, `polish_purchases`, `polish_sales` (પોલિશ વેપાર & સ્ટોક)
Finished diamond inventory and trading ledgers.
- `polish_stocks.id` (INT PK): Live stock inventory item.
- `polish_stocks.stock_type` (ENUM `'gia_single'`, `'parcel'`): Single certified diamond vs parcel lot.
- `polish_stocks.gia_number` (VARCHAR 50 NULL): GIA certificate number.
- `polish_stocks.available_pcs` & `available_weight`: Live available stock.
- `polish_purchases.id`: Polish procurement ledger.
- `polish_sales.id`: Polish sales ledger with standalone currency fields (`dollar_per_carat`, `dollar_rate`, `payment_currency`).

### 3.5 `parties` (પાર્ટી & દલાલ માસ્ટર)
Unified directory for vendors, buyers, brokers, and factory artisans.
- `party_name`, `party_type` (`'vendor'`, `'customer'`, `'broker'`, `'artisan'`), `phone`, `city`, `credit_limit`.

---

## 4. Pending & Proposed Future Migrations

### Migration 003 [Planned]: `003_add_lot_merge_audit_fk`
- **Objective:** Add explicit foreign key constraint linking merged child lot to parent lots in `rough_purchase_merges` table with `ON DELETE RESTRICT` to guarantee mathematical integrity.
- **DDL:**
  ```sql
  ALTER TABLE rough_purchase_merges
  ADD CONSTRAINT fk_rpm_parent FOREIGN KEY (parent_purchase_id) REFERENCES rough_purchases(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_rpm_child FOREIGN KEY (merged_purchase_id) REFERENCES rough_purchases(id) ON DELETE RESTRICT;
  ```
