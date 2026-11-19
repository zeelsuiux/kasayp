<?php
/**
 * 9 Pillars Executive Summary: Trade & Inventory Hub
 * (Rough, GIA Polish, Parcel Polish: Purchase, Sale, Live Stock)
 * Kasyap Diamond ERP - Surat & Mumbai
 */

declare(strict_types=1);

$pageTitle = 'વેપાર અને સ્ટોક સારાંશ (Trade & Stock Summary)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// Period & Custom Date Range Filtering
$period   = trim((string)($_GET['period'] ?? 'fy'));
$fromDate = !empty($_GET['from_date']) ? trim((string)$_GET['from_date']) : null;
$toDate   = !empty($_GET['to_date']) ? trim((string)$_GET['to_date']) : null;
$pInfo    = get_filter_period_dates($period, $fromDate, $toDate);
$activePeriod = $pInfo['period'];
$purDateFrom  = $pInfo['from_date'];
$purDateTo    = $pInfo['to_date'];
$fyLabel      = $pInfo['fy_label'];

// Fetch the 9 Pillars
$pillars = get_trade_and_stock_9_pillars($pdo, $pInfo);

$rb = $pillars['rough_buy'];
$rs = $pillars['rough_sale'];
$rstk = $pillars['rough_stock'];

$gb = $pillars['gia_buy'];
$gs = $pillars['gia_sale'];
$gstk = $pillars['gia_stock'];

$pb = $pillars['parcel_buy'];
$ps = $pillars['parcel_sale'];
$pstk = $pillars['parcel_stock'];

// Overall Totals
$totBuyPcs    = (int)$rb['pcs'] + (int)$gb['pcs'] + (int)$pb['pcs'];
$totBuyWt     = (float)$rb['weight'] + (float)$gb['weight'] + (float)$pb['weight'];
$totBuyAmt    = (float)$rb['total_amount'] + (float)$gb['total_amount'] + (float)$pb['total_amount'];

$totSalePcs   = (int)$rs['pcs'] + (int)$gs['pcs'] + (int)$ps['pcs'];
$totSaleWt    = (float)$rs['weight'] + (float)$gs['weight'] + (float)$ps['weight'];
$totSaleAmt   = (float)$rs['total_amount'] + (float)$gs['total_amount'] + (float)$ps['total_amount'];

$totStockPcs  = (int)$rstk['pcs'] + (int)$gstk['pcs'] + (int)$pstk['pcs'];
$totStockWt   = (float)$rstk['weight'] + (float)$gstk['weight'] + (float)$pstk['weight'];
$totStockVal  = (float)$rstk['total_val'] + (float)$gstk['total_val'] + (float)$pstk['total_val'];

$periodQueryStr = '&period=' . urlencode($activePeriod) . ($activePeriod === 'custom' && $purDateFrom && $purDateTo ? '&from_date=' . urlencode($purDateFrom) . '&to_date=' . urlencode($purDateTo) : '');
?>

<div class="d-print-none">
    <?= render_page_header(
        'વેપાર અને સ્ટોક સારાંશ (Executive 9-Pillars)',
        'રફ, GIA સિંગલ અને પાર્સલ પોલિશ: ખરીદી, વેચાણ અને હાજર સ્ટોકનો ચોક્કસ સરવૈયો',
        'chart-pie',
        '<button type="button" onclick="window.print()" class="btn btn-outline-secondary">' . svg_icon('print', '', 15) . 'પ્રિન્ટ / PDF</button>
         <a href="' . BASE_URL . '/modules/reports/index.php" class="btn btn-outline-primary">' . svg_icon('arrow-left', '', 14) . 'રિપોર્ટ્સ કેટલોગ</a>'
    ) ?>

    <!-- Period & Date Range Filter Toolbar -->
    <div class="finora-card p-3 mb-4 shadow-xs border">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon-tile kpi-icon-blue" style="width: 38px; height: 38px;">
                    <?= svg_icon('calendar-days', '', 18) ?>
                </div>
                <div>
                    <div class="small text-muted fw-semibold">પસંદ કરેલ સમયગાળો:</div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono fs-6 px-2.5 py-1">
                        <?= e($pInfo['label']) ?>
                    </span>
                </div>
            </div>

            <?= render_period_filter_toolbar($activePeriod, $purDateFrom, $purDateTo, BASE_URL . '/modules/reports/summary.php') ?>
        </div>
    </div>
