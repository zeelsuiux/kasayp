<?php
/**
 * Staff Salary Payment Process (સેક્શન ૬.૯)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$user = current_user();

$pdo = getDbConnection();
$empId = (int)($_GET['emp_id'] ?? 0);

$employees = $pdo->query("SELECT * FROM employees WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

$selectedEmp = null;
if ($empId > 0) {
    $st = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $st->execute([$empId]);
    $selectedEmp = $st->fetch();
}

if ($empId > 0) {
    $st = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $st->execute([$empId]);
    $selectedEmp = $st->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $month      = (int)($_POST['month'] ?? date('n'));
    $year       = (int)($_POST['year'] ?? date('Y'));
    $amount     = (float)($_POST['amount'] ?? 0.00);
    $payDate    = $_POST['payment_date'] ?? date('Y-m-d');
    $bankAccId  = (int)($_POST['bank_account_id'] ?? 0);
    $mode       = $_POST['payment_mode'] ?? 'bank_transfer';
    $notes      = trim($_POST['notes'] ?? '');

    if ($employeeId <= 0 || $amount <= 0 || $bankAccId <= 0) {
        set_flash('error', 'કૃપા કરીને કર્મચારી, રકમ અને બેંક/કેશ ખાતું પસંદ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO salary_payments (employee_id, month, year, amount, payment_date, bank_account_id, payment_mode, notes, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$employeeId, $month, $year, $amount, $payDate, $bankAccId, $mode, $notes, $user['id']]);
            $salId = (int)$pdo->lastInsertId();

            // Deduct from bank/cash account
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $bankAccId]);

            // Add ledger transaction
            $pdo->prepare("INSERT INTO account_transactions (bank_account_id, type, amount, related_type, related_id, reference_no, payment_mode, transaction_date, notes, created_by)
                           VALUES (?, 'debit', ?, 'salary', ?, ?, ?, ?, 'Staff Salary Payment', ?)")
                ->execute([$bankAccId, $amount, $salId, "SAL-$month-$year", $mode, $payDate, $user['id']]);

            $pdo->commit();
            log_activity('Salary Paid', 'Staff', $salId, "Paid salary $amount to Employee ID $employeeId for $month/$year");
            set_flash('success', "પગાર રકમ " . format_inr($amount) . " સફળતાપૂર્વક ચૂકવાઈ ગઈ છે.");
            header('Location: ' . BASE_URL . '/modules/staff_outsource/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'પગાર ચૂકવવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'સ્ટાફ પગાર ચુકવણી (Staff Salary)';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'સ્ટાફ પગાર ચુકવણી (Staff Salary Payment)',
    '',
    'money-bill-wave',
    '<a href="' . BASE_URL . '/modules/staff_outsource/index.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'સ્ટાફ લિસ્ટ પર પાછા જાઓ</a>',
    'purple'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-7">
        <form action="<?= BASE_URL ?>/modules/staff_outsource/salary.php" method="POST">
            <?= csrf_field() ?>

            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-purple"><?= svg_icon('hand-holding-dollar') ?></span>
                        પગાર ચુકવણી વાઉચર (Salary Voucher Entry)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">કર્મચારી પસંદ કરો (Select Staff Member) *</label>
                        <select name="employee_id" class="form-select fw-semibold" onchange="window.location.href='?emp_id=' + this.value" required>
                            <option value="">કર્મચારી પસંદ કરો...</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= $empId == $e['id'] ? 'selected' : '' ?>>
                                    <?= e($e['name']) ?> (<?= e($e['designation']) ?>) — મૂળ પગાર: <?= format_inr($e['basic_salary']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">મહિનો (Month) *</label>
                            <select name="month" class="form-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= date('n') == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 10)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">વર્ષ (Year) *</label>
                            <input type="number" name="year" class="form-control font-mono" value="<?= date('Y') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ચૂકવવાપાત્ર રકમ (Amount INR) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-primary font-mono" value="<?= (float)($selectedEmp['basic_salary'] ?? 0.00) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ચુકવણી તારીખ *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Bank / Cash ખાતું પસંદ કરો *</label>
                            <select name="bank_account_id" class="form-select" required>
                                <option value="">ખાતું પસંદ કરો...</option>
                                <?php foreach ($bankAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ચુકવણી મોડ</label>
                            <select name="payment_mode" class="form-select">
                                <option value="bank_transfer" selected>Bank Transfer (NEFT/RTGS)</option>
                                <option value="cash">Cash In Hand</option>
                                <option value="cheque">Cheque</option>
                                <option value="upi">UPI</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">નોંધ (Notes)</label>
                        <input type="text" name="notes" class="form-control" placeholder="માસિક પગાર ચુકવણી વિગત...">
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/staff_outsource/index.php" class="btn btn-outline-secondary px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <?= svg_icon('check', 'me-1') ?>પગાર ચુકવણી કન્ફર્મ કરો
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
