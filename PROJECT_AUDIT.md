# PROJECT AUDIT: Kasyap Diamond ERP

> **Project Name:** Kasyap Diamond ERP (સુરત & મુંબઈ હીરા ઉત્પાદન અને વેપાર મેનેજમેન્ટ સિસ્ટમ)  
> **Repository/Root:** `c:\xampp\htdocs\projects\kasyap`  
> **Environment:** PHP 8.2+ | MariaDB / MySQL (InnoDB, UTF-8mb4) | Apache (XAMPP Windows)  
> **Framework:** Custom Enterprise MVC/Modular Architecture with Native PDO & Vanilla JS / Bootstrap 5 Tokens  
> **Last Updated:** 2026-11-19T11:30:00+05:30  
> **Total PHP Files Audited:** 100 Files  

---

## 1. Executive Summary & Purpose
Kasyap Diamond ERP is an end-to-end enterprise diamond manufacturing and trading software specifically tailored for real-world diamond factory operations in Surat and trading offices in Bharat Diamond Bourse (BDB) Mumbai. The system manages the entire diamond lifecycle:
1. **Rough Procurement (રફ ખરીદી):** Lot acquisition, single & merged lots, foreign currency (USD Stand Dollar), brokerages, due dates.
2. **Rough Assortment (રફ ભાગ પાડવા / એસોર્ટ):** Splitting rough lots into manufacturing (MFG), delayed manufacturing (MFG Later), direct sale (Out Sell), and tracking Ghat/Loss differences.
3. **Kapan & Manufacturing Pipeline (કાપન અને પ્રોસેસ):** Kapan allocation, packet creation, multi-stage diamond tracking (4P, Laser, Boiling, Polish, Grading) with rapid barcode scanner integration.
4. **Boiling & Re-Assortment (બોઈલિંગ):** Internal chemical cleaning and re-allocation loops.
5. **Polish Hub & Inventory (પોલિશ વેપાર & સ્ટોક):** GIA single certified diamonds and commercial parcel stocks, purchases, sales, and lab tracking.
6. **Jangad Memo System (જાંગડ મેમો):** Secure internal & external memo dispatches for job work, trading, and approval.
7. **Finance & Banking (નાણાં & હિસાબ):** Receivables, payables, brokerage ledgers, cash/bank accounts, stand-dollar exchange settlements, and net worth balance sheets.
8. **Administration & Security (એડમિન & સેટિંગ્સ):** Multi-role access control, master settings, automated database backups, and tamper-resistant activity audit trails.

---

## 2. Architecture & Tech Stack

### 2.1 Backend Architecture
- **Language:** PHP 8.2 strict mode (`declare(strict_types=1);` across critical modules).
- **Database Engine:** MySQL 8 / MariaDB using PHP PDO with prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`, `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`).
- **Authentication & RBAC:** Session-based authentication with `session_regenerate_id(true)`, `HttpOnly`, `SameSite=Lax` cookies, and granular role enforcement (`admin`, `manager`, `operator`).
- **CSRF Protection:** Synchronizer token pattern with cryptographically secure tokens (`random_bytes(32)`) validated via `hash_equals()`.
- **Security Headers & Defense:** Direct access protection in `.htaccess` restricting `/config`, `/includes`, `/database`, `/logs`, `/backups`, and `.sql` file extensions. Browser caching disabled on sensitive pages.

### 2.2 Frontend & UI System
- **Design Tokens:** Deep navy (`#0B192C`, `#1E3E62`) and rich gold (`#FFB200`, `#D4AF37`) palette defined via CSS variables in `assets/css/diamond.css`.
- **Typography:** Primary Gujarati font `Noto Sans Gujarati` (14px base, 12-13px secondary) combined with tabular figures for financial/carat numbers (`font-mono`).
- **Component System:** Reusable Finora card components, sticky headers, responsive action bars, live calculation summaries, and mobile-friendly barcode scanner support.

---

## 3. Codebase File Structure (100 Files Audited)