</div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- 1. OVERALL MACRO TRADE CARDS                                      -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <!-- Macro Card 1: Total Purchase -->
    <div class="col-12 col-md-4">
        <div class="stat-card-finora kpi-tone-blue border h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('cart-shopping', '', 20) ?></div>
                <span class="badge bg-primary text-white font-mono small">કુલ ખરીદી</span>
            </div>
            <div class="kpi-label">સંપૂર્ણ હીરા ખરીદી (Total Diamonds Bought)</div>
            <div class="kpi-value text-primary"><?= format_inr($totBuyAmt) ?></div>
            <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top small font-mono">
                <span>કુલ વજન: <strong><?= format_carat($totBuyWt) ?></strong></span>
                <span>નંગ: <strong><?= number_format($totBuyPcs) ?> Pcs</strong></span>
            </div>
        </div>
    </div>

    <!-- Macro Card 2: Total Sales -->
    <div class="col-12 col-md-4">
        <div class="stat-card-finora kpi-tone-amber border h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('hand-holding-dollar', '', 20) ?></div>
                <span class="badge bg-warning text-dark font-mono small">કુલ વેચાણ</span>
            </div>
            <div class="kpi-label">સંપૂર્ણ હીરા વેચાણ (Total Diamonds Sold)</div>
            <div class="kpi-value text-dark"><?= format_inr($totSaleAmt) ?></div>
            <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top small font-mono">
                <span>વેચેલ વજન: <strong><?= format_carat($totSaleWt) ?></strong></span>
                <span>નંગ: <strong><?= number_format($totSalePcs) ?> Pcs</strong></span>
            </div>
        </div>
    </div>

    <!-- Macro Card 3: Live In-Hand Inventory -->
    <div class="col-12 col-md-4">
        <div class="stat-card-finora kpi-tone-green border h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('boxes-stacked', '', 20) ?></div>
                <span class="badge bg-success text-white font-mono small">હાજર સ્ટોક</span>
            </div>
            <div class="kpi-label">કુલ હાજર સ્ટોક મૂલ્યાંકન (Live Inventory Value)</div>
            <div class="kpi-value text-success"><?= format_inr($totStockVal) ?></div>
            <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top small font-mono">
                <span>હાજર વજન: <strong><?= format_carat($totStockWt) ?></strong></span>
                <span>હાજર નંગ: <strong><?= number_format($totStockPcs) ?> Pcs</strong></span>
            </div>
        </div>
    </div>
</div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- 2. THE 9 PILLARS: DETAILED STREAM BREAKDOWN                       -->
<!-- ───────────────────────────────────────────────────────────────── -->

