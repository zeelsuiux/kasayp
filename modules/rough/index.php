<?php
/**
 * Rough Hub (રફ વેપાર મેનેજમેન્ટ) - Unified Purchases, Sales & Live Stock
 * Kasyap Diamond ERP - Surat & Mumbai
 * Executive AeuxGlobal / Donezo Design
 */

declare(strict_types=1);

$tab = trim((string)($_GET['tab'] ?? 'purchase'));
if (!in_array($tab, ['purchase', 'sell', 'stock'], true)) {
    $tab = 'purchase';
}

$pageTitle = match($tab) {
    'purchase' => 'Rough ખરીદી (Rough Purchase)',
    'sell'     => 'Rough વેચાણ (Rough Sell)',
    'stock'    => 'Rough સ્ટોક (Rough Live Stock - હજુ શરૂ બાકી)'
};

require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// Period & Custom Date Range Filtering
$period   = trim((string)($_GET['period'] ?? 'fy'));
$fromDate = !empty($_GET['from_date']) ? trim((string)$_GET['from_date']) : null;
$toDate   = !empty($_GET['to_date']) ? trim((string)$_GET['to_date']) : null;
$periodInfo   = get_filter_period_dates($period, $fromDate, $toDate);
$activePeriod = $periodInfo['period'];
$purDateFrom  = $periodInfo['from_date'];
$purDateTo    = $periodInfo['to_date'];
$fyLabel      = $periodInfo['fy_label'];

$rpDateSql = "";
$rpDateParams = [];
if ($purDateFrom && $purDateTo) {
    $rpDateSql = " AND purchase_date BETWEEN ? AND ?";
    $rpDateParams = [$purDateFrom, $purDateTo];
}

$rsDateSql = "";
$rsDateParams = [];
if ($purDateFrom && $purDateTo) {
    $rsDateSql = " AND sale_date BETWEEN ? AND ?";
    $rsDateParams = [$purDateFrom, $purDateTo];
}

// 1. Live Aggregates for Top Cards (Respecting Period / Custom Date Range)
$rpStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bills,
        COALESCE(SUM(pcs), 0) as pcs,
        COALESCE(SUM(weight), 0) as weight,
        COALESCE(SUM(available_weight), 0) as avail_weight,
        COALESCE(SUM(net_payment), 0) as total_amount
    FROM rough_purchases
    WHERE status != 'merged' $rpDateSql
");
$rpStmt->execute($rpDateParams);
$rpStats = $rpStmt->fetch(PDO::FETCH_ASSOC);

$rsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bills,
        COALESCE(SUM(pcs), 0) as pcs,
        COALESCE(SUM(weight), 0) as weight,
        COALESCE(SUM(net_amount), 0) as total_amount
    FROM rough_sales
    WHERE 1=1 $rsDateSql
");
$rsStmt->execute($rsDateParams);
$rsStats = $rsStmt->fetch(PDO::FETCH_ASSOC);

