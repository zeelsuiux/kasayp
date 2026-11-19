# BUG REGISTER: Kasyap Diamond ERP

> **Governance Policy:**
> - Every issue discovered must be logged with root cause, affected files, failed approaches, and verified resolution.
> - **FAILED APPROACHES MUST NEVER BE REPEATED.**
> - Never overwrite a previously fixed file with an older or unverified version.
> - Status definitions: `RESOLVED`, `VERIFIED`, `PENDING`, `UNVERIFIED`.

---

## Active & Historical Bug Register

| Bug ID | Date | Module | Severity | Status | Summary |
|:---|:---:|:---|:---:|:---:|:---|
| **BUG-001** | 2026-11-18 | Assort / Rough | Medium | **VERIFIED** | Rough lot action button redirecting to Kapan instead of Assort workflow. |
| **BUG-002** | 2026-11-18 | Assort | High | **VERIFIED** | Assort total input weight calculation mismatching purchase weight. |
| **BUG-003** | 2026-11-18 | Rough / Finance / Dashboard | Critical | **VERIFIED** | Double-counting of merged rough lots inflating inventory (300 ct instead of 200 ct). |
| **BUG-004** | 2026-11-18 | Assort UI | Low | **VERIFIED** | Redundant sub-tab bar and empty Boil Re-Assort notification strip in Assortment index. |
| **BUG-005** | 2026-11-19 | Polish Hub (Security) | High | **VERIFIED** | SQL Injection vulnerability in date filter concatenation (`modules/polish/index.php`). |
| **BUG-006** | 2026-11-19 | Helpers (Security/DoS) | Medium | **VERIFIED** | Fatal PHP 8.2 `TypeError` in `get_filter_period_dates()` when invalid date strings supplied. |
| **BUG-007** | 2026-11-19 | Auth / RBAC (Security) | Medium | **VERIFIED** | Missing role authorization check in `delete.php` endpoints (Kapan, Polish Purchase, Polish Sales). |
| **BUG-008** | 2026-11-19 | Admin / Auth | Low | **VERIFIED** | Inconsistent inline role checks in `modules/admin/settings.php` rather than `require_role()`. |
| **BUG-009** | 2026-11-19 | Reports / Summary | Medium | **VERIFIED** | 9-Pillars Executive Summary missing `status != 'merged'` filter on rough purchases. |
| **BUG-010** | 2026-11-19 | Kapan UI (Grid) | Low | **PENDING** | Invalid Bootstrap 5 grid class `g-2.5` causing unstyled gap in Kapan Index stat cards. |
| **BUG-011** | 2026-11-19 | Kapan UI (Table) | Medium | **PENDING** | Action column width set to 6% causing 4 action buttons to violently wrap on 1366px screens. |
| **BUG-012** | 2026-11-19 | Kapan UI (Table) | Medium | **PENDING** | Kapan Index table header lacks sticky positioning (`position: sticky`), disappearing on scroll. |
| **BUG-013** | 2026-11-19 | Kapan UI (Typography) | Low | **PENDING** | Cluttered technical jargon `(Units & Rough Cost)` displayed on main page title and primary action button. |
| **BUG-014** | 2026-11-19 | Kapan UI (Semantics) | Low | **PENDING** | False alarm red location pin (`text-danger`) used for Surat/Mumbai vault in Kapan View. |
| **BUG-015** | 2026-11-19 | Kapan UI (Header) | Medium | **PENDING** | 5 un-grouped action buttons spanning 650px in Kapan View causing page title to wrap onto 3 lines. |
| **BUG-016** | 2026-11-19 | Kapan UI (Badges) | Low | **PENDING** | Closed Kapan status badge rendered with active blue `bg-primary-subtle` instead of muted slate. |
| **BUG-017** | 2026-11-19 | Kapan UI (Cards) | Low | **PENDING** | Ad-hoc grey card containers used in Kapan View instead of standardized Finora pastel stat cards. |
| **BUG-018** | 2026-11-19 | Kapan UI (Colors) | Low | **PENDING** | Filter submit button in Kapan Index styled with `btn-dark` instead of brand Navy/Primary. |
| **BUG-019** | 2026-11-19 | Kapan UI (Pills) | Low | **PENDING** | Polish Stock stage pill in Kapan View using discordant green `btn-success` while others are blue/grey. |

