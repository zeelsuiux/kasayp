<?php
/**
 * Aavak - Javak (Cash & Bank Book / Income & Expense Management)
 * Kasyap Diamond ERP - Surat & Mumbai
 * Complete Cash In / Cash Out Voucher Management
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

// Handle New Aavak / Javak Voucher Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_transaction') {
    validate_csrf();

    $accountId = (int)($_POST['bank_account_id'] ?? 0);
    $type      = $_POST['type'] ?? 'debit'; // 'credit' = આવક, 'debit' = જાવક
    $amount    = (float)($_POST['amount'] ?? 0);
    $category  = trim($_POST['category'] ?? 'General');
    $partyId   = !empty($_POST['party_id']) ? (int)$_POST['party_id'] : null;
    $refNo     = trim($_POST['reference_no'] ?? '');
    $payMode   = $_POST['payment_mode'] ?? 'cash';
    $txDate    = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d');
    $notes     = trim($_POST['notes'] ?? '');

    if ($accountId <= 0) {
        set_flash('error', 'કૃપા કરીને માન્ય બેંક અથવા રોકડ (Cash) ખાતું પસંદ કરો.');
    } elseif ($amount <= 0) {
        set_flash('error', 'કૃપા કરીને શૂન્યથી વધુ રકમ દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Fetch current account
            $stmtAcc = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ? FOR UPDATE");
            $stmtAcc->execute([$accountId]);
            $acc = $stmtAcc->fetch();

            if (!$acc) {
                throw new Exception("પસંદ કરેલું ખાતું મળ્યું નથી.");
            }

            // 2. Insert transaction record
            $sqlTx = "INSERT INTO account_transactions 
                      (bank_account_id, type, amount, related_type, category, party_id, reference_no, payment_mode, transaction_date, notes, created_by)
                      VALUES (?, ?, ?, 'other', ?, ?, ?, ?, ?, ?, ?)";
            $stmtTx = $pdo->prepare($sqlTx);
            $stmtTx->execute([
                $accountId,
                $type,
                $amount,
                $category,
                $partyId,
                $refNo,
                $payMode,
                $txDate,
                $notes,
                (int)$user['id']
            ]);
            $txId = (int)$pdo->lastInsertId();

            // 3. Update Bank Account Balance
            if ($type === 'credit') {
                // આવક -> રકમ જમા
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([$amount, $accountId]);
            } else {
                // જાવક -> રકમ ઉધાર
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([$amount, $accountId]);
            }

            $typeLabel = ($type === 'credit') ? 'આવક (Receipt)' : 'જાવક (Payment)';
            log_activity("Transaction $typeLabel", 'Finance', $txId, "Voucher #$txId: $typeLabel of ₹$amount in {$acc['account_name']} ($category)");

            $pdo->commit();
            set_flash('success', "$typeLabel ₹" . number_format($amount, 2) . " સફળતાપૂર્વક નોંધાઈ ગઈ છે.");
            header('Location: ' . BASE_URL . '/modules/finance/transactions.php');
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'વાઉચર નોંધવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Handle Transaction Deletion (Reversal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_transaction') {
    validate_csrf();
    $delId = (int)($_POST['transaction_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmtTx = $pdo->prepare("SELECT * FROM account_transactions WHERE id = ? FOR UPDATE");
        $stmtTx->execute([$delId]);
        $tx = $stmtTx->fetch();

        if ($tx) {
            $amount = (float)$tx['amount'];
            $accId = (int)$tx['bank_account_id'];

            // Reverse balance
            if ($tx['type'] === 'credit') {
                // Was credit, reverse by debiting
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([$amount, $accId]);
            } else {
                // Was debit, reverse by crediting
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([$amount, $accId]);
            }

            // Delete transaction
            $pdo->prepare("DELETE FROM account_transactions WHERE id = ?")->execute([$delId]);

            log_activity('Transaction Deleted', 'Finance', $delId, "Reversed and deleted voucher #$delId of ₹$amount");
            $pdo->commit();
            set_flash('success', 'વાઉચર સફળતાપૂર્વક કાઢી નાખવામાં આવ્યું અને ખાતાનું બેલેન્સ રિસ્ટોર થયું.');
        } else {
            $pdo->rollBack();
            set_flash('error', 'વાઉચર મળ્યું નથી.');
        }
    } catch (\Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'વાઉચર રદ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
    header('Location: ' . BASE_URL . '/modules/finance/transactions.php');
    exit;
}

// Filters & Query Parameters
$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$accFilter   = (int)($_GET['account_id'] ?? 0);
$typeFilter  = trim($_GET['type'] ?? '');
$search      = trim($_GET['search'] ?? '');

$sql = "SELECT t.*, a.account_name, a.account_type, p.party_name, u.full_name as created_user
        FROM account_transactions t
        JOIN bank_accounts a ON t.bank_account_id = a.id
        LEFT JOIN parties p ON t.party_id = p.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE 1=1";
$params = [];

if (!empty($fromDate)) {
    $sql .= " AND t.transaction_date >= ?";
    $params[] = $fromDate;
}
if (!empty($toDate)) {
    $sql .= " AND t.transaction_date <= ?";
    $params[] = $toDate;
}
if ($accFilter > 0) {
    $sql .= " AND t.bank_account_id = ?";
    $params[] = $accFilter;
}
if (!empty($typeFilter) && in_array($typeFilter, ['credit', 'debit'])) {
    $sql .= " AND t.type = ?";
    $params[] = $typeFilter;
}
if (!empty($search)) {
    $sql .= " AND (t.reference_no LIKE ? OR t.notes LIKE ? OR t.category LIKE ? OR p.party_name LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$sql .= " ORDER BY t.transaction_date DESC, t.id DESC LIMIT 150";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Fetch Active Accounts for Dropdown
$accounts = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY account_type DESC, account_name ASC")->fetchAll();
$parties = $pdo->query("SELECT id, party_name, party_type, city FROM parties ORDER BY party_name ASC")->fetchAll();

// Aggregation Stats
$companyBalance = get_total_company_balance();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$todayInStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE type = 'credit' AND transaction_date = ?");
$todayInStmt->execute([$today]);
$todayIn = (float)$todayInStmt->fetchColumn();

$todayOutStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE type = 'debit' AND transaction_date = ?");
$todayOutStmt->execute([$today]);
$todayOut = (float)$todayOutStmt->fetchColumn();

$monthInStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE type = 'credit' AND transaction_date >= ?");
$monthInStmt->execute([$monthStart]);
$monthIn = (float)$monthInStmt->fetchColumn();

$monthOutStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE type = 'debit' AND transaction_date >= ?");
$monthOutStmt->execute([$monthStart]);
$monthOut = (float)$monthOutStmt->fetchColumn();

$pageTitle = 'આવક - જાવક રોજમેળ (Cash & Bank Book)';
require_once __DIR__ . '/../../includes/header.php';

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
?>


<?= render_finance_tabs('transactions') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'આવક - જાવક રોજમેળ (Cash & Bank Book)',
    'રોકડ કેશ અને બેંક ખાતાઓની આવક, જાવક, ઓફિસ ખર્ચ, પાર્ટી ચુકવણી અને દૈનિક વાઉચર એન્ટ્રી',
    'book-bookmark',
    '
    <button class="btn btn-success px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#newIncomeModal">
        ' . svg_icon('arrow-down-left', 'me-1') . 'નવી આવક (Cash In / Receipt)
    </button>
    <button class="btn btn-danger px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#newExpenseModal">
        ' . svg_icon('arrow-up-right', 'me-1') . 'નવી જાવક (Cash Out / Expense)
    </button>
    <a href="' . BASE_URL . '/modules/reports/export.php?type=transactions' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm fw-semibold">
        ' . svg_icon('file-excel', 'me-1') . 'Excel માં Export
    </a>
    <button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ રોજમેળ
    </button>
    '
) ?>

<!-- 5 Donezo Master Financial Metric Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-sm-6 col-xl">
        <?= render_stat_card('આજની આવક (Today Inflow)', format_inr($todayIn), 'આવક', date('d-M-Y'), 'white', null, 'pill-mint', 'arrow-down-left') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <?= render_stat_card('આજની જાવક (Today Outflow)', format_inr($todayOut), 'જાવક', 'આજનો કુલ ખર્ચ', 'white', null, 'pill-coral', 'arrow-up-right') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <?= render_stat_card('મહિનાની આવક (Month Inflow)', format_inr($monthIn), date('M Y'), 'જમા થયેલ રકમ', 'white', null, 'pill-mint', 'calendar-check') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <?= render_stat_card('મહિનાની જાવક (Month Outflow)', format_inr($monthOut), date('M Y'), 'કુલ ચૂકવેલ રકમ', 'white', null, 'pill-coral', 'calendar-xmark') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <?= render_stat_card('કંપની કુલ બેલેન્સ (Net Liquidity)', format_inr($companyBalance), count($accounts) . ' ખાતા', 'સુરત & મુંબઈ', 'hero', BASE_URL . '/modules/finance/accounts.php', '', 'building-columns') ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-erp p-2 px-3 mb-3">
    <form method="GET" action="<?= BASE_URL ?>/modules/finance/transactions.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-3">
            <input type="text" name="search" class="form-control" placeholder="સંદર્ભ નં, વિગત, પાર્ટી શોધો..." value="<?= e($search) ?>">
        </div>
        <div class="col-6 col-md-2">
            <select name="type" class="form-select">
                <option value="">બધા પ્રકાર (All)</option>
                <option value="credit" <?= $typeFilter === 'credit' ? 'selected' : '' ?>>આવક (Credit / In)</option>
                <option value="debit" <?= $typeFilter === 'debit' ? 'selected' : '' ?>>જાવક (Debit / Out)</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select name="account_id" class="form-select">
                <option value="0">બધા ખાતા (All Accounts)</option>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= $accFilter === (int)$acc['id'] ? 'selected' : '' ?>>
                        <?= e($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?> - <?= format_inr($acc['current_balance']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <input type="date" name="from_date" class="form-control font-mono" value="<?= e($fromDate) ?>" title="તારીખથી">
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-dark flex-grow-1"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($typeFilter) || $accFilter > 0 || !empty($fromDate)): ?>
                <a href="<?= BASE_URL ?>/modules/finance/transactions.php" class="btn btn-outline-secondary">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Transactions Master Ledger Table -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>વાઉચર નં / તારીખ</th>
                        <th>ખાતું (Account)</th>
                        <th>પ્રકાર</th>
                        <th>કેટેગરી</th>
                        <th>પાર્ટી / વ્યક્તિ</th>
                        <th>રકમ (Amount)</th>
                        <th>મોડ & રેફરન્સ</th>
                        <th>શેરો / વિગત (Notes)</th>
                        <th class="text-end table-actions">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ આવક-જાવક એન્ટ્રી મળી નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold font-mono text-dark">#V-<?= str_pad((string)$tx['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                    <small class="text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($tx['transaction_date']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($tx['account_name']) ?></strong>
                                    <span class="badge <?= $tx['account_type'] === 'cash' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' ?> border ms-1" style="font-size: 0.80rem;">
                                        <?= strtoupper($tx['account_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($tx['type'] === 'credit'): ?>
                                        <span class="badge bg-success text-white px-2 py-1">
                                            <?= svg_icon('arrow-down-left', 'me-1') ?>આવક
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger text-white px-2 py-1">
                                            <?= svg_icon('arrow-up-right', 'me-1') ?>જાવક
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fw-bold"><?= e($tx['category'] ?? 'General') ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($tx['party_name']) && !empty($tx['party_id'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $tx['party_id'] ?>&tab=finance" class="fw-semibold text-primary text-decoration-none" title="પાર્ટીનું Finance લેજર જુઓ">
                                            <?= e($tx['party_name']) ?>
                                        </a>
                                    <?php elseif (!empty($tx['party_name'])): ?>
                                        <span class="fw-semibold"><?= e($tx['party_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="font-mono <?= $tx['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                                        <?= $tx['type'] === 'credit' ? '+' : '-' ?> <?= format_inr($tx['amount']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <div class="text-uppercase small fw-bold"><?= e($tx['payment_mode']) ?></div>
                                    <?php if (!empty($tx['reference_no'])): ?>
                                        <small class="text-muted font-mono"><?= e($tx['reference_no']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted text-truncate d-inline-block" style="max-width: 140px;"><?= e($tx['notes'] ?? '-') ?></small>
                                    <div class="small text-muted" style="font-size: 0.80rem;">બાય: <?= e($tx['created_user'] ?? 'Admin') ?></div>
                                </td>
                                <td class="text-end table-actions">
                                    <form action="<?= BASE_URL ?>/modules/finance/transactions.php" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર આ વાઉચર કાઢવા માંગો છો? આનાથી ખાતાનું બેલેન્સ રિવર્સ થઈ જશે.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_transaction">
                                        <input type="hidden" name="transaction_id" value="<?= $tx['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" title="વાઉચર રદ કરો">
                                            <?= svg_icon('trash') ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: નવી આવક એન્ટ્રી (Income / Receipt / Cash In) -->
<div class="modal fade" id="newIncomeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/finance/transactions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_transaction">
                <input type="hidden" name="type" value="credit">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <?= svg_icon('arrow-down-left', 'me-2') ?>નવી આવક એન્ટ્રી (Income / Cash In / જમા)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાતું પસંદ કરો (Account) *</label>
                        <select name="bank_account_id" class="form-select form-select-lg fw-bold" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= e($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?> - હાજર: <?= format_inr($acc['current_balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted">આવક રકમ (Amount INR) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control form-control-lg font-mono fw-bold text-success" placeholder="0.00" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">તારીખ (Date) *</label>
                            <input type="date" name="transaction_date" class="form-control form-control-lg font-mono" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">આવક કેટેગરી (Income Category) *</label>
                        <select name="category" class="form-select" required>
                            <option value="હીરા વેચાણ રકમ જમા" selected>હીરા વેચાણ રકમ જમા (Diamond Sale Payment)</option>
                            <option value="પાર્ટી એડવાન્સ જમા">પાર્ટી એડવાન્સ જમા (Party Advance)</option>
                            <option value="દલાલી આવક">દલાલી આવક (Brokerage Income)</option>
                            <option value="બેંકમાંથી રોકડ ઉપાડ">બેંકમાંથી રોકડ ઉપાડ (Cash Withdrawal for Office)</option>
                            <option value="અન્ય આવક / વ્યાજ">અન્ય આવક / વ્યાજ (Interest / Other Income)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સંબંધિત પાર્ટી / ગ્રાહક (જો હોય તો)</label>
                        <select name="party_id" class="form-select">
                            <option value="">-- કોઈ પાર્ટી નહીં / સામાન્ય --</option>
                            <?php foreach ($parties as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['party_name']) ?> (<?= e($p['city']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">પેમેન્ટ મોડ (Payment Mode)</label>
                            <select name="payment_mode" class="form-select">
                                <option value="cash" selected>Cash (રોકડ)</option>
                                <option value="cheque">Cheque (ચેક)</option>
                                <option value="neft_rtgs">NEFT / RTGS</option>
                                <option value="upi">UPI / GPay</option>
                                <option value="other">Other / Angadia</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">રેફરન્સ / ચેક નં</label>
                            <input type="text" name="reference_no" class="form-control font-mono" placeholder="Chq No / UPI Ref">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-muted">વિગત / લખાણ (Narration / શેરો)</label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="દા.ત. સુરત પાર્ટી પાસેથી બિલ નં. ૧૨ પેટે રોકડા મળ્યા"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <?= svg_icon('check', 'me-1') ?>આવક જમા કરો (Save Income)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: નવી જાવક એન્ટ્રી (Expense / Payment / Cash Out) -->
<div class="modal fade" id="newExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/finance/transactions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_transaction">
                <input type="hidden" name="type" value="debit">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold">
                        <?= svg_icon('arrow-up-right', 'me-2') ?>નવી જાવક એન્ટ્રી (Expense / Cash Out / ઉધાર)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">કયા ખાતામાંથી રકમ ગઈ? (Account) *</label>
                        <select name="bank_account_id" class="form-select form-select-lg fw-bold" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= e($acc['account_name']) ?> (<?= strtoupper($acc['account_type']) ?> - હાજર: <?= format_inr($acc['current_balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted">જાવક રકમ (Amount INR) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control form-control-lg font-mono fw-bold text-danger" placeholder="0.00" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">તારીખ (Date) *</label>
                            <input type="date" name="transaction_date" class="form-control form-control-lg font-mono" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">જાવક કેટેગરી (Expense Category) *</label>
                        <select name="category" class="form-select" required>
                            <option value="પાર્ટી ખરીદી પેમેન્ટ" selected>પાર્ટી ખરીદી પેમેન્ટ (Rough/Polish Purchase Payment)</option>
                            <option value="દલાલી ચુકવણી">દલાલી ચુકવણી (Brokerage Payment)</option>
                            <option value="કારખાનું મજૂરી / ઘસાઈ Labour">કારખાનું મજૂરી / ઘસાઈ Labour (Factory Outsource)</option>
                            <option value="સ્ટાફ પગાર / ઉપાડ">સ્ટાફ પગાર / ઉપાડ (Staff Salary / Advance)</option>
                            <option value="ઓફિસ ચા-પાણી / નાસ્તો / Canteen">ઓફિસ ચા-પાણી / નાસ્તો / Canteen</option>
                            <option value="ઓફિસ ખર્ચ / સ્ટેશનરી">ઓફિસ ખર્ચ / સ્ટેશનરી (Office Expense)</option>
                            <option value="ઓફિસ / કારખાનું ભાડું">ઓફિસ / કારખાનું ભાડું (Rent)</option>
                            <option value="વીજળી લાઈટ બિલ">વીજળી લાઈટ બિલ (Electricity)</option>
                            <option value="ટ્રાવેલિંગ / આંગડિયા / કુરિયર">ટ્રાવેલિંગ / આંગડિયા / કુરિયર (Travel / Courier)</option>
                            <option value="બેંકમાં રોકડ જમા">બેંકમાં રોકડ જમા (Cash Deposit to Bank)</option>
                            <option value="પરચૂરણ જાવક">અન્ય પરચૂરણ જાવક (Miscellaneous)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">કોને રકમ ચૂકવી? (પાર્ટી / વેન્ડર)</label>
                        <select name="party_id" class="form-select">
                            <option value="">-- સામાન્ય ઓફિસ ખર્ચ / કોઈ પાર્ટી નહીં --</option>
                            <?php foreach ($parties as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['party_name']) ?> (<?= e($p['city']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">પેમેન્ટ મોડ (Payment Mode)</label>
                            <select name="payment_mode" class="form-select">
                                <option value="cash" selected>Cash (રોકડ)</option>
                                <option value="cheque">Cheque (ચેક)</option>
                                <option value="neft_rtgs">NEFT / RTGS</option>
                                <option value="upi">UPI / GPay</option>
                                <option value="other">Other / Angadia</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">રેફરન્સ / ચેક નં</label>
                            <input type="text" name="reference_no" class="form-control font-mono" placeholder="Chq No / Voucher Ref">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-muted">વિગત / શેરો (Narration / Notes)</label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="દા.ત. રફ ખરીદી પેટે ૨ લાખ આપ્યા અથવા ઓફિસ લાઈટ બિલ ભર્યું"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-danger fw-bold px-4">
                        <?= svg_icon('check', 'me-1') ?>જાવક ઉધાર કરો (Save Expense)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