<!-- STREAM 1: ROUGH DIAMONDS (રફ હીરા) -->
<div class="finora-card mb-4 border">
    <div class="finora-card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="kpi-icon-tile kpi-icon-blue" style="width: 32px; height: 32px;">
                <?= svg_icon('shapes', '', 16) ?>
            </div>
            <h6 class="finora-card-title mb-0">૧. રફ હીરા પ્રવાહ (Rough Diamond Stream)</h6>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=purchase<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-primary py-0.5 px-2 small">
                રફ ખરીદી રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=sell<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-warning py-0.5 px-2 small text-dark">
                રફ વેચાણ રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-success py-0.5 px-2 small">
                રફ હાજર સ્ટોક &rarr;
            </a>
        </div>
    </div>
    <div class="p-3">
        <div class="row g-3">
            <!-- 1.1 Rough Purchase -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-primary small">રફ ખરીદી (Rough Kharidi)</span>
                        <span class="badge bg-primary-subtle text-primary border font-mono"><?= (int)$rb['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$rb['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$rb['pcs']) ?></strong> | 
                        વજન: <strong class="text-primary"><?= format_carat((float)$rb['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$rb['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 1.2 Rough Sell -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-warning-emphasis small">રફ વેચાણ (Rough Vechan)</span>
                        <span class="badge bg-warning-subtle text-dark border font-mono"><?= (int)$rs['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$rs['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$rs['pcs']) ?></strong> | 
                        વજન: <strong class="text-warning-emphasis"><?= format_carat((float)$rs['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$rs['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 1.3 Rough Stock -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-success small">રફ હાજર સ્ટોક (Rough Live Stock)</span>
                        <span class="badge bg-success-subtle text-success border font-mono"><?= (int)$rstk['total_lots'] ?> લૉટ્સ</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-success mb-1"><?= format_inr((float)$rstk['total_val']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$rstk['pcs']) ?></strong> | 
                        વજન: <strong class="text-success"><?= format_carat((float)$rstk['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ પડતર/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$rstk['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- STREAM 2: GIA CERTIFIED POLISH (GIA સિંગલ પોલિશ) -->
<div class="finora-card mb-4 border">
    <div class="finora-card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="kpi-icon-tile kpi-icon-blue" style="width: 32px; height: 32px;">
                <?= svg_icon('certificate', '', 16) ?>
            </div>
            <h6 class="finora-card-title mb-0">૨. GIA પ્રમાણિત સિંગલ પોલિશ પ્રવાહ (GIA Polish Stream)</h6>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_purchase<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-primary py-0.5 px-2 small">
                GIA ખરીદી રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_sell<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-warning py-0.5 px-2 small text-dark">
                GIA વેચાણ રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_stock<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-success py-0.5 px-2 small">
                GIA હાજર સ્ટોક &rarr;
            </a>
        </div>
    </div>
    <div class="p-3">
        <div class="row g-3">
            <!-- 2.1 GIA Purchase -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-primary small">GIA ખરીદી (GIA Kharidi)</span>
                        <span class="badge bg-primary-subtle text-primary border font-mono"><?= (int)$gb['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$gb['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        Stones: <strong class="text-dark"><?= number_format((int)$gb['pcs']) ?></strong> | 
                        વજન: <strong class="text-primary"><?= format_carat((float)$gb['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$gb['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 2.2 GIA Sell -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-warning-emphasis small">GIA વેચાણ (GIA Vechan)</span>
                        <span class="badge bg-warning-subtle text-dark border font-mono"><?= (int)$gs['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$gs['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        Stones: <strong class="text-dark"><?= number_format((int)$gs['pcs']) ?></strong> | 
                        વજન: <strong class="text-warning-emphasis"><?= format_carat((float)$gs['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$gs['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 2.3 GIA Stock -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-success small">GIA હાજર સ્ટોક (GIA Live Stock)</span>
                        <span class="badge bg-success-subtle text-success border font-mono"><?= (int)$gstk['total_lots'] ?> Stones</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-success mb-1"><?= format_inr((float)$gstk['total_val']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$gstk['pcs']) ?></strong> | 
                        વજન: <strong class="text-success"><?= format_carat((float)$gstk['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$gstk['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- STREAM 3: PARCEL POLISH (પાર્સલ પોલિશ) -->
<div class="finora-card mb-4 border">
    <div class="finora-card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="kpi-icon-tile kpi-icon-purple" style="width: 32px; height: 32px;">
                <?= svg_icon('boxes-stacked', '', 16) ?>
            </div>
            <h6 class="finora-card-title mb-0">૩. પાર્સલ પોલિશ પ્રવાહ (Parcel Polish Stream)</h6>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_purchase<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-primary py-0.5 px-2 small">
                પાર્સલ ખરીદી રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_sell<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-warning py-0.5 px-2 small text-dark">
                પાર્સલ વેચાણ રજિસ્ટર &rarr;
            </a>
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_stock<?= $periodQueryStr ?>" class="btn btn-sm btn-outline-success py-0.5 px-2 small">
                પાર્સલ હાજર સ્ટોક &rarr;
            </a>
        </div>
    </div>
    <div class="p-3">
        <div class="row g-3">
            <!-- 3.1 Parcel Purchase -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-primary small">પાર્સલ ખરીદી (Parcel Kharidi)</span>
                        <span class="badge bg-primary-subtle text-primary border font-mono"><?= (int)$pb['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$pb['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$pb['pcs']) ?></strong> | 
                        વજન: <strong class="text-primary"><?= format_carat((float)$pb['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$pb['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 3.2 Parcel Sell -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-warning-emphasis small">પાર્સલ વેચાણ (Parcel Vechan)</span>
                        <span class="badge bg-warning-subtle text-dark border font-mono"><?= (int)$ps['bills'] ?> બિલો</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-dark mb-1"><?= format_inr((float)$ps['total_amount']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$ps['pcs']) ?></strong> | 
                        વજન: <strong class="text-warning-emphasis"><?= format_carat((float)$ps['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$ps['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>

            <!-- 3.3 Parcel Stock -->
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light-subtle border rounded-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-success small">પાર્સલ હાજર સ્ટોક (Parcel Live Stock)</span>
                        <span class="badge bg-success-subtle text-success border font-mono"><?= (int)$pstk['total_lots'] ?> લૉટ્સ</span>
                    </div>
                    <div class="fs-4 fw-bold font-mono text-success mb-1"><?= format_inr((float)$pstk['total_val']) ?></div>
                    <div class="small font-mono text-muted mb-2">
                        નંગ: <strong class="text-dark"><?= number_format((int)$pstk['pcs']) ?></strong> | 
                        વજન: <strong class="text-success"><?= format_carat((float)$pstk['weight']) ?></strong>
                    </div>
                    <div class="pt-2 border-top d-flex justify-content-between small font-mono">
                        <span class="text-muted">સરેરાશ ભાવ/Ct:</span>
                        <strong class="text-dark">₹ <?= number_format((float)$pstk['avg_rate'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- 3. MASTER COMPARATIVE RECONCILIATION TABLE                        -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="finora-card mb-4 border shadow-xs">
    <div class="finora-card-header bg-white py-3 px-3.5 border-bottom d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <?= svg_icon('table', 'text-primary', 18) ?>
            <h6 class="finora-card-title mb-0">સંપૂર્ણ સરખામણી કોષ્ટક (9 Pillars Trade Comparison Table)</h6>
        </div>
        <span class="small text-muted font-mono"><?= e($pInfo['label']) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 font-mono" style="font-size: 0.86rem;">
            <thead class="table-light">
                <tr class="text-nowrap text-secondary">
                    <th class="ps-3">વિભાગ / કેટેગરી</th>
                    <th class="text-end">ખરીદી નંગ</th>
                    <th class="text-end">ખરીદી વજન (Ct)</th>
                    <th class="text-end">સરેરાશ ખરીદી ભાવ</th>
                    <th class="text-end">કુલ ખરીદી રકમ</th>
                    <th class="text-end">વેચાણ નંગ</th>
                    <th class="text-end">વેચાણ વજન (Ct)</th>
                    <th class="text-end">સરેરાશ વેચાણ ભાવ</th>
                    <th class="text-end">કુલ વેચાણ રકમ</th>
                    <th class="text-end">હાજર સ્ટોક નંગ</th>
                    <th class="text-end">હાજર સ્ટોક વજન</th>
                    <th class="text-end pe-3">હાજર સ્ટોક મૂલ્યાંકન</th>
                </tr>
            </thead>
            <tbody>
                <!-- Row 1: Rough -->
                <tr>
                    <td class="ps-3 fw-bold text-dark">
                        <?= svg_icon('shapes', 'text-primary me-1.5', 14) ?>
                        <a href="<?= BASE_URL ?>/modules/rough/index.php<?= !empty($periodQueryStr) ? '?' . ltrim($periodQueryStr, '&') : '' ?>" class="text-decoration-none text-dark">
                            ૧. રફ હીરા (Rough)
                        </a>
                    </td>
                    <td class="text-end"><?= number_format((int)$rb['pcs']) ?></td>
                    <td class="text-end fw-bold text-primary"><?= format_carat((float)$rb['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$rb['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$rb['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$rs['pcs']) ?></td>
                    <td class="text-end fw-bold text-warning-emphasis"><?= format_carat((float)$rs['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$rs['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$rs['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$rstk['pcs']) ?></td>
                    <td class="text-end fw-bold text-success"><?= format_carat((float)$rstk['weight']) ?></td>
                    <td class="text-end pe-3 fw-bold text-success"><?= format_inr((float)$rstk['total_val']) ?></td>
                </tr>

                <!-- Row 2: GIA Polish -->
                <tr>
                    <td class="ps-3 fw-bold text-dark">
                        <?= svg_icon('certificate', 'text-info me-1.5', 14) ?>
                        <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_purchase<?= $periodQueryStr ?>" class="text-decoration-none text-dark">
                            ૨. GIA સિંગલ પોલિશ
                        </a>
                    </td>
                    <td class="text-end"><?= number_format((int)$gb['pcs']) ?></td>
                    <td class="text-end fw-bold text-primary"><?= format_carat((float)$gb['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$gb['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$gb['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$gs['pcs']) ?></td>
                    <td class="text-end fw-bold text-warning-emphasis"><?= format_carat((float)$gs['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$gs['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$gs['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$gstk['pcs']) ?></td>
                    <td class="text-end fw-bold text-success"><?= format_carat((float)$gstk['weight']) ?></td>
                    <td class="text-end pe-3 fw-bold text-success"><?= format_inr((float)$gstk['total_val']) ?></td>
                </tr>

                <!-- Row 3: Parcel Polish -->
                <tr>
                    <td class="ps-3 fw-bold text-dark">
                        <?= svg_icon('boxes-stacked', 'text-purple me-1.5', 14) ?>
                        <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_purchase<?= $periodQueryStr ?>" class="text-decoration-none text-dark">
                            ૩. પાર્સલ પોલિશ (Parcel)
                        </a>
                    </td>
                    <td class="text-end"><?= number_format((int)$pb['pcs']) ?></td>
                    <td class="text-end fw-bold text-primary"><?= format_carat((float)$pb['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$pb['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$pb['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$ps['pcs']) ?></td>
                    <td class="text-end fw-bold text-warning-emphasis"><?= format_carat((float)$ps['weight']) ?></td>
                    <td class="text-end text-muted">₹ <?= number_format((float)$ps['avg_rate'], 2) ?></td>
                    <td class="text-end fw-bold text-dark"><?= format_inr((float)$ps['total_amount']) ?></td>

                    <td class="text-end"><?= number_format((int)$pstk['pcs']) ?></td>
                    <td class="text-end fw-bold text-success"><?= format_carat((float)$pstk['weight']) ?></td>
                    <td class="text-end pe-3 fw-bold text-success"><?= format_inr((float)$pstk['total_val']) ?></td>
                </tr>
            </tbody>
            <tfoot class="table-light border-top">
                <tr class="fw-bold">
                    <td class="ps-3 text-dark">કુલ સરવાળો (GRAND TOTAL)</td>
                    <td class="text-end text-dark"><?= number_format($totBuyPcs) ?></td>
                    <td class="text-end text-primary"><?= format_carat($totBuyWt) ?></td>
                    <td class="text-end text-muted">₹ <?= ($totBuyWt > 0) ? number_format($totBuyAmt / $totBuyWt, 2) : '0.00' ?></td>
                    <td class="text-end text-dark"><?= format_inr($totBuyAmt) ?></td>

                    <td class="text-end text-dark"><?= number_format($totSalePcs) ?></td>
                    <td class="text-end text-warning-emphasis"><?= format_carat($totSaleWt) ?></td>
                    <td class="text-end text-muted">₹ <?= ($totSaleWt > 0) ? number_format($totSaleAmt / $totSaleWt, 2) : '0.00' ?></td>
                    <td class="text-end text-dark"><?= format_inr($totSaleAmt) ?></td>

                    <td class="text-end text-dark"><?= number_format($totStockPcs) ?></td>
                    <td class="text-end text-success"><?= format_carat($totStockWt) ?></td>
                    <td class="text-end pe-3 text-success"><?= format_inr($totStockVal) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
