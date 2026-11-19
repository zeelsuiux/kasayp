<?php
/**
 * Integrated Payment Schedule (સેક્શન ૬.૮ ચુકવણી Schedule)
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

// Handle quick payment settlement (Payables & Receivables)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_payment') {
    validate_csrf();

    $billType = $_POST['bill_type'] ?? '';
    $billId = (int)($_POST['bill_id'] ?? 0);
    $paidDate = $_POST['chukvya_date'] ?? date('Y-m-d');
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;

    try {
        $pdo->beginTransaction();

        if ($billType === 'rough_purchase') {
            $st = $pdo->prepare("SELECT net_payment, paid_amount, purchase_no, party_id FROM rough_purchases WHERE id = ?");
            $st->execute([$billId]);
            $b = $st->fetch();

            if ($b) {
                $balance = max(0, (float)$b['net_payment'] - (float)$b['paid_amount']);
                $settleAmt = !empty($_POST['settle_amount']) ? min($balance, (float)$_POST['settle_amount']) : $balance;
                if ($settleAmt <= 0) $settleAmt = $balance;

                $newPaid = (float)$b['paid_amount'] + $settleAmt;
                $newStatus = ($newPaid >= (float)$b['net_payment'] - 0.01) ? 'paid' : 'partial';

                $upd = $pdo->prepare("UPDATE rough_purchases SET party_payment_status = ?, paid_amount = ?, chukvya_date = ?, bank_account_id = ? WHERE id = ?");
                $upd->execute([$newStatus, $newPaid, $paidDate, $bankAccId, $billId]);

                if ($bankAccId && $settleAmt > 0) {
                    record_account_transaction($bankAccId, 'debit', $settleAmt, 'rough_purchase', $billId, $b['purchase_no'], $paidDate, "Rough purchase payment settlement for {$b['purchase_no']}", $user['id']);
                }
                update_party_balance((int)$b['party_id'], +$settleAmt);
            }
        } elseif ($billType === 'polish_purchase') {
            $st = $pdo->prepare("SELECT net_payment, paid_amount, purchase_no, party_id FROM polish_purchases WHERE id = ?");
            $st->execute([$billId]);
            $b = $st->fetch();

            if ($b) {
                $balance = max(0, (float)$b['net_payment'] - (float)$b['paid_amount']);
                $settleAmt = !empty($_POST['settle_amount']) ? min($balance, (float)$_POST['settle_amount']) : $balance;
                if ($settleAmt <= 0) $settleAmt = $balance;

                $newPaid = (float)$b['paid_amount'] + $settleAmt;
                $newStatus = ($newPaid >= (float)$b['net_payment'] - 0.01) ? 'paid' : 'partial';

                $upd = $pdo->prepare("UPDATE polish_purchases SET payment_status = ?, paid_amount = ?, chukvya_date = ?, bank_account_id = ? WHERE id = ?");
                $upd->execute([$newStatus, $newPaid, $paidDate, $bankAccId, $billId]);

                if ($bankAccId && $settleAmt > 0) {
                    record_account_transaction($bankAccId, 'debit', $settleAmt, 'polish_purchase', $billId, $b['purchase_no'], $paidDate, "Polish purchase settlement for {$b['purchase_no']}", $user['id']);
                }
                update_party_balance((int)$b['party_id'], +$settleAmt);
            }
        } elseif ($billType === 'rough_sale') {
            $st = $pdo->prepare("SELECT net_amount, received_amount, invoice_no, party_id FROM rough_sales WHERE id = ?");
            $st->execute([$billId]);
            $b = $st->fetch();

            if ($b) {
                $balance = max(0, (float)$b['net_amount'] - (float)$b['received_amount']);
                $settleAmt = !empty($_POST['settle_amount']) ? min($balance, (float)$_POST['settle_amount']) : $balance;
                if ($settleAmt <= 0) $settleAmt = $balance;

                $newRcv = (float)$b['received_amount'] + $settleAmt;
                $newStatus = ($newRcv >= (float)$b['net_amount'] - 0.01) ? 'paid' : 'partial';

                $upd = $pdo->prepare("UPDATE rough_sales SET payment_status = ?, received_amount = ?, received_date = ? WHERE id = ?");
                $upd->execute([$newStatus, $newRcv, $paidDate, $billId]);

                if ($bankAccId && $settleAmt > 0) {
                    record_account_transaction($bankAccId, 'credit', $settleAmt, 'rough_sale', $billId, $b['invoice_no'], $paidDate, "Rough sale collection for {$b['invoice_no']}", $user['id']);
                }
                update_party_balance((int)$b['party_id'], -$settleAmt);
            }
        } elseif ($billType === 'polish_sale') {
            $st = $pdo->prepare("SELECT net_amount, received_amount, invoice_no, party_id FROM polish_sales WHERE id = ?");
            $st->execute([$billId]);
            $b = $st->fetch();

            if ($b) {
                $balance = max(0, (float)$b['net_amount'] - (float)$b['received_amount']);
                $settleAmt = !empty($_POST['settle_amount']) ? min($balance, (float)$_POST['settle_amount']) : $balance;
                if ($settleAmt <= 0) $settleAmt = $balance;

                $newRcv = (float)$b['received_amount'] + $settleAmt;
                $newStatus = ($newRcv >= (float)$b['net_amount'] - 0.01) ? 'paid' : 'partial';

                $upd = $pdo->prepare("UPDATE polish_sales SET payment_status = ?, received_amount = ?, received_date = ? WHERE id = ?");
                $upd->execute([$newStatus, $newRcv, $paidDate, $billId]);

                if ($bankAccId && $settleAmt > 0) {
                    record_account_transaction($bankAccId, 'credit', $settleAmt, 'polish_sale', $billId, $b['invoice_no'], $paidDate, "Polish diamond sale collection for {$b['invoice_no']}", $user['id']);
                }
                update_party_balance((int)$b['party_id'], -$settleAmt);
            }
        }

        $pdo->commit();
        set_flash('success', 'ચુકવણી સફળતાપૂર્વક સેટલ થઈ ગઈ છે (Payment / Collection Recorded).');
        header('Location: ' . BASE_URL . '/modules/finance/schedule.php');
        exit;
    } catch (\Throwable $ex) {
        $pdo->rollBack();
        set_flash('error', 'ચુકવણી સેટલ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
}

$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

// Payables Schedule Query (માત્ર ખરીદી અને ચુકવણી દેવું)
$filter = trim($_GET['filter'] ?? 'pending');
$currFilter = trim($_GET['curr'] ?? 'all');
if (!in_array($currFilter, ['all', 'inr', 'usd'], true)) {
    $currFilter = 'all';
}

$query = "
    (SELECT 'rough_purchase' as bill_type, 'Rough Purchase' as category, id, purchase_no as doc_no, purchase_date as bill_date,
            party_id, net_payment as total_amt, paid_amount, (net_payment - paid_amount) as balance,
            due_days, due_date, party_payment_status as status, chukvya_date, 'payable' as direction,
            payment_currency, dollar_per_carat, dollar_rate, weight,
            ROUND(COALESCE(weight, 0) * COALESCE(dollar_per_carat, 0), 2) as dollar_total
     FROM rough_purchases WHERE status != 'merged')
    UNION ALL
    (SELECT 'polish_purchase' as bill_type, 'Polish Purchase' as category, id, purchase_no as doc_no, purchase_date as bill_date,
            party_id, net_payment as total_amt, paid_amount, (net_payment - paid_amount) as balance,
            due_days, due_date, payment_status as status, chukvya_date, 'payable' as direction,
            payment_currency, dollar_per_carat, dollar_rate, weight,
            ROUND(COALESCE(weight, 0) * COALESCE(dollar_per_carat, 0), 2) as dollar_total
     FROM polish_purchases)
";

// 1. Calculate Summary Totals for Payables Only (INR દેવું & Dollar દેવું)
$summaryWhere = " WHERE 1=1";
if ($filter === 'pending') {
    $summaryWhere .= " AND s.status != 'paid'";
} elseif ($filter === 'paid') {
    $summaryWhere .= " AND s.status = 'paid'";
} elseif ($filter === 'overdue') {
    $summaryWhere .= " AND s.status != 'paid' AND s.due_date < CURDATE()";
} elseif ($filter === 'due7') {
    $summaryWhere .= " AND s.status != 'paid' AND s.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}

$summaryBills = $pdo->query("SELECT s.* FROM ($query) s $summaryWhere")->fetchAll();

$totPayableInr = 0.00;
$totPayableUsd = 0.00;
$standDollarPayableCount = 0;
$overdueCount = 0;

foreach ($summaryBills as $b) {
    $isStand = is_stand_dollar($b);
    $dollarTotal = (float)($b['dollar_total'] ?? 0);

    if ($isStand) {
        $totPayableUsd += $dollarTotal;
        $standDollarPayableCount++;
    } else {
        $totPayableInr += (float)$b['balance'];
    }

    if ($b['status'] !== 'paid' && !empty($b['due_date']) && strtotime($b['due_date']) < strtotime('today')) {
        $overdueCount++;
    }
}

// 2. Query for filtered table list
$where = " WHERE 1=1";
if ($filter === 'pending') {
    $where .= " AND s.status != 'paid'";
} elseif ($filter === 'paid') {
    $where .= " AND s.status = 'paid'";
} elseif ($filter === 'overdue') {
    $where .= " AND s.status != 'paid' AND s.due_date < CURDATE()";
} elseif ($filter === 'due7') {
    $where .= " AND s.status != 'paid' AND s.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}

if ($currFilter === 'inr') {
    $where .= " AND NOT ((s.payment_currency = 'USD' OR COALESCE(s.dollar_per_carat, 0) > 0) AND COALESCE(s.dollar_rate, 0) <= 0)";
} elseif ($currFilter === 'usd') {
    $where .= " AND ((s.payment_currency = 'USD' OR COALESCE(s.dollar_per_carat, 0) > 0) AND COALESCE(s.dollar_rate, 0) <= 0)";
}

$finalSql = "SELECT s.*, p.party_name, p.phone, p.city 
             FROM ($query) s
             JOIN parties p ON s.party_id = p.id
             $where
             ORDER BY s.due_date ASC";

$bills = $pdo->query($finalSql)->fetchAll();

$pageTitle = 'ચુકવણી મુદત Schedule (Payables Schedule)';
require_once __DIR__ . '/../../includes/header.php';

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
$bankCount = (int)$pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE status = 'active'")->fetchColumn();
?>


<?= render_finance_tabs('schedule') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'ચુકવણી મુદત Schedule (Payables Schedule)',
    'સપ્લાયરોને ચૂકવવાપાત્ર રકમ અને Stand Dollar દેવાનો સચોટ હિસાબ',
    'calendar-days',
    '
    <a href="' . BASE_URL . '/modules/reports/export.php?type=payment_schedule&direction=payable' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm fw-semibold">
        ' . svg_icon('file-excel', 'me-1') . 'Excel માં Export
    </a>
    <button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ Schedule
    </button>
    '
) ?>

<!-- Donezo Metric Stat Cards: ONLY INR દેવું & Dollar દેવું -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-6">
        <?= render_stat_card(
            'ચૂકવવાપાત્ર દેવું (INR ₹)', 
            format_inr($totPayableInr), 
            'INR ખરીદી', 
            'રૂપિયામાં કુલ પાકતી ચુકવણી', 
            $currFilter !== 'usd' ? 'rose' : 'white', 
            '?filter=' . urlencode($filter) . '&curr=inr', 
            'pill-coral', 
            'clock-rotate-left'
        ) ?>
    </div>
    <div class="col-12 col-md-6">
        <?= render_stat_card(
            'Stand Dollar દેવું ($ USD)', 
            '$' . number_format($totPayableUsd, 2), 
            $standDollarPayableCount . ' લૉટ્સ દર બાકી', 
            'ડોલર રેટ નક્કી કરવા બાકી', 
            $currFilter === 'usd' ? 'amber' : 'white', 
            '?filter=' . urlencode($filter) . '&curr=usd', 
            'pill-amber', 
            'dollar-sign'
        ) ?>
    </div>
</div>

<!-- Filter Tabs: Status & Currency Separator -->
<div class="erp-filter-card mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="small fw-bold text-muted me-1"><?= svg_icon('filter', 'me-1') ?>મુદત:</span>
            <a href="?filter=pending&curr=<?= urlencode($currFilter) ?>" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= svg_icon('hourglass-start', 'me-1') ?>Pending Bills
            </a>
            <a href="?filter=overdue&curr=<?= urlencode($currFilter) ?>" class="btn btn-sm <?= $filter === 'overdue' ? 'btn-danger' : 'btn-outline-danger' ?>">
                <?= svg_icon('triangle-exclamation', 'me-1') ?>ઓવરડ્યુ (Overdue)
            </a>
            <a href="?filter=due7&curr=<?= urlencode($currFilter) ?>" class="btn btn-sm <?= $filter === 'due7' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">
                <?= svg_icon('clock', 'me-1') ?>૭ દિવસમાં Due
            </a>
            <a href="?filter=paid&curr=<?= urlencode($currFilter) ?>" class="btn btn-sm <?= $filter === 'paid' ? 'btn-success text-white' : 'btn-outline-success' ?>">
                <?= svg_icon('check', 'me-1') ?>ચૂકવેલ (Paid)
            </a>
            <a href="?filter=all&curr=<?= urlencode($currFilter) ?>" class="btn btn-sm <?= $filter === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>">
                બધા (All Status)
            </a>
        </div>

        <div class="d-flex flex-wrap gap-1 align-items-center border-start ps-md-3 mt-2 mt-md-0">
            <span class="small fw-bold text-muted me-1"><?= svg_icon('coins', 'me-1 text-primary') ?>કરન્સી હિસાબ:</span>
            <a href="?filter=<?= urlencode($filter) ?>&curr=all" class="btn btn-sm <?= $currFilter === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>">
                બંને હિસાબ (All)
            </a>
            <a href="?filter=<?= urlencode($filter) ?>&curr=inr" class="btn btn-sm <?= $currFilter === 'inr' ? 'btn-primary' : 'btn-outline-primary' ?>">
                🇮🇳 માત્ર Rupee (₹) દેવું
            </a>
            <a href="?filter=<?= urlencode($filter) ?>&curr=usd" class="btn btn-sm <?= $currFilter === 'usd' ? 'btn-danger text-white' : 'btn-outline-danger' ?>">
                <span class="stand-dollar-dot"></span> માત્ર Stand Dollar ($) દેવું
            </a>
        </div>
    </div>
</div>

<!-- Schedule Table -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>Due Date (પાકતી તારીખ)</th>
                        <th>ખરીદી પ્રકાર</th>
                        <th>બિલ નં</th>
                        <th>સપ્લાયર / પાર્ટી</th>
                        <th>કરન્સી</th>
                        <th class="text-end">કુલ રકમ</th>
                        <th class="text-end">બાકી રકમ (Balance)</th>
                        <th>સ્થિતિ / મુદત</th>
                        <th class="text-end">ચુકવણી એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ બિલો મળ્યા નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bills as $b): 
                            $isStand = is_stand_dollar($b);
                            $dollarTotal = (float)($b['dollar_total'] ?? 0);
                        ?>
                            <tr>
                                <td>
                                    <strong class="fs-6 text-dark"><?= format_date($b['due_date']) ?></strong>
                                    <div class="small text-muted"><?= (int)$b['due_days'] ?> Days Terms</div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e($b['category']) ?></span>
                                </td>
                                <td class="font-mono text-nowrap">
                                    <?= render_stand_dollar_indicator($b) ?>
                                    <strong><?= e($b['doc_no']) ?></strong>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $b['party_id'] ?>&tab=<?= str_starts_with($b['bill_type'], 'rough_purchase') ? 'rough' : (str_starts_with($b['bill_type'], 'polish_purchase') ? 'polish' : 'sales') ?>" class="fw-semibold text-primary text-decoration-none" title="પાર્ટી લેજર જુઓ">
                                        <?= e($b['party_name']) ?>
                                    </a>
                                    <small class="text-muted d-block"><?= e($b['city']) ?></small>
                                </td>

                                <td>
                                    <?php if ($isStand): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle font-mono"><span class="stand-dollar-dot me-1"></span>Stand Dollar ($)</span>
                                    <?php elseif (($b['payment_currency'] ?? '') === 'USD' || (float)$b['dollar_rate'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border font-mono">USD ($ Locked)</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border font-mono">INR (₹)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end font-mono">
                                    <?php if ($isStand): ?>
                                        <strong class="text-danger fs-6">$<?= number_format($dollarTotal, 2) ?></strong>
                                        <div class="small text-muted font-mono"><?= (float)$b['weight'] ?> ct @ $<?= number_format((float)$b['dollar_per_carat'], 2) ?></div>
                                    <?php else: ?>
                                        <strong class="text-dark fs-6"><?= format_inr($b['total_amt']) ?></strong>
                                        <?php if ((float)$b['dollar_rate'] > 0 && (float)$b['dollar_per_carat'] > 0): ?>
                                            <div class="small text-muted font-mono">$<?= number_format($dollarTotal, 2) ?> @ ₹<?= number_format((float)$b['dollar_rate'], 2) ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end font-mono">
                                    <?php if ($isStand): ?>
                                        <strong class="fs-6 text-danger">$<?= number_format($dollarTotal, 2) ?></strong>
                                        <div class="small text-danger fw-semibold">ડોલર રેટ બાકી</div>
                                    <?php else: ?>
                                        <strong class="fs-6 <?= $b['direction'] === 'payable' ? 'text-danger' : 'text-success' ?>">
                                            <?= format_inr($b['balance']) ?>
                                        </strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= get_due_badge($b['due_date'], $b['status']) ?>
                                    <?php if ($b['status'] === 'paid' && $b['chukvya_date']): ?>
                                        <div class="small text-muted mt-1">તારીખ: <?= format_date($b['chukvya_date']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($isStand): ?>
                                        <?php 
                                            $editUrl = match($b['bill_type']) {
                                                'rough_purchase' => BASE_URL . '/modules/rough_purchase/edit.php?id=' . $b['id'],
                                                'polish_purchase' => BASE_URL . '/modules/polish_purchase/edit.php?id=' . $b['id'],
                                                'rough_sale' => BASE_URL . '/modules/rough_sales/edit.php?id=' . $b['id'],
                                                'polish_sale' => BASE_URL . '/modules/polish_sales/edit.php?id=' . $b['id'],
                                                default => '#'
                                            };
                                        ?>
                                        <a href="<?= $editUrl ?>" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1 shadow-sm px-2.5 py-1" title="ડોલર રેટ નક્કી કરી લોક કરો">
                                            <?= svg_icon('pen-to-square', '', 12) ?>
                                            <span>રેટ નક્કી કરો</span>
                                        </a>
                                    <?php elseif ($b['status'] !== 'paid'): ?>
                                        <?php if ($b['direction'] === 'receivable'): ?>
                                            <button class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 shadow-sm px-2.5 py-1" data-bs-toggle="modal" data-bs-target="#payModal<?= $b['bill_type'] . $b['id'] ?>">
                                                <?= svg_icon('arrow-down-left', '', 12) ?>
                                                <span>જમા લો</span>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1 shadow-sm px-2.5 py-1" data-bs-toggle="modal" data-bs-target="#payModal<?= $b['bill_type'] . $b['id'] ?>">
                                                <?= svg_icon('check', '', 12) ?>
                                                <span>ચૂકવો</span>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Quick Settle Modal -->
                                        <div class="modal fade" id="payModal<?= $b['bill_type'] . $b['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered text-start">
                                                <div class="modal-content">
                                                    <form action="<?= BASE_URL ?>/modules/finance/schedule.php" method="POST">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="settle_payment">
                                                        <input type="hidden" name="bill_type" value="<?= e($b['bill_type']) ?>">
                                                        <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">

                                                        <div class="modal-header <?= $b['direction'] === 'receivable' ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                                            <h6 class="modal-title fw-bold">
                                                                <?= $b['direction'] === 'receivable' ? svg_icon('arrow-down-left', 'me-2') . 'વસૂલાત સેટલમેન્ટ (Collection): ' : svg_icon('check', 'me-2') . 'ચુકવણી સેટલમેન્ટ (Payment): ' ?><?= e($b['doc_no']) ?>
                                                            </h6>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="p-2.5 bg-light rounded-3 mb-3 border">
                                                                <div>પાર્ટીનું નામ: <strong><?= e($b['party_name']) ?></strong></div>
                                                                <div>કુલ બાકી રકમ: <strong class="<?= $b['direction'] === 'receivable' ? 'text-success' : 'text-danger' ?>"><?= format_inr($b['balance']) ?></strong></div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted"><?= $b['direction'] === 'receivable' ? 'જમા લેવાની રકમ (Collection Amount INR) *' : 'સેટલ કરવાની રકમ (Settle Amount INR) *' ?></label>
                                                                <input type="number" step="0.01" name="settle_amount" class="form-control fw-bold <?= $b['direction'] === 'receivable' ? 'text-success' : 'text-danger' ?>" value="<?= (float)$b['balance'] ?>" max="<?= (float)$b['balance'] ?>" required>
                                                                <small class="text-muted">પૂરેપૂરી રકમ અથવા અંશતઃ (Partial) રકમ દાખલ કરો.</small>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted"><?= $b['direction'] === 'receivable' ? 'જમા તારીખ (Received Date) *' : 'ચુકવણી તારીખ (Paid Date) *' ?></label>
                                                                <input type="date" name="chukvya_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted"><?= $b['direction'] === 'receivable' ? 'કયા Bank / Cash ખાતામાં રકમ જમા થઈ?' : 'કયા Bank / Cash ખાતામાંથી ચુકવણી કરવી?' ?></label>
                                                                <select name="bank_account_id" class="form-select no-search" required>
                                                                    <option value="">ખાતું પસંદ કરો...</option>
                                                                    <?php foreach ($bankAccounts as $acc): ?>
                                                                        <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                            <button type="submit" class="btn <?= $b['direction'] === 'receivable' ? 'btn-success' : 'btn-danger' ?> btn-sm fw-bold">
                                                                <?= $b['direction'] === 'receivable' ? svg_icon('arrow-down-left', 'me-1') . 'રકમ જમા લો (Confirm Collection)' : svg_icon('check', 'me-1') . 'ચુકવણી જમા કરો (Confirm Payment)' ?>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success"><?= svg_icon('check', 'me-1') ?>Settled</span>
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
