<?php
/**
 * Finance Overview (સેક્શન ૬.૮)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'નાણાકીય હિસાબ (Finance Overview)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// 1. Payables (ચૂકવવાના)
$roughPayableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$roughPayableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$polishPayableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) FROM polish_purchases WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$polishPayableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM polish_purchases WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$brokeragePayable = (float)$pdo->query("SELECT 
    (SELECT COALESCE(SUM(brokerage_amount - brokerage_paid_amount), 0) FROM rough_purchases WHERE brokerage_payment_status != 'paid' AND status != 'merged') +
    (SELECT COALESCE(SUM(brokerage_amount), 0) FROM polish_purchases WHERE brokerage_payment_status != 'paid')
")->fetchColumn();
$outsourcePayable = (float)$pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM outsource_bills WHERE payment_status != 'paid'")->fetchColumn();

$totalPayableInr = $roughPayableInr + $polishPayableInr + $brokeragePayable + $outsourcePayable;
$totalPayableUsd = $roughPayableUsd + $polishPayableUsd;

// 2. Receivables (વસૂલવાપાત્ર / લેવાના)
$roughReceivableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_amount - received_amount), 0) FROM rough_sales WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$roughReceivableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM rough_sales WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$polishReceivableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_amount - received_amount), 0) FROM polish_sales WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$polishReceivableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM polish_sales WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$totalReceivableInr = $roughReceivableInr + $polishReceivableInr;
$totalReceivableUsd = $roughReceivableUsd + $polishReceivableUsd;

// 3. Bank & Cash Accounts
$accounts = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY account_type ASC, id ASC")->fetchAll();
$totalBankCash = get_total_company_balance();

$netPosition = ($totalBankCash + $totalReceivableInr) - $totalPayableInr;

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
?>


<?= render_finance_tabs('overview') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'નાણાકીય હિસાબ અને બેલેન્સ (Finance Overview)',
    '',
    'scale-balanced',
    '
    <a href="' . BASE_URL . '/modules/finance/schedule.php" class="btn btn-primary px-3 shadow-sm">
        ' . svg_icon('calendar-days', 'me-1') . 'મુદત Schedule જુઓ
    </a>
    <a href="' . BASE_URL . '/modules/finance/accounts.php" class="btn btn-outline-success px-3 shadow-sm">
        ' . svg_icon('plus', 'me-1') . 'ખાતું ઉમેરો
    </a>
    '
) ?>

<!-- 4 Donezo Master Finance Metrics -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ ચૂકવવાના (Payables)', 
            format_inr($totalPayableInr), 
            $totalPayableUsd > 0 ? '+ $' . number_format($totalPayableUsd, 2) . ' Stand $' : 'ખરીદી & દલાલી', 
            'રૂપિયામાં કુલ પાકતી ચુકવણી', 
            'white', 
            BASE_URL . '/modules/finance/schedule.php', 
            'pill-coral', 
            'arrow-trend-down'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ લેવાના (વસૂલવાપાત્ર)', 
            format_inr($totalReceivableInr), 
            $totalReceivableUsd > 0 ? '+ $' . number_format($totalReceivableUsd, 2) . ' Stand $' : 'વેચાણ લેણાં', 
            'ગ્રાહક પાસેથી કુલ ઉઘરાણી', 
            'white', 
            BASE_URL . '/modules/finance/receivables.php', 
            'pill-mint', 
            'arrow-trend-up'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('કંપની કુલ બેલેન્સ (Liquidity)', format_inr($totalBankCash), count($accounts) . ' ખાતાઓ', 'સુરત & મુંબઈ', 'hero', BASE_URL . '/modules/finance/accounts.php', '', 'vault') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('ચોખ્ખી નાણાકીય સ્થિતિ (Net)', format_inr($netPosition), 'નેટ વેલ્યુ', 'કેશ + લેણાં - દેવાં', 'white', null, $netPosition >= 0 ? 'pill-mint' : 'pill-coral', 'scale-balanced') ?>
    </div>
</div>

<!-- Breakdown Rows: Payables Breakdown & Receivables Breakdown Side-by-Side -->
<div class="row g-3 mb-4">
    <!-- Payables Detailed Breakdown -->
    <div class="col-12 col-lg-6">
        <div class="card-erp h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="text-danger fw-bold"><?= svg_icon('arrow-trend-down', 'me-2') ?>ચૂકવવાના હિસાબની વિગત (Payables Breakdown)</span>
                <div class="d-flex align-items-center gap-1">
                    <?php if ($totalPayableUsd > 0): ?>
                        <span class="badge bg-danger-subtle text-danger border"><span class="stand-dollar-dot"></span> $<?= number_format($totalPayableUsd, 2) ?></span>
                    <?php endif; ?>
                    <span class="badge bg-danger text-white"><?= format_inr($totalPayableInr) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <tbody>
                            <tr>
                                <td><?= svg_icon('layer-group', 'text-primary me-2') ?>Rough ખરીદીના બાકી:</td>
                                <td class="text-end font-mono">
                                    <span class="fw-bold text-danger"><?= format_inr($roughPayableInr) ?></span>
                                    <?php if ($roughPayableUsd > 0): ?>
                                        <div class="small text-danger fw-semibold"><span class="stand-dollar-dot"></span> $<?= number_format($roughPayableUsd, 2) ?> USD</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= svg_icon('gem', 'text-success me-2') ?>Polish ખરીદીના બાકી:</td>
                                <td class="text-end font-mono">
                                    <span class="fw-bold text-danger"><?= format_inr($polishPayableInr) ?></span>
                                    <?php if ($polishPayableUsd > 0): ?>
                                        <div class="small text-danger fw-semibold"><span class="stand-dollar-dot"></span> $<?= number_format($polishPayableUsd, 2) ?> USD</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= svg_icon('percent', 'text-warning me-2') ?>દલાલી ચૂકવવાની બાકી (Brokerage):</td>
                                <td class="text-end fw-bold text-danger font-mono"><?= format_inr($brokeragePayable) ?></td>
                            </tr>
                            <tr>
                                <td><?= svg_icon('industry', 'text-secondary me-2') ?>Galaxy, LS અને કારખાનું Outsource:</td>
                                <td class="text-end fw-bold text-danger font-mono"><?= format_inr($outsourcePayable) ?></td>
                            </tr>
                            <tr class="table-light">
                                <td class="fw-bold">કુલ ચૂકવવાપાત્ર રકમ:</td>
                                <td class="text-end font-mono">
                                    <div class="fw-bold text-danger fs-6"><?= format_inr($totalPayableInr) ?></div>
                                    <?php if ($totalPayableUsd > 0): ?>
                                        <div class="small text-danger fw-bold"><span class="stand-dollar-dot"></span> $<?= number_format($totalPayableUsd, 2) ?> Stand $</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light text-end py-2">
                <a href="<?= BASE_URL ?>/modules/finance/schedule.php" class="btn btn-sm btn-outline-danger">
                    <?= svg_icon('arrow-trend-down', 'me-1') ?>ચુકવણી Schedule જુઓ →
                </a>
            </div>
        </div>
    </div>

    <!-- Receivables Detailed Breakdown (વસૂલવાપાત્ર) -->
    <div class="col-12 col-lg-6">
        <div class="card-erp h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="text-success fw-bold"><?= svg_icon('arrow-trend-up', 'me-2') ?>વસૂલવાપાત્ર હિસાબની વિગત (Receivables Breakdown)</span>
                <div class="d-flex align-items-center gap-1">
                    <?php if ($totalReceivableUsd > 0): ?>
                        <span class="badge bg-success-subtle text-success border"><span class="stand-dollar-dot"></span> $<?= number_format($totalReceivableUsd, 2) ?></span>
                    <?php endif; ?>
                    <span class="badge bg-success text-white"><?= format_inr($totalReceivableInr) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <tbody>
                            <tr>
                                <td><?= svg_icon('shapes', 'text-primary me-2') ?>Rough વેચાણના લેણાં (Rough Sales):</td>
                                <td class="text-end font-mono">
                                    <span class="fw-bold text-success"><?= format_inr($roughReceivableInr) ?></span>
                                    <?php if ($roughReceivableUsd > 0): ?>
                                        <div class="small text-success fw-semibold"><span class="stand-dollar-dot"></span> $<?= number_format($roughReceivableUsd, 2) ?> USD</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= svg_icon('gem', 'text-emerald me-2') ?>Polish વેચાણના લેણાં (Polish Sales):</td>
                                <td class="text-end font-mono">
                                    <span class="fw-bold text-success"><?= format_inr($polishReceivableInr) ?></span>
                                    <?php if ($polishReceivableUsd > 0): ?>
                                        <div class="small text-success fw-semibold"><span class="stand-dollar-dot"></span> $<?= number_format($polishReceivableUsd, 2) ?> USD</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="table-light">
                                <td class="fw-bold">કુલ વસૂલવાપાત્ર રકમ:</td>
                                <td class="text-end font-mono">
                                    <div class="fw-bold text-success fs-6"><?= format_inr($totalReceivableInr) ?></div>
                                    <?php if ($totalReceivableUsd > 0): ?>
                                        <div class="small text-success fw-bold"><span class="stand-dollar-dot"></span> $<?= number_format($totalReceivableUsd, 2) ?> Stand $</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light text-end py-2">
                <a href="<?= BASE_URL ?>/modules/finance/receivables.php" class="btn btn-sm btn-outline-success">
                    <?= svg_icon('arrow-trend-up', 'me-1') ?>વસૂલાત Schedule જુઓ →
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Bank and Cash Accounts Overview Row -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card-erp">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="text-primary fw-bold"><?= svg_icon('building-columns', 'me-2') ?>Bank અને Cash ખાતાઓ (સેક્શન ૩ & ૬.૮)</span>
                <a href="<?= BASE_URL ?>/modules/finance/accounts.php" class="btn btn-sm btn-outline-primary"><?= svg_icon('plus', 'me-1') ?>ખાતું ઉમેરો</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ખાતાનું નામ</th>
                                <th>પ્રકાર</th>
                                <th class="text-end">હાલનું બેલેન્સ (INR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $acc): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($acc['account_name']) ?></strong>
                                        <div class="small text-muted"><?= e($acc['bank_name'] ?? 'Cash Counter') ?><?= !empty($acc['account_number']) ? ' (' . e($acc['account_number']) . ')' : '' ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $acc['account_type'] === 'bank' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?> border text-uppercase">
                                            <?= e($acc['account_type']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold text-success fs-6 font-mono"><?= format_inr($acc['current_balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light">
                                <td colspan="2" class="fw-bold fs-6">કંપની કુલ બેલેન્સ (Total Balance):</td>
                                <td class="text-end fw-bold text-success fs-5 font-mono"><?= format_inr($totalBankCash) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