// Rough Stock: Lots with available weight (હજુ શરૂ બાકી)
$rStockStats = $pdo->query("
    SELECT 
        COUNT(*) as total_lots,
        COALESCE(SUM(available_pcs), 0) as avail_pcs,
        COALESCE(SUM(available_weight), 0) as avail_weight,
        COALESCE(SUM(available_weight * price_per_carat_inr), 0) as total_value
    FROM rough_purchases
    WHERE available_weight > 0 OR status IN ('in_stock', 'pending')
")->fetch(PDO::FETCH_ASSOC);

$totPurchaseWeight = (float)($rpStats['weight'] ?? 0);
$totPurchasePcs    = (int)($rpStats['pcs'] ?? 0);
$totPurchaseBills  = (int)($rpStats['bills'] ?? 0);
$totPurchaseAmount = (float)($rpStats['total_amount'] ?? 0);

$totSaleWeight     = (float)($rsStats['weight'] ?? 0);
$totSalePcs        = (int)($rsStats['pcs'] ?? 0);
$totSaleBills      = (int)($rsStats['bills'] ?? 0);
$totSaleAmount     = (float)($rsStats['total_amount'] ?? 0);

$totRStockWeight   = (float)($rStockStats['avail_weight'] ?? 0);
$totRStockPcs      = (int)($rStockStats['avail_pcs'] ?? 0);
$totRStockLots     = (int)($rStockStats['total_lots'] ?? 0);
$totRStockValue    = (float)($rStockStats['total_value'] ?? 0);

// Actual Assorted / In Process weight from assort_tasks
$actualAssortedWeight = (float)$pdo->query("SELECT COALESCE(SUM(total_input_weight), 0) FROM assort_tasks")->fetchColumn();
$totAssortedWeight = $actualAssortedWeight > 0 ? $actualAssortedWeight : max(0.0, round($totPurchaseWeight - $totRStockWeight, 3));
$totAssortedLots   = (int)$pdo->query("SELECT COUNT(*) FROM rough_purchases WHERE status = 'assorted' OR (status != 'merged' AND available_weight < weight)")->fetchColumn();

// Search & Filter
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$tagFilter = trim((string)($_GET['tag'] ?? ''));
$processTagFilter = trim((string)($_GET['process_tag'] ?? ''));
$standDollarFilter = trim((string)($_GET['stand_dollar'] ?? ''));

$allTags = get_all_tags();
$allProcessTags = get_all_process_tags();

if ($tab === 'purchase') {
    $sql = "SELECT rp.*, p.party_name, b.party_name as broker_name, mp.purchase_no as merged_into_no 
            FROM rough_purchases rp
            JOIN parties p ON rp.party_id = p.id
            LEFT JOIN parties b ON rp.broker_id = b.id
            LEFT JOIN rough_purchases mp ON rp.merged_into_id = mp.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (rp.purchase_no LIKE ? OR p.party_name LIKE ? OR rp.product_description LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND rp.status = ?";
        $params[] = $statusFilter;
    }
    if (!empty($tagFilter)) {
        $sql .= " AND rp.tag = ?";
        $params[] = $tagFilter;
    }
    if (!empty($processTagFilter)) {
        $sql .= " AND rp.process_tag = ?";
        $params[] = $processTagFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((rp.payment_currency = 'USD' OR COALESCE(rp.dollar_per_carat, 0) > 0) AND COALESCE(rp.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((rp.payment_currency = 'USD' OR COALESCE(rp.dollar_per_carat, 0) > 0) AND COALESCE(rp.dollar_rate, 0) <= 0)";
    }
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND rp.purchase_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    $sql .= " ORDER BY rp.purchase_date DESC, rp.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagDataBuy = get_pagination_data(count($allPurchases), 25);
    $purchases = array_slice($allPurchases, $pagDataBuy['offset'], $pagDataBuy['per_page']);
} elseif ($tab === 'sell') {
    $sql = "SELECT rs.*, p.party_name, b.party_name as broker_name,
                   rp.id as purchase_id, rp.purchase_no, rp.party_id as supplier_id, supp.party_name as supplier_name,
                   ap.part_name, at.task_no as assort_task_no
            FROM rough_sales rs
            JOIN parties p ON rs.party_id = p.id
            LEFT JOIN parties b ON rs.broker_id = b.id
            LEFT JOIN rough_purchases rp ON rs.rough_purchase_id = rp.id
            LEFT JOIN parties supp ON rp.party_id = supp.id
            LEFT JOIN assort_parts ap ON rs.assort_part_id = ap.id
            LEFT JOIN assort_tasks at ON ap.assort_task_id = at.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (rs.invoice_no LIKE ? OR p.party_name LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND rs.payment_status = ?";
        $params[] = $statusFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((rs.payment_currency = 'USD' OR COALESCE(rs.dollar_per_carat, 0) > 0) AND COALESCE(rs.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((rs.payment_currency = 'USD' OR COALESCE(rs.dollar_per_carat, 0) > 0) AND COALESCE(rs.dollar_rate, 0) <= 0)";
    }
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND rs.sale_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    $sql .= " ORDER BY rs.sale_date DESC, rs.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagDataSell = get_pagination_data(count($allSales), 25);
    $sales = array_slice($allSales, $pagDataSell['offset'], $pagDataSell['per_page']);
} else {
    // TAB === 'stock': Rough Stock (હજુ શરૂ બાકી)
    $safeFilter = trim((string)($_GET['safe'] ?? ''));
    $dabbaFilter = trim((string)($_GET['dabba'] ?? ''));

    $sql = "SELECT rp.*, p.party_name, b.party_name as broker_name,
            (rp.weight - COALESCE(rp.available_weight, 0)) as used_weight
            FROM rough_purchases rp
            JOIN parties p ON rp.party_id = p.id
            LEFT JOIN parties b ON rp.broker_id = b.id
            WHERE 1=1";
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (rp.purchase_no LIKE ? OR p.party_name LIKE ? OR rp.product_description LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }
    if (!empty($safeFilter)) {
        $sql .= " AND rp.safe = ?";
        $params[] = $safeFilter;
    }
    if (!empty($dabbaFilter)) {
        $sql .= " AND rp.dabba = ?";
        $params[] = $dabbaFilter;
    }
    if (!empty($tagFilter)) {
        $sql .= " AND rp.tag = ?";
        $params[] = $tagFilter;
    }
    if (!empty($processTagFilter)) {
        $sql .= " AND rp.process_tag = ?";
        $params[] = $processTagFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((rp.payment_currency = 'USD' OR COALESCE(rp.dollar_per_carat, 0) > 0) AND COALESCE(rp.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((rp.payment_currency = 'USD' OR COALESCE(rp.dollar_per_carat, 0) > 0) AND COALESCE(rp.dollar_rate, 0) <= 0)";
    }
    if ($statusFilter === 'in_stock') {
        $sql .= " AND (rp.available_weight > 0 OR rp.status = 'in_stock')";
    } elseif ($statusFilter === 'assorted') {
        $sql .= " AND rp.status = 'assorted'";
    } elseif (!empty($statusFilter)) {
        $sql .= " AND rp.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY (rp.available_weight > 0) DESC, rp.purchase_date DESC, rp.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allRoughStockLots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagDataStock = get_pagination_data(count($allRoughStockLots), 25);
    $roughStockLots = array_slice($allRoughStockLots, $pagDataStock['offset'], $pagDataStock['per_page']);

    $allSafes = $pdo->query("SELECT DISTINCT safe FROM rough_purchases WHERE safe IS NOT NULL AND safe != '' ORDER BY safe ASC")->fetchAll(PDO::FETCH_COLUMN);
    $allDabbas = $pdo->query("SELECT DISTINCT dabba FROM rough_purchases WHERE dabba IS NOT NULL AND dabba != '' ORDER BY dabba ASC")->fetchAll(PDO::FETCH_COLUMN);
}
?>

<?= render_page_header(
    'Rough (રફ)',
    '',
    'shapes',
    match($tab) {
        'purchase' => '
            <a href="' . BASE_URL . '/modules/rough_purchase/import.php" class="btn btn-outline-success">' . svg_icon('file-excel', '', 15) . 'બલ્ક આયાત (Excel)</a>
            <a href="' . BASE_URL . '/modules/reports/export.php?type=rough_purchase' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-secondary">' . svg_icon('file-arrow-down', '', 15) . 'Export</a>
            <a href="' . BASE_URL . '/modules/rough_sales/create.php" class="btn btn-outline-primary">' . svg_icon('plus', '', 15) . 'નવું વેચાણ</a>
            <a href="' . BASE_URL . '/modules/rough_purchase/create.php" class="btn btn-primary">' . svg_icon('plus', '', 15) . 'નવી ખરીદી</a>
        ',
        'sell' => '
            <a href="' . BASE_URL . '/modules/reports/export.php?type=rough_sales' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-secondary">' . svg_icon('file-arrow-down', '', 15) . 'Export</a>
            <a href="' . BASE_URL . '/modules/rough_purchase/create.php" class="btn btn-outline-primary">' . svg_icon('plus', '', 15) . 'નવી ખરીદી</a>
            <a href="' . BASE_URL . '/modules/rough_sales/create.php" class="btn btn-primary">' . svg_icon('plus', '', 15) . 'નવું વેચાણ</a>
        ',
        'stock' => '
            <a href="' . BASE_URL . '/modules/rough_purchase/import.php" class="btn btn-outline-success">' . svg_icon('file-excel', '', 15) . 'બલ્ક આયાત (Excel)</a>
            <a href="' . BASE_URL . '/modules/rough_sales/create.php" class="btn btn-outline-primary">' . svg_icon('plus', '', 15) . 'નવું વેચાણ</a>
            <a href="' . BASE_URL . '/modules/rough_purchase/create.php" class="btn btn-outline-secondary">' . svg_icon('plus', '', 15) . 'નવી ખરીદી</a>
            <a href="' . BASE_URL . '/modules/kapan/create.php" class="btn btn-primary">' . svg_icon('scissors', '', 15) . 'નવો કાપન</a>
        '
    }
) ?>

<?php
$periodQueryStr = '&period=' . urlencode($activePeriod) . ($activePeriod === 'custom' && $purDateFrom && $purDateTo ? '&from_date=' . urlencode($purDateFrom) . '&to_date=' . urlencode($purDateTo) : '');
?>

<!-- Period Switcher & Custom Date Filter Toolbar -->
<div class="finora-card p-2.5 mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-dark small"><?= svg_icon('calendar-days', 'text-primary me-1', 15) ?>સમયગાળો (Period):</span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono px-2 py-1"><?= e($periodInfo['label']) ?></span>
        </div>
        <?= render_period_filter_toolbar($activePeriod, $purDateFrom, $purDateTo, BASE_URL . '/modules/rough/index.php', ['tab' => $tab]) ?>
    </div>
</div>

<!-- Top Category Cards (Finora Pastel Cards - Clean & Important Titles Only) -->
<div class="row g-3 mb-2">
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=purchase<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'purchase' ? 'kpi-tone-blue' : 'bg-white border' ?> h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('shapes', '', 19) ?></div>
                    <span class="kpi-arrow-wrap"><?= svg_icon('chevron-right', '', 16) ?></span>
                </div>
                <div class="kpi-label">Rough Purchase (કુલ ખરીદી)</div>
                <div class="kpi-value"><?= format_carat($totPurchaseWeight) ?></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="finora-pill finora-pill-blue"><?= number_format($totPurchasePcs) ?> Pcs</span>
                    <span class="kpi-subtext"><?= $totPurchaseBills ?> બિલો</span>
                </div>
                <div class="mt-1.5 pt-1.5 border-top d-flex justify-content-between small text-muted font-mono">
                    <span>કુલ ખરીદી રકમ:</span>
                    <strong class="text-dark"><?= format_inr($totPurchaseAmount) ?></strong>
                </div>
            </div>
        </a>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'stock' ? 'kpi-tone-green' : 'bg-white border' ?> h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('boxes-stacked', '', 19) ?></div>
                    <span class="kpi-arrow-wrap"><?= svg_icon('chevron-right', '', 16) ?></span>
                </div>
                <div class="kpi-label">Rough Stock (હાજર સ્ટોક)</div>
                <div class="kpi-value text-success"><?= format_carat($totRStockWeight) ?></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="finora-pill finora-pill-green"><?= number_format($totRStockPcs) ?> Pcs</span>
                    <span class="kpi-subtext"><?= $totRStockLots ?> લૉટ્સ હાજર</span>
                </div>
                <div class="mt-1.5 pt-1.5 border-top d-flex justify-content-between small text-muted font-mono">
                    <span>સ્ટોક મૂલ્યાંકન:</span>
                    <strong class="text-success"><?= format_inr($totRStockValue) ?></strong>
                </div>
            </div>
        </a>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/assort/index.php" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora bg-white border h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('layer-group', '', 19) ?></div>
                    <span class="kpi-arrow-wrap"><?= svg_icon('arrow-up-right-from-square', '', 14) ?></span>
                </div>
                <div class="kpi-label">કાપન & એસોર્ટ (In Process)</div>
                <div class="kpi-value text-primary"><?= format_carat($totAssortedWeight) ?></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="finora-pill finora-pill-purple"><?= $totAssortedLots ?> લૉટ્સ ઇશ્યુ</span>
                    <span class="kpi-subtext">ઉત્પાદનમાં ચાલુ</span>
                </div>
                <div class="mt-1.5 pt-1.5 border-top d-flex justify-content-between small text-muted font-mono">
                    <span>પ્રોસેસ સ્ટેજ:</span>
                    <span class="text-primary fw-semibold">એસોર્ટ / મેકિંગ</span>
                </div>
            </div>
        </a>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=sell<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'sell' ? 'kpi-tone-amber' : 'bg-white border' ?> h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('hand-holding-dollar', '', 19) ?></div>
                    <span class="kpi-arrow-wrap"><?= svg_icon('chevron-right', '', 16) ?></span>
                </div>
                <div class="kpi-label">Rough Sell (વેચેલ રફ)</div>
                <div class="kpi-value text-warning-emphasis"><?= format_carat($totSaleWeight) ?></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="finora-pill finora-pill-amber"><?= number_format($totSalePcs) ?> Pcs</span>
                    <span class="kpi-subtext"><?= $totSaleBills ?> બિલો</span>
                </div>
                <div class="mt-1.5 pt-1.5 border-top d-flex justify-content-between small text-muted font-mono">
                    <span>કુલ વેચાણ રકમ:</span>
                    <strong class="text-dark"><?= format_inr($totSaleAmount) ?></strong>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Executive Reconciliation Strip -->
<div class="alert alert-light border rounded-3 py-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2 shadow-sm" style="background: #f8fafc;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary px-2 py-1"><?= svg_icon('scale-balanced', 'me-1', 13) ?>રફ સ્ટોક મેળ (Reconciliation)</span>
        <span class="small text-muted d-none d-md-inline">કુલ ખરીદીમાંથી સ્ટોક અને પ્રોસેસની ચોક્કસ ગણતરી:</span>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 font-mono small">
        <span class="badge bg-white text-dark border">ખરીદી: <strong><?= format_carat($totPurchaseWeight) ?></strong></span>
        <span>=</span>
        <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock<?= $periodQueryStr ?>" class="text-decoration-none">
            <span class="badge bg-success-subtle text-success border border-success-subtle">હાજર સ્ટોક: <strong><?= format_carat($totRStockWeight) ?></strong></span>
        </a>
        <span>+</span>
        <a href="<?= BASE_URL ?>/modules/assort/index.php" class="text-decoration-none">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">કાપન/એસોર્ટ: <strong><?= format_carat($totAssortedWeight) ?></strong></span>
        </a>
        <?php if ($totSaleWeight > 0): ?>
        <span class="text-muted d-none d-lg-inline">|</span>
        <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=sell<?= $periodQueryStr ?>" class="text-decoration-none d-none d-lg-inline">
            <span class="badge bg-secondary-subtle text-secondary border" title="વેચેલ રફ (એસોર્ટ આઉટપુટમાંથી): <?= format_carat($totSaleWeight) ?>">વેચેલ: <strong><?= format_carat($totSaleWeight) ?></strong></span>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($tab === 'purchase'): ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="finora-card-title mb-0">Rough Purchase રજિસ્ટર</h6>
                <button type="button" class="btn btn-sm btn-outline-primary d-none shadow-sm" id="btn_open_merge_modal" onclick="openMergeModal()">
                    <?= svg_icon('code-merge', 'me-1', 14) ?>ખરીદી મર્જ કરો (<span id="merge_selected_count">0</span>)
                </button>
            </div>
            <form method="GET" action="" class="d-flex flex-wrap gap-2 align-items-center mb-0">
                <input type="hidden" name="tab" value="purchase">
                <input type="hidden" name="period" value="<?= e($activePeriod) ?>">
                <?php if ($activePeriod === 'custom' && $purDateFrom && $purDateTo): ?>
                    <input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>">
                    <input type="hidden" name="to_date" value="<?= e($purDateTo) ?>">
                <?php endif; ?>
                <div class="input-group input-group-sm" style="width: 210px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ખરીદી નં, પાર્ટી..." value="<?= e($search) ?>">
                </div>
                <select name="tag" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા TAG</option>
                    <?php foreach ($allTags as $tg): ?>
                        <option value="<?= e($tg['name']) ?>" <?= $tagFilter === $tg['name'] ? 'selected' : '' ?>><?= e($tg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="process_tag" class="form-select form-select-sm" style="width: 155px;" onchange="this.form.submit()">
                    <option value="">બધા PROCESS TAG</option>
                    <?php foreach ($allProcessTags as $ptg): ?>
                        <option value="<?= e($ptg['name']) ?>" <?= $processTagFilter === $ptg['name'] ? 'selected' : '' ?>><?= e($ptg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="in_stock" <?= $statusFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="in_assort" <?= $statusFilter === 'in_assort' ? 'selected' : '' ?>>In Assort</option>
                    <option value="assorted" <?= $statusFilter === 'assorted' ? 'selected' : '' ?>>Assorted</option>
                    <option value="merged" <?= $statusFilter === 'merged' ? 'selected' : '' ?>>Merged (મર્જ થયેલ)</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 145px;" onchange="this.form.submit()">
                    <option value="">બધા દરો (All)</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || !empty($tagFilter) || !empty($processTagFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=purchase" class="btn btn-sm btn-outline-danger" title="રીસેટ ફિલ્ટર"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3 text-center" style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="check_all_purchases" title="બધી પસંદ કરો">
                            </th>
                            <th>ખરીદી નં.</th>
                            <th class="text-nowrap">તારીખ</th>
                            <th>પાર્ટી</th>
                            <th class="text-nowrap">TAG</th>
                            <th class="text-nowrap">PROCESS TAG</th>
                            <th>દલાલ</th>
                            <th class="text-end">નંગ</th>
                            <th class="text-end">વજન</th>
                            <th class="text-end">હાજર વજન</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th class="text-end pe-3 table-sticky-action">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchases)): ?>
                            <tr><td colspan="13" class="text-center py-5 text-muted">કોઈ Rough ખરીદી રેકોર્ડ નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($purchases as $rp): ?>
                                <tr class="<?= $rp['status'] === 'merged' ? 'opacity-75 bg-light-subtle' : '' ?>">
                                    <td class="ps-3 text-center">
                                        <?php if ($rp['status'] === 'merged'): ?>
                                            <span class="text-muted small" title="આ ખરીદી પહેલેથી જ મર્જ થયેલી છે">—</span>
                                        <?php else: ?>
                                            <input type="checkbox" class="form-check-input purchase-select-cb" 
                                                value="<?= $rp['id'] ?>"
                                                data-id="<?= $rp['id'] ?>"
                                                data-purchase-no="<?= e($rp['purchase_no']) ?>"
                                                data-party="<?= e($rp['party_name']) ?>"
                                                data-weight="<?= (float)$rp['weight'] ?>"
                                                data-avail-weight="<?= (float)$rp['available_weight'] ?>"
                                                data-pcs="<?= (int)$rp['pcs'] ?>"
                                                data-price="<?= (float)$rp['price_per_carat_inr'] ?>"
                                                data-net="<?= (float)$rp['net_payment'] ?>"
                                                data-date="<?= format_date($rp['purchase_date']) ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($rp) ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $rp['id'] ?>" class="text-primary text-decoration-none"><?= e($rp['purchase_no']) ?></a>
                                        <?php if ($rp['status'] === 'merged'): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border font-mono ms-1 small" style="font-size: 0.72rem;">
                                                🔀 Merged into #<?= e($rp['merged_into_no'] ?? (string)$rp['merged_into_id']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= format_date($rp['purchase_date']) ?></td>
                                    <td class="fw-semibold text-dark"><?= e($rp['party_name']) ?></td>
                                    <td class="text-nowrap"><?= render_tag_badge($rp['tag'] ?? 'Rough') ?></td>
                                    <td class="text-nowrap"><?= render_process_tag_badge($rp['process_tag'] ?? 'Assort') ?></td>
                                    <td class="text-muted"><?= e($rp['broker_name'] ?? '—') ?></td>
                                    <td class="text-end font-mono"><?= (int)$rp['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$rp['weight']) ?></td>
                                    <td class="text-end font-mono text-success fw-semibold"><?= format_carat((float)$rp['available_weight']) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($rp)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$rp['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$rp['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold">
                                        <?php if (is_stand_dollar($rp)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$rp['net_payment']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap table-sticky-action">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <?php if ($rp['status'] !== 'merged' && (float)$rp['available_weight'] > 0): ?>
                                                <a href="<?= BASE_URL ?>/modules/assort/create.php?rough_id=<?= $rp['id'] ?>" class="btn btn-xs btn-success text-white d-inline-flex align-items-center gap-1 shadow-sm px-2" title="ભાગ પાડો (Assort)">
                                                    <?= svg_icon('layer-group', '', 12) ?>
                                                    <span>એસોર્ટ</span>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $rp['id'] ?>" class="btn btn-xs btn-outline-primary" title="જુઓ"><?= svg_icon('eye', '', 13) ?></a>
                                            <?php if ($rp['status'] !== 'merged'): ?>
                                                <a href="<?= BASE_URL ?>/modules/rough_purchase/edit.php?id=<?= $rp['id'] ?>" class="btn btn-xs btn-outline-secondary" title="એડિટ"><?= svg_icon('pen-to-square', '', 13) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagDataBuy, BASE_URL . '/modules/rough/index.php', ['tab' => 'purchase'] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($tagFilter) ? ['tag' => $tagFilter] : []) + (!empty($processTagFilter) ? ['process_tag' => $processTagFilter] : [])) ?>
        </div>
    </div>

<?php elseif ($tab === 'sell'): ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header flex-wrap">
            <h6 class="finora-card-title">Rough Sell રજિસ્ટર</h6>
            <form method="GET" action="" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="tab" value="sell">
                <input type="hidden" name="period" value="<?= e($activePeriod) ?>">
                <?php if ($activePeriod === 'custom' && $purDateFrom && $purDateTo): ?>
                    <input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>">
                    <input type="hidden" name="to_date" value="<?= e($purDateTo) ?>">
                <?php endif; ?>
                <div class="input-group input-group-sm" style="width: 230px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ઇન્વોઇસ નં, પાર્ટી..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 145px;" onchange="this.form.submit()">
                    <option value="">બધા દરો (All)</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=sell" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">ઇન્વોઇસ નં.</th>
                            <th class="text-nowrap">તારીખ</th>
                            <th>ગ્રાહક પાર્ટી</th>
                            <th>દલાલ</th>
                            <th>મૂળ ખરીદી લોટ (History)</th>
                            <th class="text-end">નંગ</th>
                            <th class="text-end">વજન</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end pe-3 table-sticky-action">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr><td colspan="11" class="text-center py-5 text-muted">કોઈ Rough વેચાણ રેકોર્ડ નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sales as $s): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($s) ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_sales/view.php?id=<?= $s['id'] ?>" class="text-primary text-decoration-none fw-bold" title="આ રફ વેચાણનો સંપૂર્ણ ઇતિહાસ જુઓ">
                                            <?= e($s['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td class="text-nowrap"><?= format_date($s['sale_date']) ?></td>
                                    <td class="fw-semibold text-dark"><?= e($s['party_name']) ?></td>
                                    <td class="text-muted"><?= e($s['broker_name'] ?? '—') ?></td>
                                    <td>
                                        <?php if (!empty($s['purchase_no'])): ?>
                                            <div>
                                                <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $s['purchase_id'] ?>" class="font-mono fw-bold text-primary text-decoration-none" title="મૂળ ખરીદી વિગત જુઓ">
                                                    #<?= e($s['purchase_no']) ?>
                                                </a>
                                                <?php if (!empty($s['supplier_name'])): ?>
                                                    <small class="text-muted d-block text-truncate" style="max-width: 140px; font-size: 0.72rem;">
                                                        <?= e($s['supplier_name']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($s['source_type'] === 'assort_part' && !empty($s['part_name'])): ?>
                                            <span class="badge bg-warning-subtle text-dark border font-mono" style="font-size: 0.70rem;" title="Assort પાર્ટમાંથી વેચાણ">
                                                Assort: <?= e($s['part_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border" style="font-size: 0.70rem;">ડાયરેક્ટ રફ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono"><?= (int)$s['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$s['weight']) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$s['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['net_amount']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['payment_status'] === 'paid'): ?>
                                            <span class="finora-pill finora-pill-green">Paid</span>
                                        <?php elseif ($s['payment_status'] === 'partial'): ?>
                                            <span class="finora-pill finora-pill-amber">Partial</span>
                                        <?php else: ?>
                                            <span class="finora-pill finora-pill-rose">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap table-sticky-action">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/rough_sales/view.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-primary py-1 px-2" title="સંપૂર્ણ હિસ્ટ્રી / વિગત જુઓ">
                                                <?= svg_icon('eye', 'me-1', 12) ?>હિસ્ટ્રી
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/rough_sales/edit.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" title="એડિટ">
                                                <?= svg_icon('pen-to-square', 'me-1', 12) ?>એડિટ
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagDataSell, BASE_URL . '/modules/rough/index.php', ['tab' => 'sell'] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : [])) ?>
        </div>
    </div>

<?php else: ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header flex-wrap gap-2">
            <h6 class="finora-card-title mb-0">Rough Stock રજિસ્ટર</h6>
            <form method="GET" action="" class="d-flex flex-wrap gap-2 align-items-center mb-0">
                <input type="hidden" name="tab" value="stock">
                <div class="input-group input-group-sm" style="width: 200px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="પેકેટ નં, વિગત, પાર્ટી..." value="<?= e($search) ?>">
                </div>
                
                <select name="tag" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
                    <option value="">બધા TAG</option>
                    <?php foreach ($allTags ?? [] as $tg): ?>
                        <option value="<?= e($tg['name']) ?>" <?= ($tagFilter ?? '') === $tg['name'] ? 'selected' : '' ?>><?= e($tg['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="process_tag" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા PROCESS TAG</option>
                    <?php foreach ($allProcessTags ?? [] as $ptg): ?>
                        <option value="<?= e($ptg['name']) ?>" <?= ($processTagFilter ?? '') === $ptg['name'] ? 'selected' : '' ?>><?= e($ptg['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="safe" class="form-select form-select-sm" style="width: 120px;" onchange="this.form.submit()">
                    <option value="">બધા SAFE</option>
                    <?php foreach ($allSafes ?? [] as $sf): ?>
                        <option value="<?= e($sf) ?>" <?= ($safeFilter ?? '') === $sf ? 'selected' : '' ?>><?= e($sf) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="dabba" class="form-select form-select-sm" style="width: 120px;" onchange="this.form.submit()">
                    <option value="">બધા DABBA</option>
                    <?php foreach ($allDabbas ?? [] as $db): ?>
                        <option value="<?= e($db) ?>" <?= ($dabbaFilter ?? '') === $db ? 'selected' : '' ?>><?= e($db) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
                    <option value="">બધો સ્ટોક</option>
                    <option value="in_stock" <?= $statusFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="assorted" <?= $statusFilter === 'assorted' ? 'selected' : '' ?>>Assorted</option>
                </select>

                <select name="stand_dollar" class="form-select form-select-sm" style="width: 145px;" onchange="this.form.submit()">
                    <option value="">બધા દરો (All)</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>

                <?php if (!empty($search) || !empty($statusFilter) || !empty($safeFilter) || !empty($dabbaFilter) || !empty($tagFilter) || !empty($processTagFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock" class="btn btn-sm btn-outline-danger" title="રીસેટ"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Packet No</th>
                            <th class="text-nowrap">TAG</th>
                            <th class="text-nowrap">PROCESS TAG</th>
                            <th class="text-end">Pcs</th>
                            <th class="text-end">Weight</th>
                            <th class="text-end">Avg Size</th>
                            <th class="text-end">Price / Ct</th>
                            <th class="text-center">DABBA</th>
                            <th class="text-center">SAFE</th>
                            <th>Detail</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($roughStockLots)): ?>
                            <tr><td colspan="13" class="text-center py-5 text-muted">કોઈ Rough સ્ટોક રેકોર્ડ નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($roughStockLots as $stk): 
                                $curWt = (float)($stk['available_weight'] ?? $stk['weight']);
                                $curPcs = (int)($stk['available_pcs'] ?? $stk['pcs']);
                                $priceCt = (float)($stk['price_per_carat_inr'] ?? 0);
                                $totalVal = $curWt * $priceCt;
                                $avgSize = ($curPcs > 0 && $curWt > 0) ? ($curWt / $curPcs) : 0;
                                $isPendingStart = ($curWt > 0 || $stk['status'] === 'in_stock');
                            ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($stk) ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $stk['id'] ?>" class="text-primary text-decoration-none">
                                            <?= e($stk['purchase_no']) ?>
                                        </a>
                                    </td>
                                    <td class="text-nowrap"><?= render_tag_badge($stk['tag'] ?? 'Rough') ?></td>
                                    <td class="text-nowrap"><?= render_process_tag_badge($stk['process_tag'] ?? 'Assort') ?></td>
                                    <td class="text-end font-mono"><?= $curPcs ?></td>
                                    <td class="text-end font-mono fw-bold text-success"><?= format_carat($curWt) ?></td>
                                    <td class="text-end font-mono text-dark fw-semibold"><?= $avgSize > 0 ? number_format($avgSize, 3) : '-' ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($stk)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$stk['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr($priceCt) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($stk['dabba'])): ?>
                                            <span class="badge bg-light text-dark border font-mono"><?= e($stk['dabba']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($stk['safe'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono"><?= e($stk['safe']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-truncate" style="max-width: 180px;" title="<?= e($stk['product_description'] ?? '') ?>">
                                        <?= !empty($stk['product_description']) ? e($stk['product_description']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_inr($totalVal) ?></td>
                                    <td>
                                        <span class="finora-pill <?= $isPendingStart ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                            <?= $isPendingStart ? 'In Stock' : 'Assorted' ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap table-sticky-action">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <?php if ($isPendingStart): ?>
                                                <a href="<?= BASE_URL ?>/modules/assort/create.php?rough_id=<?= $stk['id'] ?>" class="btn btn-xs btn-outline-success" title="ભાગ પાડો (Assort)">
                                                    <?= svg_icon('layer-group', '', 13) ?>
                                                    <span>એસોર્ટ</span>
                                                </a>
                                                <a href="<?= BASE_URL ?>/modules/kapan/create.php?rough_id=<?= $stk['id'] ?>" class="btn btn-xs btn-outline-primary" title="કાપન બનાવો">
                                                    <?= svg_icon('scissors', '', 13) ?>
                                                    <span>કાપન</span>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $stk['id'] ?>" class="btn btn-xs btn-outline-secondary" title="જુઓ"><?= svg_icon('eye', '', 13) ?></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagDataStock, BASE_URL . '/modules/rough/index.php', ['tab' => 'stock'] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($safeFilter) ? ['safe' => $safeFilter] : []) + (!empty($dabbaFilter) ? ['dabba' => $dabbaFilter] : [])) ?>
        </div>
    </div>
<?php endif; ?>

<!-- Floating Sticky Merge Action Bar -->
<div id="sticky_merge_bar" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg p-2.5 px-4 bg-dark text-white rounded-pill d-none align-items-center gap-3 border border-secondary" style="z-index: 1050;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary rounded-pill fs-7 px-2.5 py-1" id="sticky_count">0</span>
        <span class="fw-semibold small">ખરીદી પસંદ કરેલ છે</span>
    </div>
    <div class="vr bg-secondary" style="height: 20px;"></div>
    <button type="button" class="btn btn-sm btn-primary fw-bold px-3 rounded-pill d-flex align-items-center gap-1.5 shadow-sm" onclick="openMergeModal()">
        <?= svg_icon('code-merge', '', 14) ?>
        <span>પસંદ કરેલ ખરીદી મર્જ કરો (Merge)</span>
    </button>
    <button type="button" class="btn btn-sm btn-link text-white-50 text-decoration-none p-0 ms-1" onclick="clearSelectedPurchases()">રદ કરો</button>
</div>

<!-- Modal: રફ ખરીદી મર્જ કરો (Merge Rough Purchases) -->
<div class="modal fade" id="modalMergePurchases" tabindex="-1" aria-labelledby="modalMergePurchasesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 bg-primary-subtle text-primary rounded-3">
                        <?= svg_icon('code-merge', '', 20) ?>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="modalMergePurchasesLabel">રફ ખરીદી મર્જ કરો (Merge Rough Purchases)</h5>
                        <small class="text-muted">પસંદ કરેલી ખરીદીઓનું વજન, નંગ અને ભાવ ભેગા કરીને સિંગલ લોટ બનશે.</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formMergePurchases" method="POST" action="<?= BASE_URL ?>/modules/rough_purchase/merge.php">
                <?= csrf_field() ?>
                <input type="hidden" name="purchase_ids" id="merge_hidden_ids">
                <div class="modal-body p-4">
                    <!-- 1. Table of Selected Purchases -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-1.5">પસંદ કરેલ ખરીદીઓ (Selected Purchases to Merge):</label>
                        <div class="table-responsive border rounded-3 bg-white">
                            <table class="table table-sm table-hover mb-0" id="merge_items_table">
                                <thead class="table-light">
                                    <tr class="small text-muted">
                                        <th class="ps-3 text-center" style="width: 70px;">Master</th>
                                        <th>ખરીદી નં.</th>
                                        <th>તારીખ</th>
                                        <th>પાર્ટી</th>
                                        <th class="text-end">નંગ</th>
                                        <th class="text-end">કુલ વજન</th>
                                        <th class="text-end">હાજર વજન</th>
                                        <th class="text-end">ભાવ/Ct</th>
                                        <th class="text-end pe-3">નેટ રકમ</th>
                                    </tr>
                                </thead>
                                <tbody id="merge_items_tbody">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 2. Target Configuration -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">માસ્ટર ખરીદી લોટ (Target Master Lot) *</label>
                            <select name="target_id" id="merge_target_select" class="form-select form-select-lg fw-bold font-mono text-primary" required onchange="syncMasterSelectionFromDropdown(this.value)">
                                <!-- populated dynamically -->
                            </select>
                            <div class="form-text small text-muted">અન્ય તમામ ખરીદી આ માસ્ટર ખરીદીમાં મર્જ થઈ જશે.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">નવો ખરીદી નંબર આપવો હોય તો (Custom Purchase No)</label>
                            <input type="text" name="custom_purchase_no" id="merge_custom_purchase_no" class="form-control form-control-lg font-mono" placeholder="ખાલી = માસ્ટર નંબર જળવાશે">
                            <div class="form-text small text-muted">દા.ત. '1+5' અથવા નવો સંયુક્ત નંબર આપવા માટે.</div>
                        </div>
                    </div>

                    <!-- 3. Live Calculated Merge Summary -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="row text-center g-2">
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block" style="font-size:0.75rem;">કુલ સંયુક્ત નંગ</small>
                                <strong class="fs-6 font-mono text-dark" id="merge_calc_pcs">0 Pcs</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block" style="font-size:0.75rem;">કુલ સંયુક્ત વજન</small>
                                <strong class="fs-6 font-mono text-primary" id="merge_calc_weight">0.00 ct</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block" style="font-size:0.75rem;">હાજર વજન (Available)</small>
                                <strong class="fs-6 font-mono text-success" id="merge_calc_avail_weight">0.00 ct</strong>
                            </div>
                            <div class="col-6 col-md-3">
                                <small class="text-muted d-block" style="font-size:0.75rem;">નવો સરેરાશ ભાવ (Avg Rate)</small>
                                <strong class="fs-6 font-mono text-dark" id="merge_calc_avg_rate">₹ 0.00 / Ct</strong>
                            </div>
                            <div class="col-12 border-top pt-2 mt-2">
                                <div class="d-flex justify-content-between align-items-center px-2">
                                    <span class="text-muted small">કુલ સંયુક્ત નેટ રકમ (Total Merged Net Value):</span>
                                    <strong class="fs-5 font-mono text-success" id="merge_calc_net">₹ 0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Merge Notes -->
                    <div class="mb-0">
                        <label class="form-label small fw-bold text-muted">મર્જ નોંધ (Notes / Reason):</label>
                        <input type="text" name="notes" id="merge_notes" class="form-control" placeholder="દા.ત. ખરીદી ૫ અને ૧ એક જ માલ હોવાથી મર્જ કર્યા.">
                    </div>
                </div>
                <div class="modal-footer bg-light border-top py-2.5 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" id="btn_submit_merge">
                        <?= svg_icon('code-merge', 'me-1') ?>કન્ફર્મ મર્જ કરો (Execute Merge)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('check_all_purchases');
    const checkboxes = document.querySelectorAll('.purchase-select-cb');
    const btnOpenModal = document.getElementById('btn_open_merge_modal');
    const stickyBar = document.getElementById('sticky_merge_bar');
    const countBadge = document.getElementById('merge_selected_count');
    const stickyCount = document.getElementById('sticky_count');

    function updateSelectionState() {
        const checked = document.querySelectorAll('.purchase-select-cb:checked');
        const count = checked.length;
        if (countBadge) countBadge.innerText = count;
        if (stickyCount) stickyCount.innerText = count;

        if (count >= 2) {
            btnOpenModal?.classList.remove('d-none');
            stickyBar?.classList.remove('d-none');
            stickyBar?.classList.add('d-flex');
        } else {
            btnOpenModal?.classList.add('d-none');
            stickyBar?.classList.add('d-none');
            stickyBar?.classList.remove('d-flex');
        }
    }

    checkAll?.addEventListener('change', function () {
        checkboxes.forEach(cb => {
            if (!cb.disabled) {
                cb.checked = checkAll.checked;
            }
        });
        updateSelectionState();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function () {
            updateSelectionState();
            if (checkAll) {
                const totalActive = document.querySelectorAll('.purchase-select-cb:not(:disabled)').length;
                const totalChecked = document.querySelectorAll('.purchase-select-cb:checked').length;
                checkAll.checked = totalActive > 0 && totalActive === totalChecked;
            }
        });
    });

    window.clearSelectedPurchases = function() {
        checkboxes.forEach(cb => cb.checked = false);
        if (checkAll) checkAll.checked = false;
        updateSelectionState();
    };

    window.openMergeModal = function () {
        const checked = Array.from(document.querySelectorAll('.purchase-select-cb:checked'));
        if (checked.length < 2) {
            alert('મર્જ કરવા માટે ઓછામાં ઓછી ૨ ખરીદી પસંદ કરવી જરૂરી છે.');
            return;
        }

        const ids = checked.map(cb => cb.value);
        document.getElementById('merge_hidden_ids').value = ids.join(',');

        const targetSelect = document.getElementById('merge_target_select');
        const itemsTbody = document.getElementById('merge_items_tbody');
        targetSelect.innerHTML = '';
        itemsTbody.innerHTML = '';

        let totPcs = 0;
        let totWeight = 0;
        let totAvailWeight = 0;
        let totNet = 0;

        checked.forEach((cb, idx) => {
            const id = cb.dataset.id;
            const pNo = cb.dataset.purchaseNo;
            const party = cb.dataset.party;
            const wt = parseFloat(cb.dataset.weight) || 0;
            const avWt = parseFloat(cb.dataset.availWeight) || 0;
            const pcs = parseInt(cb.dataset.pcs) || 0;
            const price = parseFloat(cb.dataset.price) || 0;
            const net = parseFloat(cb.dataset.net) || 0;
            const date = cb.dataset.date;

            totPcs += pcs;
            totWeight += wt;
            totAvailWeight += avWt;
            totNet += net;

            const isDefaultTarget = (idx === 0);

            // Row in modal table
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-3 text-center">
                    <input type="radio" class="form-check-input merge-radio-target" name="merge_radio_pick" value="${id}" ${isDefaultTarget ? 'checked' : ''} onchange="syncMasterSelectionFromRadio(this.value)">
                </td>
                <td class="font-mono fw-bold text-dark">#${pNo}</td>
                <td class="text-nowrap small text-muted">${date}</td>
                <td class="fw-semibold text-truncate" style="max-width:140px;">${party}</td>
                <td class="text-end font-mono">${pcs}</td>
                <td class="text-end font-mono fw-bold text-primary">${wt.toFixed(2)}</td>
                <td class="text-end font-mono text-success">${avWt.toFixed(2)}</td>
                <td class="text-end font-mono small">₹${price.toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 2})}</td>
                <td class="text-end font-mono fw-bold pe-3">₹${net.toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 2})}</td>
            `;
            itemsTbody.appendChild(tr);

            // Option in Target dropdown
            const opt = document.createElement('option');
            opt.value = id;
            opt.text = `ખરીદી #${pNo} (${party} - ${wt.toFixed(2)} ct)`;
            if (isDefaultTarget) opt.selected = true;
            targetSelect.appendChild(opt);
        });

        // Set live totals in modal
        const avgRate = totWeight > 0 ? (totNet / totWeight) : 0;
        document.getElementById('merge_calc_pcs').innerText = totPcs + ' Pcs';
        document.getElementById('merge_calc_weight').innerText = totWeight.toFixed(2) + ' ct';
        document.getElementById('merge_calc_avail_weight').innerText = totAvailWeight.toFixed(2) + ' ct';
        document.getElementById('merge_calc_avg_rate').innerText = '₹ ' + avgRate.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' / Ct';
        document.getElementById('merge_calc_net').innerText = '₹ ' + totNet.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const pNos = checked.map(cb => cb.dataset.purchaseNo);
        document.getElementById('merge_notes').value = `ખરીદી નં. ${pNos.join(' અને ')} મર્જ કર્યા.`;

        const modalEl = document.getElementById('modalMergePurchases');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    window.syncMasterSelectionFromDropdown = function(val) {
        document.querySelectorAll('.merge-radio-target').forEach(r => {
            r.checked = (r.value === val);
        });
    };

    window.syncMasterSelectionFromRadio = function(val) {
        const sel = document.getElementById('merge_target_select');
        if (sel) sel.value = val;
    };

    document.getElementById('formMergePurchases')?.addEventListener('submit', function () {
        const btn = document.getElementById('btn_submit_merge');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>મર્જ થઈ રહ્યું છે...`;
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