---

## Detailed Bug Reports & Resolution Archive

### BUG-001: Rough Action Button Redirecting to Kapan
- **Affected Files:** `modules/rough/index.php`, `modules/assort/index.php`
- **Root Cause:** The action button on rough purchase cards had a hardcoded `href` pointing to `modules/kapan/create.php?rough_id=...` bypassing the necessary rough assortment (ભાગ પાડવા) stage.
- **Failed Approaches:** Attempting to auto-create dummy assort tasks in the background; this corrupted packet lineage.
- **Final Resolution:** Changed action URL to point directly to `modules/assort/create.php?purchase_id=...` or `modules/assort/index.php?tab=pending`, allowing proper segregation into MFG, MFG Later, or Sell Tag.
- **Verification Evidence:** Verified via UI navigation and query parameter resolution; clicking the button opens the Assortment creation screen with prefilled lot metadata.

---

### BUG-002: Assort Total Input Weight Mismatch
- **Affected Files:** `modules/assort/index.php`
- **Root Cause:** Assortment KPI card calculated total rough by summing only lots that were already partially processed, omitting unprocessed lots awaiting assortment.
- **Failed Approaches:** Summing all records without checking lot status, causing duplicate aggregation of completed packets.
- **Final Resolution:** Structured the 7 top cards into distinct, non-overlapping subsets:
  1. Total Rough Received (`511.00 ct`)
  2. Pending Assort (`200.00 ct`)
  3. Assort Done (`211.00 ct` input / `210.90 ct` output)
  4. MFG Tag (`71.50 ct`)
  5. MFG Later (`67.50 ct`)
  6. Sell Tag (`71.90 ct`)
  7. Net Ghat / Loss (`-0.10 ct`)
- **Verification Evidence:** Verified mathematical identity: `MFG (71.50) + MFG Later (67.50) + Sell (71.90) = 210.90 ct` output; `211.00 input - 210.90 output = 0.10 ct Ghat`.

---

### BUG-003: Double-Counting of Merged Rough Lots
- **Affected Files:**
  - `modules/rough/index.php`
  - `modules/assort/index.php`
  - `modules/dashboard/index.php`
  - `modules/finance/index.php`
  - `modules/finance/networth.php`
  - `modules/finance/schedule.php`
  - `modules/finance/receivables.php`
  - `modules/finance/transactions.php`
- **Root Cause:** When Lot 03 (100 ct) was merged into Lot 04 (100 ct) to create a merged lot of 200 ct, both Lot 03 (marked as `merged`) and Lot 04 (200 ct) were being summed in active purchase weight queries (`SUM(weight)`), yielding 300 ct instead of the true 200 ct (and inflating total purchase from 411 ct to 511 ct).
- **Failed Approaches:** Deleting the merged parent record (Lot 03) from the database; this broke invoice history, ledger audits, and purchase trail.
- **Final Resolution:** Maintained parent records for historical purchase registration, but enforced `WHERE status != 'merged'` across all live inventory, stock valuation, and active financial obligation queries.
- **Verification Evidence:** Active inventory displays true physical lot weight (411 ct net trade), while the complete historical purchase register retains all 5 original lot entries for audit compliance.

---

### BUG-004: Redundant Assort Sub-Tabs and Boil Notification Strip
- **Affected Files:** `modules/assort/index.php`
- **Root Cause:** Legacy layout contained a second horizontal tab bar that duplicated the top 7 interactive cards, as well as an empty alert strip for Boil Re-Assort when 0 lots were pending.
- **Failed Approaches:** Hiding elements via CSS `display:none`; this left unwanted DOM nodes and layout shift.
- **Final Resolution:** Completely excised the redundant HTML markup from `modules/assort/index.php`.
- **Verification Evidence:** Clean page render matching user screenshot with zero duplicate navigation bars.

