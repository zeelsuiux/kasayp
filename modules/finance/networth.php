<?php
/**
 * Net Worth Statement (કંપની નેટવર્થ અને નાણાકીય મૂડી)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'નેટવર્થ (Company Net Worth Statement)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// 1. Bank & Cash Liquidity (રોકડ અને બેંક શિલક)
$accounts = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY account_type ASC, id ASC")->fetchAll();
$totalBankCash = get_total_company_balance();

// 2. Receivables (લેણાં / ઉઘરાણી)
$roughReceivableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_amount - received_amount), 0) FROM rough_sales WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$roughReceivableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM rough_sales WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$polishReceivableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_amount - received_amount), 0) FROM polish_sales WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$polishReceivableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM polish_sales WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$totalReceivablesInr = $roughReceivableInr + $polishReceivableInr;
$totalReceivablesUsd = $roughReceivableUsd + $polishReceivableUsd;

// 3. Stock Valuations (હાજર માલ સ્ટોક મૂલ્યાંકન)
$roughStockData = $pdo->query("SELECT COALESCE(SUM(available_weight), 0) as wt, COALESCE(SUM(available_weight * price_per_carat_inr), 0) as val FROM rough_purchases WHERE status = 'in_stock' AND available_weight > 0")->fetch();
$roughStockWt = (float)($roughStockData['wt'] ?? 0);
$roughStockVal = (float)($roughStockData['val'] ?? 0);

$polishStockData = $pdo->query("SELECT COALESCE(SUM(available_weight), 0) as wt, COALESCE(SUM(available_weight * (CASE WHEN cost_per_carat > 0 THEN cost_per_carat ELSE sale_price_per_carat END)), 0) as val FROM polish_stocks WHERE status IN ('available', 'partially_on_jangad', 'in_stock') AND available_weight > 0")->fetch();
$polishStockWt = (float)($polishStockData['wt'] ?? 0);
$polishStockVal = (float)($polishStockData['val'] ?? 0);

$kapanInProcessData = $pdo->query("SELECT COALESCE(SUM(total_weight), 0) as wt, COALESCE(SUM(total_cost), 0) as val FROM kapans WHERE status = 'in_process'")->fetch();
$kapanStockWt = (float)($kapanInProcessData['wt'] ?? 0);
$kapanStockVal = (float)($kapanInProcessData['val'] ?? 0);

$totalStockVal = $roughStockVal + $polishStockVal + $kapanStockVal;
$totalStockWt  = $roughStockWt + $polishStockWt + $kapanStockWt;

// Total Assets (કુલ અસ્કયામતો)
$totalAssetsInr = $totalBankCash + $totalReceivablesInr + $totalStockVal;

// 4. Liabilities / Payables (દેવાં / ચૂકવવાના)
$roughPayableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$roughPayableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$polishPayableInr = (float)$pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) FROM polish_purchases WHERE payment_status != 'paid' AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();
$polishPayableUsd = (float)$pdo->query("SELECT COALESCE(SUM(ROUND(weight * dollar_per_carat, 2)), 0) FROM polish_purchases WHERE payment_status != 'paid' AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)")->fetchColumn();

$brokeragePayable = (float)$pdo->query("SELECT 
    (SELECT COALESCE(SUM(brokerage_amount - brokerage_paid_amount), 0) FROM rough_purchases WHERE brokerage_payment_status != 'paid' AND status != 'merged') +
    (SELECT COALESCE(SUM(brokerage_amount), 0) FROM polish_purchases WHERE brokerage_payment_status != 'paid')
")->fetchColumn();
$outsourcePayable = (float)$pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM outsource_bills WHERE payment_status != 'paid'")->fetchColumn();

$totalLiabilitiesInr = $roughPayableInr + $polishPayableInr + $brokeragePayable + $outsourcePayable;
$totalLiabilitiesUsd = $roughPayableUsd + $polishPayableUsd;

// Net Worth (નેટવર્થ)
$netWorthInr = $totalAssetsInr - $totalLiabilitiesInr;

$duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
$dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
?>


<?= render_finance_tabs('networth') ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'કંપની નેટવર્થ સ્ટેટમેન્ટ (Company Net Worth)',
    'શિલક + લેણાં + હાજર સ્ટોક - કુલ દેવાં',
    'scale-balanced',
    '
    <a href="' . BASE_URL . '/modules/finance/index.php" class="btn btn-outline-secondary px-3">
        ' . svg_icon('chart-pie', 'me-1') . 'Finance Overview
    </a>
    <a href="' . BASE_URL . '/modules/finance/transactions.php" class="btn btn-primary px-3 shadow-sm">
        ' . svg_icon('book-bookmark', 'me-1') . 'રોજમેળ (Transactions)
    </a>
    ',
    'green'
) ?>

<!-- Top Net Worth Master Card -->
<div class="p-4 mb-4" style="background: linear-gradient(135deg, #064e3b 0%, #065f46 60%, #047857 100%) !important; border: none !important; border-radius: 18px !important; color: #ffffff !important; box-shadow: 0 10px 25px -5px rgba(6, 78, 59, 0.3) !important;">
    <div class="row align-items-center">
        <div class="col-12 col-md-7">
            <span class="badge font-mono px-3 py-1.5 rounded-pill mb-2 d-inline-flex align-items-center" style="background: rgba(255, 255, 255, 0.18) !important; color: #ffffff !important; border: 1px solid rgba(255, 255, 255, 0.3) !important; font-size: 0.82rem; font-weight: 500;">
                <?= svg_icon('scale-balanced', 'me-1.5', 14) ?><span>સંપૂર્ણ નાણાકીય મૂડી (Total Net Worth)</span>
            </span>
            <h1 class="display-5 fw-bold font-mono mb-1 text-white">
                <?= format_inr($netWorthInr) ?>
            </h1>
            <p class="mb-0 small" style="color: rgba(255, 255, 255, 0.8) !important;">
                કુલ અસ્કયામતો (<?= format_inr($totalAssetsInr) ?>) માંથી કુલ જવાબદારીઓ (<?= format_inr($totalLiabilitiesInr) ?>) બાદ કર્યા પછીની વાસ્તવિક મૂડી.
            </p>
        </div>
        <div class="col-12 col-md-5 mt-3 mt-md-0">
            <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.12); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.18);">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                    <span class="small" style="color: rgba(255, 255, 255, 0.8);">કુલ અસ્કયામતો (Assets):</span>
                    <strong class="font-mono" style="color: #ffffff;"><?= format_inr($totalAssetsInr) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                    <span class="small" style="color: rgba(255, 255, 255, 0.8);">કુલ દેવાં (Liabilities):</span>
                    <strong class="font-mono" style="color: #fef08a;"><?= format_inr($totalLiabilitiesInr) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small" style="color: rgba(255, 255, 255, 0.8);">ચોખ્ખી સ્થિતિ (Net):</span>
                    <strong class="font-mono fs-6" style="color: #ffffff;"><?= $netWorthInr >= 0 ? '+' : '' ?><?= format_inr($netWorthInr) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4 Key Balance Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            '૧. બેંક & કેશ શિલક (Liquidity)', 
            format_inr($totalBankCash), 
            count($accounts) . ' ખાતાઓ સક્રિય', 
            'સુરત & મુંબઈ રોકડ બેલેન્સ', 
            'hero', 
            BASE_URL . '/modules/finance/accounts.php', 
            '', 
            'vault'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            '૨. કુલ લેણાં (Receivables)', 
            format_inr($totalReceivablesInr), 
            $totalReceivablesUsd > 0 ? '+ $' . number_format($totalReceivablesUsd, 2) : 'ગ્રાહક ઉઘરાણી', 
            'રફ & પોલિશ વેચાણ લેણાં', 
            'white', 
            BASE_URL . '/modules/finance/receivables.php', 
            'pill-mint', 
            'hand-holding-dollar'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            '૩. હાજર સ્ટોક વેલ્યુ (Inventory)', 
            format_inr($totalStockVal), 
            format_carat($totalStockWt), 
            'રફ, પોલિશ & કાપન સ્ટોક', 
            'white', 
            BASE_URL . '/modules/polish/index.php', 
            'pill-blue', 
            'gem'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            '૪. કુલ દેવાં (Payables)', 
            format_inr($totalLiabilitiesInr), 
            $totalLiabilitiesUsd > 0 ? '+ $' . number_format($totalLiabilitiesUsd, 2) : 'ચૂકવવાના', 
            'ખરીદી, દલાલી & કારખાના બિલ', 
            'white', 
            BASE_URL . '/modules/finance/schedule.php', 
            'pill-coral', 
            'arrow-trend-down'
        ) ?>
    </div>
</div>

<!-- Detailed Split: Assets vs Liabilities -->
<div class="row g-4">
    <!-- Assets Column -->
    <div class="col-12 col-lg-6">
        <div class="card-erp h-100 shadow-sm border">
            <div class="card-header bg-success-subtle py-3 px-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-success-emphasis fs-6">
                    <?= svg_icon('arrow-trend-up', 'me-2') ?>અસ્કયામતોની વિગત (Assets Breakdown)
                </span>
                <span class="badge bg-success font-mono fs-6"><?= format_inr($totalAssetsInr) ?></span>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush font-mono">
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('building-columns', 'text-primary me-2') ?>બેંક અને રોકડ ખાતાઓ (Bank & Cash)</div>
                            <small class="text-muted font-sans"><?= count($accounts) ?> ખાતાઓ (સુરત + મુંબઈ)</small>
                        </div>
                        <strong class="text-dark fs-6"><?= format_inr($totalBankCash) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('hand-holding-dollar', 'text-success me-2') ?>રફ વેચાણ લેણાં (Rough Receivables)</div>
                            <small class="text-muted font-sans"><?= $roughReceivableUsd > 0 ? '+ $' . number_format($roughReceivableUsd, 2) : 'બાકી ઉઘરાણી' ?></small>
                        </div>
                        <strong class="text-success"><?= format_inr($roughReceivableInr) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('gem', 'text-info me-2') ?>પોલિશ વેચાણ લેણાં (Polish Receivables)</div>
                            <small class="text-muted font-sans"><?= $polishReceivableUsd > 0 ? '+ $' . number_format($polishReceivableUsd, 2) : 'બાકી ઉઘરાણી' ?></small>
                        </div>
                        <strong class="text-success"><?= format_inr($polishReceivableInr) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('shapes', 'text-primary me-2') ?>હાજર રફ સ્ટોક (Rough Available Stock)</div>
                            <small class="text-muted font-sans"><?= format_carat($roughStockWt) ?></small>
                        </div>
                        <strong class="text-dark"><?= format_inr($roughStockVal) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('gem', 'text-warning me-2') ?>હાજર પોલિશ સ્ટોક (Polish Available Stock)</div>
                            <small class="text-muted font-sans"><?= format_carat($polishStockWt) ?></small>
                        </div>
                        <strong class="text-dark"><?= format_inr($polishStockVal) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('boxes-stacked', 'text-secondary me-2') ?>ઇન-પ્રોસેસ કાપન સ્ટોક (Kapan In-Process)</div>
                            <small class="text-muted font-sans"><?= format_carat($kapanStockWt) ?></small>
                        </div>
                        <strong class="text-dark"><?= format_inr($kapanStockVal) ?></strong>
                    </li>
                </ul>
            </div>
            <div class="card-footer bg-light p-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark font-sans">કુલ અસ્કયામતો (Total Assets)</span>
                <span class="fw-bold text-success font-mono fs-5"><?= format_inr($totalAssetsInr) ?></span>
            </div>
        </div>
    </div>

    <!-- Liabilities Column -->
    <div class="col-12 col-lg-6">
        <div class="card-erp h-100 shadow-sm border">
            <div class="card-header bg-danger-subtle py-3 px-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-danger-emphasis fs-6">
                    <?= svg_icon('arrow-trend-down', 'me-2') ?>દેવાં અને જવાબદારીઓ (Liabilities Breakdown)
                </span>
                <span class="badge bg-danger font-mono fs-6"><?= format_inr($totalLiabilitiesInr) ?></span>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush font-mono">
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('shapes', 'text-danger me-2') ?>રફ ખરીદી ચૂકવવાના (Rough Payables)</div>
                            <small class="text-muted font-sans"><?= $roughPayableUsd > 0 ? '+ $' . number_format($roughPayableUsd, 2) : 'બાકી પેમેન્ટ' ?></small>
                        </div>
                        <strong class="text-danger"><?= format_inr($roughPayableInr) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('gem', 'text-danger me-2') ?>પોલિશ ખરીદી ચૂકવવાના (Polish Payables)</div>
                            <small class="text-muted font-sans"><?= $polishPayableUsd > 0 ? '+ $' . number_format($polishPayableUsd, 2) : 'બાકી પેમેન્ટ' ?></small>
                        </div>
                        <strong class="text-danger"><?= format_inr($polishPayableInr) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('handshake', 'text-warning me-2') ?>દલાલી ચૂકવવાની (Brokerage Payables)</div>
                            <small class="text-muted font-sans">ખરીદી દલાલો</small>
                        </div>
                        <strong class="text-danger"><?= format_inr($brokeragePayable) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <div class="fw-bold text-dark font-sans"><?= svg_icon('industry', 'text-secondary me-2') ?>આઉટસોર્સ / જોબવર્ક બિલ (Labour Bills)</div>
                            <small class="text-muted font-sans">લેબર યુનિટ બાકી બિલ</small>
                        </div>
                        <strong class="text-danger"><?= format_inr($outsourcePayable) ?></strong>
                    </li>
                </ul>
            </div>
            <div class="card-footer bg-light p-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark font-sans">કુલ જવાબદારીઓ (Total Liabilities)</span>
                <span class="fw-bold text-danger font-mono fs-5"><?= format_inr($totalLiabilitiesInr) ?></span>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
