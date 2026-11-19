# PENDING ISSUES & TECHNICAL DEBT: Kasyap Diamond ERP

> **Governance Policy:**
> - Tracks all open user requests, edge-case limitations, technical debt items, and planned architectural enhancements.
> - Before starting any new batch, review this list to align development priorities.
> - When an issue is addressed, move it to `BUG_REGISTER.md` (if a defect) or `CHANGELOG.md` (if a feature) and remove or mark it closed here.

---

## 1. High Priority Backlog (Operational & Factory Priorities)

| Issue ID | Area / Module | Priority | Description | Dependencies / Constraints |
|:---:|:---|:---:|:---|:---|
| **PI-001** | Process / Barcode | High | Implement continuous autofocus loop on barcode scanner input with audio feedback (success beep on valid scan, error buzz on duplicate/invalid scan). | Hardware/browser audio autoplay policy; must handle USB laser scanner rapid carriage returns (`Enter`). |
| **PI-002** | Reports / Print | High | Provide dedicated thermal barcode label print layouts (50mm x 25mm and 38mm x 25mm) for packet stickers and Jangad vouchers. | Needs CSS `@media print` size definitions and ESC/POS compatibility. |
| **PI-003** | UI / Responsive | Medium | Optimize table horizontal scroll and sticky column behavior on tablet screens (iPad / Samsung Galaxy Tab) for factory floor supervisors. | Must maintain tabular numerals without breaking Gujarati font line-height. |
| **PI-009** | Kapan UI / UX | High | Kapan Module UI/UX Optimization: Fix action column width squeeze (BUG-011), sticky header (BUG-012), header button sprawl (BUG-015), Finora stat cards (BUG-017), and terminology clean up (BUG-013). | Requires updates across `modules/kapan/index.php` and `view.php`. |

---

## 2. Medium Priority Technical Debt & Hardening

| Issue ID | Area / Module | Priority | Description | Dependencies / Constraints |
|:---:|:---|:---:|:---|:---|
| **PI-004** | Finance / USD | Medium | Stand Dollar settlement module: Add automated currency exchange variance ledger when settling USD bills in INR at a future date. | Requires accounting entries in `account_transactions` for FX Gain/Loss. |
| **PI-005** | Boil / Re-Assort | Medium | Expand Boil Re-Assort tracking to provide direct packet-level weight yield comparison between pre-boil rough weight and post-boil clean weight. | Depends on `packets.weight` vs `boil_batches.post_weight`. |
| **PI-006** | Database Indexing | Medium | Add composite indexes on `polish_stocks(status, stock_type, available_weight)` and `packets(kapan_id, current_stage_id, status)` for ultra-fast queries as record count grows past 100,000. | Safe online DDL; will be packaged into Migration 003. |

---

## 3. Low Priority Enhancements & Nice-to-Haves

| Issue ID | Area / Module | Priority | Description | Dependencies / Constraints |
|:---:|:---|:---:|:---|:---|
| **PI-007** | Admin / Backup | Low | Scheduled automatic database dump to cloud storage (Google Drive / S3) via background cron/task. | Requires cloud storage credentials or agy automation hook. |
| **PI-008** | Polish Hub / GIA | Low | Automated GIA Report Check API integration via GIA direct API to pull cut, polish, symmetry, fluorescence automatically from certificate number. | Requires active GIA API key / credentials. |

---

## 4. Closed / Addressed Issues Archive

- [x] **PI-RESOLVED-01:** Double-counting of merged rough purchases (Lot 03 + Lot 04 inflating 411 ct to 511 ct) -> Resolved in Batch 3.
- [x] **PI-RESOLVED-02:** Missing role validation on deletion endpoints -> Resolved in Batch 5.
- [x] **PI-RESOLVED-03:** SQL injection vulnerability in Polish Hub date filtering -> Resolved in Batch 5.
- [x] **PI-RESOLVED-04:** Unhandled `TypeError` in `get_filter_period_dates()` -> Resolved in Batch 5.
- [x] **PI-RESOLVED-05:** Assortment Hub redundant sub-tab bars & empty boil strip -> Resolved in Batch 4.
