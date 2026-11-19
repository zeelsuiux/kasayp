<?php
/**
 * Finora Minimalist Dashboard - Diamond / Kasyap ERP
 * Operational Priorities First • 4 Core KPIs • Gujarati Native Experience
 */

declare(strict_types=1);

$pageTitle = 'ડેશબોર્ડ';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// 1. Rough Stock Metrics
$roughStock = $pdo->query("SELECT 
    COALESCE(SUM(weight), 0) as total_wt,
    COALESCE(SUM(pcs), 0) as total_pcs,
    COALESCE(SUM(available_weight), 0) as avail_wt,
    COALESCE(SUM(available_pcs), 0) as avail_pcs
FROM rough_purchases WHERE status IN ('in_stock', 'in_assort')")->fetch();

// 2. MFG Rough (In production kapans)
$mfgRough = $pdo->query("SELECT 
    COALESCE(SUM(total_weight), 0) as total_wt,
    COALESCE(SUM(total_pcs), 0) as total_pcs
FROM kapans WHERE status IN ('active', 'in_production')")->fetch();

// 3. Active Kapans Count
$activeKapanCount = (int)$pdo->query("SELECT COUNT(*) FROM kapans WHERE status IN ('active', 'in_production')")->fetchColumn();

// 4. Rough Out Stock (Sold rough)
$roughOut = $pdo->query("SELECT 
    COALESCE(SUM(weight), 0) as total_wt,
    COALESCE(SUM(pcs), 0) as total_pcs,
    COALESCE(SUM(net_amount), 0) as total_amt
FROM rough_sales")->fetch();

// 5. Tops / Scrap Stock
$topsStock = $pdo->query("SELECT 
    COALESCE(SUM(weight), 0) as total_wt,
    COALESCE(SUM(pcs), 0) as total_pcs,
    COALESCE(SUM(total_value), 0) as total_val
FROM tops_records WHERE status = 'in_stock'")->fetch();

// 6. Polish Stock Metrics
$polishStock = $pdo->query("SELECT 
    COALESCE(SUM(available_weight), 0) as avail_wt,
    COALESCE(SUM(available_pcs), 0) as avail_pcs,
    COALESCE(SUM(available_weight * cost_per_carat), 0) as total_val
FROM polish_stocks WHERE status IN ('available', 'partially_on_jangad')")->fetch();

// 7. Purchase vs Sales Totals
$roughBuyTotal = $pdo->query("SELECT COALESCE(SUM(net_payment), 0) as amt, COALESCE(SUM(weight), 0) as wt FROM rough_purchases WHERE status != 'merged'")->fetch();
$polishBuyTotal = $pdo->query("SELECT COALESCE(SUM(net_payment), 0) as amt, COALESCE(SUM(weight), 0) as wt FROM polish_purchases")->fetch();
$polishSaleTotal = $pdo->query("SELECT COALESCE(SUM(net_amount), 0) as amt, COALESCE(SUM(weight), 0) as wt FROM polish_sales")->fetch();

// 8. Pending Dues
$dueRoughPayment = $pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) as due_amt, COUNT(*) as bill_count FROM rough_purchases WHERE party_payment_status != 'paid' AND status != 'merged'")->fetch();
$duePolishPayment = $pdo->query("SELECT COALESCE(SUM(net_payment - paid_amount), 0) as due_amt, COUNT(*) as bill_count FROM polish_purchases WHERE payment_status != 'paid'")->fetch();
$totalPendingDues = (float)$dueRoughPayment['due_amt'] + (float)$duePolishPayment['due_amt'];

// 9. Jangad Goods
$jangadGoods = $pdo->query("SELECT 
    COALESCE(SUM(total_weight - returned_weight), 0) as pending_wt,
    COALESCE(SUM(total_pcs - returned_pcs), 0) as pending_pcs,
    COUNT(*) as total_vouchers
FROM jangad_vouchers WHERE status IN ('issued', 'partially_returned')")->fetch();

// 10. Pipeline Progress Packet Counts
$pktStats = $pdo->query("SELECT
    COUNT(*) as total_pkts,
    SUM(CASE WHEN status = 'in_process' AND current_stage IN ('galaxy', 'planner', 'sarin') THEN 1 ELSE 0 END) as prep_cnt,
    SUM(CASE WHEN status = 'in_process' AND current_stage NOT IN ('galaxy', 'planner', 'sarin', 'polish_stock') THEN 1 ELSE 0 END) as mfg_cnt,
    SUM(CASE WHEN current_stage = 'polish_stock' OR status IN ('completed', 'in_polish_stock', 'boil_ready') THEN 1 ELSE 0 END) as ready_cnt
FROM packets")->fetch();

$totPkts   = max(1, (int)($pktStats['total_pkts'] ?? 0));
$prepPct   = (int)round((((int)($pktStats['prep_cnt'] ?? 0)) / $totPkts) * 100);
$mfgPct    = (int)round((((int)($pktStats['mfg_cnt'] ?? 0)) / $totPkts) * 100);
$readyPct  = (int)round((((int)($pktStats['ready_cnt'] ?? 0)) / $totPkts) * 100);

// 11. Location-wise Breakdown
$locationStats = $pdo->query("SELECT current_location, COUNT(*) as total_packets, COALESCE(SUM(COALESCE(current_weight, weight)), 0) as total_weight FROM packets WHERE status = 'in_process' GROUP BY current_location LIMIT 3")->fetchAll();
$polishLocationStats = $pdo->query("SELECT current_location, COALESCE(SUM(available_pcs), 0) as total_pcs, COALESCE(SUM(available_weight), 0) as total_weight FROM polish_stocks WHERE status IN ('available', 'partially_on_jangad') GROUP BY current_location LIMIT 3")->fetchAll();

// 12. Upcoming Dues Feed
$upcomingDues = $pdo->query("
    (SELECT 'રફ ખરીદી' as bill_type, rp.purchase_no as doc_no, rp.party_id, p.party_name, rp.due_date, (rp.net_payment - rp.paid_amount) as balance
     FROM rough_purchases rp JOIN parties p ON rp.party_id = p.id WHERE rp.party_payment_status != 'paid' AND rp.status != 'merged' AND rp.due_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
    UNION ALL
    (SELECT 'પોલિશ ખરીદી' as bill_type, pp.purchase_no as doc_no, pp.party_id, p.party_name, pp.due_date, (pp.net_payment - pp.paid_amount) as balance
     FROM polish_purchases pp JOIN parties p ON pp.party_id = p.id WHERE pp.payment_status != 'paid' AND pp.due_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY))
    ORDER BY due_date ASC LIMIT 5
")->fetchAll();

// 13. Recent Transactions Table Feed
$recentActivity = $pdo->query("
    (SELECT 'રફ ખરીદી' as category, 'blue' as cat_tone, rp.purchase_no as doc_no, p.party_name, rp.weight, rp.net_payment as amount, rp.purchase_date as tx_date, rp.party_payment_status as st
     FROM rough_purchases rp JOIN parties p ON rp.party_id = p.id ORDER BY rp.id DESC LIMIT 3)
    UNION ALL
    (SELECT 'પોલિશ ખરીદી' as category, 'amber' as cat_tone, pp.purchase_no as doc_no, p.party_name, pp.weight, pp.net_payment as amount, pp.purchase_date as tx_date, pp.payment_status as st
     FROM polish_purchases pp JOIN parties p ON pp.party_id = p.id ORDER BY pp.id DESC LIMIT 3)
    UNION ALL
    (SELECT 'પોલિશ વેચાણ' as category, 'green' as cat_tone, ps.invoice_no as doc_no, p.party_name, ps.weight, ps.net_amount as amount, ps.sale_date as tx_date, ps.payment_status as st
     FROM polish_sales ps JOIN parties p ON ps.party_id = p.id ORDER BY ps.id DESC LIMIT 3)
    UNION ALL
    (SELECT 'રફ વેચાણ' as category, 'purple' as cat_tone, rs.invoice_no as doc_no, p.party_name, rs.weight, rs.net_amount as amount, rs.sale_date as tx_date, rs.payment_status as st
     FROM rough_sales rs JOIN parties p ON rs.party_id = p.id ORDER BY rs.id DESC LIMIT 2)
    ORDER BY tx_date DESC LIMIT 5
")->fetchAll();

$totalTrackedCarats = (float)$roughStock['avail_wt'] + (float)$mfgRough['total_wt'] + (float)$polishStock['avail_wt'] + (float)$jangadGoods['pending_wt'] + (float)$topsStock['total_wt'];

$hour = (int)date('G');
$timeGreeting = $hour < 12 ? 'શુભ સવાર' : ($hour < 17 ? 'શુભ બપોર' : 'શુભ સાંજ');
$greeting = $timeGreeting . ' - કશ્યપ ડાયમંડ ERP';
?>

<!-- 1. Header with Greeting and Direct Quick Actions -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex align-items-center gap-2.5">
        <span style="color: #f59e0b; display: inline-flex;"><?= svg_icon('sun', '', 28) ?></span>
        <div>
            <h2 class="erp-page-title mb-0"><?= $greeting ?></h2>
            <div class="text-muted" style="font-size: 0.84rem;">આજના હીરા ઉત્પાદન, સ્ટોક અને નાણાકીય હિસાબની પરિસ્થિતિ</div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="btn btn-light btn-pill px-3" style="cursor: default;">
            <?= svg_icon('calendar', 'text-primary', 15) ?>
            <span><?= date('d-m-Y (D)') ?></span>
        </span>
        <a href="<?= BASE_URL ?>/modules/rough_purchase/create.php" class="btn btn-primary btn-pill px-3">
            <?= svg_icon('plus', '', 15) ?>
            <span>Rough ખરીદી</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php" class="btn btn-outline-primary btn-pill px-3">
            <?= svg_icon('plus', '', 15) ?>
            <span>Polish ખરીદી</span>
        </a>
    </div>
</div>

<!-- 2. Priority 1: FOUR CORE KPIs (Total Balance, Today's Dues, Stock Carats, Active Kapan) -->
<div class="row g-3 mb-3">
    <!-- KPI 1: Total Balance -->
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ સિલક (Total Balance)',
            format_inr($companyBalance),
            'બેંક & કેશ ખાતાઓ',
            'રોકડ અને બેંક બેલેન્સ',
            'blue',
            BASE_URL . '/modules/finance/transactions.php',
            '',
            'wallet'
        ) ?>
    </div>

    <!-- KPI 2: Today's / Pending Dues -->
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ બાકી ચુકવણી (Total Payables)',
            format_inr($totalPendingDues),
            count($upcomingDues) > 0 ? (count($upcomingDues) . ' બિલો મુદત પર') : 'આગામી ૧૫ દિવસ મુક્ત',
            'ખરીદી બાકી ચૂકવણી',
            'rose',
            BASE_URL . '/modules/finance/schedule.php',
            '',
            'bell'
        ) ?>
    </div>

    <!-- KPI 3: Total Stock Carats -->
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ સ્ટોક (Stock Carats)',
            format_carat($totalTrackedCarats),
            format_carat($roughStock['avail_wt']) . ' રફ • ' . format_carat($mfgRough['total_wt']) . ' પ્રોસેસ • ' . format_carat($polishStock['avail_wt']) . ' પોલિશ',
            'હાજર & પ્રોસેસ સ્ટોક',
            'green',
            BASE_URL . '/modules/polish/index.php?tab=gia_stock',
            '',
            'gem'
        ) ?>
    </div>

    <!-- KPI 4: Active Kapan -->
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'એક્ટિવ કાપન (Active Kapan)',
            (string)$activeKapanCount . ' કાપન',
            format_carat($mfgRough['total_wt']) . ' ઉત્પાદનમાં',
            'કારખાના ચાલુ પ્રોસેસ',
            'amber',
            BASE_URL . '/modules/kapan/index.php',
            '',
            'boxes-stacked'
        ) ?>
    </div>
</div>

<!-- 3. Priority 2: ACTIONABLE DAILY TASKS (આજના અગત્યના કામ) -->
<div class="finora-card mb-3 border">
    <div class="finora-card-header d-flex justify-content-between align-items-center bg-white border-bottom py-2.5 px-3">
        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <?= svg_icon('list-check', 'text-primary', 17) ?>
            <span>આજના અગત્યના કામ (Daily Operational Checklist)</span>
        </h6>
        <span class="badge bg-primary-subtle text-primary border font-mono small">Action Items</span>
    </div>
    <div class="p-3">
        <div class="row g-2.5">
            <div class="col-12 col-md-3">
                <a href="<?= BASE_URL ?>/modules/finance/schedule.php" class="text-decoration-none d-block h-100 p-2.5 rounded-3 border bg-light-subtle hover-shadow transition">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-danger text-white rounded-circle p-1"><?= svg_icon('bell', '', 12) ?></span>
                        <strong class="text-dark small">ચુકવણી મુદત ચકાસણી</strong>
                    </div>
                    <div class="text-muted small">
                        <?php if (count($upcomingDues) > 0): ?>
                            <span class="text-danger fw-bold"><?= count($upcomingDues) ?> પાર્ટીઓ</span>ની મુદત બાકી છે
                        <?php else: ?>
                            આજે કોઈ મુદત બાકી નથી
                        <?php endif; ?>
                    </div>
                </a>
            </div>

            <div class="col-12 col-md-3">
                <a href="<?= BASE_URL ?>/modules/jangad/index.php" class="text-decoration-none d-block h-100 p-2.5 rounded-3 border bg-light-subtle hover-shadow transition">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-warning text-dark rounded-circle p-1"><?= svg_icon('file-invoice', '', 12) ?></span>
                        <strong class="text-dark small">જાંગડ મેમો ફોલોઅપ</strong>
                    </div>
                    <div class="text-muted small">
                        <span class="text-warning-emphasis fw-bold"><?= (int)$jangadGoods['total_vouchers'] ?> મેમો</span> પરત જમા બાકી છે
                    </div>
                </a>
            </div>

            <div class="col-12 col-md-3">
                <a href="<?= BASE_URL ?>/modules/process/barcode_scanner.php" class="text-decoration-none d-block h-100 p-2.5 rounded-3 border bg-light-subtle hover-shadow transition">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary text-white rounded-circle p-1"><?= svg_icon('barcode', '', 12) ?></span>
                        <strong class="text-dark small">હીરા સ્ટેજ ટ્રાન્સફર</strong>
                    </div>
                    <div class="text-muted small">
                        <span class="text-primary fw-bold"><?= (int)($pktStats['prep_cnt'] + $pktStats['mfg_cnt']) ?> પેકેટો</span> પ્રોસેસમાં ચાલુ છે
                    </div>
                </a>
            </div>

            <div class="col-12 col-md-3">
                <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock" class="text-decoration-none d-block h-100 p-2.5 rounded-3 border bg-light-subtle hover-shadow transition">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-success text-white rounded-circle p-1"><?= svg_icon('layer-group', '', 12) ?></span>
                        <strong class="text-dark small">રફ સ્ટોક અને એસોર્ટ</strong>
                    </div>
                    <div class="text-muted small">
                        <span class="text-success fw-bold"><?= format_carat($roughStock['avail_wt']) ?></span> હાજર રફ શરૂ કરવા માટે
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 4. OPERATIONAL PRIORITIES FIRST (Recent Transactions & Dues Feeds) -->
<div class="row g-3 mb-3">
    <!-- LEFT: Recent Transactions Table -->
    <div class="col-12 col-xl-8">
        <div class="finora-card h-100">
            <div class="finora-card-header d-flex justify-content-between align-items-center">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('receipt', 'text-primary', 17) ?>
                    <span>તાજેતરના વ્યવહારો (Recent Transactions)</span>
                </h6>
                <a href="<?= BASE_URL ?>/modules/finance/transactions.php" class="small text-primary text-decoration-none fw-semibold">બધું જુઓ &rarr;</a>
            </div>
            <div class="table-responsive px-2 pb-2">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th>તારીખ</th>
                            <th>બિલ નં. & પાર્ટી</th>
                            <th>કેટેગરી</th>
                            <th class="text-end">વજન (ct)</th>
                            <th class="text-end">રકમ (₹)</th>
                            <th class="text-center">સ્થિતિ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentActivity)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <div class="mb-2"><?= svg_icon('file-circle-question', 'text-muted', 32) ?></div>
                                    <div class="fw-semibold">કોઈ તાજેતરનો વ્યવહાર નોંધાયેલ નથી.</div>
                                    <small class="text-muted">ખરીદી અથવા વેચાણની નવી એન્ટ્રી કરો.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $act): ?>
                                <?php
                                $pillTone = match ($act['cat_tone']) {
                                    'green'  => 'finora-pill-green',
                                    'amber'  => 'finora-pill-amber',
                                    'purple' => 'finora-pill-purple',
                                    default  => 'finora-pill-blue',
                                };
                                $isPaid = (($act['st'] ?? '') === 'paid');
                                ?>
                                <tr>
                                    <td class="text-muted font-mono small"><?= format_date($act['tx_date']) ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($act['party_name']) ?></div>
                                        <small class="text-muted font-mono"><?= e($act['doc_no']) ?></small>
                                    </td>
                                    <td><span class="finora-pill <?= $pillTone ?>"><?= e($act['category']) ?></span></td>
                                    <td class="text-end font-mono text-dark fw-bold"><?= format_carat($act['weight']) ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_inr($act['amount']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $isPaid ? 'bg-success-subtle text-success border-success' : 'bg-warning-subtle text-warning-emphasis border-warning' ?> border font-mono small">
                                            <?= $isPaid ? 'ચૂકતે (Paid)' : 'બાકી (Pending)' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Upcoming Dues Feed -->
    <div class="col-12 col-xl-4">
        <div class="finora-card h-100">
            <div class="finora-card-header d-flex justify-content-between align-items-center">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('clock', 'text-danger', 17) ?>
                    <span>આગામી ચુકવણી મુદત (Upcoming Dues)</span>
                </h6>
                <a href="<?= BASE_URL ?>/modules/finance/schedule.php" class="small text-primary text-decoration-none fw-semibold">બધું જુઓ &rarr;</a>
            </div>
            <div class="p-2">
                <?php if (empty($upcomingDues)): ?>
                    <div class="py-4 px-3 text-center">
                        <div class="mb-2 text-success"><?= svg_icon('circle-check', '', 36) ?></div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.92rem;">આવતા ૧૫ દિવસમાં કોઈ મુદત બાકી નથી</h6>
                        <p class="text-muted small mb-3">બધા વેપારીઓ અને દલાલોના બિલો નિયમિત સમયસર ચૂકવાયેલા છે.</p>
                        <div class="p-2.5 rounded-3 border bg-light-subtle d-flex justify-content-between align-items-center mb-2 font-mono small">
                            <span class="text-muted">કુલ બાકી ખરીદી:</span>
                            <strong class="text-dark"><?= format_inr($totalPendingDues) ?></strong>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/finance/schedule.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 font-mono" style="font-size: 0.78rem;">
                            <?= svg_icon('calendar-days', 'me-1', 12) ?>ચુકવણી શિડ્યુલ જુઓ &rarr;
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingDues as $du): ?>
                        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $du['party_id'] ?>" class="finora-list-item text-decoration-none">
                            <div class="d-flex align-items-center gap-2.5" style="min-width: 0;">
                                <div class="kpi-icon-tile kpi-icon-rose" style="width: 34px; height: 34px; border-radius: 50%;">
                                    <?= svg_icon('clock', '', 15) ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="fw-semibold text-dark text-truncate" style="font-size: 0.84rem;"><?= e($du['party_name']) ?></div>
                                    <small class="text-muted font-mono" style="font-size: 0.74rem;"><?= format_date($du['due_date']) ?> &bull; <?= e($du['doc_no']) ?></small>
                                </div>
                            </div>
                            <strong class="font-mono text-danger flex-shrink-0 ms-2" style="font-size: 0.85rem;"><?= format_inr($du['balance']) ?></strong>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 5. VISUAL CHARTS & ANALYTICS (Positioned below operational priorities) -->
<div class="row g-3 mb-3">
    <!-- Stock Trend Line Chart -->
    <div class="col-12 col-xl-8">
        <div class="finora-card">
            <div class="finora-card-header d-flex justify-content-between align-items-center">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('chart-line', 'text-primary', 17) ?>
                    <span>હીરા સ્ટોક અને ઉત્પાદન પ્રવાહ (Stock & Pipeline Trends)</span>
                </h6>
                <a href="<?= BASE_URL ?>/modules/process/jobwork_stock.php" class="small text-primary text-decoration-none fw-semibold">વિગતવાર જુઓ</a>
            </div>
            <div class="p-3 pt-2">
                <div style="height: 205px; position: relative;">
                    <canvas id="stockOverviewChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Breakdown Donut -->
    <div class="col-12 col-xl-4">
        <div class="finora-card">
            <div class="finora-card-header">
                <h6 class="finora-card-title mb-0">સ્ટોક કેરેટ વિતરણ (Stock Breakdown)</h6>
            </div>
            <div class="p-3">
                <div style="height: 175px; position: relative;" class="d-flex align-items-center justify-content-center">
                    <canvas id="stockDonutChart"></canvas>
                    <div class="position-absolute text-center" style="pointer-events: none;">
                        <div class="fw-bold text-dark font-mono" style="font-size: 1.15rem;"><?= format_carat($totalTrackedCarats) ?></div>
                        <small class="text-muted" style="font-size: 0.72rem;">કુલ કેરેટ</small>
                    </div>
                </div>
                <div class="d-flex flex-column gap-2 mt-3 pt-2 border-top">
                    <div class="d-flex justify-content-between align-items-center small">
                        <span class="d-flex align-items-center gap-2 text-muted"><span style="width: 9px; height: 9px; border-radius: 50%; background: #3b82f6;"></span>રફ હાજર સ્ટોક</span>
                        <strong class="font-mono text-dark"><?= format_carat($roughStock['avail_wt']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center small">
                        <span class="d-flex align-items-center gap-2 text-muted"><span style="width: 9px; height: 9px; border-radius: 50%; background: #fbbf24;"></span>કારખાના પ્રોસેસ</span>
                        <strong class="font-mono text-dark"><?= format_carat($mfgRough['total_wt']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center small">
                        <span class="d-flex align-items-center gap-2 text-muted"><span style="width: 9px; height: 9px; border-radius: 50%; background: #10b981;"></span>પોલિશ સ્ટોક</span>
                        <strong class="font-mono text-dark"><?= format_carat($polishStock['avail_wt']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center small">
                        <span class="d-flex align-items-center gap-2 text-muted"><span style="width: 9px; height: 9px; border-radius: 50%; background: #ef4444;"></span>જાંગડ મેમો</span>
                        <strong class="font-mono text-dark"><?= format_carat($jangadGoods['pending_wt']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center small">
                        <span class="d-flex align-items-center gap-2 text-muted"><span style="width: 9px; height: 9px; border-radius: 50%; background: #a855f7;"></span>ટોપ્સ / સ્ક્રેપ</span>
                        <strong class="font-mono text-dark"><?= format_carat($topsStock['total_wt']) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Finora Smooth Curved Area Line Chart (Stock Overview)
    const ctxArea = document.getElementById('stockOverviewChart')?.getContext('2d');
    if (ctxArea) {
        const gradient = ctxArea.createLinearGradient(0, 0, 0, 200);
        gradient.addColorStop(0, 'rgba(30, 58, 138, 0.25)');
        gradient.addColorStop(1, 'rgba(30, 58, 138, 0.01)');

        new Chart(ctxArea, {
            type: 'line',
            data: {
                labels: ['રફ સ્ટોક', 'કારખાના પ્રોસેસ', 'પોલિશ સ્ટોક', 'જાંગડ મેમો', 'રફ વેચાણ'],
                datasets: [{
                    label: 'કેરેટ',
                    data: [
                        <?= (float)$roughStock['avail_wt'] ?>,
                        <?= (float)$mfgRough['total_wt'] ?>,
                        <?= (float)$polishStock['avail_wt'] ?>,
                        <?= (float)$jangadGoods['pending_wt'] ?>,
                        <?= (float)$roughOut['total_wt'] ?>
                    ],
                    borderColor: '#1e3a8a',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    tension: 0.42,
                    fill: true,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#1e3a8a',
                    pointBorderWidth: 2.5,
                    pointRadius: 4.5,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9', borderDash: [4, 4] },
                        border: { display: false },
                        ticks: { font: { family: 'JetBrains Mono', size: 11 }, color: '#94a3b8' }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { family: 'Noto Sans Gujarati, Poppins', size: 11 }, color: '#64748b' }
                    }
                }
            }
        });
    }

    // 2. Stock Breakdown Hollow Donut
    const ctxDonut = document.getElementById('stockDonutChart')?.getContext('2d');
    if (ctxDonut) {
        new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: ['રફ સ્ટોક', 'પ્રોસેસ', 'પોલિશ સ્ટોક', 'જાંગડ', 'ટોપ્સ'],
                datasets: [{
                    data: [
                        <?= (float)$roughStock['avail_wt'] ?>,
                        <?= (float)$mfgRough['total_wt'] ?>,
                        <?= (float)$polishStock['avail_wt'] ?>,
                        <?= (float)$jangadGoods['pending_wt'] ?>,
                        <?= (float)$topsStock['total_wt'] ?>
                    ],
                    backgroundColor: ['#3b82f6', '#fbbf24', '#10b981', '#ef4444', '#a855f7'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
