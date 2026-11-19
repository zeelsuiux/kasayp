<?php
/**
 * Polish Hub (પોલિશ વેપાર & સ્ટોક મેનેજમેન્ટ) - Unified Purchases, Sales & Completed Stock
 * Kasyap Diamond ERP - Surat & Mumbai
 * Executive AeuxGlobal / Donezo Design
 */

declare(strict_types=1);

$rawTab = trim((string)($_GET['tab'] ?? 'gia_stock'));

// Legacy / alias tab mapping
$tabAliases = [
    'purchase'        => 'gia_purchase',
    'sell'            => 'gia_sell',
    'gia'             => 'gia_stock',
    'parcel'          => 'parcel_stock',
    'stock'           => 'gia_stock',
    'sell_memo'       => 'sell_jangad',
    'gia_memo'        => 'gia_jangad',
    'memo'            => 'sell_jangad',
];
$tab = $tabAliases[$rawTab] ?? $rawTab;

if (!in_array($tab, ['gia_stock', 'parcel_stock', 'sell_jangad', 'gia_jangad', 'gia_purchase', 'parcel_purchase', 'gia_sell', 'parcel_sell'], true)) {
    $tab = 'gia_stock';
}

$section = trim((string)($_GET['section'] ?? ''));
if ($section === 'parcel') {
    $isPolishParcel = true;
    $isPolishGia = false;
} elseif ($section === 'gia') {
    $isPolishGia = true;
    $isPolishParcel = false;
} else {
    if (in_array($tab, ['parcel_stock', 'parcel_purchase', 'parcel_sell'], true)) {
        $section = 'parcel';
        $isPolishParcel = true;
        $isPolishGia = false;
    } else {
        $section = 'gia';
        $isPolishGia = true;
        $isPolishParcel = false;
    }
}

require_once __DIR__ . '/../../includes/helpers.php';

// Time Period & Date Range Filter: Current Financial Year ('fy'), Month, Today, Custom, or All History ('all')
$period   = trim((string)($_GET['period'] ?? 'fy'));
$fromDate = !empty($_GET['from_date']) ? trim((string)$_GET['from_date']) : null;
$toDate   = !empty($_GET['to_date']) ? trim((string)$_GET['to_date']) : null;
$pInfo    = get_filter_period_dates($period, $fromDate, $toDate);
$period   = $pInfo['period'];
$purDateFrom = $pInfo['from_date'];
$purDateTo   = $pInfo['to_date'];
$fyLabel     = $pInfo['fy_label'];

$locationFilter = trim((string)($_GET['location'] ?? '')); // 'Surat', 'Mumbai', or ''

$pageTitle = match($tab) {
    'gia_stock'       => '💎 GIA સ્ટોક (GIA Certified Polish)',
    'parcel_stock'    => '📦 PARCEL સ્ટોક (Parcel Live Stock)',
    'sell_jangad'     => '🤝 વેચાણ જાંગડ સ્ટોક (Sell Memo Jangad)',
    'gia_jangad'      => '🔬 GIA જાંગડ સ્ટોક (GIA Lab Jangad)',
    'gia_purchase'    => '💎 GIA ખરીદી (GIA Diamond Purchase)',
    'parcel_purchase' => '📦 PARCEL ખરીદી (Parcel Diamond Purchase)',
    'gia_sell'        => '💎 GIA વેચાણ (GIA Diamond Sell)',
    'parcel_sell'     => '📦 PARCEL વેચાણ (Parcel Diamond Sell)',
};

require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// Sync any finished manufacturing packets into polish_stocks
sync_all_completed_packets_to_polish_stock();

$isConsolidated = false;
$scJoinP_pp   = "";
$scJoinB_pp   = "";
$scJoinP_ps   = "";
$scJoinB_ps   = "";
$scJoinStk_ps = "";
$scShape_pp   = "";
$scShape_ps   = "";
$scKapan_stk  = "";
$scPur_stk    = "";
$scPty_stk    = "";
$scPkt_stk    = "";
$scSa_col     = "";
$scSa_join    = "";

// ─────────────────────────────────────────────────────────────────
// LIVE AGGREGATES: RESPECTING FINANCIAL YEAR, CUSTOM DATES & ALL HISTORY
// ─────────────────────────────────────────────────────────────────
$purDateSql = "";
$purParams = [];
if ($purDateFrom && $purDateTo) {
    $purDateSql = " AND purchase_date BETWEEN ? AND ?";
    $purParams = [$purDateFrom, $purDateTo];
}

$saleDateSql = "";
$saleParams = [];
if ($purDateFrom && $purDateTo) {
    $saleDateSql = " AND ps.sale_date BETWEEN ? AND ?";
    $saleParams = [$purDateFrom, $purDateTo];
}

$periodParams = ['period' => $period] + ($period === 'custom' && $purDateFrom && $purDateTo ? ['from_date' => $purDateFrom, 'to_date' => $purDateTo] : []) + (!empty($section) ? ['section' => $section] : []);
$periodQueryStr = '&' . http_build_query($periodParams);

// 1. GIA Purchases
$giaPurStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bills,
        COALESCE(SUM(pcs), 0) as pcs,
        COALESCE(SUM(weight), 0) as weight,
        COALESCE(SUM(net_payment), 0) as total_amount
    FROM polish_purchases
    WHERE (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))
    $purDateSql
");
$giaPurStmt->execute($purParams);
$giaPurStats = $giaPurStmt->fetch(PDO::FETCH_ASSOC);

// 2. Parcel Purchases
$parcelPurStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bills,
        COALESCE(SUM(pcs), 0) as pcs,
        COALESCE(SUM(weight), 0) as weight,
        COALESCE(SUM(net_payment), 0) as total_amount
    FROM polish_purchases
    WHERE (stock_type = 'parcel' OR (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = '')))
    $purDateSql
");
$parcelPurStmt->execute($purParams);
$parcelPurStats = $parcelPurStmt->fetch(PDO::FETCH_ASSOC);

// 3. GIA Sales
$giaSaleStmt = $pdo->prepare("
    SELECT 
        COUNT(ps.id) as bills,
        COALESCE(SUM(ps.pcs), 0) as pcs,
        COALESCE(SUM(ps.weight), 0) as weight,
        COALESCE(SUM(ps.net_amount), 0) as total_amount
    FROM polish_sales ps
    JOIN polish_stocks stk ON ps.polish_stock_id = stk.id $scJoinStk_ps
    WHERE (stk.stock_type = 'gia_single' OR (stk.gia_number IS NOT NULL AND stk.gia_number != ''))
    $saleDateSql
");
$giaSaleStmt->execute($saleParams);
$giaSaleStats = $giaSaleStmt->fetch(PDO::FETCH_ASSOC);

// 4. Parcel Sales
$parcelSaleStmt = $pdo->prepare("
    SELECT 
        COUNT(ps.id) as bills,
        COALESCE(SUM(ps.pcs), 0) as pcs,
        COALESCE(SUM(ps.weight), 0) as weight,
        COALESCE(SUM(ps.net_amount), 0) as total_amount
    FROM polish_sales ps
    JOIN polish_stocks stk ON ps.polish_stock_id = stk.id $scJoinStk_ps
    WHERE (stk.stock_type = 'parcel' OR (stk.stock_type != 'gia_single' AND (stk.gia_number IS NULL OR stk.gia_number = '')))
    $saleDateSql
");
$parcelSaleStmt->execute($saleParams);
$parcelSaleStats = $parcelSaleStmt->fetch(PDO::FETCH_ASSOC);

// 5. GIA Stock Aggregates (Available / હાજર સ્ટોક)
$giaStockAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))
")->fetch(PDO::FETCH_ASSOC);

// GIA Stock by Location (Surat vs Mumbai)
$giaSuratStock = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))
      AND (current_location LIKE '%Surat%' OR current_location IS NULL OR current_location = '' OR current_location = 'Vault')
")->fetch(PDO::FETCH_ASSOC);

$giaMumbaiStock = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))
      AND current_location LIKE '%Mumbai%'
")->fetch(PDO::FETCH_ASSOC);

// 6. PARCEL Stock Aggregates (Available / હાજર સ્ટોક)
$parcelStockAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = ''))
")->fetch(PDO::FETCH_ASSOC);

// Parcel Stock by Location (Surat vs Mumbai)
$parcelSuratStock = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = ''))
      AND (current_location LIKE '%Surat%' OR current_location IS NULL OR current_location = '' OR current_location = 'Vault')
")->fetch(PDO::FETCH_ASSOC);

$parcelMumbaiStock = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * sale_price_per_carat), 0) as total_val
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = ''))
      AND current_location LIKE '%Mumbai%'
")->fetch(PDO::FETCH_ASSOC);

$totGiaPurWeight     = (float)($giaPurStats['weight'] ?? 0);
$totGiaPurPcs        = (int)($giaPurStats['pcs'] ?? 0);
$totGiaPurBills      = (int)($giaPurStats['bills'] ?? 0);
$totGiaPurAmount     = (float)($giaPurStats['total_amount'] ?? 0);

$totParcelPurWeight  = (float)($parcelPurStats['weight'] ?? 0);
$totParcelPurPcs     = (int)($parcelPurStats['pcs'] ?? 0);
$totParcelPurBills   = (int)($parcelPurStats['bills'] ?? 0);
$totParcelPurAmount  = (float)($parcelPurStats['total_amount'] ?? 0);

$totGiaSaleWeight    = (float)($giaSaleStats['weight'] ?? 0);
$totGiaSalePcs       = (int)($giaSaleStats['pcs'] ?? 0);
$totGiaSaleBills     = (int)($giaSaleStats['bills'] ?? 0);
$totGiaSaleAmount    = (float)($giaSaleStats['total_amount'] ?? 0);

$totParcelSaleWeight = (float)($parcelSaleStats['weight'] ?? 0);
$totParcelSalePcs    = (int)($parcelSaleStats['pcs'] ?? 0);
$totParcelSaleBills  = (int)($parcelSaleStats['bills'] ?? 0);
$totParcelSaleAmount = (float)($parcelSaleStats['total_amount'] ?? 0);

$totGiaWeight        = (float)($giaStockAgg['weight'] ?? 0);
$totGiaPcs           = (int)($giaStockAgg['pcs'] ?? 0);
$totGiaLots          = (int)($giaStockAgg['cnt'] ?? 0);
$totGiaStockVal      = (float)($giaStockAgg['total_val'] ?? 0);

$totParcelWeight     = (float)($parcelStockAgg['weight'] ?? 0);
$totParcelPcs        = (int)($parcelStockAgg['pcs'] ?? 0);
$totParcelLots       = (int)($parcelStockAgg['cnt'] ?? 0);
$totParcelStockVal   = (float)($parcelStockAgg['total_val'] ?? 0);

// 7. Sell mate gayelo Jangad Stock (memo_broker, memo_party)
$sellJangadAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(GREATEST(0, total_pcs - COALESCE(sold_pcs, 0) - COALESCE(returned_pcs, 0))), 0) as pending_pcs,
        COALESCE(SUM(GREATEST(0, total_weight - COALESCE(sold_weight, 0) - COALESCE(returned_weight, 0) - COALESCE(total_tops_weight, 0))), 0) as pending_weight
    FROM jangad_vouchers 
    WHERE voucher_type = 'issue' 
      AND category IN ('memo_broker', 'memo_party')
      AND status IN ('issued', 'partially_returned')
")->fetch(PDO::FETCH_ASSOC);