---

### BUG-005: SQL Injection via Date Filter Parameters
- **Affected Files:** `modules/polish/index.php` (Lines 79-80, 86-134)
- **Root Cause:** User-supplied `$_GET['from_date']` and `$_GET['to_date']` were interpolated directly into raw SQL queries:
  `$purDateSql = " AND purchase_date BETWEEN '$purDateFrom' AND '$purDateTo'";` executed via `$pdo->query(...)`.
- **Failed Approaches:** Sanitizing with `addslashes()` or simple quote wrapping; insufficient for SQL dialect nuances and against security guidelines.
- **Final Resolution:** Replaced all string concatenations with parameterized PDO Prepared Statements (`$pdo->prepare(...)` and `$stmt->execute([$purDateFrom, $purDateTo])`).
- **Verification Evidence:** Executed security scanner test; arbitrary SQL payloads are treated strictly as literal parameters.

---

### BUG-006: Fatal DoS TypeError on Invalid Date Input
- **Affected Files:** `includes/helpers.php` (Lines 3504-3515)
- **Root Cause:** In `get_filter_period_dates()`, `date('d-m-Y', strtotime($from))` failed under PHP 8.2 when `$from` was not a valid date string (`strtotime` returned `false`, throwing fatal `TypeError: date(): Argument #2 must be of type ?int, bool given`).
- **Failed Approaches:** Catching `Throwable` inside every caller module; messy and prone to omissions.
- **Final Resolution:** Implemented strict regex format check `^\d{4}-\d{2}-\d{2}$` and `strtotime() !== false` inside `get_filter_period_dates()`. If invalid dates are passed, the function safely falls back to current Financial Year (FY) defaults without throwing exceptions.
- **Verification Evidence:** Passed invalid strings `foo`, `2026-99-99`, `''` to `get_filter_period_dates()`; returns safe FY array with zero warnings or errors.

---

### BUG-007: Missing Role-Based Authorization on Record Deletion
- **Affected Files:**
  - `modules/kapan/delete.php`
  - `modules/polish_purchase/delete.php`
  - `modules/polish_sales/delete.php`
- **Root Cause:** Deletion scripts called `require_login()` but did not verify whether the authenticated user had managerial or administrative privileges (`require_role(...)`). Any authenticated user with role `operator` could submit a POST request with an ID and delete production or trade records.
- **Failed Approaches:** Hiding delete buttons in the frontend UI; completely ineffective against direct HTTP requests.
- **Final Resolution:** Added `require_role(['admin', 'manager']);` at the top of each deletion endpoint.
- **Verification Evidence:** Tested with `operator` session; endpoint terminates with HTTP 403 Forbidden.

---

### BUG-008: Inconsistent RBAC Implementation in Settings
- **Affected Files:** `modules/admin/settings.php`
- **Root Cause:** Used an ad-hoc inline conditional `if (($user['role'] ?? '') !== 'admin')` rather than the central framework function `require_role(['admin'])`.
- **Final Resolution:** Standardized to `require_role(['admin']);`.
- **Verification Evidence:** Verified consistent 403 response rendering across all admin modules.

---

### BUG-009: Merged Lots Counted in 9-Pillars Summary
- **Affected Files:** `includes/helpers.php` (Line 3561)
- **Root Cause:** `get_trade_and_stock_9_pillars()` calculated rough purchase totals using `WHERE 1=1` without checking `status != 'merged'`.
- **Final Resolution:** Added `WHERE status != 'merged'` to the rough purchase aggregation query.
- **Verification Evidence:** 9-Pillars summary matches physical lot totals across the ERP.

---

