# CHANGELOG: Kasyap Diamond ERP

All notable changes, fixes, and architecture improvements to the Kasyap Diamond ERP project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to strict batch versioning and regression testing.

---

## [Batch 7] - 2026-11-19
### UI/UX Audit
- **Kapan Manufacturing Hub & Lifecycle Audit:** Conducted deep microscopic element-by-element UI/UX audit of `modules/kapan/index.php`, `modules/kapan/view.php`, `modules/kapan/create.php`, and `modules/kapan/edit.php`.
- Evaluated cards, badges, tables, typography, spacing, colors, icons, and touch targets.
- Identified and registered 10 specific UI defects (BUG-010 to BUG-019) in `BUG_REGISTER.md` and `ui_ux_expert_audit_report.md`.
- No source code modified (Audit Only per user instruction).

---

## [Batch 6] - 2026-11-19
### Added
- Created 7 project-level governance and engineering documents:
  - `PROJECT_AUDIT.md`: System architecture, operational workflows, and security posture.
  - `BUG_REGISTER.md`: Complete defect history, root causes, failed approaches, and verified fixes.
  - `CHANGELOG.md`: Detailed record of all code changes across batches.
  - `REGRESSION_TESTS.md`: Automated and manual test suites covering core business logic.
  - `DATABASE_MIGRATIONS.md`: Database schema catalog, table dictionaries, and migration standards.
  - `PENDING_ISSUES.md`: Backlog, technical debt, and pending feature roadmap.
  - `MODULE_DEPENDENCIES.md`: Dependency map and impact matrix for shared files.

---

## [Batch 5] - 2026-11-19
### Security
- **[SQL Injection Fix]** `modules/polish/index.php`: Replaced concatenated `$purDateSql` and `$saleDateSql` in `$pdo->query(...)` with parameterized prepared statements (`$pdo->prepare(...)` + `$stmt->execute(...)`).
- **[DoS & TypeError Fix]** `includes/helpers.php`: Added regex format validation (`^\d{4}-\d{2}-\d{2}$`) and `strtotime` return check in `get_filter_period_dates()` to prevent fatal PHP 8.2 crash when handling invalid custom date query strings.
- **[Access Control Hardening]** `modules/kapan/delete.php`, `modules/polish_purchase/delete.php`, `modules/polish_sales/delete.php`: Added `require_role(['admin', 'manager'])` to prevent unauthorized deletion by lower-privileged operators.
- **[RBAC Standardization]** `modules/admin/settings.php`: Standardized authentication check to central `require_role(['admin'])`.
- **[Business Logic Integrity]** `includes/helpers.php`: Excluded merged rough purchases (`WHERE status != 'merged'`) in `get_trade_and_stock_9_pillars()`.

---

## [Batch 4] - 2026-11-18
### Removed
- `modules/assort/index.php`: Removed redundant secondary sub-tab bar duplicating the top 7 KPI metric cards.
- `modules/assort/index.php`: Removed empty Boil Re-Assort warning strip that displayed when zero re-assort lots were present.

---

## [Batch 3] - 2026-11-18
### Fixed
- **Double Counting on Merged Rough Lots:** Fixed double counting where Lot 03 (100 ct) merged into Lot 04 (100 ct) resulted in 300 ct instead of 200 ct.
- Modified files:
  - `modules/rough/index.php`: Filtered active inventory queries with `status != 'merged'`.
  - `modules/assort/index.php`: Enforced non-overlapping lot weight summation.
  - `modules/dashboard/index.php`: Updated live stock and trade balance aggregates.
  - `modules/finance/index.php`, `networth.php`, `schedule.php`, `receivables.php`, `transactions.php`, `brokerage.php`: Excluded merged lot obligations from payables and balance sheets.

---

## [Batch 2] - 2026-11-18
### Added & Changed
- `modules/assort/index.php`: Implemented 7 top operational cards with interactive filtering:
  1. Total Rough Received (`511.00 ct`)
  2. Pending Assort (`200.00 ct`)
  3. Assort Done (`211.00 ct`)
  4. Kapan (MFG Tag) (`71.50 ct`)
  5. MFG Later Tag (`67.50 ct`)
  6. Out Sell Tag (`71.90 ct`)
  7. Loss / Ghat Diff (`-0.10 ct`)
- Corrected rough action button navigation to route directly to Assortment rather than premature Kapan creation.

---

## [Batch 1] - 2026-11-18
### Added & Changed
- **UI/UX Overhaul & Gujarati Enterprise Localization:**
  - Implemented `assets/css/diamond.css` with deep navy (`#0B192C`) and gold (`#FFB200`) design tokens.
  - Configured `Noto Sans Gujarati` font family for high legibility in factory lighting.
  - Added collapsible sidebar navigation grouped into 5 operational areas (Trade, Production, Inventory, Finance, Admin).
  - Standardized breadcrumb navigation, card radius (8-12px), tabular figure alignment, and Indian currency formatting (`₹1,25,000`).