// 8. GIA ma gayelo Jangad Stock (lab_gia)
$giaJangadAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(GREATEST(0, total_pcs - COALESCE(sold_pcs, 0) - COALESCE(returned_pcs, 0))), 0) as pending_pcs,
        COALESCE(SUM(GREATEST(0, total_weight - COALESCE(sold_weight, 0) - COALESCE(returned_weight, 0) - COALESCE(total_tops_weight, 0))), 0) as pending_weight
    FROM jangad_vouchers 
    WHERE voucher_type = 'issue' 
      AND category = 'lab_gia'
      AND status IN ('issued', 'partially_returned')
")->fetch(PDO::FETCH_ASSOC);

$totSellJangadWeight   = (float)($sellJangadAgg['pending_weight'] ?? 0);
$totSellJangadPcs      = (int)($sellJangadAgg['pending_pcs'] ?? 0);
$totSellJangadVouchers = (int)($sellJangadAgg['cnt'] ?? 0);

$totGiaJangadWeight    = (float)($giaJangadAgg['pending_weight'] ?? 0);
$totGiaJangadPcs       = (int)($giaJangadAgg['pending_pcs'] ?? 0);
$totGiaJangadVouchers  = (int)($giaJangadAgg['cnt'] ?? 0);

// 9. Mfg Polish Aggregates & Net Calculation (Mfg Polish - GIA Certification Jangad)
$mfgPolishAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight,
        COALESCE(SUM(available_weight * cost_per_carat), 0) as total_val
    FROM polish_stocks
    WHERE status IN ('available', 'partially_on_jangad', 'in_stock')
      AND available_weight > 0
      AND (source_type = 'mfg' OR kapan_id IS NOT NULL)
")->fetch(PDO::FETCH_ASSOC);