```
kasyap/
├── .htaccess                     (Root security rewrite rules & directory protections)
├── index.php                     (Entry point & dashboard router)
├── login.php                     (Brute-force protected authentication endpoint)
├── logout.php                    (Session destruction & cleanup)
├── change_password.php           (Mandatory first-login & user password change)
├── assets/                       (CSS, JS, Fonts, Images)
│   ├── css/diamond.css           (Primary ERP Design System & Tokens)
│   └── js/                       (Barcode scanner, AJAX handlers, formatters)
├── config/
│   ├── config.php                (Constants, Base URL, Currency & Session settings)
│   └── database.php              (PDO Singleton connection pool)
├── includes/
│   ├── auth.php                  (Authentication, Session timeout, require_role)
│   ├── csrf.php                  (CSRF token generation & hash_equals validation)
│   ├── helpers.php               (Shared logic, 9-Pillars, SVG icons, formatters)
│   ├── header.php                (HTML head, sidebar navigation, topbar)
│   └── footer.php                (Scripts, toasts, modals, closing tags)
└── modules/
    ├── admin/                    (Users, Roles, Backups, Master Settings, CSV Upload)
    ├── assort/                   (Rough Assortment, Part Splitting, Ghat Reconciliation)
    ├── boil/                     (Boiling entry, issue/receive, re-assortment)
    ├── dashboard/                (Executive KPIs, daily tasks, chart visualization)
    ├── finance/                  (Brokerage, Receivables, Schedule, Networth, Bank)
    ├── jangad/                   (Memo issue, returns, outstanding party ledger)
    ├── kapan/                    (Kapan creation, packet generation, planning)
    ├── polish/                   (Polish Hub, GIA & Parcel inventory and trade)
    ├── polish_purchase/          (Polish diamond purchases, bulk import, delete)
    ├── polish_sales/             (Polish diamond sales, invoice generation, delete)
    ├── polish_stock/             (Live polish stock, locations, cert tracking)
    ├── process/                  (Factory stages, rapid barcode scanner, AJAX APIs)
    ├── reports/                  (9-Pillars summary, daily register, CSV export)
    ├── rough/                    (Rough Hub, live inventory, reconciliation)
    ├── rough_purchase/           (Rough lot purchase, lot merge, bulk import)
    ├── rough_sales/              (Rough lot sales & outward invoices)
    └── stock/                    (Unified factory & office inventory breakdown)
```

---

## 4. Historical Batch Review & Audit Milestones

### Batch 1: UI/UX & Gujarati ERP Optimization
- Standardized color system, CSS custom properties, and `Noto Sans Gujarati` typography.
- Established collapsible navigation sidebar with 5 distinct operational groups:
  1. ખરીદી-વેચાણ (Trade)
  2. ઉત્પાદન (Production)
  3. સ્ટોક (Inventory)
  4. નાણાં (Finance)
  5. સેટિંગ (Admin)
- Added breadcrumb navigation, sticky action headers, and Indian numbering format (`₹1,25,000`).

### Batch 2: Assortment Workflow Redirection & Metric Cards
- Corrected redirect path on rough lots from raw Kapan to Assort task workflow.
- Implemented 7 top metrics in Assortment hub:
  1. Total Rough Received (511.00 ct)
  2. Pending Assort (200.00 ct)
  3. Assort Done (211.00 ct / 210.90 ct output)
  4. MFG Tag (Kapan Make)
  5. MFG Later Tag
  6. Sell Tag (Direct Outward)
  7. Ghat / Weight Loss Reconciliation (-0.10 ct)
- Enabled tabbed list navigation on metric card click.

### Batch 3: Double-Count Elimination on Merged Rough Lots
- **Problem Identified:** When Lot 03 (100 ct) was merged into Lot 04 (100 ct), resulting in 200 ct, systems were counting Lot 03 (100 ct) + Lot 04 (200 ct) = 300 ct, inflating total purchase from 411 ct to 511 ct.
- **Resolution:** Added universal status filtering `WHERE status != 'merged'` across all live inventory and trade calculation modules (`modules/rough/index.php`, `modules/assort/index.php`, `modules/dashboard/index.php`, `modules/finance/`). Original lots remain audit-traceable in purchase logs, while live calculations reflect true physical inventory.

### Batch 4: Assortment Hub UI Cleanup
- Removed duplicate secondary tab bars and empty boil re-assort alert strips in `modules/assort/index.php` as requested by user.

### Batch 5: 100+ File Cyber Security Audit & Hardening
- Performed deep automated and manual source code review across all 100 PHP files.
- Remediated SQL injection vulnerability in `modules/polish/index.php`.
- Remediated fatal DoS TypeError in `includes/helpers.php`.
- Enforced role-based access control (`require_role(['admin', 'manager'])`) on destructive record deletion endpoints.
- Validated CSRF protection, file upload security, and brute-force defenses.

---

## 5. Security Posture Summary
- **SQL Injection:** 0 Vulnerabilities (All raw query concatenations converted to prepared statements).
- **CSRF:** 100% Coverage on all state-altering POST requests and AJAX endpoints.
- **Access Control:** All sensitive administrative and deletion actions guarded by strict RBAC.
- **Authentication:** Bcrypt password hashing (`PASSWORD_DEFAULT`), session timeout (60 min), brute-force lockout (5 attempts / 15 min).
- **File Uploads:** Uploaded spreadsheets are processed directly from memory via native parsers; no unvalidated files stored in web directory.
