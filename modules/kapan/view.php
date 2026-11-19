<?php
/**
 * View Kapan Details & Packets List (સેક્શન ૬.૩ & Units & Rough Cost Merging)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();

$pdo = getDbConnection();
ensure_boil_reassort_columns();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// POST Handler for Batch Updating Packet Weights
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_packet_weights') {
    validate_csrf();
    $weights = $_POST['packet_weights'] ?? [];
    if (!is_array($weights) || empty($weights)) {
        set_flash('error', 'કોઈ વજન વિગત મળી નથી.');
    } else {
        try {
            $pdo->beginTransaction();
            $updPktStmt = $pdo->prepare("UPDATE packets SET weight = ?, current_weight = ? WHERE id = ? AND kapan_id = ?");
            $updatedCount = 0;

            foreach ($weights as $pktId => $rawWeight) {
                $pktId = (int)$pktId;
                $wt = round((float)$rawWeight, 3);
                if ($pktId > 0 && $wt > 0) {
                    $updPktStmt->execute([$wt, $wt, $pktId, $id]);
                    $updatedCount++;
                }
            }

            // Recalculate Kapan totals from active production packets
            $totStmt = $pdo->prepare("SELECT COUNT(*) as pcs, COALESCE(SUM(weight), 0) as tot_wt 
                                      FROM packets 
                                      WHERE kapan_id = ? 
                                        AND COALESCE(is_reassorted, 0) = 0 
                                        AND status != 'reassorted'");
            $totStmt->execute([$id]);
            $totData = $totStmt->fetch(PDO::FETCH_ASSOC);

            $newTotWt = round((float)($totData['tot_wt'] ?? 0), 3);

            // Fetch current kapan costs to update cost_per_carat & yield
            $kCostStmt = $pdo->prepare("SELECT rough_cost, total_cost, total_polish_weight FROM kapans WHERE id = ?");
            $kCostStmt->execute([$id]);
            $kCost = $kCostStmt->fetch(PDO::FETCH_ASSOC);

            $rCost = (float)($kCost['rough_cost'] ?? 0);
            $tCost = (float)($kCost['total_cost'] ?? $rCost);
            $polWt = (float)($kCost['total_polish_weight'] ?? 0);

            $costPerCarat = $newTotWt > 0 ? round($tCost / $newTotWt, 2) : 0;
            $yieldPct = $newTotWt > 0 ? round(($polWt / $newTotWt) * 100, 2) : 0;

            $updKapanStmt = $pdo->prepare("UPDATE kapans SET 
                total_weight = ?, 
                cost_per_carat = ?,
                yield_percentage = ?
                WHERE id = ?");
            $updKapanStmt->execute([$newTotWt, $costPerCarat, $yieldPct, $id]);

            $pdo->commit();
            set_flash('success', "સફળતા! {$updatedCount} પેકેટ્સના વજન સફળતાપૂર્વક સાચવવામાં આવ્યા. કાપનનું કુલ વજન હવે " . format_carat($newTotWt) . " છે.");
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'વજન સાચવતી વખતે ભૂલ આવી: ' . $e->getMessage());
        }
    }
    $redirectUrl = BASE_URL . '/modules/kapan/view.php?id=' . $id;
    if (!empty($_GET['stage'])) {
        $redirectUrl .= '&stage=' . urlencode($_GET['stage']);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$pageTitle = 'Kapan વિગત અને Packets (Kapan Details)';
require_once __DIR__ . '/../../includes/header.php';

$stmt = $pdo->prepare("SELECT k.*, rp.purchase_no, p.party_name
                       FROM kapans k
                       LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
                       LEFT JOIN parties p ON rp.party_id = p.id
                       WHERE k.id = ?");
$stmt->execute([$id]);
$kapan = $stmt->fetch();

if (!$kapan) {
    set_flash('error', 'Kapan રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

// Fetch mapped rough packets from kapan_rough_mapping
$mappedRoughPackets = get_kapan_rough_mapping($id);

$stageFilter = trim($_GET['stage'] ?? '');

$pktSql = "SELECT p.*, pt.party_name as vendor_name
           FROM packets p
           LEFT JOIN parties pt ON p.assigned_party_id = pt.id
           WHERE p.kapan_id = ?
             AND COALESCE(p.is_reassorted, 0) = 0
             AND p.status != 'reassorted'";
$pktParams = [$id];

if (!empty($stageFilter)) {
    $pktSql .= " AND p.current_stage = ?";
    $pktParams[] = $stageFilter;
}

$pktSql .= " ORDER BY p.id ASC";
$pktStmt = $pdo->prepare($pktSql);
$pktStmt->execute($pktParams);
$packets = $pktStmt->fetchAll();

$currentDisplayedWeight = 0.0;
foreach ($packets as $pkt) {
    $currentDisplayedWeight += (float)($pkt['weight'] ?? 0);
}

// Historical Boil -> Re-Assort exited packets (preserved for full traceability)
$reaStmt = $pdo->prepare("
    SELECT brli.*, brl.lot_no, brl.return_date, brl.status as lot_status, brl.assort_task_id,
           bb.batch_no as boil_batch_no, at.task_no as assort_task_no, p.barcode
    FROM boil_reassort_lot_items brli
    JOIN boil_reassort_lots brl ON brli.reassort_lot_id = brl.id
    LEFT JOIN boil_batches bb ON brli.boil_batch_id = bb.id
    LEFT JOIN assort_tasks at ON brl.assort_task_id = at.id
    LEFT JOIN packets p ON brli.packet_id = p.id
    WHERE brli.old_kapan_id = ?
    ORDER BY brli.id DESC
");
$reaStmt->execute([$id]);
$reassortedHistoryPackets = $reaStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Kapan's Polish Stock & Sales/Jangad tracking breakdown
ensure_jangad_sales_columns();
sync_all_completed_packets_to_polish_stock();

$kpsStmt = $pdo->prepare("
    SELECT ps.*, p.id as linked_packet_id, p.diamond_no, p.barcode as linked_barcode
    FROM polish_stocks ps
    LEFT JOIN packets p ON p.polish_stock_id = ps.id
    WHERE ps.kapan_id = ?
      AND ps.status != 'reassorted'
    ORDER BY ps.id DESC
");
$kpsStmt->execute([$id]);
$kapanPolishStocks = $kpsStmt->fetchAll(PDO::FETCH_ASSOC);

$kpAvailPcs = 0;
$kpAvailWt  = 0.0;
foreach ($kapanPolishStocks as $kps) {
    $kpAvailPcs += (int)($kps['available_pcs'] ?? 0);
    $kpAvailWt  += (float)($kps['available_weight'] ?? 0);
}

$kJangadStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(GREATEST(0, ji.issued_pcs - COALESCE(ji.sold_pcs, 0) - ji.returned_pcs)), 0) as on_jangad_pcs,
        COALESCE(SUM(GREATEST(0, ji.issued_weight - COALESCE(ji.sold_weight, 0) - ji.returned_weight)), 0) as on_jangad_wt,
        COALESCE(SUM(ji.returned_pcs), 0) as ret_pcs,
        COALESCE(SUM(ji.returned_weight), 0) as ret_wt
    FROM jangad_items ji
    LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
    WHERE COALESCE(ji.kapan_id, ps.kapan_id) = ?
");
$kJangadStmt->execute([$id]);
$kJangadAgg = $kJangadStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$kpSaleStmt = $pdo->prepare("
    SELECT COALESCE(SUM(psale.pcs), 0) as sold_pcs, COALESCE(SUM(psale.weight), 0) as sold_wt, COALESCE(SUM(psale.net_amount), 0) as sold_amt
    FROM polish_sales psale
    JOIN polish_stocks ps ON psale.polish_stock_id = ps.id
    WHERE ps.kapan_id = ?
");
$kpSaleStmt->execute([$id]);
$kSaleAgg = $kpSaleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$kpOnJangadPcs = (int)($kJangadAgg['on_jangad_pcs'] ?? 0);
$kpOnJangadWt  = (float)($kJangadAgg['on_jangad_wt'] ?? 0);
$kpRetPcs      = (int)($kJangadAgg['ret_pcs'] ?? 0);
$kpRetWt       = (float)($kJangadAgg['ret_wt'] ?? 0);
$kpSoldPcs     = (int)($kSaleAgg['sold_pcs'] ?? 0);
$kpSoldWt      = (float)($kSaleAgg['sold_wt'] ?? 0);
$kpSoldAmt     = (float)($kSaleAgg['sold_amt'] ?? 0);

// Check if safe to delete
$chkAdvStmt = $pdo->prepare("SELECT COUNT(*) FROM packets WHERE kapan_id = ? AND (current_stage IN ('factory', 'polish_stock') OR polish_weight > 0)");
$chkAdvStmt->execute([$id]);
$advancedPacketsCount = (int)$chkAdvStmt->fetchColumn();
$canDelete = ($kapan['status'] !== 'closed' && $advancedPacketsCount === 0);

$kapanActions = '
    <a href="' . BASE_URL . '/modules/kapan/edit.php?id=' . $kapan['id'] . '" class="btn btn-outline-primary px-3 shadow-sm me-2">
        ' . svg_icon('pen-to-square', 'me-1') . 'કાપન એડિટ
    </a>
    <a href="' . BASE_URL . '/modules/kapan/print_barcode.php?kapan_id=' . $kapan['id'] . '" target="_blank" class="btn btn-dark px-3 shadow-sm me-2">
        ' . svg_icon('print', 'me-1') . 'બધા બારકોડ પ્રિન્ટ કરો
    </a>
    <a href="' . BASE_URL . '/modules/costing/view_kapan_costing.php?id=' . $kapan['id'] . '" class="btn btn-outline-primary px-3 shadow-sm me-2">
        ' . svg_icon('calculator', 'me-1') . 'Costing વિગત
    </a>
';

if ($canDelete) {
    $kapanActions .= '
        <button type="button" class="btn btn-outline-danger px-3 shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#deleteKapanModal">
            ' . svg_icon('trash', 'me-1') . 'કાપન ડિલીટ
        </button>
    ';
}

$kapanActions .= '
    <a href="' . BASE_URL . '/modules/kapan/index.php" class="btn btn-outline-secondary px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'Kapan યાદી
    </a>
';

$roughRefDisplay = !empty($kapan['rough_numbers']) 
    ? $kapan['rough_numbers'] 
    : (!empty($kapan['purchase_no']) ? $kapan['purchase_no'] : 'Direct');
?>

<?= render_page_header(
    $kapan['kapan_name'] . ' (' . $kapan['kapan_code'] . ')',
    'Rough સંદર્ભ: ' . $roughRefDisplay . ' • કુલ વજન: ' . format_carat($kapan['total_weight']) . ' (' . (int)$kapan['total_pcs'] . ' Pcs)',
    'boxes-stacked',
    $kapanActions,
    'primary'
) ?>

<!-- Metrics Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">કુલ પેકેટ્સ (Packets)</small>
            <strong class="fs-5 text-dark font-mono"><?= count($packets) ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">Rough પડતર (Cost)</small>
            <strong class="fs-5 text-dark font-mono"><?= format_inr($kapan['rough_cost']) ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">તૈયાર Polish વજન</small>
            <strong class="fs-5 text-success font-mono"><?= format_carat($kapan['total_polish_weight']) ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">Yield % (ટકાવારી)</small>
            <strong class="fs-5 text-primary font-mono"><?= number_format((float)$kapan['yield_percentage'], 2) ?>%</strong>
        </div>
    </div>
</div>

<?php if (!empty($kapanPolishStocks)): ?>
<!-- SECTION: KAPAN POLISH STOCK TRACKING (AVAILABLE vs ON JANGAD vs SOLD vs RETURNED) -->
<div class="erp-form-card mb-4">
    <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="header-title mb-0">
                <span class="header-icon icon-green"><?= svg_icon('gem') ?></span>
                આ કાપનનો તૈયાર પોલિશ સ્ટોક — હાજર / જાંગડ પર / વેચાણ / પરત (Kapan Polish Vault)
            </h6>
            <small class="text-muted">જાંગડમાંથી વણવેચાયેલ માલ પાછો અહીં આ જ કાપનના પોલિશ સ્ટોકમાં જમા થાય છે.</small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=stock&kapan_id=<?= $id ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                <?= svg_icon('up-right-from-square', 'me-1') ?>Polish Hub માં જુઓ
            </a>
        </div>
    </div>
    <div class="px-3 pt-3 pb-2 bg-light border-bottom">
        <div class="row g-2 text-center font-mono">
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-3 bg-white border">
                    <small class="text-muted d-block">હાલ સ્ટોકમાં હાજર (Available)</small>
                    <strong class="text-success fs-6"><?= format_carat($kpAvailWt) ?></strong>
                    <small class="text-muted ms-1">(<?= $kpAvailPcs ?> Pcs)</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-3 bg-white border">
                    <small class="text-muted d-block">જાંગડ પર બાકી (On Jangad)</small>
                    <strong class="text-warning-emphasis fs-6"><?= format_carat($kpOnJangadWt) ?></strong>
                    <small class="text-muted ms-1">(<?= $kpOnJangadPcs ?> Pcs)</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-3 bg-white border">
                    <small class="text-muted d-block">જાંગડમાંથી પાછો જમા (Returned)</small>
                    <strong class="text-info-emphasis fs-6"><?= format_carat($kpRetWt) ?></strong>
                    <small class="text-muted ms-1">(<?= $kpRetPcs ?> Pcs)</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2 rounded-3 bg-white border">
                    <small class="text-muted d-block">વેચાણ થયેલ (Sold)</small>
                    <strong class="text-primary fs-6"><?= format_carat($kpSoldWt) ?></strong>
                    <small class="text-muted ms-1">(<?= $kpSoldPcs ?> Pcs • <?= format_inr($kpSoldAmt) ?>)</small>
                </div>
            </div>
        </div>
    </div>
    <div class="erp-card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0 align-middle">
                <thead>
                    <tr>
                        <th>પોલિશ પેકેટ નં.</th>
                        <th>શેપ & વિગત</th>
                        <th class="text-center">હાજર નંગ / કુલ નંગ</th>
                        <th class="text-end">હાજર વજન (Ct)</th>
                        <th class="text-end">કુલ વજન (Ct)</th>
                        <th>સ્થળ (Location)</th>
                        <th>સ્થિતિ (Status)</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kapanPolishStocks as $kps):
                        $kAvailWt  = (float)($kps['available_weight'] ?? 0);
                        $kAvailPcs = (int)($kps['available_pcs'] ?? 0);
                        $kSt       = $kps['status'] ?? 'available';
                    ?>
                        <tr>
                            <td class="font-mono fw-bold">
                                <?= svg_icon('gem', 'text-success me-1') ?><?= e($kps['packet_no']) ?>
                                <?php if (!empty($kps['gia_number'])): ?>
                                    <span class="badge bg-info-subtle text-info border ms-1 small">GIA: <?= e($kps['gia_number']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($kps['shape'] ?: 'Round') ?></strong>
                                <small class="text-muted ms-1 font-mono"><?= e($kps['color']) ?> <?= e($kps['clarity']) ?></small>
                            </td>
                            <td class="text-center font-mono"><?= $kAvailPcs ?> / <?= (int)$kps['total_pcs'] ?> Pcs</td>
                            <td class="text-end font-mono fw-bold text-success"><?= format_carat($kAvailWt) ?></td>
                            <td class="text-end font-mono text-muted"><?= format_carat($kps['total_weight']) ?></td>
                            <td><small class="text-muted"><?= svg_icon('location-dot', 'text-danger me-1') ?><?= e($kps['current_location'] ?: 'Surat') ?></small></td>
                            <td>
                                <?php if ($kSt === 'on_jangad'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border">જાંગડ પર ગયેલ</span>
                                <?php elseif ($kSt === 'partially_on_jangad'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ જાંગડ પર</span>
                                <?php elseif ($kSt === 'sold'): ?>
                                    <span class="badge bg-primary-subtle text-primary border">વેચાઈ ગયેલ (Sold)</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border">કાપન સ્ટોકમાં હાજર</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($kAvailWt > 0 && $kAvailPcs > 0): ?>
                                    <a href="<?= BASE_URL ?>/modules/jangad/create.php?stock_id=<?= (int)$kps['id'] ?>" class="btn btn-xs btn-outline-warning text-dark fw-semibold px-2 py-1 me-1" title="Sales Jangad પર આપો">
                                        <?= svg_icon('file-invoice', 'me-1') ?>જાંગડ
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?stock_id=<?= (int)$kps['id'] ?>" class="btn btn-xs btn-outline-primary fw-semibold px-2 py-1" title="સીધું વેચાણ કરો">
                                        <?= svg_icon('cart-shopping', 'me-1') ?>વેચાણ
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- SECTION: ALLOCATED / MERGED ROUGH PACKETS TABLE -->
<div class="erp-form-card mb-4">
    <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="header-title mb-0">
                <span class="header-icon"><?= svg_icon('gem') ?></span>
                સમાવિષ્ટ Rough પેકેટ્સ (Allocated / Merged Rough Packets)
            </h6>
        </div>
        <div>
            <span class="badge bg-primary-subtle text-primary border font-mono">
                <?= count($mappedRoughPackets) ?> પેકેટ્સ મર્જ થયેલ
            </span>
        </div>
    </div>
    <div class="erp-card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 25%;">Rough Number (રફ પેકેટ નં)</th>
                        <th style="width: 20%;">સ્ત્રોત પ્રકાર (Type)</th>
                        <th style="width: 15%;" class="text-center">ફાળવેલ નંગ (Pcs)</th>
                        <th style="width: 15%;" class="text-end">ફાળવેલ વજન (Carats)</th>
                        <th style="width: 10%;" class="text-end">ભાવ / Ct (₹)</th>
                        <th style="width: 15%;" class="text-end">Rough પડતર (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mappedRoughPackets)): ?>
                        <?php if (!empty($kapan['rough_purchase_id']) || !empty($kapan['rough_numbers'])): ?>
                            <tr>
                                <td class="font-mono fw-bold text-dark"><?= e($roughRefDisplay) ?></td>
                                <td><span class="badge bg-secondary-subtle text-secondary border">Direct Legacy</span></td>
                                <td class="text-center font-mono"><?= (int)$kapan['total_pcs'] ?></td>
                                <td class="text-end font-mono fw-bold text-primary"><?= format_carat($kapan['total_weight']) ?></td>
                                <td class="text-end font-mono">₹ <?= (float)$kapan['total_weight'] > 0 ? number_format((float)$kapan['rough_cost'] / (float)$kapan['total_weight'], 2) : '0.00' ?></td>
                                <td class="text-end font-mono fw-bold text-dark"><?= format_inr($kapan['rough_cost']) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-3 text-muted">કોઈ Rough પેકેટ મેપિંગ મળ્યું નથી.</td></tr>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php 
                        $sumPcs = 0;
                        $sumWeight = 0.0;
                        $sumCost = 0.0;
                        foreach ($mappedRoughPackets as $m): 
                            $sumPcs += (int)$m['allocated_pcs'];
                            $sumWeight += (float)$m['allocated_weight'];
                            $sumCost += (float)$m['allocated_cost'];
                            $ratePerCt = (float)$m['allocated_weight'] > 0 ? (float)$m['allocated_cost'] / (float)$m['allocated_weight'] : 0.0;
                        ?>
                            <tr>
                                <td>
                                    <span class="font-mono fw-bold text-dark fs-6"><?= e($m['rough_number']) ?></span>
                                </td>
                                <td>
                                    <?php if ($m['rough_packet_type'] === 'assort_part'): ?>
                                        <span class="badge bg-primary-subtle text-primary border">
                                            <?= svg_icon('layer-group', 'me-1') ?>Assort Part
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border">
                                            <?= svg_icon('cart-shopping', 'me-1') ?>Direct Purchase
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono fw-semibold"><?= (int)$m['allocated_pcs'] ?></td>
                                <td class="text-end font-mono fw-bold text-primary"><?= format_carat($m['allocated_weight']) ?></td>
                                <td class="text-end font-mono text-muted">₹ <?= number_format($ratePerCt, 2) ?></td>
                                <td class="text-end font-mono fw-bold text-dark"><?= format_inr($m['allocated_cost']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light" style="border-top: 2px solid #cbd5e1;">
                    <tr class="fw-bold">
                        <td colspan="2" class="text-dark">
                            <?= svg_icon('calculator', 'text-primary me-1') ?>કુલ કાપન Rough વિગતો (Kapan Total)
                        </td>
                        <td class="text-center font-mono fs-6"><?= (int)$kapan['total_pcs'] ?> Pcs</td>
                        <td class="text-end font-mono fs-6 text-primary"><?= format_carat($kapan['total_weight']) ?></td>
                        <td class="text-end text-muted small">કુલ પડતર:</td>
                        <td class="text-end font-mono fs-6 text-dark"><?= format_inr($kapan['rough_cost']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Stage Filter Pills -->
<div class="erp-filter-card mb-4">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="small fw-bold text-muted me-2"><?= svg_icon('filter', 'me-1') ?>સ્ટેજ ફિલ્ટર:</span>
        <a href="?id=<?= $id ?>" class="btn btn-sm <?= empty($stageFilter) ? 'btn-dark' : 'btn-outline-secondary' ?>">બધા (All)</a>
        <a href="?id=<?= $id ?>&stage=galaxy" class="btn btn-sm <?= $stageFilter === 'galaxy' ? 'btn-primary' : 'btn-outline-secondary' ?>">૧. Galaxy</a>
        <a href="?id=<?= $id ?>&stage=planner" class="btn btn-sm <?= $stageFilter === 'planner' ? 'btn-primary' : 'btn-outline-secondary' ?>">૨. Planner</a>
        <a href="?id=<?= $id ?>&stage=sarin" class="btn btn-sm <?= $stageFilter === 'sarin' ? 'btn-primary' : 'btn-outline-secondary' ?>">૩. Sarin</a>
        <a href="?id=<?= $id ?>&stage=ls" class="btn btn-sm <?= $stageFilter === 'ls' ? 'btn-primary' : 'btn-outline-secondary' ?>">૪. LS Sawing</a>
        <a href="?id=<?= $id ?>&stage=factory" class="btn btn-sm <?= $stageFilter === 'factory' ? 'btn-primary' : 'btn-outline-secondary' ?>">૫. કારખાનું (Polish)</a>
        <a href="?id=<?= $id ?>&stage=boil" class="btn btn-sm <?= $stageFilter === 'boil' ? 'btn-primary' : 'btn-outline-secondary' ?>">૬. Boil</a>
        <a href="?id=<?= $id ?>&stage=polish_stock" class="btn btn-sm <?= $stageFilter === 'polish_stock' ? 'btn-success text-white' : 'btn-outline-secondary' ?>">૭. Polish Stock</a>
    </div>
</div>

<!-- Packets Table with Batch Weight Editing -->
<div class="erp-form-card mb-4 shadow-sm border">
    <div class="erp-card-header py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 bg-white">
        <div class="d-flex align-items-center gap-2">
            <span class="header-icon icon-gold"><?= svg_icon('barcode') ?></span>
            <div>
                <h6 class="header-title mb-0">
                    Kapan ના પેકેટ્સ અને વજન એડિટ (<?= count($packets) ?> Pcs)
                </h6>
                <small class="text-muted" style="font-size: 0.72rem;">દરેક હીરાનું વજન નીચેના બોક્સમાં સીધું બદલી શકો છો — સુધારીને 'સાચવો' દબાવો.</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark font-mono border px-2.5 py-1.5 small">
                કુલ વજન: <strong id="header_live_weight" class="text-primary"><?= format_carat($currentDisplayedWeight) ?></strong>
            </span>
            <span id="all_saved_badge" class="badge bg-success-subtle text-success border px-2.5 py-1.5 small">
                <?= svg_icon('check', 'me-1 text-success') ?>બધા વજન સાચવેલ છે
            </span>
            <button type="submit" form="kapanWeightsForm" class="btn btn-sm btn-success px-3 shadow-sm fw-bold d-none align-items-center gap-1.5" id="headerSaveWeightsBtn">
                <?= svg_icon('check', 'me-1') ?>બધા વજન સાચવો (Save All)
            </button>
        </div>
    </div>
    <div class="erp-card-body p-0">
        <form method="POST" id="kapanWeightsForm" action="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= $id ?><?= !empty($stageFilter) ? '&stage=' . urlencode($stageFilter) : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
            <input type="hidden" name="action" value="update_packet_weights">
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="table-responsive table-scroll-container" style="max-height: 420px; overflow-y: auto; overflow-x: auto; scrollbar-width: thin;">
                <table class="table table-diamond mb-0 align-middle">
                    <thead style="position: sticky; top: 0; z-index: 5; background: #f8fafc; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                        <tr>
                            <th>ડાયમંડ નંબર (Diamond No)</th>
                            <th>Assort / Rough</th>
                            <th>બારકોડ</th>
                            <th style="min-width: 145px; width: 155px;" class="bg-primary-subtle text-primary">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span class="fw-bold"><?= svg_icon('pen-to-square', 'me-1 text-primary') ?>વજન (Ct) *</span>
                                    <span class="badge bg-primary text-white" style="font-size: 0.65rem;">Editable</span>
                                </div>
                            </th>
                            <th>સ્થિતિ (Status)</th>
                            <th>હાલનું સ્ટેજ (Stage)</th>
                            <th>જગ્યા (Location)</th>
                            <th>વેન્ડર / પાર્ટી</th>
                            <th>Polish વજન</th>
                            <th class="text-end">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packets)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-muted">કોઈ ડાયમંડ રેકોર્ડ મળ્યો નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($packets as $pkt): 
                                $dNo = !empty($pkt['diamond_no']) ? $pkt['diamond_no'] : $pkt['packet_no'];
                                $pktWt = (float)($pkt['weight'] ?? 0);
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-1.5">
                                            <strong class="font-mono fs-6 text-dark"><?= e($dNo) ?></strong>
                                            <?php if (!empty($pkt['parent_diamond_no'])): ?>
                                                <span class="badge bg-secondary-subtle text-secondary border font-mono small" title="Parent: <?= e($pkt['parent_diamond_no']) ?>">
                                                    Sub (<?= e($pkt['parent_diamond_no']) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark font-mono border">
                                            <?= e($pkt['assort_number'] ?: $pkt['rough_number'] ?: '-') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-monospace small bg-light px-2 py-1 rounded border">
                                            <?= e($pkt['barcode']) ?>
                                        </span>
                                    </td>
                                    <td class="bg-light-subtle">
                                        <div class="input-group input-group-sm">
                                            <input type="number" 
                                                   step="0.001" 
                                                   min="0" 
                                                   name="packet_weights[<?= $pkt['id'] ?>]" 
                                                   value="<?= $pktWt > 0 ? number_format($pktWt, 3, '.', '') : '' ?>" 
                                                   data-original="<?= $pktWt > 0 ? number_format($pktWt, 3, '.', '') : '' ?>"
                                                   placeholder="0.000"
                                                   class="form-control form-control-sm font-mono text-end fw-bold pkt-weight-input border-primary-subtle" 
                                                   style="background-color: <?= $pktWt > 0 ? '#ffffff' : '#fffdf5' ?>; transition: all 0.15s ease;"
                                                   title="વજન ટાઈપ કરો (Enter દબાવી નીચેના બોક્સમાં જઈ શકો છો)">
                                            <span class="input-group-text small px-1.5 bg-light text-muted font-mono" style="font-size: 0.72rem;">ct</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $pStatus = $pkt['status'] ?? '';
                                        $dStatus = $pkt['diamond_status'] ?? 'available';
                                        if ($pStatus === 'sold') {
                                            $sb = ['વેચાઈ ગયેલ (Sold)', 'bg-primary-subtle text-primary border'];
                                        } elseif ($pStatus === 'in_polish_stock' && $dStatus === 'available') {
                                            $sb = ['પોલિશ સ્ટોકમાં હાજર', 'bg-success-subtle text-success border'];
                                        } else {
                                            $statusBadges = [
                                                'available'          => ['હાજર (Available)', 'bg-success-subtle text-success border'],
                                                'assigned_to_jangad' => ['જાંગડમાં (Assigned)', 'bg-warning-subtle text-warning-emphasis border'],
                                                'partially_returned' => ['અંશતઃ પરત', 'bg-warning-subtle text-warning-emphasis border'],
                                                'returned'           => ['પરત મળેલ', 'bg-info-subtle text-info border'],
                                                'split'              => ['સ્પ્લિટ થયેલ (Split)', 'bg-secondary-subtle text-secondary border'],
                                                'completed'          => ['પૂર્ણ (Completed)', 'bg-dark-subtle text-dark border'],
                                                'tops'               => ['Tops રદિયો', 'bg-danger-subtle text-danger border'],
                                                'in_process'         => ['પ્રોસેસમાં', 'bg-light text-dark border']
                                            ];
                                            $sb = $statusBadges[$dStatus] ?? [e($dStatus), 'bg-light text-dark border'];
                                        }
                                        ?>
                                        <span class="badge <?= $sb[1] ?> font-mono"><?= $sb[0] ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $stageColors = [
                                            'galaxy' => 'bg-info-subtle text-info',
                                            'planner' => 'bg-primary-subtle text-primary',
                                            'sarin' => 'bg-primary-subtle text-primary',
                                            'ls' => 'bg-warning-subtle text-warning-emphasis',
                                            'laser' => 'bg-warning-subtle text-warning-emphasis',
                                            'sawing' => 'bg-warning-subtle text-warning-emphasis',
                                            'blocking' => 'bg-danger-subtle text-danger',
                                            'faceting' => 'bg-danger-subtle text-danger',
                                            'polishing' => 'bg-danger-subtle text-danger',
                                            'factory' => 'bg-danger-subtle text-danger',
                                            'final_checking' => 'bg-info-subtle text-info',
                                            'boil' => 'bg-secondary-subtle text-secondary',
                                            'polish_stock' => 'bg-success-subtle text-success fw-bold'
                                        ];
                                        $c = $stageColors[$pkt['current_stage']] ?? 'bg-light text-dark';
                                        ?>
                                        <span class="badge <?= $c ?> border text-uppercase"><?= e($pkt['current_stage']) ?></span>
                                    </td>
                                    <td>
                                        <?= svg_icon('location-dot', 'text-danger me-1') ?>
                                        <?= e($pkt['current_location']) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($pkt['vendor_name']) && !empty($pkt['assigned_party_id'])): ?>
                                            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $pkt['assigned_party_id'] ?>&tab=jangad" class="text-primary text-decoration-none fw-semibold" title="વેન્ડર લેજર જુઓ">
                                                <?= e($pkt['vendor_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= e($pkt['vendor_name'] ?? '-') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((float)$pkt['polish_weight'] > 0): ?>
                                            <strong class="text-success font-mono"><?= format_carat($pkt['polish_weight']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted font-mono">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/process/update_stage.php?id=<?= $pkt['id'] ?>" class="btn btn-sm btn-outline-primary" title="સ્ટેજ અપડેટ">
                                            <?= svg_icon('arrow-right-arrow-left', 'me-1') ?>સ્ટેજ
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($packets)): ?>
                    <tfoot style="position: sticky; bottom: 0; z-index: 5; background: #f8fafc; box-shadow: 0 -2px 5px rgba(0,0,0,0.06);">
                        <tr class="table-light border-top border-2">
                            <th colspan="3" class="text-end fw-bold">કુલ પેકેટ્સ: <?= count($packets) ?> Pcs</th>
                            <th class="bg-primary-subtle">
                                <div class="d-flex flex-column align-items-end">
                                    <span class="text-muted small" style="font-size: 0.7rem;">નવું કુલ વજન:</span>
                                    <strong id="footer_live_weight" class="text-primary font-mono fs-6"><?= format_carat($currentDisplayedWeight) ?></strong>
                                    <span id="footer_diff_weight" class="badge bg-light text-muted font-mono border" style="font-size: 0.68rem;">Diff: 0.000 ct</span>
                                </div>
                            </th>
                            <th colspan="5">
                                <span class="small text-muted">
                                    (મૂળ Kapan વજન: <strong class="font-mono"><?= format_carat($kapan['total_weight']) ?></strong>)
                                </span>
                            </th>
                            <th class="text-end">
                                <button type="submit" form="kapanWeightsForm" class="btn btn-sm btn-success px-3 shadow-sm fw-bold d-none" id="footerSaveWeightsBtn">
                                    <?= svg_icon('check', 'me-1') ?>બધા વજન સાચવો
                                </button>
                            </th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- Floating Quick Save Bar (Reveals automatically ONLY when any weight is modified) -->
<div id="sticky_weight_save_bar" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg rounded-pill p-2 px-4 bg-dark text-white align-items-center gap-3 border border-secondary d-none" style="z-index: 1050;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-warning text-dark font-mono px-2 py-1" id="changed_count_badge">0 બદલાયેલ</span>
        <span class="small">કુલ વજન: <strong id="floating_live_weight" class="text-warning font-mono"><?= format_carat($currentDisplayedWeight) ?></strong></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" id="resetWeightsBtn" class="btn btn-outline-light btn-sm px-2 rounded-pill small" title="મૂળ વજન પુનઃસ્થાપિત કરો">
            રદ કરો
        </button>
        <button type="submit" form="kapanWeightsForm" class="btn btn-success btn-sm px-3 rounded-pill fw-bold shadow">
            <?= svg_icon('check', 'me-1') ?>બધા વજન સાચવો (Save All)
        </button>
    </div>
</div>

<?php if (!empty($reassortedHistoryPackets)): ?>
<!-- SECTION: BOIL RETURN -> RE-ASSORT EXITED HISTORY (PRESERVED FOR AUDIT) -->
<div class="erp-form-card mb-4 border border-warning">
    <div class="erp-card-header bg-warning-subtle py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="header-title mb-0 text-dark">
                <span class="header-icon icon-rose"><?= svg_icon('clock-rotate-left') ?></span>
                ઇતિહાસ: Boil પરત &rarr; Re-Assort માં નીકળેલા હીરા (<?= count($reassortedHistoryPackets) ?> Pcs)
            </h6>
            <small class="text-muted">આ હીરા જૂની કાપનના હાજર સ્ટોકમાંથી નીકળી નવા લોટ નંબર સાથે ફરીથી Assort માં ગયા છે — તમામ ઇતિહાસ અહીં સુરક્ષિત છે.</small>
        </div>
    </div>
    <div class="erp-card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0 align-middle">
                <thead>
                    <tr>
                        <th>જૂનો ડાયમંડ નં.</th>
                        <th>Boil બેચ</th>
                        <th>પરત તારીખ</th>
                        <th class="text-end">વજન (Ct)</th>
                        <th>નવો લોટ નંબર (New Lot No)</th>
                        <th>Assort ટાસ્ક</th>
                        <th class="text-end">ઇતિહાસ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reassortedHistoryPackets as $rhp): ?>
                        <tr>
                            <td>
                                <strong class="font-mono text-dark"><?= e($rhp['old_diamond_no']) ?></strong>
                                <?php if (!empty($rhp['barcode'])): ?>
                                    <small class="text-muted d-block font-mono"><?= e($rhp['barcode']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border font-mono"><?= e($rhp['boil_batch_no'] ?? '-') ?></span></td>
                            <td><?= format_date($rhp['return_date']) ?></td>
                            <td class="text-end font-mono fw-bold"><?= format_carat($rhp['weight']) ?></td>
                            <td><strong class="font-mono text-primary"><?= e($rhp['lot_no']) ?></strong></td>
                            <td>
                                <?php if (!empty($rhp['assort_task_id'])): ?>
                                    <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$rhp['assort_task_id'] ?>" class="badge bg-success-subtle text-success border text-decoration-none font-mono">
                                        <?= e($rhp['assort_task_no'] ?? ('ASRT #' . $rhp['assort_task_id'])) ?> &rarr;
                                    </a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?>/modules/assort/create.php?reassort_lot_id=<?= (int)$rhp['reassort_lot_id'] ?>" class="badge bg-warning-subtle text-warning-emphasis border text-decoration-none">
                                        Assort (ભાગ પાડવા) બાકી &rarr;
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= (int)$rhp['packet_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <?= svg_icon('clock-rotate-left', 'me-1') ?>જર્ની જુઓ
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<!-- Delete Kapan Confirmation Modal -->
<div class="modal fade" id="deleteKapanModal" tabindex="-1" aria-labelledby="deleteKapanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="deleteKapanModalLabel">
                    <?= svg_icon('triangle-exclamation', 'me-2') ?>કાપન ડિલીટ કરવાની પુષ્ટિ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= BASE_URL ?>/modules/kapan/delete.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="modal-body py-3">
                    <p class="mb-2">શું તમે ખરેખર Kapan <strong class="text-dark font-mono"><?= e($kapan['kapan_code']) ?> (<?= e($kapan['kapan_name']) ?>)</strong> ને ડિલીટ કરવા માંગો છો?</p>
                    <div class="alert alert-warning small mb-0">
                        <?= svg_icon('shield-halved', 'me-1') ?>
                        આ કાપનમાં ફાળવેલા તમામ <strong>Rough પેકેટ્સ આપમેળે મુક્ત (Available)</strong> થઈને ફરીથી નવા કાપન બનાવવા માટે ઉપલબ્ધ થઈ જશે.
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-danger px-4 fw-bold">
                        <?= svg_icon('trash', 'me-1') ?>હા, કાપન ડિલીટ કરો
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = Array.from(document.querySelectorAll('.pkt-weight-input'));
    if (!inputs.length) return;

    const headerWeight = document.getElementById('header_live_weight');
    const footerWeight = document.getElementById('footer_live_weight');
    const footerDiff = document.getElementById('footer_diff_weight');
    const floatingWeight = document.getElementById('floating_live_weight');
    const stickyBar = document.getElementById('sticky_weight_save_bar');
    const changedBadge = document.getElementById('changed_count_badge');
    const resetBtn = document.getElementById('resetWeightsBtn');

    const origTotal = inputs.reduce((sum, inp) => sum + (parseFloat(inp.dataset.original) || 0), 0);

    function updateWeightTotals() {
        let currentTotal = 0;
        let changedCount = 0;

        inputs.forEach(inp => {
            const val = parseFloat(inp.value) || 0;
            const orig = parseFloat(inp.dataset.original) || 0;
            currentTotal += val;

            if (Math.abs(val - orig) > 0.0005) {
                changedCount++;
                inp.style.backgroundColor = '#fffbeb';
                inp.classList.remove('border-primary-subtle');
                inp.classList.add('border-warning', 'text-dark');
            } else {
                inp.style.backgroundColor = '#ffffff';
                inp.classList.remove('border-warning');
                inp.classList.add('border-primary-subtle');
            }
        });

        const diff = currentTotal - origTotal;
        const formattedTotal = currentTotal.toFixed(3) + ' ct';
        const formattedDiff = (diff >= 0 ? '+' : '') + diff.toFixed(3) + ' ct';

        if (headerWeight) headerWeight.innerText = formattedTotal;
        if (footerWeight) footerWeight.innerText = formattedTotal;
        if (floatingWeight) floatingWeight.innerText = formattedTotal;

        if (footerDiff) {
            footerDiff.innerText = 'Diff: ' + formattedDiff;
            if (Math.abs(diff) > 0.005) {
                footerDiff.className = 'badge bg-warning-subtle text-warning border font-mono';
            } else {
                footerDiff.className = 'badge bg-light text-muted border font-mono';
            }
        }

        const headerSaveBtn = document.getElementById('headerSaveWeightsBtn');
        const footerSaveBtn = document.getElementById('footerSaveWeightsBtn');
        const allSavedBadge = document.getElementById('all_saved_badge');

        if (changedCount > 0) {
            // Show Save buttons and Floating Bar when any weight is modified
            if (stickyBar) {
                stickyBar.classList.remove('d-none');
                stickyBar.classList.add('d-flex');
            }
            if (changedBadge) changedBadge.innerText = changedCount + ' બદલાયેલ';
            if (headerSaveBtn) {
                headerSaveBtn.classList.remove('d-none');
                headerSaveBtn.classList.add('d-inline-flex');
            }
            if (footerSaveBtn) footerSaveBtn.classList.remove('d-none');
            if (allSavedBadge) allSavedBadge.classList.add('d-none');
        } else {
            // Hide Save buttons and Floating Bar completely when no unsaved changes
            if (stickyBar) {
                stickyBar.classList.remove('d-flex');
                stickyBar.classList.add('d-none');
            }
            if (headerSaveBtn) {
                headerSaveBtn.classList.remove('d-inline-flex');
                headerSaveBtn.classList.add('d-none');
            }
            if (footerSaveBtn) footerSaveBtn.classList.add('d-none');
            if (allSavedBadge) allSavedBadge.classList.remove('d-none');
        }
    }

    inputs.forEach((inp, idx) => {
        inp.addEventListener('input', updateWeightTotals);
        inp.addEventListener('focus', function () {
            this.select();
        });

        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === 'ArrowDown') {
                e.preventDefault();
                if (idx < inputs.length - 1) {
                    inputs[idx + 1].focus();
                    inputs[idx + 1].select();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (idx > 0) {
                    inputs[idx - 1].focus();
                    inputs[idx - 1].select();
                }
            }
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (confirm('શું તમે ખરેખર બધા કરેલા ફેરફારો રદ કરી મૂળ વજન રાખવા માંગો છો?')) {
                inputs.forEach(inp => {
                    inp.value = inp.dataset.original;
                });
                updateWeightTotals();
            }
        });
    }

    // Initial check
    updateWeightTotals();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
