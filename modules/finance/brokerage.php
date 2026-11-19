<?php
/**
 * Brokerage Register & Settle Ledger (સેક્શન ૬.૮ દલાલી ચુકવણી)
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

// Settle Brokerage Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_brokerage') {
    validate_csrf();

    $type = $_POST['purchase_type'] ?? 'rough';
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $chukvyaDate = $_POST['brokerage_chukvya_date'] ?? date('Y-m-d');
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $redirectUrl = !empty($_POST['redirect']) ? $_POST['redirect'] : BASE_URL . '/modules/finance/brokerage.php';

    try {
        $pdo->beginTransaction();

        $brokerageAmount = 0.00;
        $docNo = '';
        $brokerId = null;

        if ($type === 'rough') {
            $st = $pdo->prepare("SELECT brokerage_amount, purchase_no, broker_id FROM rough_purchases WHERE id = ?");
            $st->execute([$purchaseId]);
            $r = $st->fetch();
            if ($r) {
                $brokerageAmount = (float)$r['brokerage_amount'];
                $docNo = $r['purchase_no'];
                $brokerId = !empty($r['broker_id']) ? (int)$r['broker_id'] : null;
                $pdo->prepare("UPDATE rough_purchases SET brokerage_payment_status = 'paid', brokerage_paid_amount = brokerage_amount, brokerage_chukvya_date = ? WHERE id = ?")
                    ->execute([$chukvyaDate, $purchaseId]);
            }
        } elseif ($type === 'polish') {
            $st = $pdo->prepare("SELECT brokerage_amount, purchase_no, broker_id FROM polish_purchases WHERE id = ?");
            $st->execute([$purchaseId]);
            $p = $st->fetch();
            if ($p) {
                $brokerageAmount = (float)$p['brokerage_amount'];
                $docNo = $p['purchase_no'];
                $brokerId = !empty($p['broker_id']) ? (int)$p['broker_id'] : null;
                $pdo->prepare("UPDATE polish_purchases SET brokerage_payment_status = 'paid', brokerage_chukvya_date = ? WHERE id = ?")
                    ->execute([$chukvyaDate, $purchaseId]);
            }
        } elseif ($type === 'rough_sale') {
            $st = $pdo->prepare("SELECT brokerage_amount, invoice_no, broker_id FROM rough_sales WHERE id = ?");
            $st->execute([$purchaseId]);
            $rs = $st->fetch();
            if ($rs) {
                $brokerageAmount = (float)$rs['brokerage_amount'];
                $docNo = $rs['invoice_no'];
                $brokerId = !empty($rs['broker_id']) ? (int)$rs['broker_id'] : null;
                $pdo->prepare("UPDATE rough_sales SET brokerage_payment_status = 'paid', brokerage_chukvya_date = ? WHERE id = ?")
                    ->execute([$chukvyaDate, $purchaseId]);
            }
        } elseif ($type === 'polish_sale') {
            $st = $pdo->prepare("SELECT brokerage_amount, invoice_no, broker_id FROM polish_sales WHERE id = ?");
            $st->execute([$purchaseId]);
            $ps = $st->fetch();
            if ($ps) {
                $brokerageAmount = (float)$ps['brokerage_amount'];
                $docNo = $ps['invoice_no'];
                $brokerId = !empty($ps['broker_id']) ? (int)$ps['broker_id'] : null;
                $pdo->prepare("UPDATE polish_sales SET brokerage_payment_status = 'paid', brokerage_chukvya_date = ? WHERE id = ?")
                    ->execute([$chukvyaDate, $purchaseId]);
            }
        }

        if ($brokerageAmount > 0) {
            if ($bankAccId) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([$brokerageAmount, $bankAccId]);
                $pdo->prepare("INSERT INTO account_transactions (bank_account_id, type, amount, related_type, related_id, reference_no, transaction_date, notes, created_by)
                               VALUES (?, 'debit', ?, 'brokerage', ?, ?, ?, ?, ?)")
                    ->execute([$bankAccId, $brokerageAmount, $purchaseId, $docNo, $chukvyaDate, "Brokerage payment for $docNo", $user['id']]);
            }
            if ($brokerId) {
                update_party_balance($brokerId, +$brokerageAmount);
            }
        }

        $pdo->commit();
        set_flash('success', 'દલાલી સફળતાપૂર્વક ચૂકવાઈ ગઈ છે (Brokerage Settled).');
        header('Location: ' . $redirectUrl);
        exit;
    } catch (\Throwable $ex) {
        $pdo->rollBack();
        set_flash('error', 'દલાલી સેટલ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
}

$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();
$allBrokers = $pdo->query("SELECT id, party_name FROM parties WHERE party_type IN ('broker', 'both') ORDER BY party_name ASC")->fetchAll();

$filterBrokerId = (int)($_GET['broker_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? 'all');

// Unified Brokerage Entries (Rough Purchase, Polish Purchase, Rough Sale, Polish Sale)
$sql = "
    (SELECT 'rough' as purchase_type, 'રફ ખરીદી' as deal_category, rp.id, rp.purchase_no, rp.purchase_date, rp.broker_id, b.party_name as broker_name,
            rp.party_id, p.party_name, rp.payment_amount, rp.brokerage_percent, rp.brokerage_amount,
            rp.brokerage_payment_status as status, rp.brokerage_chukvya_date
     FROM rough_purchases rp
     JOIN parties p ON rp.party_id = p.id
     JOIN parties b ON rp.broker_id = b.id
     WHERE rp.brokerage_amount > 0 AND rp.status != 'merged')
    UNION ALL
    (SELECT 'polish' as purchase_type, 'પોલિશ ખરીદી' as deal_category, pp.id, pp.purchase_no, pp.purchase_date, pp.broker_id, b.party_name as broker_name,
            pp.party_id, p.party_name, pp.payment_amount, pp.brokerage_percent, pp.brokerage_amount,
            pp.brokerage_payment_status as status, pp.brokerage_chukvya_date
     FROM polish_purchases pp
     JOIN parties p ON pp.party_id = p.id
     JOIN parties b ON pp.broker_id = b.id
     WHERE pp.brokerage_amount > 0)
    UNION ALL
    (SELECT 'rough_sale' as purchase_type, 'રફ વેચાણ' as deal_category, rs.id, rs.invoice_no as purchase_no, rs.sale_date as purchase_date, rs.broker_id, b.party_name as broker_name,
            rs.party_id, p.party_name, rs.payment_amount, rs.brokerage_percent, rs.brokerage_amount,
            rs.brokerage_payment_status as status, rs.brokerage_chukvya_date
     FROM rough_sales rs
     JOIN parties p ON rs.party_id = p.id
     JOIN parties b ON rs.broker_id = b.id
     WHERE rs.brokerage_amount > 0)
    UNION ALL
    (SELECT 'polish_sale' as purchase_type, 'પોલિશ વેચાણ' as deal_category, ps.id, ps.invoice_no as purchase_no, ps.sale_date as purchase_date, ps.broker_id, b.party_name as broker_name,
            ps.party_id, p.party_name, ps.net_amount as payment_amount, ps.brokerage_percent, ps.brokerage_amount,
            ps.brokerage_payment_status as status, ps.brokerage_chukvya_date
     FROM polish_sales ps
     JOIN parties p ON ps.party_id = p.id
     JOIN parties b ON ps.broker_id = b.id
     WHERE ps.brokerage_amount > 0)
    ORDER BY purchase_date DESC
";
$allBrokerages = $pdo->query($sql)->fetchAll();

// Apply PHP filters
$brokerages = [];
$totPending = 0.00;
$totPaid = 0.00;
foreach ($allBrokerages as $b) {
    if ($filterBrokerId > 0 && (int)$b['broker_id'] !== $filterBrokerId) {
        continue;
    }
    if ($filterStatus === 'pending' && $b['status'] === 'paid') {
        continue;
    }
    if ($filterStatus === 'paid' && $b['status'] !== 'paid') {
        continue;
    }
    $brokerages[] = $b;
    if ($b['status'] === 'paid') {
        $totPaid += (float)$b['brokerage_amount'];
    } else {
        $totPending += (float)$b['brokerage_amount'];
    }
}

$pageTitle = 'દલાલી ચુકવણી હિસાબ (Brokerage Register)';
require_once __DIR__ . '/../../includes/header.php';

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
$bankCount = (int)$pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE status = 'active'")->fetchColumn();
?>


<?= render_finance_tabs('brokerage') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'દલાલી ચુકવણી હિસાબ (Brokerage Register)',
    '',
    'percent',
    '
    <a href="' . BASE_URL . '/modules/finance/schedule.php" class="btn btn-outline-secondary px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'મુદત Schedule
    </a>
    ',
    'gold'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('ચૂકવવાની બાકી દલાલી (Pending)', format_inr($totPending), 'બાકી દલાલી', 'દલાલ પેમેન્ટ', 'hero', null, '', 'clock-rotate-left') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ ચૂકવેલ દલાલી (Paid Brokerage)', format_inr($totPaid), 'ચૂકવેલ હિસાબ', 'ચુકવણી પૂર્ણ', 'white', null, 'pill-mint', 'circle-check') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ દલાલી સોદા (Total Deals)', (string)count($brokerages) . ' સોદા', 'Rough & Polish', 'દલાલ રજિસ્ટર', 'white', null, 'pill-mint', 'handshake') ?>
    </div>
</div>

<!-- Brokerage Filters Bar -->
<div class="card-erp p-3 mb-3">
    <form method="GET" action="<?= BASE_URL ?>/modules/finance/brokerage.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <label class="form-label small fw-bold text-muted mb-1">દલાલ ફિલ્ટર (Filter by Broker)</label>
            <select name="broker_id" class="form-select no-search" onchange="this.form.submit()">
                <option value="0">-- બધા દલાલો (All Brokers) --</option>
                <?php foreach ($allBrokers as $br): ?>
                    <option value="<?= (int)$br['id'] ?>" <?= ($filterBrokerId === (int)$br['id']) ? 'selected' : '' ?>>
                        <?= e($br['party_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small fw-bold text-muted mb-1">ચુકવણી સ્થિતિ (Payment Status)</label>
            <select name="status" class="form-select no-search" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>બધી દલાલી (All Status)</option>
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>🕒 બાકી દલાલી (Pending Only)</option>
                <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>✅ ચૂકવાઈ ગયેલ (Paid Only)</option>
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex align-items-end gap-2 pt-md-3">
            <button type="submit" class="btn btn-primary btn-sm px-3 fw-bold w-100">
                <?= svg_icon('magnifying-glass', 'me-1') ?>ફિલ્ટર
            </button>
            <?php if ($filterBrokerId > 0 || $filterStatus !== 'all'): ?>
                <a href="<?= BASE_URL ?>/modules/finance/brokerage.php" class="btn btn-outline-secondary btn-sm px-3" title="રીસેટ">
                    <?= svg_icon('xmark', 'me-1') ?>રીસેટ
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>સોદા સંદર્ભ / તારીખ</th>
                        <th>દલાલનું નામ (Broker)</th>
                        <th>પાર્ટીનું નામ</th>
                        <th>સોદાની રકમ</th>
                        <th>દલાલી %</th>
                        <th>દલાલી રકમ (Brokerage INR)</th>
                        <th>સ્થિતિ</th>
                        <th>Brokerage Chukvya Date</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($brokerages)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ દલાલી રેકોર્ડ મળ્યો નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($brokerages as $brk): ?>
                            <tr>
                                <td>
                                    <strong><?= e($brk['purchase_no']) ?></strong>
                                    <div class="small text-muted">
                                        <?= format_date($brk['purchase_date']) ?> 
                                        <span class="badge bg-light text-dark border ms-1"><?= e($brk['deal_category'] ?? $brk['purchase_type']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $brk['broker_id'] ?>&tab=brokerage" class="text-primary fw-semibold text-decoration-none" title="દલાલ ખાતાવહી જુઓ">
                                        <?= e($brk['broker_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $brk['party_id'] ?>&tab=<?= str_contains($brk['purchase_type'], 'rough') ? 'rough' : 'polish' ?>" class="text-dark fw-semibold text-decoration-none" title="પાર્ટી લેજર જુઓ">
                                        <?= e($brk['party_name']) ?>
                                    </a>
                                </td>
                                <td><?= format_inr($brk['payment_amount']) ?></td>
                                <td><?= number_format((float)$brk['brokerage_percent'], 2) ?>%</td>
                                <td><strong class="text-danger fs-6"><?= format_inr($brk['brokerage_amount']) ?></strong></td>
                                <td>
                                    <?php if ($brk['status'] === 'paid'): ?>
                                        <span class="badge bg-success-subtle text-success border">ચૂકવાઈ ગઈ</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">બાકી</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($brk['brokerage_chukvya_date']): ?>
                                        <span class="text-success fw-semibold"><?= svg_icon('check', 'me-1') ?><?= format_date($brk['brokerage_chukvya_date']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($brk['status'] !== 'paid'): ?>
                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#brkModal<?= $brk['purchase_type'] . $brk['id'] ?>">
                                            <?= svg_icon('check', 'me-1') ?>દલાલી ચૂકવો
                                        </button>

                                        <!-- Modal -->
                                        <div class="modal fade" id="brkModal<?= $brk['purchase_type'] . $brk['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered text-start">
                                                <div class="modal-content">
                                                    <form action="<?= BASE_URL ?>/modules/finance/brokerage.php" method="POST">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="settle_brokerage">
                                                        <input type="hidden" name="purchase_type" value="<?= e($brk['purchase_type']) ?>">
                                                        <input type="hidden" name="purchase_id" value="<?= $brk['id'] ?>">

                                                        <div class="modal-header bg-danger text-white">
                                                            <h6 class="modal-title fw-bold text-white"><?= svg_icon('percent', 'me-1.5') ?>દલાલી ચુકવણી: <?= e($brk['broker_name']) ?></h6>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="p-2.5 bg-light rounded-3 mb-3 border">
                                                                <div>સંદર્ભ બિલ: <strong><?= e($brk['purchase_no']) ?></strong> (<?= e($brk['deal_category'] ?? $brk['purchase_type']) ?>)</div>
                                                                <div>પાર્ટી: <strong><?= e($brk['party_name']) ?></strong></div>
                                                                <div class="mt-1">દલાલી રકમ: <strong class="text-danger fs-5 font-mono"><?= format_inr($brk['brokerage_amount']) ?></strong></div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted">ચૂકવ્યા તારીખ (Brokerage Chukvya Date) *</label>
                                                                <input type="date" name="brokerage_chukvya_date" class="form-control font-mono" value="<?= date('Y-m-d') ?>" required>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-dark">
                                                                    <?= svg_icon('building-columns', 'text-primary me-1') ?>Bank / Cash ખાતું પસંદ કરો *
                                                                </label>
                                                                <select name="bank_account_id" class="form-select no-search fw-semibold" required>
                                                                    <option value="">-- ખાતું પસંદ કરો --</option>
                                                                    <?php foreach ($bankAccounts as $acc): ?>
                                                                        <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <small class="text-muted">જે ખાતામાંથી દલાલીની રકમ આપવાની હોય તે પસંદ કરો.</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                            <button type="submit" class="btn btn-danger btn-sm fw-bold">
                                                                <?= svg_icon('check', 'me-1') ?>દલાલી ચુકવણી કન્ફર્મ કરો
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
