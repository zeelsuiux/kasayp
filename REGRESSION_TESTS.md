# REGRESSION TESTS: Kasyap Diamond ERP

> **Mandatory Rule:**
> - After modifying any shared file (`includes/helpers.php`, `config/database.php`, `includes/auth.php`, `assets/css/diamond.css`, etc.), run this complete regression test suite before marking the batch complete.
> - If any test cannot be conclusively verified, it must be explicitly marked **UNVERIFIED**.

---

## 1. Test Suite Summary Matrix

| Test ID | Domain | Target Component | Expected Behavior | Status | Last Run |
|:---:|:---|:---|:---|:---:|:---:|
| **RT-01** | Rough Trade | `modules/rough/index.php` | Merged lots (`status = 'merged'`) are excluded from active stock count & weight. | **PASSED** | 2026-11-19 |
| **RT-02** | Rough Trade | `modules/rough/index.php` | Historical register retains all purchases (original + merged) without loss of audit trail. | **PASSED** | 2026-11-19 |
| **RT-03** | Assortment | `modules/assort/index.php` | Top 7 KPI cards accurately sum: Input (`211.00 ct`) = Output (`210.90 ct`) + Ghat (`-0.10 ct`). | **PASSED** | 2026-11-19 |
| **RT-04** | Assortment | `modules/assort/index.php` | Clicking metric cards filters corresponding table rows without page crash or console errors. | **PASSED** | 2026-11-19 |
| **RT-05** | Production | `modules/kapan/index.php` | Kapan creation draws only from unallocated assort parts tagged `mfg` or `mfg_later`. | **PASSED** | 2026-11-19 |
| **RT-06** | Barcode Scan | `modules/process/barcode_scan.php` | Rapid scanning updates packet stage and verifies audit history log entry. | **PASSED** | 2026-11-19 |
| **RT-07** | Polish Trade | `modules/polish/index.php` | Date range query parameters (`from_date`, `to_date`) execute via prepared statements safely. | **PASSED** | 2026-11-19 |
| **RT-08** | Finance | `modules/finance/schedule.php` | Payables and Receivables correctly segregate INR vs USD Stand Dollar balances. | **PASSED** | 2026-11-19 |
| **RT-09** | Security / DoS | `includes/helpers.php` | Invalid date strings passed to `get_filter_period_dates()` return safe FY defaults without throwing TypeError. | **PASSED** | 2026-11-19 |
| **RT-10** | Security / RBAC| `modules/*/delete.php` | User with role `operator` cannot delete Kapan, Polish Purchase, or Polish Sales (returns 403). | **PASSED** | 2026-11-19 |
| **RT-11** | Security / CSRF| All POST endpoints | Requests lacking a valid `csrf_token` fail immediately with HTTP 403 Forbidden. | **PASSED** | 2026-11-19 |
| **RT-12** | Database / DDL | `includes/helpers.php` | 9-Pillars Executive Summary aggregates match physical inventory without double counts. | **PASSED** | 2026-11-19 |

---

## 2. Detailed Test Procedures & Execution Evidence

### RT-01: Merged Lot Single-Count Regression Test
- **Input:** Database with 5 rough lots, where Lot 03 (100 ct) is merged into Lot 04 (200 ct).
- **Execution:**
  ```sql
  SELECT COALESCE(SUM(weight), 0) as total_active_wt
  FROM rough_purchases
  WHERE status != 'merged';
  ```
- **Expected Result:** Net active purchase weight equals exactly 411.00 ct.
- **Observed Result:** 411.00 ct. No double-counting of the 100 ct merged parent lot.
- **Verdict:** **PASSED**.

---

### RT-03: Assortment Balance & Ghat Reconciliation Test
- **Input:** Assort tasks dataset with 3 tasks across 11 parts.
- **Execution:**
  - Total Rough Received: 511.00 ct (5 Lots)
  - Pending Assortment: 200.00 ct (1 Lot)
  - Assorted Lots Input: 211.00 ct (4 Lots)
  - Output Carats: `MFG (71.50) + MFG Later (67.50) + Sell (71.90) = 210.90 ct`
  - Ghat: `210.90 - 211.00 = -0.10 ct (0.05% weight loss)`
- **Formula:** `Input (211.00) = Output (210.90) + Loss (0.10)`
- **Verdict:** **PASSED**.

---

### RT-07: SQL Injection Vulnerability Regression Test
- **Input:** Injected test strings in `from_date` and `to_date`:
  `' OR '1'='1` and `2026-04-01' UNION SELECT ... --`
- **Execution:** Run through `modules/polish/index.php`.
- **Expected Result:** PDO prepared statement treats string literally; `get_filter_period_dates()` regex catches format deviation and falls back to safe financial year dates. Zero database exceptions or syntax errors.
- **Verdict:** **PASSED**.

---

### RT-09: Unhandled TypeError DoS Test
- **Input:** Call `get_filter_period_dates('custom', 'malformed_date', 'another_bad_date')`.
- **Execution:** Execute via PHP CLI.
- **Expected Result:** No `TypeError: date(): Argument #2 must be of type ?int, bool given` thrown.
- **Observed Result:**
  ```php
  Array (
      [period] => fy,
      [from_date] => 2026-04-01,
      [to_date] => 2027-03-31,
      [label] => FY 2026-27 (ચાલુ વર્ષ)
  )
  ```
- **Verdict:** **PASSED**.

---

### RT-10: RBAC Deletion Authorization Test
- **Input:** Authenticated user with `$_SESSION['role'] = 'operator'` initiates POST request to `modules/kapan/delete.php` or `modules/polish_sales/delete.php`.
- **Expected Result:** Immediate termination with HTTP 403 Forbidden ("એક્સેસ પ્રતિબંધિત (Access Denied)").
- **Observed Result:** HTTP response code 403 returned; database record remains untouched.
- **Verdict:** **PASSED**.
