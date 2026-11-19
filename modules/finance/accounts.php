<?php
/**
 * Bank & Cash Accounts Management (સેક્શન ૩ & ૬.૮)
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

// Create Account Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {
    validate_csrf();

    $name = trim($_POST['account_name'] ?? '');
    $type = $_POST['account_type'] ?? 'bank';
    $bankName = trim($_POST['bank_name'] ?? '');
    $accNo = trim($_POST['account_number'] ?? '');
    $ifsc = trim($_POST['ifsc_code'] ?? '');
    $openBal = (float)($_POST['opening_balance'] ?? 0.00);

    if (empty($name)) {
        set_flash('error', 'કૃપા કરીને ખાતાનું નામ દાખલ કરો.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO bank_accounts (account_name, account_type, bank_name, account_number, ifsc_code, opening_balance, current_balance, status)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $type, $bankName, $accNo, $ifsc, $openBal, $openBal]);

            log_activity('Bank Account Created', 'Finance', (int)$pdo->lastInsertId(), "Created $type account: $name with opening bal $openBal");
            set_flash('success', "ખાતું '$name' સફળતાપૂર્વક ઉમેરાઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/finance/accounts.php');
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'ખાતું બનાવવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Edit Account Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_account') {
    validate_csrf();
    $accId = (int)($_POST['account_id'] ?? 0);
    $name = trim($_POST['account_name'] ?? '');
    $type = $_POST['account_type'] ?? 'bank';
    $bankName = trim($_POST['bank_name'] ?? '');
    $accNo = trim($_POST['account_number'] ?? '');
    $ifsc = trim($_POST['ifsc_code'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($name) || $accId <= 0) {
        set_flash('error', 'કૃપા કરીને ખાતાનું નામ દાખલ કરો.');
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE bank_accounts SET account_name = ?, account_type = ?, bank_name = ?, account_number = ?, ifsc_code = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $type, $bankName, $accNo, $ifsc, $status, $accId]);

            log_activity('Bank Account Updated', 'Finance', $accId, "Updated account: $name ($type)");
            set_flash('success', "ખાતું '$name' સફળતાપૂર્વક અપડેટ થઈ ગયું છે.");
        } catch (\Throwable $ex) {
            set_flash('error', 'ખાતું અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/modules/finance/accounts.php');
    exit;
}

// Delete Account Action (Secure POST with CSRF and ledger protection)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    validate_csrf();
    $delId = (int)($_POST['account_id'] ?? 0);
    
    // Check if transactions exist to protect audit trail
    $txStmt = $pdo->prepare("SELECT COUNT(*) FROM account_transactions WHERE bank_account_id = ?");
    $txStmt->execute([$delId]);
    $txCount = (int)$txStmt->fetchColumn();
    if ($txCount > 0) {
        set_flash('error', "આ ખાતામાં $txCount વ્યવહારો થયેલા હોવાથી હિસાબી સુરક્ષા ખાતર તેને ડિલીટ કરી શકાશે નહીં.");
    } else {
        try {
            $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$delId]);
            set_flash('success', 'ખાતું સફળતાપૂર્વક કાઢી નાખવામાં આવ્યું.');
        } catch (\Throwable $ex) {
            set_flash('error', 'ખાતું કાઢવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/modules/finance/accounts.php');
    exit;
}

$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id ASC")->fetchAll();
$companyBalance = get_total_company_balance();

// Recent transactions across all accounts
$txns = $pdo->query("SELECT t.*, a.account_name, a.account_type, u.full_name as user_name
                     FROM account_transactions t
                     JOIN bank_accounts a ON t.bank_account_id = a.id
                     LEFT JOIN users u ON t.created_by = u.id
                     ORDER BY t.transaction_date DESC, t.id DESC LIMIT 15")->fetchAll();

$bankBal = 0.00;
$bankCount = 0;
$cashBal = 0.00;
$cashCount = 0;
foreach ($accounts as $a) {
    if ($a['account_type'] === 'bank') {
        $bankBal += (float)$a['current_balance'];
        $bankCount++;
    } else {
        $cashBal += (float)$a['current_balance'];
        $cashCount++;
    }
}

$pageTitle = 'Bank અને Cash ખાતાઓ (Account Management)';
require_once __DIR__ . '/../../includes/header.php';

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
?>


<?= render_finance_tabs('accounts') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Bank અને Cash ખાતાઓ (Account Management)',
    'સેક્શન ૩ & ૬.૮: કંપનીનું કુલ Balance જાણવા માટે જેટલા Account હોય તે બધા ઉમેરવા / કાઢવાની સગવડ',
    'building-columns',
    '
    <button class="btn btn-primary px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#newAccountModal">
        ' . svg_icon('plus', 'me-1') . 'નવું ખાતું ઉમેરો (Add Account)
    </button>
    <a href="' . BASE_URL . '/modules/finance/index.php" class="btn btn-outline-dark px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'Finance Overview
    </a>
    ',
    'emerald'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કંપની કુલ બેલેન્સ (Total Balance)', format_inr($companyBalance), count($accounts) . ' ખાતાઓ', 'સંયુક્ત રોકડ & બેંક', 'hero', null, '', 'building-columns') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('બેંક ખાતાઓ બેલેન્સ (Bank Accounts)', format_inr($bankBal), $bankCount . ' બેંક ખાતા', 'સુરત & મુંબઈ બેંક', 'white', null, 'pill-mint', 'landmark') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('રોકડ કેશ પેટી (Cash Books)', format_inr($cashBal), $cashCount . ' રોકડ ખાતા', 'ઓફિસ કેશ બેલેન્સ', 'white', null, 'pill-mint', 'money-bill') ?>
    </div>
</div>

<!-- Accounts List Table -->
<div class="card-erp mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><?= svg_icon('wallet', 'me-2 text-primary') ?>બેંક અને કેશ ખાતાઓની યાદી</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>ખાતાનું નામ</th>
                        <th>પ્રકાર (Type)</th>
                        <th>બેંકનું નામ / શાખા</th>
                        <th>એકાઉન્ટ નંબર / IFSC</th>
                        <th>શરૂઆતનું બેલેન્સ</th>
                        <th>હાલનું બેલેન્સ (Current Balance)</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td><strong class="text-dark fs-6"><?= e($acc['account_name']) ?></strong></td>
                            <td>
                                <span class="badge <?= $acc['account_type'] === 'bank' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?> border text-uppercase">
                                    <?= e($acc['account_type']) ?>
                                </span>
                            </td>
                            <td><?= e($acc['bank_name'] ?? 'Cash Counter') ?></td>
                            <td>
                                <div><?= e($acc['account_number'] ?? '-') ?></div>
                                <small class="text-muted"><?= e($acc['ifsc_code'] ?? '') ?></small>
                            </td>
                            <td><?= format_inr($acc['opening_balance']) ?></td>
                            <td><strong class="text-success fs-6"><?= format_inr($acc['current_balance']) ?></strong></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit-account" 
                                    data-id="<?= $acc['id'] ?>"
                                    data-name="<?= e($acc['account_name']) ?>"
                                    data-type="<?= e($acc['account_type']) ?>"
                                    data-bank="<?= e($acc['bank_name'] ?? '') ?>"
                                    data-accno="<?= e($acc['account_number'] ?? '') ?>"
                                    data-ifsc="<?= e($acc['ifsc_code'] ?? '') ?>"
                                    data-status="<?= e($acc['status']) ?>"
                                    title="એડિટ કરો">
                                    <?= svg_icon('pen-to-square') ?>
                                </button>
                                <form action="<?= BASE_URL ?>/modules/finance/accounts.php" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર આ ખાતું કાઢવા માંગો છો?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_account">
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="ડિલીટ કરો">
                                        <?= svg_icon('trash') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Transactions Ledger -->
<div class="card-erp">
    <div class="card-header bg-white">
        <h6 class="fw-bold text-dark mb-0"><?= svg_icon('book-open', 'me-2 text-primary') ?>તાજેતરના નાણાકીય વ્યવહારો (Recent Transactions Ledger)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>તારીખ</th>
                        <th>ખાતું</th>
                        <th>પ્રકાર</th>
                        <th>રકમ (Amount)</th>
                        <th>સંદર્ભ / મોડ</th>
                        <th>વિગત</th>
                        <th>યુઝર</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($txns)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">કોઈ તાજેતરના વ્યવહારો નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($txns as $tx): ?>
                            <tr>
                                <td><?= format_date($tx['transaction_date']) ?></td>
                                <td><strong><?= e($tx['account_name']) ?></strong></td>
                                <td>
                                    <?php if ($tx['type'] === 'credit'): ?>
                                        <span class="badge bg-success-subtle text-success border">Credit (જમા)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">Debit (ઉધાર)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="<?= $tx['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                                        <?= $tx['type'] === 'credit' ? '+' : '-' ?> <?= format_inr($tx['amount']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e($tx['reference_no'] ?? '-') ?></span>
                                    <small class="text-muted d-block"><?= e($tx['payment_mode']) ?></small>
                                </td>
                                <td><small class="text-muted"><?= e($tx['notes'] ?? '-') ?></small></td>
                                <td><small class="text-muted"><?= e($tx['user_name'] ?? 'Admin') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add New Account -->
<div class="modal fade" id="newAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/finance/accounts.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_account">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><?= svg_icon('plus', 'me-2 text-primary') ?>નવું Bank / Cash ખાતું ઉમેરો</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાતાનું નામ (Account Name) *</label>
                        <input type="text" name="account_name" class="form-control" placeholder="દા.ત. HDFC Current A/c અથવા Surat Cash Counter" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાતાનો પ્રકાર (Type) *</label>
                        <select name="account_type" class="form-select" required>
                            <option value="bank" selected>Bank Account (બેંક ખાતું)</option>
                            <option value="cash">Cash Account (રોકડ કેશ કાઉન્ટર)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">બેંકનું નામ (Bank Name)</label>
                        <input type="text" name="bank_name" class="form-control" placeholder="દા.ત. State Bank of India, HDFC Bank">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">એકાઉન્ટ નંબર</label>
                            <input type="text" name="account_number" class="form-control" placeholder="Account Number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">IFSC Code</label>
                            <input type="text" name="ifsc_code" class="form-control" placeholder="IFSC Code">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">શરૂઆતનું બેલેન્સ (Opening Balance INR)</label>
                        <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold">ખાતું ઉમેરો (Save Account)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Account -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/finance/accounts.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" id="edit_account_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><?= svg_icon('pen-to-square', 'me-2 text-primary') ?>ખાતું એડિટ કરો (Edit Account)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાતાનું નામ (Account Name) *</label>
                        <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાતાનો પ્રકાર (Type) *</label>
                        <select name="account_type" id="edit_account_type" class="form-select" required>
                            <option value="bank">Bank Account (બેંક ખાતું)</option>
                            <option value="cash">Cash Account (રોકડ કેશ કાઉન્ટર)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">બેંકનું નામ (Bank Name)</label>
                        <input type="text" name="bank_name" id="edit_bank_name" class="form-control">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">એકાઉન્ટ નંબર</label>
                            <input type="text" name="account_number" id="edit_account_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">IFSC Code</label>
                            <input type="text" name="ifsc_code" id="edit_ifsc_code" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સ્થિતિ (Status)</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active (ચાલુ)</option>
                            <option value="inactive">Inactive (બંધ)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold">ફેરફારો સેવ કરો (Save Changes)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editModalEl = document.getElementById('editAccountModal');
    if (!editModalEl) return;
    const editModal = new bootstrap.Modal(editModalEl);
    
    document.querySelectorAll('.btn-edit-account').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_account_id').value = this.dataset.id;
            document.getElementById('edit_account_name').value = this.dataset.name;
            document.getElementById('edit_account_type').value = this.dataset.type;
            document.getElementById('edit_bank_name').value = this.dataset.bank;
            document.getElementById('edit_account_number').value = this.dataset.accno;
            document.getElementById('edit_ifsc_code').value = this.dataset.ifsc;
            document.getElementById('edit_status').value = this.dataset.status || 'active';
            editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