$kapanPolishAgg = $pdo->query("
    SELECT 
        COALESCE(SUM(total_polish_weight), 0) as total_mfg_weight,
        COALESCE(SUM(total_polish_pcs), 0) as total_mfg_pcs
    FROM kapans
")->fetch(PDO::FETCH_ASSOC);

$totMfgPolishWeight = (float)($mfgPolishAgg['weight'] ?? 0);
$totMfgPolishPcs    = (int)($mfgPolishAgg['pcs'] ?? 0);
$totMfgPolishLots   = (int)($mfgPolishAgg['cnt'] ?? 0);
$totMfgPolishVal    = (float)($mfgPolishAgg['total_val'] ?? 0);

if ($totMfgPolishWeight <= 0 && (float)($kapanPolishAgg['total_mfg_weight'] ?? 0) > 0) {
    $totMfgPolishWeight = (float)$kapanPolishAgg['total_mfg_weight'];
    $totMfgPolishPcs    = (int)$kapanPolishAgg['total_mfg_pcs'];
}

$mfgMinusGiaWeight  = max(0.000, $totMfgPolishWeight - $totGiaJangadWeight);

// Search & Filter
$search = trim((string)($_GET['search'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$standDollarFilter = trim((string)($_GET['stand_dollar'] ?? ''));
$kapanFilter  = (int)($_GET['kapan_id'] ?? 0);
$shapeFilter  = trim((string)($_GET['shape'] ?? ''));
$kapansList   = [];

if (in_array($tab, ['gia_stock', 'parcel_stock'], true)) {
    $kapansList = $pdo->query("
        SELECT DISTINCT k.id, k.kapan_code, k.kapan_name
        FROM polish_stocks ps
        JOIN kapans k ON ps.kapan_id = k.id
        ORDER BY k.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────
// DATA QUERIES PER ACTIVE TAB
// ─────────────────────────────────────────────────────────────────
if ($tab === 'gia_purchase') {
    $sql = "SELECT pp.*, p.party_name, b.party_name as broker_name
            FROM polish_purchases pp
            JOIN parties p ON pp.party_id = p.id $scJoinP_pp
            LEFT JOIN parties b ON pp.broker_id = b.id $scJoinB_pp
            WHERE (pp.stock_type = 'gia_single' OR (pp.gia_number IS NOT NULL AND pp.gia_number != ''))";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND pp.purchase_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (pp.purchase_no LIKE ? OR p.party_name LIKE ? OR pp.gia_number LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND pp.payment_status = ?";
        $params[] = $statusFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((pp.payment_currency = 'USD' OR COALESCE(pp.dollar_per_carat, 0) > 0) AND COALESCE(pp.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((pp.payment_currency = 'USD' OR COALESCE(pp.dollar_per_carat, 0) > 0) AND COALESCE(pp.dollar_rate, 0) <= 0)";
    }
    $sql .= " ORDER BY pp.purchase_date DESC, pp.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allGiaPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allGiaPurchases), 25);
    $giaPurchases = array_slice($allGiaPurchases, $pagData['offset'], $pagData['per_page']);

} elseif ($tab === 'parcel_purchase') {
    $sql = "SELECT pp.*, p.party_name, b.party_name as broker_name,
                   (SELECT COUNT(*) FROM polish_parcel_shapes WHERE transaction_type = 'purchase' AND transaction_id = pp.id $scShape_pp) as shape_count
            FROM polish_purchases pp
            JOIN parties p ON pp.party_id = p.id $scJoinP_pp
            LEFT JOIN parties b ON pp.broker_id = b.id $scJoinB_pp
            WHERE (pp.stock_type = 'parcel' OR (pp.stock_type != 'gia_single' AND (pp.gia_number IS NULL OR pp.gia_number = '')))";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND pp.purchase_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (pp.purchase_no LIKE ? OR p.party_name LIKE ? OR pp.shape LIKE ? OR pp.color_tag LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND pp.payment_status = ?";
        $params[] = $statusFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((pp.payment_currency = 'USD' OR COALESCE(pp.dollar_per_carat, 0) > 0) AND COALESCE(pp.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((pp.payment_currency = 'USD' OR COALESCE(pp.dollar_per_carat, 0) > 0) AND COALESCE(pp.dollar_rate, 0) <= 0)";
    }
    $sql .= " ORDER BY pp.purchase_date DESC, pp.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allParcelPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allParcelPurchases), 25);
    $parcelPurchases = array_slice($allParcelPurchases, $pagData['offset'], $pagData['per_page']);

} elseif ($tab === 'gia_sell') {
    $sql = "SELECT ps.*, p.party_name, b.party_name as broker_name,
                   stk.packet_no, stk.shape, stk.gia_number, stk.color, stk.clarity, stk.cut
            FROM polish_sales ps
            JOIN parties p ON ps.party_id = p.id $scJoinP_ps
            LEFT JOIN parties b ON ps.broker_id = b.id $scJoinB_ps
            JOIN polish_stocks stk ON ps.polish_stock_id = stk.id $scJoinStk_ps
            WHERE (stk.stock_type = 'gia_single' OR (stk.gia_number IS NOT NULL AND stk.gia_number != ''))";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND ps.sale_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (ps.invoice_no LIKE ? OR p.party_name LIKE ? OR stk.gia_number LIKE ? OR stk.packet_no LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND ps.payment_status = ?";
        $params[] = $statusFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((ps.payment_currency = 'USD' OR COALESCE(ps.dollar_per_carat, 0) > 0) AND COALESCE(ps.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((ps.payment_currency = 'USD' OR COALESCE(ps.dollar_per_carat, 0) > 0) AND COALESCE(ps.dollar_rate, 0) <= 0)";
    }
    $sql .= " ORDER BY ps.sale_date DESC, ps.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allGiaSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allGiaSales), 25);
    $giaSales = array_slice($allGiaSales, $pagData['offset'], $pagData['per_page']);

} elseif ($tab === 'parcel_sell') {
    $sql = "SELECT ps.*, p.party_name, b.party_name as broker_name,
                   stk.packet_no, stk.shape, stk.color_tag, stk.clarity_tag,
                   (SELECT COUNT(*) FROM polish_parcel_shapes WHERE transaction_type = 'sale' AND transaction_id = ps.id $scShape_ps) as shape_count
            FROM polish_sales ps
            JOIN parties p ON ps.party_id = p.id $scJoinP_ps
            LEFT JOIN parties b ON ps.broker_id = b.id $scJoinB_ps
            JOIN polish_stocks stk ON ps.polish_stock_id = stk.id $scJoinStk_ps
            WHERE (stk.stock_type = 'parcel' OR (stk.stock_type != 'gia_single' AND (stk.gia_number IS NULL OR stk.gia_number = '')))";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND ps.sale_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (ps.invoice_no LIKE ? OR p.party_name LIKE ? OR stk.packet_no LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND ps.payment_status = ?";
        $params[] = $statusFilter;
    }
    if ($standDollarFilter === '1') {
        $sql .= " AND ((ps.payment_currency = 'USD' OR COALESCE(ps.dollar_per_carat, 0) > 0) AND COALESCE(ps.dollar_rate, 0) <= 0)";
    } elseif ($standDollarFilter === '0') {
        $sql .= " AND NOT ((ps.payment_currency = 'USD' OR COALESCE(ps.dollar_per_carat, 0) > 0) AND COALESCE(ps.dollar_rate, 0) <= 0)";
    }
    $sql .= " ORDER BY ps.sale_date DESC, ps.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allParcelSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allParcelSales), 25);
    $parcelSales = array_slice($allParcelSales, $pagData['offset'], $pagData['per_page']);

} elseif ($tab === 'sell_jangad') {
    $sql = "SELECT jv.*, p.party_name, p.city, b.party_name as broker_name
            FROM jangad_vouchers jv
            JOIN parties p ON jv.party_id = p.id
            LEFT JOIN parties b ON jv.broker_id = b.id
            WHERE jv.voucher_type = 'issue'
              AND jv.category IN ('memo_broker', 'memo_party')";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND jv.issue_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (jv.jangad_no LIKE ? OR p.party_name LIKE ? OR b.party_name LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND jv.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY jv.issue_date DESC, jv.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allSellJangad = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allSellJangad), 25);
    $sellJangadVouchers = array_slice($allSellJangad, $pagData['offset'], $pagData['per_page']);

} elseif ($tab === 'gia_jangad') {
    $sql = "SELECT jv.*, p.party_name, p.city, b.party_name as broker_name
            FROM jangad_vouchers jv
            JOIN parties p ON jv.party_id = p.id
            LEFT JOIN parties b ON jv.broker_id = b.id
            WHERE jv.voucher_type = 'issue'
              AND jv.category = 'lab_gia'";
    $params = [];
    if ($purDateFrom && $purDateTo) {
        $sql .= " AND jv.issue_date BETWEEN ? AND ?";
        $params[] = $purDateFrom;
        $params[] = $purDateTo;
    }
    if (!empty($search)) {
        $sql .= " AND (jv.jangad_no LIKE ? OR p.party_name LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term]);
    }
    if (!empty($statusFilter)) {
        $sql .= " AND jv.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY jv.issue_date DESC, jv.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allGiaJangad = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allGiaJangad), 25);
    $giaJangadVouchers = array_slice($allGiaJangad, $pagData['offset'], $pagData['per_page']);

} else {
    // TAB === 'gia_stock' OR 'parcel_stock'
    $sql = "SELECT ps.*, k.kapan_code, k.kapan_name, pp.purchase_no, pp.place as pur_place, pty.party_name,
                   pkt.id as linked_packet_id, pkt.barcode as linked_barcode,
                   COALESCE(sa.sold_pcs, 0) as s_pcs,
                   COALESCE(sa.sold_weight, 0.000) as s_weight,
                   COALESCE(sa.sold_amount, 0.00) as s_amount
            FROM polish_stocks ps
            LEFT JOIN kapans k ON ps.kapan_id = k.id $scKapan_stk
            LEFT JOIN polish_purchases pp ON ps.polish_purchase_id = pp.id $scPur_stk
            LEFT JOIN parties pty ON pp.party_id = pty.id $scPty_stk
            LEFT JOIN packets pkt ON pkt.polish_stock_id = ps.id $scPkt_stk
            LEFT JOIN (
                SELECT polish_stock_id $scSa_col,
                       SUM(pcs) as sold_pcs,
                       SUM(weight) as sold_weight,
                       SUM(payment_amount) as sold_amount
                FROM polish_sales
                GROUP BY polish_stock_id $scSa_col
            ) sa ON sa.polish_stock_id = ps.id $scSa_join
            WHERE 1=1";
    $params = [];

    if ($tab === 'gia_stock') {
        $sql .= " AND (ps.stock_type = 'gia_single' OR (ps.gia_number IS NOT NULL AND ps.gia_number != ''))";
    } else {
        $sql .= " AND (ps.stock_type = 'parcel' OR (ps.stock_type != 'gia_single' AND (ps.gia_number IS NULL OR ps.gia_number = '')))";
        if ($sourceFilter === 'mfg') {
            $sql .= " AND (ps.source_type = 'mfg' OR ps.kapan_id IS NOT NULL)";
        }
    }

    // Default to Showing Available Stock (હાજર સ્ટોક)
    if (!empty($statusFilter)) {
        $sql .= " AND ps.status = ?";
        $params[] = $statusFilter;
    } else {
        $sql .= " AND ps.status IN ('available', 'partially_on_jangad', 'in_stock') AND ps.available_weight > 0";
    }

    // Surat vs Mumbai Location Filter
    if ($locationFilter === 'Surat') {
        $sql .= " AND (ps.current_location LIKE '%Surat%' OR ps.current_location IS NULL OR ps.current_location = '' OR ps.current_location = 'Vault')";
    } elseif ($locationFilter === 'Mumbai') {
        $sql .= " AND ps.current_location LIKE '%Mumbai%'";
    }

    if (!empty($search)) {
        $sql .= " AND (ps.packet_no LIKE ? OR ps.gia_number LIKE ? OR ps.shape LIKE ? OR k.kapan_code LIKE ?)";
        $term = "%$search%";
        $params = array_merge($params, [$term, $term, $term, $term]);
    }
    if ($kapanFilter > 0) {
        $sql .= " AND ps.kapan_id = ?";
        $params[] = $kapanFilter;
    }
    $sql .= " ORDER BY ps.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allPolishStocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pagData = get_pagination_data(count($allPolishStocks), 25);
    $polishStocks = array_slice($allPolishStocks, $pagData['offset'], $pagData['per_page']);
}
?>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- ENTERPRISE HEADER WITH FINANCIAL YEAR / HISTORY SWITCHER & ACTIONS -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2.5 mb-3">
    <div>
        <h3 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <?= svg_icon($isPolishGia ? 'certificate' : ($isPolishParcel ? 'boxes-stacked' : 'gem'), 'text-primary') ?>
            <span><?= $isPolishGia ? 'Polish GIA (GIA સર્ટિફાઇડ પોલિશ)' : ($isPolishParcel ? 'Polish Parcel (પાર્સલ હીરા)' : 'Polish (પોલિશ હબ - GIA & Parcel)') ?></span>
        </h3>
        <div class="small text-muted">
            <?= $isPolishGia ? 'GIA સર્ટિફાઇડ હાજર સ્ટોક, ખરીદી, વેચાણ અને લેબ/મેમો જાંગડ હિસાબ' : ($isPolishParcel ? 'Parcel સ્ટોકની ખરીદી, વેચાણ અને સુરત/મુંબઈ હાજર સ્ટોક રજિસ્ટર' : 'GIA સર્ટિફાઇડ & PARCEL સ્ટોકની ખરીદી, વેચાણ અને સુરત/મુંબઈ હાજર સ્ટોક રજિસ્ટર') ?>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <!-- Financial Year, Custom Date Range & All History Switcher -->
        <?= render_period_filter_toolbar($period, $purDateFrom, $purDateTo, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($locationFilter) ? ['location' => $locationFilter] : [])) ?>

        <!-- Surat vs Mumbai Modal Button -->
        <button type="button" class="btn btn-sm btn-outline-primary fw-semibold px-2.5 shadow-xs" data-bs-toggle="modal" data-bs-target="#locationStockModal">
            <?= svg_icon('location-dot', 'text-secondary me-1') ?>Surat & Mumbai સ્ટોક વિગત
        </button>

        <!-- Polish Sell Bulk Upload Button -->
        <a href="<?= BASE_URL ?>/modules/polish_sales/import.php" class="btn btn-sm btn-outline-success fw-bold px-2.5 shadow-xs" title="Excel અથવા CSV ફાઇલ દ્વારા એકસાથે વેચાણ અપલોડ કરો">
            <?= svg_icon('file-arrow-up', 'me-1') ?>Bulk વેચાણ અપલોડ
        </a>

        <!-- Dynamic Action Button based on Active Tab -->
        <?php if ($tab === 'gia_purchase'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=gia" class="btn btn-sm btn-primary fw-bold px-3 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>નવી GIA ખરીદી
            </a>
        <?php elseif ($tab === 'parcel_purchase'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=parcel" class="btn btn-sm btn-success text-white fw-bold px-3 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>નવી Parcel ખરીદી
            </a>
        <?php elseif ($tab === 'gia_sell'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=gia" class="btn btn-sm btn-primary fw-bold px-3 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>નવું GIA વેચાણ
            </a>
        <?php elseif ($tab === 'parcel_sell'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=parcel" class="btn btn-sm text-white fw-bold px-3 shadow-xs" style="background:#8b5cf6;">
                <?= svg_icon('plus', 'me-1') ?>નવું Parcel વેચાણ
            </a>
        <?php elseif ($tab === 'sell_jangad'): ?>
            <a href="<?= BASE_URL ?>/modules/jangad/create.php?category=memo_party" class="btn btn-sm btn-warning text-dark fw-bold px-3 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>નવો વેચાણ જાંગડ (Memo)
            </a>
        <?php elseif ($tab === 'gia_jangad'): ?>
            <a href="<?= BASE_URL ?>/modules/jangad/create.php?category=lab_gia" class="btn btn-sm text-white fw-bold px-3 shadow-xs" style="background:#7c3aed;">
                <?= svg_icon('plus', 'me-1') ?>નવો GIA લેબ જાંગડ
            </a>
        <?php elseif ($tab === 'gia_stock'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=gia" class="btn btn-sm btn-outline-primary fw-semibold px-2.5 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>GIA ખરીદી
            </a>
            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=gia" class="btn btn-sm btn-primary fw-bold px-3 shadow-xs">
                <?= svg_icon('cart-shopping', 'me-1') ?>GIA વેચાણ
            </a>
        <?php elseif ($tab === 'parcel_stock'): ?>
            <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=parcel" class="btn btn-sm btn-outline-success fw-semibold px-2.5 shadow-xs">
                <?= svg_icon('plus', 'me-1') ?>Parcel ખરીદી
            </a>
            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=parcel" class="btn btn-sm btn-success text-white fw-bold px-3 shadow-xs">
                <?= svg_icon('cart-shopping', 'me-1') ?>Parcel વેચાણ
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- DYNAMIC KPI CARDS: GIA (5 CARDS), PARCEL (4 CARDS) OR ALL (8 CARDS) -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php if ($isPolishGia): ?>
<!-- ───────────────────────────────────────────────────────────────── -->
<!-- POLISH GIA: EXACT 5 SPECIALIZED KPI CARDS                         -->
<!-- 1. GIA Available Stock                                            -->
<!-- 2. GIA Polish Purchase                                            -->
<!-- 3. GIA Certification mate gayel Jangad                            -->
<!-- 4. GIA sell mate gayel Jangad                                     -->
<!-- 5. GIA Sell                                                       -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="row g-2.5 mb-3">
    <!-- 1. GIA Available Stock -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=gia_stock<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_stock' ? 'kpi-tone-blue border-primary shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('gem', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small">હાજર સ્ટોક</span>
                </div>
                <div class="kpi-label">💎 GIA Available Stock</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaLots ?> લૉટ્સ • <?= $totGiaPcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaStockVal) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 2. GIA Polish Purchase -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=gia_purchase<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_purchase' ? 'kpi-tone-blue border-primary shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('certificate', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">💎 GIA Polish Purchase</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaPurWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaPurBills ?> બિલો • <?= $totGiaPurPcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaPurAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 3. GIA Certification mate gayel Jangad -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=gia_jangad<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_jangad' ? 'kpi-tone-purple border-purple shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('microscope', '', 18) ?></div>
                    <span class="badge bg-purple-subtle text-purple border font-mono small">GIA લેબ</span>
                </div>
                <div class="kpi-label">🔬 GIA Certification માટે ગયેલ જાંગડ</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= format_carat($totGiaJangadWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaJangadVouchers ?> વાઉચર્સ • <?= number_format($totGiaJangadPcs) ?> Stones</span>
                    <span class="fw-semibold text-muted">લેબ પ્રમાણીકરણ</span>
                </div>
            </div>
        </a>
    </div>

    <!-- 4. GIA sell mate gayel Jangad -->
    <div class="col-12 col-sm-6 col-lg-6 col-xl">
        <a href="?tab=sell_jangad<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'sell_jangad' ? 'kpi-tone-amber border-warning shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('handshake', '', 18) ?></div>
                    <span class="badge bg-warning-subtle text-dark border font-mono small">મેમો / જાંગડ</span>
                </div>
                <div class="kpi-label">🤝 GIA sell માટે ગયેલ જાંગડ</div>
                <div class="kpi-value text-dark"><?= format_carat($totSellJangadWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totSellJangadVouchers ?> વાઉચર્સ • <?= number_format($totSellJangadPcs) ?> Pcs બાકી</span>
                    <span class="fw-semibold text-muted">દલાલ/પાર્ટી મેમો</span>
                </div>
            </div>
        </a>
    </div>

    <!-- 5. GIA Sell -->
    <div class="col-12 col-sm-6 col-lg-6 col-xl">
        <a href="?tab=gia_sell<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_sell' ? 'kpi-tone-blue border-primary shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('cart-shopping', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">💎 GIA Sell</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaSaleWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaSaleBills ?> બિલો • <?= $totGiaSalePcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaSaleAmount) ?></span>
                </div>
            </div>
        </a>
    </div>
</div>

<?php elseif ($isPolishParcel): ?>
<!-- ───────────────────────────────────────────────────────────────── -->
<!-- POLISH PARCEL: EXACT 5 SPECIALIZED KPI CARDS                       -->
<!-- 1. Polish Parcel Stock                                            -->
<!-- 2. Polish Parcel Purchase                                         -->
<!-- 3. Mfg Polish (Mfg Polish - GIA Certification Jangad)             -->
<!-- 4. Polish Parcel Sell Jangad                                      -->
<!-- 5. Polish Sell                                                    -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="row g-2.5 mb-3">
    <!-- 1. Polish Parcel Stock -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=parcel_stock&section=parcel<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= ($tab === 'parcel_stock' && empty($sourceFilter)) ? 'kpi-tone-green border-success shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('shapes', '', 18) ?></div>
                    <span class="badge bg-success-subtle text-success border font-mono small">હાજર સ્ટોક</span>
                </div>
                <div class="kpi-label">📦 Polish Parcel Stock</div>
                <div class="kpi-value text-success"><?= format_carat($totParcelWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelLots ?> લૉટ્સ • <?= number_format($totParcelPcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelStockVal) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 2. Polish Parcel Purchase -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=parcel_purchase&section=parcel<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'parcel_purchase' ? 'kpi-tone-green border-success shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('boxes-stacked', '', 18) ?></div>
                    <span class="badge bg-success-subtle text-success border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">📦 Polish Parcel Purchase</div>
                <div class="kpi-value text-success"><?= format_carat($totParcelPurWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelPurBills ?> બિલો • <?= number_format($totParcelPurPcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelPurAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 3. Mfg Polish (Mfg Polish - GIA Certification Jangad) -->
    <div class="col-12 col-sm-6 col-lg-4 col-xl">
        <a href="?tab=parcel_stock&section=parcel&source=mfg<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100" title="Mfg Polish (Mfg Polish - GIA Certification Jangad)">
            <div class="stat-card-finora <?= ($tab === 'parcel_stock' && $sourceFilter === 'mfg') ? 'kpi-tone-purple border-purple shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('gears', '', 18) ?></div>
                    <span class="badge bg-purple-subtle text-purple border font-mono small">Mfg - GIA Lab</span>
                </div>
                <div class="kpi-label">⚙️ Mfg Polish (Net)</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= format_carat($mfgMinusGiaWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span title="કુલ Mfg Polish વજન">કુલ Mfg: <?= format_carat($totMfgPolishWeight) ?></span>
                    <span class="fw-semibold text-danger" title="GIA લેબમાં ગયેલ જાંગડ">GIA: -<?= format_carat($totGiaJangadWeight) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 4. Polish Parcel Sell Jangad -->
    <div class="col-12 col-sm-6 col-lg-6 col-xl">
        <a href="?tab=sell_jangad&section=parcel<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'sell_jangad' ? 'kpi-tone-amber border-warning shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('handshake', '', 18) ?></div>
                    <span class="badge bg-warning-subtle text-dark border font-mono small">મેમો / જાંગડ</span>
                </div>
                <div class="kpi-label">🤝 Polish Parcel Sell Jangad</div>
                <div class="kpi-value text-dark"><?= format_carat($totSellJangadWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totSellJangadVouchers ?> વાઉચર્સ • <?= number_format($totSellJangadPcs) ?> Pcs બાકી</span>
                    <span class="fw-semibold text-muted">દલાલ/પાર્ટી મેમો</span>
                </div>
            </div>
        </a>
    </div>

    <!-- 5. Polish Sell -->
    <div class="col-12 col-sm-6 col-lg-6 col-xl">
        <a href="?tab=parcel_sell&section=parcel<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'parcel_sell' ? 'kpi-tone-purple border-purple shadow-xs' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('bag-shopping', '', 18) ?></div>
                    <span class="badge bg-purple-subtle text-purple border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">📦 Polish Sell</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= format_carat($totParcelSaleWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelSaleBills ?> બિલો • <?= number_format($totParcelSalePcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelSaleAmount) ?></span>
                </div>
            </div>
        </a>
    </div>
</div>

<?php else: ?>
<!-- ───────────────────────────────────────────────────────────────── -->
<!-- 8 COMPREHENSIVE KPI CARDS: PURCHASES, SALES, STOCKS & JANGAD      -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="row g-2.5 mb-3">
    <!-- 1. GIA Purchase -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=gia_purchase<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_purchase' ? 'kpi-tone-blue border-primary' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('certificate', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">💎 GIA Purchase (ખરીદી)</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaPurWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaPurBills ?> બિલો • <?= $totGiaPurPcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaPurAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 2. GIA Sell -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=gia_sell<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_sell' ? 'kpi-tone-blue border-primary' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('cart-shopping', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">💎 GIA Sell (વેચાણ)</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaSaleWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaSaleBills ?> બિલો • <?= $totGiaSalePcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaSaleAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 3. Parcel Purchase -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=parcel_purchase<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'parcel_purchase' ? 'kpi-tone-green border-success' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('boxes-stacked', '', 18) ?></div>
                    <span class="badge bg-success-subtle text-success border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">📦 Parcel Purchase (ખરીદી)</div>
                <div class="kpi-value text-success"><?= format_carat($totParcelPurWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelPurBills ?> બિલો • <?= number_format($totParcelPurPcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelPurAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 4. Parcel Sell -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=parcel_sell<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'parcel_sell' ? 'kpi-tone-purple border-purple' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('bag-shopping', '', 18) ?></div>
                    <span class="badge bg-purple-subtle text-purple border font-mono small"><?= e($pInfo['label']) ?></span>
                </div>
                <div class="kpi-label">📦 Parcel Sell (વેચાણ)</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= format_carat($totParcelSaleWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelSaleBills ?> બિલો • <?= number_format($totParcelSalePcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelSaleAmount) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 5. GIA Stock hajar -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=gia_stock<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_stock' ? 'kpi-tone-blue border-primary' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('gem', '', 18) ?></div>
                    <span class="badge bg-primary-subtle text-primary border font-mono small">હાજર સ્ટોક</span>
                </div>
                <div class="kpi-label">💎 GIA Stock (હાજર)</div>
                <div class="kpi-value text-primary"><?= format_carat($totGiaWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaLots ?> લૉટ્સ • <?= $totGiaPcs ?> Stones</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totGiaStockVal) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 6. Parcel Stock Hajar -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=parcel_stock<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'parcel_stock' ? 'kpi-tone-green border-success' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('shapes', '', 18) ?></div>
                    <span class="badge bg-success-subtle text-success border font-mono small">હાજર સ્ટોક</span>
                </div>
                <div class="kpi-label">📦 Parcel Stock (હાજર)</div>
                <div class="kpi-value text-success"><?= format_carat($totParcelWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totParcelLots ?> લૉટ્સ • <?= number_format($totParcelPcs) ?> Pcs</span>
                    <span class="fw-semibold text-dark"><?= format_inr($totParcelStockVal) ?></span>
                </div>
            </div>
        </a>
    </div>

    <!-- 7. વેચાણ માટે ગયેલ જાંગડ -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=sell_jangad<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'sell_jangad' ? 'kpi-tone-amber border-warning' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('handshake', '', 18) ?></div>
                    <span class="badge bg-warning-subtle text-dark border font-mono small">મેમો / જાંગડ</span>
                </div>
                <div class="kpi-label">🤝 વેચાણ માટે ગયેલ જાંગડ</div>
                <div class="kpi-value text-dark"><?= format_carat($totSellJangadWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totSellJangadVouchers ?> વાઉચર્સ • <?= number_format($totSellJangadPcs) ?> Pcs બાકી</span>
                    <span class="fw-semibold text-muted">દલાલ/પાર્ટી મેમો</span>
                </div>
            </div>
        </a>
    </div>

    <!-- 8. GIA માં ગયેલ જાંગડ -->
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="?tab=gia_jangad<?= $periodQueryStr ?>" class="text-decoration-none d-block h-100">
            <div class="stat-card-finora <?= $tab === 'gia_jangad' ? 'kpi-tone-purple border-purple' : 'bg-white border' ?> h-100 p-3">
                <div class="d-flex justify-content-between align-items-start mb-1.5">
                    <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('microscope', '', 18) ?></div>
                    <span class="badge bg-purple-subtle text-purple border font-mono small">GIA લેબ</span>
                </div>
                <div class="kpi-label">🔬 GIA માં ગયેલ જાંગડ</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= format_carat($totGiaJangadWeight) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-1 text-muted small font-mono">
                    <span><?= $totGiaJangadVouchers ?> વાઉચર્સ • <?= number_format($totGiaJangadPcs) ?> Stones લેબમાં</span>
                    <span class="fw-semibold text-muted">લેબ પ્રમાણીકરણ</span>
                </div>
            </div>
        </a>
    </div>
</div>
<?php endif; ?>



<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB 1: GIA PURCHASE REGISTER                                      -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php if ($tab === 'gia_purchase'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <?= svg_icon('certificate', 'text-primary', 18) ?>
                <h6 class="finora-card-title mb-0">💎 GIA સર્ટિફાઇડ ખરીદી રજિસ્ટર (GIA Diamond Purchases)</h6>
                <span class="badge bg-primary-subtle text-primary border font-mono"><?= count($giaPurchases) ?> Bills</span>
            </div>
            <form method="GET" action="" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="gia_purchase">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ખરીદી નં, પાર્ટી, GIA..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા દરો</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_purchase&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=gia" class="btn btn-sm btn-primary fw-bold shadow-xs text-nowrap">
                    <?= svg_icon('plus', 'me-1') ?>નવી GIA ખરીદી
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">ખરીદી નં / તારીખ</th>
                            <th>GIA રિપોર્ટ નં.</th>
                            <th>પાર્ટી & દલાલ</th>
                            <th>Shape & 4Cs</th>
                            <th>Pol / Sym / Floro</th>
                            <th>માપ (L×W×D)</th>
                            <th class="text-center">નંગ</th>
                            <th class="text-end">વજન (Ct)</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th>ચુકવણી</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($giaPurchases)): ?>
                            <tr><td colspan="12" class="text-center py-5 text-muted">કોઈ GIA ખરીદી રેકોર્ડ મળ્યો નથી. નવી ખરીદી નોંધવા <strong>નવી GIA ખરીદી</strong> બટન દબાવો.</td></tr>
                        <?php else: ?>
                            <?php foreach ($giaPurchases as $pp): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($pp) ?>
                                        <span class="text-primary"><?= e($pp['purchase_no']) ?></span>
                                        <div class="small text-muted font-mono"><?= format_date($pp['purchase_date']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning-subtle text-dark border font-mono">
                                            <?= svg_icon('certificate', 'text-warning me-1', 11) ?><?= e($pp['gia_number'] ?: '-') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($pp['party_name']) ?></div>
                                        <small class="text-muted"><?= e($pp['broker_name'] ?? '—') ?></small>
                                    </td>
                                    <td class="small font-mono">
                                        <span class="fw-bold text-dark"><?= e($pp['shape'] ?: 'Round') ?></span>
                                        <span class="badge bg-light text-dark border ms-1"><?= e($pp['color'] ?? '') ?> / <?= e($pp['clarity'] ?? '') ?></span>
                                        <?php if (!empty($pp['cut'])): ?>
                                            <span class="badge bg-info-subtle text-info border"><?= e($pp['cut']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small font-mono text-muted">
                                        <?= e($pp['polish'] ?? '-') ?> / <?= e($pp['symmetry'] ?? '-') ?> / <?= e($pp['fluorescence'] ?? '-') ?>
                                    </td>
                                    <td class="small font-mono text-muted">
                                        <?= (!empty($pp['length_mm']) ? number_format((float)$pp['length_mm'], 2) : '-') ?> × <?= (!empty($pp['width_mm']) ? number_format((float)$pp['width_mm'], 2) : '-') ?> × <?= (!empty($pp['depth_mm']) ? number_format((float)$pp['depth_mm'], 2) : '-') ?>
                                    </td>
                                    <td class="text-center font-mono fw-bold text-dark"><?= (int)$pp['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-primary"><?= format_carat((float)$pp['weight']) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($pp)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$pp['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$pp['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-dark">
                                        <?php if (is_stand_dollar($pp)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$pp['net_payment']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pp['payment_status'] === 'paid'): ?>
                                            <span class="finora-pill finora-pill-green">Paid</span>
                                        <?php elseif ($pp['payment_status'] === 'partial'): ?>
                                            <span class="finora-pill finora-pill-amber">Partial</span>
                                        <?php else: ?>
                                            <span class="finora-pill finora-pill-rose">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= BASE_URL ?>/modules/polish_purchase/edit.php?id=<?= $pp['id'] ?>" class="btn btn-sm btn-outline-secondary" title="એડિટ"><?= svg_icon('pen-to-square', '', 13) ?> એડિટ</a>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/polish_purchase/delete.php" class="d-inline mb-0" onsubmit="return confirm('શું તમે ખરેખર આ GIA ખરીદી બિલ (<?= e($pp['purchase_no']) ?>) રદ (Delete) કરવા માંગો છો?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
                                                <input type="hidden" name="return_tab" value="gia_purchase">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="રદ કરો"><?= svg_icon('trash', '', 13) ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($locationFilter) ? ['location' => $locationFilter] : []) + (!empty($kapanFilter) ? ['kapan_id' => $kapanFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB 2: PARCEL PURCHASE REGISTER                                   -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'parcel_purchase'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <?= svg_icon('boxes-stacked', 'text-success', 18) ?>
                <h6 class="finora-card-title mb-0">📦 PARCEL લૂઝ હીરા ખરીદી રજિસ્ટર (Parcel Diamond Purchases)</h6>
                <span class="badge bg-success-subtle text-success border font-mono"><?= count($parcelPurchases) ?> Bills</span>
            </div>
            <form method="GET" action="" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="parcel_purchase">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ખરીદી નં, પાર્ટી, શેપ..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા દરો</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_purchase&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/polish_purchase/create.php?type=parcel" class="btn btn-sm btn-success text-white fw-bold shadow-xs text-nowrap">
                    <?= svg_icon('plus', 'me-1') ?>નવી Parcel ખરીદી
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">ખરીદી નં / તારીખ</th>
                            <th>પાર્ટી & દલાલ</th>
                            <th>શેપ & ટેગ્સ</th>
                            <th>Shape Breakdown</th>
                            <th>સ્થળ</th>
                            <th class="text-center">કુલ નંગ</th>
                            <th class="text-end">કુલ વજન (Ct)</th>
                            <th class="text-end">સરેરાશ સાઇઝ</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th>ચુકવણી</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($parcelPurchases)): ?>
                            <tr><td colspan="12" class="text-center py-5 text-muted">કોઈ Parcel ખરીદી રેકોર્ડ મળ્યો નથી. નવી ખરીદી નોંધવા <strong>નવી Parcel ખરીદી</strong> બટન દબાવો.</td></tr>
                        <?php else: ?>
                            <?php foreach ($parcelPurchases as $pp): ?>
                                <?php 
                                    $pcsVal = (int)$pp['pcs'];
                                    $wtVal = (float)$pp['weight'];
                                    $avgSz = $pcsVal > 0 ? ($wtVal / $pcsVal) : 0;
                                ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($pp) ?>
                                        <span class="text-success"><?= e($pp['purchase_no']) ?></span>
                                        <div class="small text-muted font-mono"><?= format_date($pp['purchase_date']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($pp['party_name']) ?></div>
                                        <small class="text-muted"><?= e($pp['broker_name'] ?? '—') ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark font-mono"><?= e($pp['shape'] ?: 'Mix') ?></span>
                                        <?php if (!empty($pp['color_tag']) || !empty($pp['clarity_tag'])): ?>
                                            <div class="mt-0.5">
                                                <span class="badge bg-light text-dark border font-mono" style="font-size:0.7rem;"><?= e($pp['color_tag'] ?: '-') ?> / <?= e($pp['clarity_tag'] ?: '-') ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)($pp['shape_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-success-subtle text-success border font-mono">
                                                ✓ <?= (int)$pp['shape_count'] ?> Shapes Table
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Standard</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono small text-muted"><?= e($pp['place'] ?? 'Surat') ?></td>
                                    <td class="text-center font-mono fw-bold text-dark"><?= $pcsVal ?></td>
                                    <td class="text-end font-mono fw-bold text-success"><?= format_carat($wtVal) ?></td>
                                    <td class="text-end font-mono text-muted"><?= number_format($avgSz, 2) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($pp)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$pp['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$pp['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-dark">
                                        <?php if (is_stand_dollar($pp)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$pp['net_payment']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pp['payment_status'] === 'paid'): ?>
                                            <span class="finora-pill finora-pill-green">Paid</span>
                                        <?php elseif ($pp['payment_status'] === 'partial'): ?>
                                            <span class="finora-pill finora-pill-amber">Partial</span>
                                        <?php else: ?>
                                            <span class="finora-pill finora-pill-rose">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= BASE_URL ?>/modules/polish_purchase/edit.php?id=<?= $pp['id'] ?>" class="btn btn-sm btn-outline-secondary" title="એડિટ"><?= svg_icon('pen-to-square', '', 13) ?> એડિટ</a>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/polish_purchase/delete.php" class="d-inline mb-0" onsubmit="return confirm('શું તમે ખરેખર આ Parcel ખરીદી બિલ (<?= e($pp['purchase_no']) ?>) રદ (Delete) કરવા માંગો છો?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$pp['id'] ?>">
                                                <input type="hidden" name="return_tab" value="parcel_purchase">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="રદ કરો"><?= svg_icon('trash', '', 13) ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($locationFilter) ? ['location' => $locationFilter] : []) + (!empty($kapanFilter) ? ['kapan_id' => $kapanFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB 3: GIA SELL REGISTER                                          -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'gia_sell'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <?= svg_icon('cart-shopping', 'text-primary', 18) ?>
                <h6 class="finora-card-title mb-0">💎 GIA સર્ટિફાઇડ વેચાણ રજિસ્ટર (GIA Diamond Sales)</h6>
                <span class="badge bg-primary-subtle text-primary border font-mono"><?= count($giaSales) ?> Bills</span>
            </div>
            <form method="GET" action="" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="gia_sell">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ઇન્વોઇસ નં, પાર્ટી, GIA..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા દરો</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_sell&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=gia" class="btn btn-sm btn-primary fw-bold shadow-xs text-nowrap">
                    <?= svg_icon('plus', 'me-1') ?>નવું GIA વેચાણ
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">ઇન્વોઇસ નં / તારીખ</th>
                            <th>GIA રિપોર્ટ નં.</th>
                            <th>ગ્રાહક પાર્ટી & દલાલ</th>
                            <th>સ્ટોક પેકેટ</th>
                            <th>Shape & 4Cs</th>
                            <th class="text-center">નંગ</th>
                            <th class="text-end">વજન (Ct)</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($giaSales)): ?>
                            <tr><td colspan="11" class="text-center py-5 text-muted">કોઈ GIA વેચાણ રેકોર્ડ મળ્યો નથી. નવું વેચાણ નોંધવા <strong>નવું GIA વેચાણ</strong> બટન દબાવો.</td></tr>
                        <?php else: ?>
                            <?php foreach ($giaSales as $s): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($s) ?>
                                        <span class="text-primary"><?= e($s['invoice_no']) ?></span>
                                        <div class="small text-muted font-mono"><?= format_date($s['sale_date']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning-subtle text-dark border font-mono">
                                            <?= svg_icon('certificate', 'text-warning me-1', 11) ?><?= e($s['gia_number'] ?: '-') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($s['party_name']) ?></div>
                                        <small class="text-muted"><?= e($s['broker_name'] ?? '—') ?></small>
                                    </td>
                                    <td class="font-mono text-muted small"><?= e($s['packet_no'] ?? '-') ?></td>
                                    <td class="small font-mono">
                                        <span class="fw-bold text-dark"><?= e($s['shape'] ?: 'Round') ?></span>
                                        <span class="badge bg-light text-dark border ms-1"><?= e($s['color'] ?? '') ?> / <?= e($s['clarity'] ?? '') ?></span>
                                    </td>
                                    <td class="text-center font-mono fw-bold text-dark"><?= (int)$s['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-primary"><?= format_carat((float)$s['weight']) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$s['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-dark">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['net_amount']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if ($s['payment_status'] === 'paid'): ?>
                                            <span class="finora-pill finora-pill-green">Paid (ચુકતે)</span>
                                            <?php if (!empty($s['received_date'])): ?>
                                                <div class="small text-success font-mono mt-1" style="font-size:0.73rem;">
                                                    <?= svg_icon('check-circle', 'me-0.5', 11) ?><?= format_date($s['received_date']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($s['payment_status'] === 'partial'): ?>
                                            <span class="finora-pill finora-pill-amber">Partial (અંશતઃ)</span>
                                            <div class="small text-muted font-mono mt-1" style="font-size:0.73rem;">
                                                Due: <?= format_date($s['due_date']) ?>
                                            </div>
                                        <?php else: ?>
                                            <?php $isOverdue = (!empty($s['due_date']) && $s['due_date'] < date('Y-m-d')); ?>
                                            <span class="finora-pill <?= $isOverdue ? 'finora-pill-rose' : 'finora-pill-sky' ?>">
                                                <?= $isOverdue ? 'Overdue (મુદત વીતી)' : 'Pending (બાકી)' ?>
                                            </span>
                                            <div class="small font-mono mt-1 <?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:0.73rem;">
                                                Due: <?= format_date($s['due_date']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= BASE_URL ?>/modules/polish_sales/edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="એડિટ"><?= svg_icon('pen-to-square', '', 13) ?> એડિટ</a>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/polish_sales/delete.php" class="d-inline mb-0" onsubmit="return confirm('શું તમે ખરેખર આ GIA વેચાણ બિલ (<?= e($s['invoice_no']) ?>) રદ (Delete) કરવા માંગો છો? આનાથી વેચાયેલ હીરો પાછો વોલ્ટમાં જમા થશે.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="return_tab" value="gia_sell">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="રદ કરો"><?= svg_icon('trash', '', 13) ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($locationFilter) ? ['location' => $locationFilter] : []) + (!empty($kapanFilter) ? ['kapan_id' => $kapanFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB 4: PARCEL SELL REGISTER                                       -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'parcel_sell'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <?= svg_icon('bag-shopping', 'text-purple', 18) ?>
                <h6 class="finora-card-title mb-0">📦 PARCEL હીરા વેચાણ રજિસ્ટર (Parcel Diamond Sales)</h6>
                <span class="badge bg-purple-subtle text-purple border font-mono"><?= count($parcelSales) ?> Bills</span>
            </div>
            <form method="GET" action="" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="parcel_sell">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="ઇન્વોઇસ નં, પાર્ટી, પેકેટ..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                <select name="stand_dollar" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધા દરો</option>
                    <option value="1" <?= $standDollarFilter === '1' ? 'selected' : '' ?>>🔴 Stand Dollar</option>
                    <option value="0" <?= $standDollarFilter === '0' ? 'selected' : '' ?>>Regular (Locked)</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter) || $standDollarFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=parcel_sell&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=parcel" class="btn btn-sm text-white fw-bold shadow-xs text-nowrap" style="background:#8b5cf6;">
                    <?= svg_icon('plus', 'me-1') ?>નવું Parcel વેચાણ
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">ઇન્વોઇસ નં / તારીખ</th>
                            <th>ગ્રાહક પાર્ટી & દલાલ</th>
                            <th>પેકેટ / સ્ટોક સંદર્ભ</th>
                            <th>શેપ & ટેગ્સ</th>
                            <th>Shape Breakdown</th>
                            <th class="text-center">વેચાયેલ નંગ</th>
                            <th class="text-end">વેચાયેલ વજન (Ct)</th>
                            <th class="text-end">ભાવ / Ct</th>
                            <th class="text-end">નેટ રકમ</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($parcelSales)): ?>
                            <tr><td colspan="11" class="text-center py-5 text-muted">કોઈ Parcel વેચાણ રેકોર્ડ મળ્યો નથી. નવું વેચાણ નોંધવા <strong>નવું Parcel વેચાણ</strong> બટન દબાવો.</td></tr>
                        <?php else: ?>
                            <?php foreach ($parcelSales as $s): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?= render_stand_dollar_indicator($s) ?>
                                        <span style="color:#7c3aed;"><?= e($s['invoice_no']) ?></span>
                                        <div class="small text-muted font-mono"><?= format_date($s['sale_date']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($s['party_name']) ?></div>
                                        <small class="text-muted"><?= e($s['broker_name'] ?? '—') ?></small>
                                    </td>
                                    <td class="font-mono text-muted small"><?= e($s['packet_no'] ?? '-') ?></td>
                                    <td>
                                        <span class="fw-bold text-dark font-mono"><?= e($s['shape'] ?: 'Mix') ?></span>
                                        <?php if (!empty($s['color_tag']) || !empty($s['clarity_tag'])): ?>
                                            <span class="badge bg-light text-dark border font-mono ms-1" style="font-size:0.7rem;"><?= e($s['color_tag'] ?: '-') ?> / <?= e($s['clarity_tag'] ?: '-') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)($s['shape_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-purple-subtle text-purple border font-mono">
                                                ✓ <?= (int)$s['shape_count'] ?> Sold Shapes
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Standard</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-mono fw-bold text-dark"><?= (int)$s['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold" style="color:#7c3aed;"><?= format_carat((float)$s['weight']) ?></td>
                                    <td class="text-end font-mono">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-danger fw-bold">$<?= number_format((float)$s['dollar_per_carat'], 2) ?></span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['price_per_carat_inr']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-dark">
                                        <?php if (is_stand_dollar($s)): ?>
                                            <span class="text-muted small">દર બાકી</span>
                                        <?php else: ?>
                                            <?= format_inr((float)$s['net_amount']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if ($s['payment_status'] === 'paid'): ?>
                                            <span class="finora-pill finora-pill-green">Paid (ચુકતે)</span>
                                            <?php if (!empty($s['received_date'])): ?>
                                                <div class="small text-success font-mono mt-1" style="font-size:0.73rem;">
                                                    <?= svg_icon('check-circle', 'me-0.5', 11) ?><?= format_date($s['received_date']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($s['payment_status'] === 'partial'): ?>
                                            <span class="finora-pill finora-pill-amber">Partial (અંશતઃ)</span>
                                            <div class="small text-muted font-mono mt-1" style="font-size:0.73rem;">
                                                Due: <?= format_date($s['due_date']) ?>
                                            </div>
                                        <?php else: ?>
                                            <?php $isOverdue = (!empty($s['due_date']) && $s['due_date'] < date('Y-m-d')); ?>
                                            <span class="finora-pill <?= $isOverdue ? 'finora-pill-rose' : 'finora-pill-sky' ?>">
                                                <?= $isOverdue ? 'Overdue (મુદત વીતી)' : 'Pending (બાકી)' ?>
                                            </span>
                                            <div class="small font-mono mt-1 <?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:0.73rem;">
                                                Due: <?= format_date($s['due_date']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= BASE_URL ?>/modules/polish_sales/edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="એડિટ"><?= svg_icon('pen-to-square', '', 13) ?> એડિટ</a>
                                            <form method="POST" action="<?= BASE_URL ?>/modules/polish_sales/delete.php" class="d-inline mb-0" onsubmit="return confirm('શું તમે ખરેખર આ Parcel વેચાણ બિલ (<?= e($s['invoice_no']) ?>) રદ (Delete) કરવા માંગો છો? આનાથી વેચાયેલ સ્ટોક પાછો વોલ્ટમાં જમા થશે.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="return_tab" value="parcel_sell">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="રદ કરો"><?= svg_icon('trash', '', 13) ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($locationFilter) ? ['location' => $locationFilter] : []) + (!empty($kapanFilter) ? ['kapan_id' => $kapanFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB: SELL MATE GAYELO JANGAD STOCK (વેચાણ જાંગડ / મેમો)           -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'sell_jangad'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom gap-2">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <?= svg_icon('handshake', 'text-warning', 18) ?>
                    <h6 class="finora-card-title mb-0">🤝 વેચાણ માટે ગયેલ જાંગડ સ્ટોક (Sales Memo Jangad Register)</h6>
                    <span class="badge bg-warning text-dark border font-mono"><?= count($sellJangadVouchers) ?> Vouchers</span>
                </div>
                <div class="small text-muted mt-0.5">
                    દલાલ કે પાર્ટી પાસે વેચાણ અર્થે ગયેલ હીરાનો હિસાબ • કુલ બાકી વજન: <strong><?= format_carat($totSellJangadWeight) ?></strong> (<?= number_format($totSellJangadPcs) ?> Pcs)
                </div>
            </div>

            <form method="GET" action="" class="d-flex flex-wrap gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="sell_jangad">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="જાંગડ નં, પાર્ટી, દલાલ..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>Issued (પેન્ડિંગ)</option>
                    <option value="partially_returned" <?= $statusFilter === 'partially_returned' ? 'selected' : '' ?>>Partial Return</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter)): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=sell_jangad&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/jangad/create.php?category=memo_party" class="btn btn-sm btn-warning text-dark fw-bold shadow-xs text-nowrap">
                    <?= svg_icon('plus', 'me-1') ?>નવો વેચાણ જાંગડ (Memo)
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">જાંગડ નં / તારીખ</th>
                            <th>ગ્રાહક પાર્ટી & દલાલ</th>
                            <th>હેતુ (Category)</th>
                            <th class="text-center">ઇસ્યુ નંગ</th>
                            <th class="text-end">ઇસ્યુ વજન (Ct)</th>
                            <th class="text-end">વેચાયેલ (Ct)</th>
                            <th class="text-end">પરત આવેલ (Ct)</th>
                            <th class="text-end">બાકી વજન (Pending Ct)</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sellJangadVouchers)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <div class="text-warning mb-2"><?= svg_icon('handshake', 'text-warning', 32) ?></div>
                                    <div class="fw-bold text-dark">કોઈ વેચાણ જાંગડ રેકોર્ડ મળ્યો નથી.</div>
                                    <small class="text-muted">વેચાણ જોવા આપવા માટે ઉપરના <strong>નવો વેચાણ જાંગડ (Memo)</strong> બટન પર ક્લિક કરો.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sellJangadVouchers as $jv): 
                                $pendWt = max(0.0, (float)$jv['total_weight'] - (float)($jv['sold_weight'] ?? 0) - (float)($jv['returned_weight'] ?? 0) - (float)($jv['total_tops_weight'] ?? 0));
                                $pendPcs = max(0, (int)$jv['total_pcs'] - (int)($jv['sold_pcs'] ?? 0) - (int)($jv['returned_pcs'] ?? 0));
                            ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= (int)$jv['id'] ?>" class="text-primary text-decoration-none">
                                            <?= e($jv['jangad_no']) ?>
                                        </a>
                                        <div class="small text-muted font-mono"><?= format_date($jv['issue_date']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($jv['party_name']) ?></div>
                                        <small class="text-muted"><?= e($jv['broker_name'] ?? '—') ?><?= !empty($jv['city']) ? ' (' . e($jv['city']) . ')' : '' ?></small>
                                    </td>
                                    <td>
                                        <?php if ($jv['category'] === 'memo_broker'): ?>
                                            <span class="badge bg-warning-subtle text-dark border">🤝 દલાલ મેમો</span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-dark border">🏢 પાર્ટી મેમો</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-mono"><?= (int)$jv['total_pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold"><?= format_carat((float)$jv['total_weight']) ?></td>
                                    <td class="text-end font-mono text-success">
                                        <?= (float)$jv['sold_weight'] > 0 ? format_carat((float)$jv['sold_weight']) : '—' ?>
                                    </td>
                                    <td class="text-end font-mono text-muted">
                                        <?= (float)$jv['returned_weight'] > 0 ? format_carat((float)$jv['returned_weight']) : '—' ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold <?= $pendWt > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= format_carat($pendWt) ?>
                                        <div class="small text-muted font-mono"><?= $pendPcs ?> Pcs</div>
                                    </td>
                                    <td>
                                        <?php if ($jv['status'] === 'completed'): ?>
                                            <span class="badge bg-success-subtle text-success border">પૂરો થયેલ</span>
                                        <?php elseif ($jv['status'] === 'partially_returned'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ પરત</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border">પેન્ડિંગ (ચાલુ)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= (int)$jv['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="જુઓ">
                                                <?= svg_icon('eye', '', 12) ?> જુઓ
                                            </a>
                                            <?php if ($jv['status'] !== 'completed'): ?>
                                                <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= (int)$jv['id'] ?>" class="btn btn-sm btn-success py-1 px-2" title="પરત / વેચાણ સેટલ">
                                                    <?= svg_icon('rotate-left', 'me-1', 12) ?>પરત/સેટલ
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB: GIA MA GAYELO JANGAD STOCK (GIA લેબ જાંગડ)                    -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'gia_jangad'): ?>
    <div class="finora-card mb-4 border">
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom gap-2">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <?= svg_icon('microscope', 'text-purple', 18) ?>
                    <h6 class="finora-card-title mb-0">🔬 GIA લેબમાં ગયેલ જાંગડ સ્ટોક (GIA Lab Certification Jangad)</h6>
                    <span class="badge bg-purple text-white border font-mono" style="background:#7c3aed !important;"><?= count($giaJangadVouchers) ?> Vouchers</span>
                </div>
                <div class="small text-muted mt-0.5">
                    GIA લેબમાં પ્રમાણીકરણ માટે મોકલેલ હીરા • લેબમાં બાકી વજન: <strong><?= format_carat($totGiaJangadWeight) ?></strong> (<?= number_format($totGiaJangadPcs) ?> Stones)
                </div>
            </div>

            <form method="GET" action="" class="d-flex flex-wrap gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="gia_jangad">
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="જાંગડ નં, લેબ..." value="<?= e($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="">બધી સ્થિતિ</option>
                    <option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>Issued (લેબમાં)</option>
                    <option value="partially_returned" <?= $statusFilter === 'partially_returned' ? 'selected' : '' ?>>Partial Return</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
                <?php if (!empty($search) || !empty($statusFilter)): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_jangad&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/jangad/create.php?category=lab_gia" class="btn btn-sm text-white fw-bold shadow-xs text-nowrap" style="background:#7c3aed;">
                    <?= svg_icon('plus', 'me-1') ?>નવો GIA લેબ જાંગડ
                </a>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">જાંગડ નં / તારીખ</th>
                            <th>GIA લેબ પાર્ટી</th>
                            <th>અપેક્ષિત પરત તારીખ</th>
                            <th class="text-center">કુલ Stones</th>
                            <th class="text-end">કુલ વજન (Ct)</th>
                            <th class="text-end">પરત આવેલ (Ct)</th>
                            <th class="text-end">લેબમાં બાકી વજન (Pending Ct)</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($giaJangadVouchers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <div class="text-primary mb-2"><?= svg_icon('microscope', 'text-primary', 32) ?></div>
                                    <div class="fw-bold text-dark">GIA લેબમાં મોકલેલ કોઈ જાંગડ મળ્યો નથી.</div>
                                    <small class="text-muted">લેબમાં પ્રમાણીકરણ માટે મોકલવા ઉપરના <strong>નવો GIA લેબ જાંગડ</strong> બટન પર ક્લિક કરો.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($giaJangadVouchers as $jv): 
                                $pendWt = max(0.0, (float)$jv['total_weight'] - (float)($jv['sold_weight'] ?? 0) - (float)($jv['returned_weight'] ?? 0) - (float)($jv['total_tops_weight'] ?? 0));
                                $pendPcs = max(0, (int)$jv['total_pcs'] - (int)($jv['sold_pcs'] ?? 0) - (int)($jv['returned_pcs'] ?? 0));
                            ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= (int)$jv['id'] ?>" class="text-purple text-decoration-none" style="color:#7c3aed;">
                                            <?= e($jv['jangad_no']) ?>
                                        </a>
                                        <div class="small text-muted font-mono"><?= format_date($jv['issue_date']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($jv['party_name']) ?></div>
                                        <small class="text-muted"><?= !empty($jv['city']) ? e($jv['city']) : 'GIA India Lab' ?></small>
                                    </td>
                                    <td class="font-mono text-muted">
                                        <?= !empty($jv['expected_return_date']) ? format_date($jv['expected_return_date']) : '—' ?>
                                    </td>
                                    <td class="text-center font-mono fw-bold"><?= (int)$jv['total_pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold"><?= format_carat((float)$jv['total_weight']) ?></td>
                                    <td class="text-end font-mono text-success">
                                        <?= (float)$jv['returned_weight'] > 0 ? format_carat((float)$jv['returned_weight']) : '—' ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold <?= $pendWt > 0 ? 'text-primary' : 'text-success' ?>">
                                        <?= format_carat($pendWt) ?>
                                        <div class="small text-muted font-mono"><?= $pendPcs ?> Stones</div>
                                    </td>
                                    <td>
                                        <?php if ($jv['status'] === 'completed'): ?>
                                            <span class="badge bg-success-subtle text-success border">લેબમાંથી પરત આવેલ</span>
                                        <?php elseif ($jv['status'] === 'partially_returned'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ પરત</span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info border">🔬 લેબમાં ચકાસણી હેઠળ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= (int)$jv['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="જુઓ">
                                                <?= svg_icon('eye', '', 12) ?> જુઓ
                                            </a>
                                            <?php if ($jv['status'] !== 'completed'): ?>
                                                <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= (int)$jv['id'] ?>" class="btn btn-sm btn-info text-dark py-1 px-2" title="લેબમાંથી પરત સ્વીકારો">
                                                    <?= svg_icon('rotate-left', 'me-1', 12) ?>પરત સ્વીકારો
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : [])) ?>
        </div>
    </div>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- TAB 5 & 6: GIA STOCK & PARCEL STOCK (હાજર સ્ટોક & SURAT/MUMBAI)   -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php else: ?>
    <?php
    $isGiaStock = ($tab === 'gia_stock');
    $tabTitle = $isGiaStock
        ? '💎 GIA સર્ટિફાઇડ હાજર સ્ટોક (Single Diamonds in Vault)'
        : ($sourceFilter === 'mfg'
            ? '⚙️ MFG PARCEL સ્ટોક (In-House Manufactured Polish)'
            : '📦 PARCEL લૂઝ હાજર સ્ટોક (૧ પેકેટમાં ૧-૨-૩ કે તેથી વધુ હીરા)');
    ?>
    <div class="finora-card mb-4 border">
        <!-- Stock Sub-Header with Active Location Filter & Quick Switch -->
        <div class="finora-card-header flex-wrap py-2.5 px-3 bg-white border-bottom gap-2">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h6 class="finora-card-title mb-0"><?= $tabTitle ?></h6>
                    <span class="badge bg-success text-white border font-mono">🟢 હાજર સ્ટોક (Available)</span>
                    <?php if ($sourceFilter === 'mfg'): ?>
                        <span class="badge bg-purple text-white border font-mono">⚙️ MFG Polish Only <a href="?tab=parcel_stock&section=parcel<?= $periodQueryStr ?>" class="text-white text-decoration-none ms-1 fw-bold" title="ફિલ્ટર હટાવો">✕</a></span>
                    <?php endif; ?>
                    <?php if (!empty($locationFilter)): ?>
                        <span class="badge bg-primary text-white border font-mono">📍 <?= e($locationFilter) ?> Only</span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mt-0.5">
                    <?php if ($isGiaStock): ?>
                        કુલ હાજર: <strong><?= $totGiaLots ?> Stones / <?= format_carat($totGiaWeight) ?></strong> (સુરત: <?= format_carat((float)$giaSuratStock['weight']) ?> | મુંબઈ: <?= format_carat((float)$giaMumbaiStock['weight']) ?>)
                    <?php else: ?>
                        કુલ હાજર: <strong><?= $totParcelLots ?> પેકેટો / <?= format_carat($totParcelWeight) ?></strong> (સુરત: <?= format_carat((float)$parcelSuratStock['weight']) ?> | મુંબઈ: <?= format_carat((float)$parcelMumbaiStock['weight']) ?>)
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters & Location Tabs -->
            <form method="GET" action="" class="d-flex flex-wrap gap-2 align-items-center mb-0">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <?php if (!empty($sourceFilter)): ?><input type="hidden" name="source" value="<?= e($sourceFilter) ?>"><?php endif; ?>
                <input type="hidden" name="period" value="<?= e($period) ?>"><?php if ($period === 'custom' && $purDateFrom && $purDateTo): ?><input type="hidden" name="from_date" value="<?= e($purDateFrom) ?>"><input type="hidden" name="to_date" value="<?= e($purDateTo) ?>"><?php endif; ?>

                <!-- Surat vs Mumbai Location Filter Pill -->
                <div class="btn-group btn-group-sm border rounded-2" role="group">
                    <a href="?tab=<?= e($tab) ?>&section=<?= e($section) ?>&period=<?= $period ?><?= !empty($sourceFilter) ? '&source=' . urlencode($sourceFilter) : '' ?>" class="btn <?= empty($locationFilter) ? 'btn-primary fw-bold' : 'btn-light text-muted' ?>">બધા સ્થળ</a>
                    <a href="?tab=<?= e($tab) ?>&section=<?= e($section) ?>&period=<?= $period ?>&location=Surat<?= !empty($sourceFilter) ? '&source=' . urlencode($sourceFilter) : '' ?>" class="btn <?= $locationFilter === 'Surat' ? 'btn-primary fw-bold' : 'btn-light text-muted' ?>">📍 Surat</a>
                    <a href="?tab=<?= e($tab) ?>&section=<?= e($section) ?>&period=<?= $period ?>&location=Mumbai<?= !empty($sourceFilter) ? '&source=' . urlencode($sourceFilter) : '' ?>" class="btn <?= $locationFilter === 'Mumbai' ? 'btn-primary fw-bold' : 'btn-light text-muted' ?>">🏙️ Mumbai</a>
                </div>

                <div class="input-group input-group-sm" style="width: 200px;">
                    <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 14) ?></span>
                    <input type="text" name="search" class="form-control" placeholder="<?= $isGiaStock ? 'GIA નં, પેકેટ...' : 'પેકેટ નં, શેપ...' ?>" value="<?= e($search) ?>">
                </div>

                <select name="status" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="" <?= empty($statusFilter) ? 'selected' : '' ?>>🟢 ફક્ત હાજર સ્ટોક</option>
                    <option value="partially_on_jangad" <?= $statusFilter === 'partially_on_jangad' ? 'selected' : '' ?>>Partial Jangad</option>
                    <option value="on_jangad" <?= $statusFilter === 'on_jangad' ? 'selected' : '' ?>>On Jangad</option>
                    <option value="sold" <?= $statusFilter === 'sold' ? 'selected' : '' ?>>Sold (વેચાયેલ)</option>
                </select>

                <?php if (!empty($search) || !empty($statusFilter) || !empty($locationFilter)): ?>
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=<?= e($tab) ?>&period=<?= $period ?>" class="btn btn-sm btn-outline-danger" title="Reset Filters"><?= svg_icon('xmark', '', 14) ?></a>
                <?php endif; ?>

                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#locationStockModal">
                    <?= svg_icon('location-dot', 'text-secondary me-1') ?>Surat & Mumbai સ્ટોક વિગત
                </button>

                <?php if ($isGiaStock): ?>
                    <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=gia" class="btn btn-sm btn-primary fw-bold shadow-xs text-nowrap">
                        <?= svg_icon('plus', 'me-1') ?>નવું GIA વેચાણ
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?type=parcel" class="btn btn-sm btn-success text-white fw-bold shadow-xs text-nowrap">
                        <?= svg_icon('plus', 'me-1') ?>નવું Parcel વેચાણ
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <?php if (!$isGiaStock): ?>
                            <!-- PARCEL STOCK TABLE HEADERS -->
                            <tr>
                                <th class="text-center font-mono text-nowrap ps-3">NO</th>
                                <th class="text-nowrap font-mono">PACKET NO</th>
                                <th class="text-center text-nowrap">હાજર PC</th>
                                <th class="text-end text-nowrap">હાજર WEIGHT</th>
                                <th class="text-end text-nowrap">PRICE (₹/ct)</th>
                                <th class="text-center text-nowrap">PARCEL</th>
                                <th class="text-nowrap">COLOR TAG</th>
                                <th class="text-nowrap">CLARITY TAG</th>
                                <th class="text-center text-nowrap">AVG SZ</th>
                                <th class="text-end text-nowrap">હાજર રકમ (₹)</th>
                                <th class="text-nowrap">DETAIL</th>
                                <th class="text-nowrap">સ્થળ (LOCATION)</th>
                                <th class="text-center text-nowrap">સ્થિતિ</th>
                                <th class="text-end pe-3 text-nowrap">એક્શન</th>
                            </tr>
                        <?php else: ?>
                            <!-- GIA STOCK TABLE HEADERS -->
                            <tr>
                                <th class="ps-3 text-nowrap">Stock ID (પેકેટ)</th>
                                <th class="text-nowrap">GIA રિપોર્ટ નંબર</th>
                                <th class="text-nowrap">Shape & 4Cs</th>
                                <th class="text-nowrap">Pol / Sym / Floro</th>
                                <th class="text-nowrap">Measurements (L×W×D)</th>
                                <th class="text-nowrap">Depth % / Ratio</th>
                                <th class="text-nowrap">Key to Symbols</th>
                                <th class="text-end text-nowrap">હાજર વજન (Ct)</th>
                                <th class="text-end text-nowrap">વેચાણ ભાવ / Ct</th>
                                <th class="text-end text-nowrap">કુલ રકમ</th>
                                <th class="text-nowrap">સ્થળ (Location)</th>
                                <th class="text-nowrap">સ્થિતિ</th>
                                <th class="text-end pe-3 text-nowrap">એક્શન</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if (empty($polishStocks)): ?>
                            <tr>
                                <td colspan="<?= $isGiaStock ? 13 : 15 ?>" class="text-center py-5 text-muted">
                                    <div class="text-success mb-2"><?= svg_icon('gem', 'text-success', 32) ?></div>
                                    <div class="fw-bold text-dark">હાલ કોઈ <?= $isGiaStock ? 'GIA' : 'PARCEL' ?> હાજર સ્ટોક ઉપલબ્ધ નથી.</div>
                                    <small class="text-muted">નવી ખરીદી નોંધવાથી અથવા પ્રોસેસ પૂરી થવાથી સ્ટોક આપોઆપ અહીં હાજર દેખાશે.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $rowNum = 0;
                            foreach ($polishStocks as $ps): 
                                $rowNum++;
                                $availPcs = (int)($ps['available_pcs'] ?? 0);
                                $availWt  = (float)($ps['available_weight'] ?? 0);
                                $linkedPktId = (int)($ps['linked_packet_id'] ?? 0);
                                $rateCt = (float)($ps['sale_price_per_carat'] > 0 ? $ps['sale_price_per_carat'] : $ps['cost_per_carat']);
                                $totalVal = round($availWt * $rateCt, 2);
                                $locText = !empty($ps['current_location']) ? $ps['current_location'] : (!empty($ps['pur_place']) ? $ps['pur_place'] : 'Surat');
                                $isMumbai = stripos($locText, 'Mumbai') !== false;
                            ?>
                                <?php if (!$isGiaStock): 
                                    $avgSz = $availPcs > 0 ? ($availWt / $availPcs) : 0;
                                ?>
                                <!-- PARCEL ROW -->
                                <tr style="border-bottom: 1px solid #dee2e6;">
                                    <td class="text-center font-mono fw-bold" style="background-color: #fff2cc; color: #dc3545; border-right: 1px solid #dee2e6;"><?= $rowNum ?></td>
                                    <td class="font-mono fw-bold" style="color: #dc3545; border-right: 1px solid #dee2e6;">
                                        <?php if ($linkedPktId > 0): ?>
                                            <a href="javascript:void(0)" onclick="openDiamondHistoryModal(<?= $linkedPktId ?>)" class="text-decoration-none" style="color: #dc3545;">
                                                <?= e($ps['packet_no']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= e($ps['packet_no']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= $availPcs ?></td>
                                    <td class="text-end font-mono fw-bold text-success" style="border-right: 1px solid #dee2e6;"><?= number_format($availWt, 2) ?></td>
                                    <td class="text-end font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= $rateCt > 0 ? number_format($rateCt, 0) : '' ?></td>
                                    <td style="background-color: #f8fafc; width: 10px; border-right: 1px solid #dee2e6;"></td>
                                    <td class="text-center font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= strtoupper(e($ps['stock_type'] ?: 'PARCEL')) ?></td>
                                    <td class="font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= e($ps['color_tag'] ?: $ps['color'] ?: '-') ?></td>
                                    <td class="font-mono text-secondary small" style="border-right: 1px solid #dee2e6;"><?= e($ps['clarity_tag'] ?: $ps['clarity'] ?: '-') ?></td>
                                    <td class="text-center font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= $avgSz > 0 ? number_format($avgSz, 2) : '0.00' ?></td>
                                    <td class="text-end font-mono fw-bold text-dark" style="border-right: 1px solid #dee2e6;"><?= $totalVal > 0 ? number_format($totalVal, 0) : '' ?></td>
                                    <td class="font-mono small text-dark" style="border-right: 1px solid #dee2e6; max-width: 140px;"><?= e($ps['detail'] ?: $ps['notes'] ?: '-') ?></td>
                                    <td class="font-mono fw-bold small" style="border-right: 1px solid #dee2e6;">
                                        <span class="badge <?= $isMumbai ? 'bg-info-subtle text-info' : 'bg-primary-subtle text-primary' ?> border">
                                            <?= $isMumbai ? '🏙️ Mumbai' : '📍 Surat' ?>
                                        </span>
                                    </td>
                                    <td class="text-center" style="border-right: 1px solid #dee2e6;">
                                        <span class="badge bg-success-subtle text-success border">🟢 હાજર</span>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <?php if ($availWt > 0 && $availPcs > 0): ?>
                                                <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?stock_id=<?= (int)$ps['id'] ?>&type=parcel" class="btn btn-xs btn-success text-white py-1 px-2 fw-semibold" style="font-size: 0.75rem;">
                                                    <?= svg_icon('cart-shopping', '', 11) ?>
                                                    <span>વેચાણ</span>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($linkedPktId > 0): ?>
                                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" onclick="openDiamondHistoryModal(<?= $linkedPktId ?>)" title="ઇતિહાસ">
                                                    <?= svg_icon('clock-rotate-left', '', 11) ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <!-- GIA ROW -->
                                <tr>
                                    <td class="ps-3 font-mono fw-bold text-nowrap">
                                        <?php if ($linkedPktId > 0): ?>
                                            <a href="javascript:void(0)" onclick="openDiamondHistoryModal(<?= $linkedPktId ?>)" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1">
                                                <?= svg_icon('gem', 'text-warning', 14) ?>
                                                <span><?= e($ps['packet_no']) ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="d-inline-flex align-items-center gap-1">
                                                <?= svg_icon('gem', 'text-warning', 14) ?>
                                                <span><?= e($ps['packet_no']) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono text-nowrap">
                                        <?php if (!empty($ps['gia_number'])): ?>
                                            <span class="badge bg-warning-subtle text-dark border px-2 py-1">
                                                <?= svg_icon('certificate', 'text-warning me-1', 13) ?>
                                                <?= e($ps['gia_number']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono small">
                                        <span class="fw-bold text-dark"><?= e($ps['shape'] ?: 'Round') ?></span>
                                        <span class="badge bg-light text-dark border ms-1"><?= e($ps['color'] ?: '-') ?> / <?= e($ps['clarity'] ?: '-') ?></span>
                                        <?php if (!empty($ps['cut'])): ?>
                                            <span class="badge bg-info-subtle text-info border"><?= e($ps['cut']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono small text-muted">
                                        <?= e($ps['polish'] ?: '-') ?> / <?= e($ps['symmetry'] ?: '-') ?> / <?= e($ps['fluorescence'] ?: '-') ?>
                                    </td>
                                    <td class="font-mono small text-muted">
                                        <?= (!empty($ps['length_mm']) ? number_format((float)$ps['length_mm'], 2) : '-') ?> × <?= (!empty($ps['width_mm']) ? number_format((float)$ps['width_mm'], 2) : '-') ?> × <?= (!empty($ps['depth_mm']) ? number_format((float)$ps['depth_mm'], 2) : '-') ?>
                                    </td>
                                    <td class="font-mono small text-muted">
                                        <?= (!empty($ps['depth_pct']) ? number_format((float)$ps['depth_pct'], 2) . '%' : '-') ?> / <?= (!empty($ps['ratio']) ? number_format((float)$ps['ratio'], 2) : '-') ?>
                                    </td>
                                    <td class="font-mono small text-muted" style="max-width: 120px;">
                                        <?= e($ps['key_symbols'] ?: '—') ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-primary"><?= format_carat($availWt) ?></td>
                                    <td class="text-end font-mono text-dark"><?= format_inr($rateCt) ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_inr($totalVal) ?></td>
                                    <td class="font-mono small">
                                        <span class="badge <?= $isMumbai ? 'bg-info-subtle text-info' : 'bg-primary-subtle text-primary' ?> border">
                                            <?= $isMumbai ? '🏙️ Mumbai' : '📍 Surat' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success-subtle text-success border">🟢 હાજર</span>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <?php if ($availWt > 0): ?>
                                                <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?stock_id=<?= (int)$ps['id'] ?>&type=gia" class="btn btn-sm btn-primary py-1 px-2.5 fw-bold shadow-xs">
                                                    <?= svg_icon('cart-shopping', 'me-1', 12) ?>GIA વેચાણ
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($linkedPktId > 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-1.5" onclick="openDiamondHistoryModal(<?= $linkedPktId ?>)" title="ઇતિહાસ">
                                                    <?= svg_icon('clock-rotate-left', '', 12) ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_table_pagination($pagData, BASE_URL . '/modules/polish/index.php', ['tab' => $tab] + (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($locationFilter) ? ['location' => $locationFilter] : []) + (!empty($kapanFilter) ? ['kapan_id' => $kapanFilter] : [])) ?>
        </div>
    </div>

<?php endif; ?>

<!-- ───────────────────────────────────────────────────────────────── -->
<div class="modal fade" id="locationStockModal" tabindex="-1" aria-labelledby="locationStockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light py-2.5 px-3 border-bottom">
                <h6 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="locationStockModalLabel">
                    <?= svg_icon('location-dot', 'text-danger') ?>
                    <span>Surat & Mumbai હાજર સ્ટોક વિભાજન (Location Breakdown)</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="row g-3">
                    <!-- SURAT SUMMARY CARD -->
                    <div class="col-md-6">
                        <div class="card h-100 border border-primary-subtle rounded-3 p-3 bg-primary-subtle bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="text-primary fs-6">📍 Surat ઓફિસ / તિજોરી સ્ટોક</strong>
                                <span class="badge bg-primary text-white">Surat Vault</span>
                            </div>
                            <hr class="my-2 border-primary-subtle">
                            <div class="mb-2">
                                <div class="small text-muted fw-bold">💎 GIA સર્ટિફાઇડ સિંગલ સ્ટોક:</div>
                                <div class="font-mono fs-6 fw-bold text-dark">
                                    <?= format_carat((float)$giaSuratStock['weight']) ?>
                                    <small class="text-muted">(<?= (int)$giaSuratStock['pcs'] ?> Stones)</small>
                                </div>
                                <div class="small text-muted font-mono">અંદાજિત વેલ્યુ: <?= format_inr((float)$giaSuratStock['total_val']) ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="small text-muted fw-bold">📦 PARCEL લૂઝ પેકેટ સ્ટોક:</div>
                                <div class="font-mono fs-6 fw-bold text-success">
                                    <?= format_carat((float)$parcelSuratStock['weight']) ?>
                                    <small class="text-muted">(<?= number_format((int)$parcelSuratStock['pcs']) ?> Pcs / <?= (int)$parcelSuratStock['cnt'] ?> લૉટ્સ)</small>
                                </div>
                                <div class="small text-muted font-mono">અંદાજિત વેલ્યુ: <?= format_inr((float)$parcelSuratStock['total_val']) ?></div>
                            </div>
                            <div class="d-flex gap-2 mt-auto">
                                <a href="?tab=gia_stock&location=Surat&period=<?= $period ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                    Surat GIA સ્ટોક &rarr;
                                </a>
                                <a href="?tab=parcel_stock&location=Surat&period=<?= $period ?>" class="btn btn-sm btn-primary flex-fill text-white">
                                    Surat Parcel સ્ટોક &rarr;
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- MUMBAI SUMMARY CARD -->
                    <div class="col-md-6">
                        <div class="card h-100 border border-info-subtle rounded-3 p-3 bg-info-subtle bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="text-info-emphasis fs-6">🏙️ Mumbai ઓફિસ / BDB સ્ટોક</strong>
                                <span class="badge bg-info text-white">Mumbai Vault</span>
                            </div>
                            <hr class="my-2 border-info-subtle">
                            <div class="mb-2">
                                <div class="small text-muted fw-bold">💎 GIA સર્ટિફાઇડ સિંગલ સ્ટોક:</div>
                                <div class="font-mono fs-6 fw-bold text-dark">
                                    <?= format_carat((float)$giaMumbaiStock['weight']) ?>
                                    <small class="text-muted">(<?= (int)$giaMumbaiStock['pcs'] ?> Stones)</small>
                                </div>
                                <div class="small text-muted font-mono">અંદાજિત વેલ્યુ: <?= format_inr((float)$giaMumbaiStock['total_val']) ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="small text-muted fw-bold">📦 PARCEL લૂઝ પેકેટ સ્ટોક:</div>
                                <div class="font-mono fs-6 fw-bold text-purple" style="color:#7c3aed;">
                                    <?= format_carat((float)$parcelMumbaiStock['weight']) ?>
                                    <small class="text-muted">(<?= number_format((int)$parcelMumbaiStock['pcs']) ?> Pcs / <?= (int)$parcelMumbaiStock['cnt'] ?> લૉટ્સ)</small>
                                </div>
                                <div class="small text-muted font-mono">અંદાજિત વેલ્યુ: <?= format_inr((float)$parcelMumbaiStock['total_val']) ?></div>
                            </div>
                            <div class="d-flex gap-2 mt-auto">
                                <a href="?tab=gia_stock&location=Mumbai&period=<?= $period ?>" class="btn btn-sm btn-outline-info flex-fill">
                                    Mumbai GIA સ્ટોક &rarr;
                                </a>
                                <a href="?tab=parcel_stock&location=Mumbai&period=<?= $period ?>" class="btn btn-sm text-white flex-fill" style="background:#8b5cf6;">
                                    Mumbai Parcel સ્ટોક &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 px-3 bg-light border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Surat અને Mumbai બંને લોકેશનનો સ્ટોક રીયલ-ટાઇમમાં ટ્રેક થાય છે.</span>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">બંધ કરો</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