### BUG-010: Invalid Bootstrap 5 Grid Class `g-2.5`
- **Affected Files:** `modules/kapan/index.php` (Line 70)
- **Root Cause:** Class `<div class="row g-2.5 mb-3">` uses decimal grid spacing which is invalid in Bootstrap 5 (only integer steps `g-0` to `g-5` supported).
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Standardize to `row g-3 mb-3` or define custom CSS token `--kasyap-grid-gap`.

---

### BUG-011: Action Column Width Squeeze (6% Width for 4 Action Buttons)
- **Affected Files:** `modules/kapan/index.php` (Line 126)
- **Root Cause:** Table column header `<th style="width: 6%;" class="text-end">` allocates only ~66px on 1366px laptops, while the cell renders 4 action buttons (View, Edit, Print, Delete) requiring at least 140px, causing violent button wrapping.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Adjust table column widths to give `min-width: 140px; width: 14%;` to the action column.

---

### BUG-012: Kapan Table Header Loss of Glanceability on Scroll
- **Affected Files:** `modules/kapan/index.php` (Line 116)
- **Root Cause:** Table header lacks `position: sticky; top: 0;`, disappearing from view when scrolling through 50+ records.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Apply standard `.table-sticky-header` class with `z-index: 10; background: #f8fafc;`.

---

### BUG-013: Cluttered Database Technical Jargon in Page Titles
- **Affected Files:** `modules/kapan/index.php` (Lines 58, 63), `modules/kapan/create.php` (Lines 219, 225)
- **Root Cause:** Titles and buttons display raw database schema hints `(Units & Rough Cost)` instead of clear Gujarati enterprise terminology.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Clean labels to `કાપન ઉત્પાદન અને પેકેટ્સ રજિસ્ટર` and `+ નવો Kapan બનાવો`.

---

### BUG-014: False Alarm Red Location Pin in Kapan Polish Stock
- **Affected Files:** `modules/kapan/view.php` (Line 358)
- **Root Cause:** Location pin for Surat/Mumbai uses `svg_icon('location-dot', 'text-danger me-1')`, creating a false alarm error impression.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Change color class to `text-secondary` to match the global ERP design standard.

---

### BUG-015: Action Button Sprawl Causing Title Wrapping in Kapan View
- **Affected Files:** `modules/kapan/view.php` (Lines 207-232)
- **Root Cause:** 5 individual action buttons rendered in a raw inline flex layout occupy >650px, forcing the Kapan name and carats subtitle onto 3 wrapped lines on standard 1366px screens.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Keep primary action visible; group secondary actions into a compact dropdown menu.

---

### BUG-016: Closed Kapan Status Badge Rendered with Active Primary Blue
- **Affected Files:** `modules/kapan/index.php` (Line 182)
- **Root Cause:** Unhandled status values fall back to `bg-primary-subtle text-primary`, causing closed/archived Kapans to look actively in-progress.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Add explicit status branch for `closed` returning `bg-secondary-subtle text-secondary border`.

---

### BUG-017: Ad-Hoc Metric Cards in Kapan View Breaking Finora Tokens
- **Affected Files:** `modules/kapan/view.php` (Lines 248-272)
- **Root Cause:** Uses raw `.erp-form-card p-3` containers with basic grey borders instead of standard Finora pastel stat cards.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Standardize to `.stat-card-finora` components.

---

### BUG-018: Filter Submit Button Color Inconsistency (`btn-dark`)
- **Affected Files:** `modules/kapan/index.php` (Line 104)
- **Root Cause:** Uses generic Bootstrap `btn-dark` instead of Kasyap deep navy primary button.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Update to brand `.btn-primary` or `.btn-kasyap-navy`.

---

### BUG-019: Polish Stock Stage Pill Discordant Color
- **Affected Files:** `modules/kapan/view.php` (Line 494)
- **Root Cause:** The 7th stage pill uses `btn-success` while preceding stages use `btn-primary`, creating visual noise in the stage filter bar.
- **Status:** **PENDING** (Audit Only Batch).
- **Planned Resolution:** Standardize active filter state across all 7 stage pills.
