<?php
/**
 * Assort Tasks Register (સેક્શન ૬.૨ ભાગ પાડવા)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Assort Tasks રજિસ્ટર (ભાગ પાડવા)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_boil_reassort_columns();

// Active tab and sub-tab selection via GET
$activeTab = $_GET['tab'] ?? 'assorted'; // options: 'total_rough', 'assorted', 'pending'
$activeSubTab = $_GET['subtab'] ?? 'mfg'; // options: 'mfg', 'mfg_later', 'sell', 'ghat', 'all_tasks'

// 1. All Assort Tasks
$stmt = $pdo->query("SELECT at.*, rp.purchase_no, p.party_name,
                            brl.lot_no as reassort_lot_no, brl.lot_rate as reassort_lot_rate, brl.source_kapan_codes, bb.batch_no as boil_batch_no,
                            (SELECT COUNT(*) FROM assort_parts WHERE assort_task_id = at.id) as total_parts
                     FROM assort_tasks at
                     LEFT JOIN rough_purchases rp ON at.rough_purchase_id = rp.id
                     LEFT JOIN parties p ON rp.party_id = p.id
                     LEFT JOIN boil_reassort_lots brl ON at.reassort_lot_id = brl.id
                     LEFT JOIN boil_batches bb ON brl.boil_batch_id = bb.id
                     ORDER BY at.assort_date DESC, at.id DESC");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Pending Boil Re-Assort Lots
$pendingReassortLots = $pdo->query("
    SELECT brl.*, bb.batch_no
    FROM boil_reassort_lots brl
    LEFT JOIN boil_batches bb ON brl.boil_batch_id = bb.id
    WHERE brl.status = 'pending_assort'
    ORDER BY brl.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. All Rough Purchases (Total Rough Inventory)
$roughPurchases = $pdo->query("
    SELECT rp.*, p.party_name, b.party_name as broker_name,
           (rp.weight - rp.available_weight) as used_weight,
           (rp.pcs - rp.available_pcs) as used_pcs
    FROM rough_purchases rp
    LEFT JOIN parties p ON rp.party_id = p.id
    LEFT JOIN parties b ON rp.broker_id = b.id
    ORDER BY rp.purchase_date DESC, rp.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Pending Rough Purchases (Assort બાકી)
$pendingRoughPurchases = $pdo->query("
    SELECT rp.*, p.party_name, b.party_name as broker_name
    FROM rough_purchases rp
    LEFT JOIN parties p ON rp.party_id = p.id
    LEFT JOIN parties b ON rp.broker_id = b.id
    WHERE rp.status IN ('in_stock', 'in_assort') AND rp.available_weight > 0
    ORDER BY rp.purchase_date DESC, rp.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 5. All Assorted Parts
$allParts = $pdo->query("
    SELECT ap.*, at.task_no, at.assort_date, rp.purchase_no, p.party_name,
           brl.lot_no as reassort_lot_no
    FROM assort_parts ap
    LEFT JOIN assort_tasks at ON ap.assort_task_id = at.id
    LEFT JOIN rough_purchases rp ON at.rough_purchase_id = rp.id
    LEFT JOIN parties p ON rp.party_id = p.id
    LEFT JOIN boil_reassort_lots brl ON at.reassort_lot_id = brl.id
    WHERE ap.status != 'merged'
    ORDER BY ap.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Partition parts into MFG, MFG Later, and Sell
$mfgParts = array_values(array_filter($allParts, fn($p) => ($p['part_type'] ?? '') === 'mfg'));
$mfgLaterParts = array_values(array_filter($allParts, fn($p) => in_array($p['part_type'] ?? '', ['low_number_mfg', 'manual_clv', 'manual_sawing'])));
$sellParts = array_values(array_filter($allParts, fn($p) => in_array($p['part_type'] ?? '', ['out_sell', 'sell'])));

// High-level aggregates for the 7 requested KPI Cards
$assortCount = count($tasks);
$totInputWt = 0.000;
$totOutputWt = 0.000;
$totParts = 0;
foreach ($tasks as $t) {
    $totInputWt += (float)($t['total_input_weight'] ?? 0);
    $totOutputWt += (float)($t['total_output_weight'] ?? 0);
    $totParts += (int)($t['total_parts'] ?? 0);
}

// 1. Total Rough Input for Assort (કુલ રફ એસોર્ટ માટે આવી)
// Note: Exclude status = 'merged' so lots merged into another purchase are counted only ONCE
$activeRoughPurchases = array_values(array_filter($roughPurchases, fn($rp) => ($rp['status'] ?? '') !== 'merged'));
$totalRoughCount = count($activeRoughPurchases);
$totalRoughCarat = (float)array_sum(array_column($activeRoughPurchases, 'weight'));
$activeRoughUsedWeight = (float)array_sum(array_column($activeRoughPurchases, 'used_weight'));
$activeRoughAvailWeight = (float)array_sum(array_column($activeRoughPurchases, 'available_weight'));
$mergedRoughPurchases = array_filter($roughPurchases, fn($rp) => ($rp['status'] ?? '') === 'merged');
$mergedRoughCount = count($mergedRoughPurchases);
$mergedRoughWeight = (float)array_sum(array_column($mergedRoughPurchases, 'weight'));

// 2. Pending Assort (એસોર્ટ બાકી)
$pendingRoughCount = count($pendingRoughPurchases);
$pendingRoughWeight = (float)array_sum(array_column($pendingRoughPurchases, 'available_weight'));
$pendingBoilCount = count($pendingReassortLots);
$pendingBoilWeight = (float)array_sum(array_column($pendingReassortLots, 'total_weight'));
$totalPendingCount = $pendingRoughCount + $pendingBoilCount;
$totalPendingWeight = $pendingRoughWeight + $pendingBoilWeight;

// 3. Assort Done (એસોર્ટ થઈ ગઈ)
$assortDoneTasksCount = $assortCount;
$assortDoneInputWeight = $totInputWt;
$assortDoneOutputWeight = $totOutputWt;
$assortDonePartsCount = count($allParts);

// 4. MFG Tag (કાપન બનશે / બનેલ)
$mfgPartsCount = count($mfgParts);
$mfgPartsWeight = (float)array_sum(array_column($mfgParts, 'weight'));
$mfgInKapanCount = count(array_filter($mfgParts, fn($p) => ($p['status'] ?? '') === 'in_kapan'));

// 5. MFG Later Tag (પછી કાપન)
$mfgLaterPartsCount = count($mfgLaterParts);
$mfgLaterPartsWeight = (float)array_sum(array_column($mfgLaterParts, 'weight'));

// 6. Sell Tag (સીધું વેચાણ)
$sellPartsCount = count($sellParts);
$sellPartsWeight = (float)array_sum(array_column($sellParts, 'weight'));

// 7. Ghat / તફાવત (Loss Diff)
$totGhatWeight = round($totInputWt - $totOutputWt, 3);
$totGhatPct = $totInputWt > 0 ? round(($totGhatWeight / $totInputWt) * 100, 2) : 0.00;
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Assort Tasks (રફના ભાગ પાડવા - Rough Assortment)',
    '',
    'layer-group',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/admin/settings.php?tab=part_names" class="btn btn-outline-secondary px-3">' . svg_icon('sliders', 'me-1') . 'ભાગ સેટિંગ્સ</a>
        <a href="' . BASE_URL . '/modules/assort/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવો Assort ટાસ્ક બનાવો</a>
    </div>',
    'purple'
) ?>

<!-- ======================================================================= -->
<!-- EXECUTIVE ASSORT & ROUGH RECONCILIATION STRIP                            -->
<!-- ======================================================================= -->
<div class="alert alert-light border rounded-3 py-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2 shadow-sm" style="background: #f8fafc;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary px-2 py-1"><?= svg_icon('scale-balanced', 'me-1', 13) ?>એસોર્ટ સ્ટોક મેળ (Reconciliation)</span>
        <span class="small text-muted d-none d-md-inline">કુલ રફ ખરીદી (<?= format_carat($totalRoughCarat) ?>) અને એસોર્ટ ઉત્પાદન વિભાજનની પાકી ગણતરી:</span>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 font-mono small">
        <span class="badge bg-white text-dark border">કુલ રફ: <strong><?= format_carat($totalRoughCarat) ?></strong></span>
        <span>=</span>
        <a href="?tab=pending" class="text-decoration-none" onclick="showAssortSection('pending', ''); return false;">
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="એસોર્ટ બાકી લૉટ્સ">બાકી: <strong><?= format_carat($totalPendingWeight) ?></strong></span>
        </a>
        <span>+</span>
        <a href="?tab=assorted&subtab=all_tasks" class="text-decoration-none" onclick="showAssortSection('assorted', 'all_tasks'); return false;">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="એસોર્ટ પૂર્ણ થયેલ રફ">પૂર્ણ: <strong><?= format_carat($assortDoneInputWeight) ?></strong></span>
        </a>
        <span class="text-muted ms-1 d-none d-lg-inline">|</span>
        <span class="badge bg-white text-dark border d-none d-lg-inline">
            <span class="text-muted">વિભાજન મેળ: </span>
            <strong class="text-primary"><?= format_carat($assortDoneInputWeight) ?></strong> = 
            <span class="text-primary"><?= format_carat($mfgPartsWeight) ?> કાપન</span> + 
            <span class="text-secondary"><?= format_carat($mfgLaterPartsWeight) ?> પછી</span> + 
            <span class="text-success"><?= format_carat($sellPartsWeight) ?> વેચાણ</span>
            <?php if ($totGhatWeight > 0): ?>
            + <span class="text-danger"><?= format_carat($totGhatWeight) ?> ઘટ</span>
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- ======================================================================= -->
<!-- 7 CORE ASSORT LIFECYCLE KPI CARDS WITH INSTANT LIST FILTERING           -->
<!-- ======================================================================= -->
<div class="mb-3">
    <!-- Row 1: Pipeline Overview (3 Cards: Total Rough, Pending Assort, Assort Done) -->
    <div class="row g-2.5 mb-2.5">
        <!-- 1. Total Rough (કુલ રફ એસોર્ટ માટે આવી) -->
        <div class="col-12 col-md-4">
            <a href="?tab=total_rough" class="text-decoration-none d-block h-100" onclick="showAssortSection('total_rough', ''); return false;" title="કુલ રફ ખરીદી લિસ્ટ જુઓ">
                <div id="kpi-card-total_rough" class="stat-card-finora assort-kpi-card <?= $activeTab === 'total_rough' ? 'kpi-tone-blue border-primary kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('shapes', '', 18) ?></div>
                        <span class="badge bg-primary-subtle text-primary border font-mono small"><?= $totalRoughCount ?> લૉટ્સ</span>
                    </div>
                    <div class="kpi-label">૧. કુલ રફ (Total Rough)</div>
                    <div class="kpi-value text-primary"><?= format_carat($totalRoughCarat) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>એસોર્ટ માટે આવેલ રફ</span>
                        <strong class="text-dark font-mono"><?= $totalRoughCount ?> લૉટ્સ (સિંગલ ગણતરી)</strong>
                    </div>
                </div>
            </a>
        </div>

        <!-- 2. Pending Assort (એસોર્ટ બાકી) -->
        <div class="col-12 col-md-4">
            <a href="?tab=pending" class="text-decoration-none d-block h-100" onclick="showAssortSection('pending', ''); return false;" title="એસોર્ટ બાકી રફ લિસ્ટ જુઓ">
                <div id="kpi-card-pending" class="stat-card-finora assort-kpi-card <?= $activeTab === 'pending' ? 'kpi-tone-amber border-warning kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-amber"><?= svg_icon('clock', '', 18) ?></div>
                        <span class="badge bg-warning-subtle text-warning-emphasis border font-mono small"><?= $totalPendingCount ?> બાકી</span>
                    </div>
                    <div class="kpi-label">૨. એસોર્ટ બાકી (Pending Assort)</div>
                    <div class="kpi-value text-warning-emphasis"><?= format_carat($totalPendingWeight) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>ભાગ પાડવાના બાકી લૉટ્સ</span>
                        <strong class="text-dark font-mono"><?= $pendingRoughCount ?> રફ<?= $pendingBoilCount > 0 ? ' + ' . $pendingBoilCount . ' Boil' : '' ?></strong>
                    </div>
                </div>
            </a>
        </div>

        <!-- 3. Assort Done (એસોર્ટ પૂર્ણ થયેલ) -->
        <div class="col-12 col-md-4">
            <a href="?tab=assorted&subtab=all_tasks" class="text-decoration-none d-block h-100" onclick="showAssortSection('assorted', 'all_tasks'); return false;" title="બધા એસોર્ટ ટાસ્ક લિસ્ટ જુઓ">
                <div id="kpi-card-all_tasks" class="stat-card-finora assort-kpi-card <?= ($activeTab === 'assorted' && $activeSubTab === 'all_tasks') ? 'kpi-tone-purple border-primary kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-purple"><?= svg_icon('layer-group', '', 18) ?></div>
                        <span class="badge bg-purple-subtle text-purple border font-mono small"><?= $assortDoneTasksCount ?> ટાસ્ક પૂર્ણ</span>
                    </div>
                    <div class="kpi-label">૩. એસોર્ટ પૂર્ણ (Assort Done)</div>
                    <div class="kpi-value text-indigo"><?= format_carat($assortDoneInputWeight) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>તૈયાર આઉટપુટ: <strong class="text-dark font-mono"><?= format_carat($assortDoneOutputWeight) ?></strong></span>
                        <span class="badge bg-light text-dark border font-mono"><?= $assortDonePartsCount ?> ભાગો</span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Row 2: Assorted Output Breakdown (4 Cards: MFG Tag, MFG Later, Sell Tag, Ghat) -->
    <div class="row g-2.5">
        <!-- 4. MFG Tag (કાપન બનશે / બનેલ) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="?tab=assorted&subtab=mfg" class="text-decoration-none d-block h-100" onclick="showAssortSection('assorted', 'mfg'); return false;" title="MFG Tag (કાપન) લિસ્ટ જુઓ">
                <div id="kpi-card-mfg" class="stat-card-finora assort-kpi-card <?= ($activeTab === 'assorted' && $activeSubTab === 'mfg') ? 'kpi-tone-blue border-primary kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('scissors', '', 18) ?></div>
                        <span class="badge bg-primary-subtle text-primary border font-mono small"><?= $mfgPartsCount ?> ભાગો</span>
                    </div>
                    <div class="kpi-label">૪. કાપન બનશે (MFG Tag)</div>
                    <div class="kpi-value text-primary"><?= format_carat($mfgPartsWeight) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>કાપન પ્રોસેસ ફાળવણી</span>
                        <strong class="text-dark font-mono"><?= $mfgInKapanCount ?> કાપન બનેલ</strong>
                    </div>
                </div>
            </a>
        </div>

        <!-- 5. MFG Later (પછી કાપન) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="?tab=assorted&subtab=mfg_later" class="text-decoration-none d-block h-100" onclick="showAssortSection('assorted', 'mfg_later'); return false;" title="MFG Later Tag લિસ્ટ જુઓ">
                <div id="kpi-card-mfg_later" class="stat-card-finora assort-kpi-card <?= ($activeTab === 'assorted' && $activeSubTab === 'mfg_later') ? 'bg-light border-secondary kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-blue"><?= svg_icon('clock-rotate-left', '', 18) ?></div>
                        <span class="badge bg-secondary-subtle text-secondary border font-mono small"><?= $mfgLaterPartsCount ?> ભાગો</span>
                    </div>
                    <div class="kpi-label">૫. પછી કાપન (MFG Later)</div>
                    <div class="kpi-value text-secondary"><?= format_carat($mfgLaterPartsWeight) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>હોલ્ડ / વિલંબિત મેકિંગ</span>
                        <span class="badge bg-light text-muted border font-mono">સાચવેલ રફ</span>
                    </div>
                </div>
            </a>
        </div>

        <!-- 6. Sell Tag (સીધું વેચાણ) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="?tab=assorted&subtab=sell" class="text-decoration-none d-block h-100" onclick="showAssortSection('assorted', 'sell'); return false;" title="Sell Tag (સીધું વેચાણ) લિસ્ટ જુઓ">
                <div id="kpi-card-sell" class="stat-card-finora assort-kpi-card <?= ($activeTab === 'assorted' && $activeSubTab === 'sell') ? 'kpi-tone-green border-success kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-green"><?= svg_icon('handshake', '', 18) ?></div>
                        <span class="badge bg-success-subtle text-success border font-mono small"><?= $sellPartsCount ?> ભાગો</span>
                    </div>
                    <div class="kpi-label">૬. સીધું વેચાણ (Sell Tag)</div>
                    <div class="kpi-value text-success"><?= format_carat($sellPartsWeight) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>Out Sell વેપાર લોટ્સ</span>
                        <strong class="text-success font-mono"><?= $sellPartsCount ?> લોટ્સ તૈયાર</strong>
                    </div>
                </div>
            </a>
        </div>

        <!-- 7. Ghat / તફાવત (Loss Diff) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="?tab=assorted&subtab=ghat" class="text-decoration-none d-block h-100" onclick="showAssortSection('assorted', 'ghat'); return false;" title="Ghat / તફાવત રિપોર્ટ જુઓ">
                <div id="kpi-card-ghat" class="stat-card-finora assort-kpi-card <?= ($activeTab === 'assorted' && $activeSubTab === 'ghat') ? 'kpi-tone-rose border-danger kpi-active-ring' : 'bg-white border' ?> h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="kpi-icon-tile kpi-icon-rose"><?= svg_icon('scale-unbalanced', '', 18) ?></div>
                        <span class="badge <?= $totGhatWeight > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?> border font-mono small"><?= $totGhatPct ?>% ઘટ</span>
                    </div>
                    <div class="kpi-label">૭. કુલ ઘટ (Loss / Ghat)</div>
                    <div class="kpi-value <?= $totGhatWeight > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= ($totGhatWeight > 0 ? '-' : '') . format_carat(abs($totGhatWeight)) ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
                        <span>ભાગ પાડ્યા પછીનો તફાવત</span>
                        <strong class="text-dark font-mono"><?= $assortCount ?> ટાસ્ક રીપોર્ટ</strong>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Boil Re-Assort Alert Strip -->
<?php if (!empty($pendingReassortLots)): ?>
<div class="erp-form-card mb-3 border border-warning">
    <div class="erp-card-header bg-warning-subtle py-2.5 px-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark">
            <?= svg_icon('fire-burner', 'text-warning-emphasis me-1') ?>Boil પરત આવેલ Re-Assort લોટ (Assort બાકી: <?= count($pendingReassortLots) ?>)
        </span>
        <span class="small text-muted">નવો લોટ નંબર અપાયો છે — નીચેથી ભાગ પાડી નવી કાપન બનાવો</span>
    </div>
    <div class="erp-card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">નવો લોટ નંબર (Lot No)</th>
                        <th>Boil બેચ</th>
                        <th>જૂની કાપન</th>
                        <th>પરત તારીખ</th>
                        <th>નંગ / વજન</th>
                        <th>લોટ રેટ (₹/Ct)</th>
                        <th class="text-end pe-3">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingReassortLots as $prl): ?>
                        <tr>
                            <td class="ps-3"><strong class="font-mono text-primary"><?= e($prl['lot_no']) ?></strong></td>
                            <td><span class="font-mono small"><?= e($prl['batch_no'] ?? '-') ?></span></td>
                            <td><span class="badge bg-light text-dark border font-mono"><?= e($prl['source_kapan_codes'] ?? '-') ?></span></td>
                            <td><?= format_date($prl['return_date']) ?></td>
                            <td class="font-mono"><strong><?= format_carat($prl['total_weight']) ?></strong> <small class="text-muted">(<?= (int)$prl['total_pcs'] ?> Pcs)</small></td>
                            <td class="font-mono">
                                <?php if ((float)($prl['lot_rate'] ?? 0) > 0): ?>
                                    <span class="badge bg-success-subtle text-success border">₹ <?= number_format((float)$prl['lot_rate'], 2) ?>/ct</span>
                                <?php else: ?>
                                    <span class="text-muted small">બાકી (Assort વખતે નાખો)</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <a href="<?= BASE_URL ?>/modules/assort/create.php?reassort_lot_id=<?= (int)$prl['id'] ?>" class="btn btn-sm btn-primary fw-bold">
                                    <?= svg_icon('layer-group', 'me-1') ?>Assort (ભાગ) પાડો &rarr;
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

<!-- TAB CONTENT AREA -->
<div class="tab-content" id="assortMainTabsContent">

    <!-- ───────────────────────────────────────────────────────────── -->
    <!-- TAB 1: TOTAL ROUGH (કુલ રફ ખરીદી સ્ટોક)                       -->
    <!-- ───────────────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab === 'total_rough' ? 'show active' : '' ?>" id="tab-total-rough" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <?= svg_icon('gem', 'text-primary') ?>
                    <strong class="text-dark">કુલ રફ ખરીદી રજિસ્ટર (Total Rough Inventory)</strong>
                    <span class="badge bg-light text-secondary border font-mono"><?= $totalRoughCount ?> Purchases (Active)</span>
                </div>
                <div class="d-flex align-items-center gap-3 small">
                    <span class="text-muted">કુલ રફ: <strong class="text-dark font-mono"><?= format_carat($totalRoughCarat) ?></strong></span>
                    <span class="text-muted">Assort થયેલ: <strong class="text-success font-mono"><?= format_carat($activeRoughUsedWeight) ?></strong></span>
                    <span class="text-muted">બાકી સ્ટોક: <strong class="text-primary font-mono"><?= format_carat($activeRoughAvailWeight) ?></strong></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>ખરીદી નં / તારીખ</th>
                                <th>પાર્ટી & દલાલ</th>
                                <th>કુલ મૂળ રફ</th>
                                <th>વપરાયેલ / Assort</th>
                                <th>બાકી સ્ટોક</th>
                                <th>ખરીદી ભાવ (₹/Ct)</th>
                                <th>સ્થિતિ</th>
                                <th class="text-end">એક્શન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roughPurchases)): ?>
                                <tr><td colspan="8" class="text-center py-5 text-muted">કોઈ રફ ખરીદી મળી નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($roughPurchases as $rp): ?>
                                    <?php 
                                        $isMerged = ($rp['status'] ?? '') === 'merged';
                                        $availWt = (float)($rp['available_weight'] ?? 0);
                                        $origWt = (float)($rp['weight'] ?? 0);
                                        $usedWt = $isMerged ? 0.0 : (float)($rp['used_weight'] ?? 0);
                                        $rateInr = (float)($rp['price_per_carat_inr'] ?? 0);
                                    ?>
                                    <tr class="<?= $isMerged ? 'bg-light text-muted opacity-75' : '' ?>">
                                        <td>
                                            <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $rp['id'] ?>" class="fw-bold font-mono <?= $isMerged ? 'text-muted' : 'text-primary' ?> text-decoration-none">
                                                <?= e($rp['purchase_no']) ?>
                                            </a>
                                            <div class="small text-muted font-mono"><?= svg_icon('calendar', 'me-1', 11) ?><?= format_date($rp['purchase_date']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= e($rp['party_name'] ?? '-') ?></div>
                                            <?php if (!empty($rp['broker_name'])): ?>
                                                <small class="text-muted"><?= svg_icon('handshake', 'me-1', 11) ?><?= e($rp['broker_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isMerged): ?>
                                                <div class="fw-bold font-mono text-muted text-decoration-line-through"><?= format_carat($origWt) ?></div>
                                                <small class="text-muted font-mono"><?= (int)$rp['pcs'] ?> Pcs (મર્જ)</small>
                                            <?php else: ?>
                                                <div class="fw-bold font-mono text-dark"><?= format_carat($origWt) ?></div>
                                                <small class="text-muted font-mono"><?= (int)$rp['pcs'] ?> Pcs</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isMerged): ?>
                                                <span class="badge bg-secondary-subtle text-muted font-mono">0.000 ct (મર્જ)</span>
                                            <?php else: ?>
                                                <div class="fw-semibold font-mono text-secondary"><?= format_carat($usedWt) ?></div>
                                                <small class="text-muted font-mono"><?= (int)$rp['used_pcs'] ?> Pcs</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isMerged): ?>
                                                <span class="badge bg-light text-muted border font-mono">0.000 ct</span>
                                            <?php elseif ($availWt > 0.001): ?>
                                                <div class="fw-bold font-mono text-success"><?= format_carat($availWt) ?></div>
                                                <small class="text-success font-mono"><?= (int)$rp['available_pcs'] ?> Pcs બાકી</small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary font-mono">0.000 ct (સંપૂર્ણ વિભાજિત)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold font-mono text-dark"><?= format_inr($rateInr) ?>/ct</div>
                                            <small class="text-muted font-mono">કુલ: <?= format_inr((float)($rp['payment_amount'] ?? 0)) ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                                $st = $rp['status'] ?? 'in_stock';
                                                $stMap = [
                                                    'in_stock'  => ['bg-success-subtle text-success', 'સ્ટોકમાં'],
                                                    'in_assort' => ['bg-warning-subtle text-warning-emphasis', 'Assort ચાલુ'],
                                                    'assorted'  => ['bg-info-subtle text-info', 'સંપૂર્ણ Assort'],
                                                    'sold'      => ['bg-secondary-subtle text-secondary', 'વેચાઈ ગયેલ'],
                                                    'merged'    => ['bg-light text-muted', 'મર્જ થયેલ'],
                                                ];
                                                $badgeInfo = $stMap[$st] ?? ['bg-light text-dark', $st];
                                            ?>
                                            <span class="badge <?= $badgeInfo[0] ?> border"><?= $badgeInfo[1] ?></span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1.5 align-items-center">
                                                <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $rp['id'] ?>" class="btn btn-sm btn-outline-secondary" title="ખરીદી વિગત જુઓ">
                                                    <?= svg_icon('eye', 'me-1') ?>જુઓ
                                                </a>
                                                <?php if ($availWt > 0.001): ?>
                                                    <a href="<?= BASE_URL ?>/modules/assort/create.php?rough_purchase_id=<?= $rp['id'] ?>" class="btn btn-sm btn-primary fw-bold" title="આ રફ લોટનું Assort કરો">
                                                        <?= svg_icon('layer-group', 'me-1') ?>Assort પાડો
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
            </div>
        </div>
    </div>

    <!-- ───────────────────────────────────────────────────────────── -->
    <!-- TAB 2: ASSORT THAY GAY (Assort થઈ ગઈ) with SUB-TABS          -->
    <!-- ───────────────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab === 'assorted' ? 'show active' : '' ?>" id="tab-assorted" role="tabpanel">

        <!-- SUB-TAB CONTENT -->
        <div class="tab-content" id="assortedSubTabsContent">

            <!-- 1. SUB-TAB: MFG TAG -->
            <div class="tab-pane fade <?= $activeSubTab === 'mfg' ? 'show active' : '' ?>" id="subtab-mfg" role="tabpanel">
                <div class="card-erp">
                    <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <?= svg_icon('gear', 'text-primary') ?>
                            <strong class="text-dark">MFG Tag લિસ્ટ (કાપન બનાવવા માટેના ભાગો)</strong>
                            <span class="badge bg-primary-subtle text-primary border font-mono"><?= count($mfgParts) ?> ભાગો</span>
                        </div>
                        <div class="small text-muted">
                            જે ભાગની કાપન બનાવવાની બાકી હોય તેની સામે <strong>[+ Kapan બનાવો]</strong> બટન દબાવો
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Assort No (હાયરાર્કી)</th>
                                        <th>ભાગનું નામ</th>
                                        <th>રફ સંદર્ભ / પાર્ટી</th>
                                        <th>તારીખ</th>
                                        <th class="text-center">નંગ (Pcs)</th>
                                        <th class="text-end">વજન (Weight Ct)</th>
                                        <th class="text-end">અંદાજિત ભાવ (₹/Ct)</th>
                                        <th class="text-end">અંદાજિત રકમ (Total ₹)</th>
                                        <th class="text-center">સ્થિતિ</th>
                                        <th class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($mfgParts)): ?>
                                        <tr><td colspan="10" class="text-center py-5 text-muted">હાલ કોઈ MFG Tag ભાગ ઉપલબ્ધ નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($mfgParts as $p): ?>
                                            <?php 
                                                $wt = (float)($p['weight'] ?? 0);
                                                $rate = (float)($p['cost_per_carat'] ?? 0);
                                                $totalAmt = round($wt * $rate, 2);
                                                $isAvailable = ($p['status'] === 'available');
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="font-mono fw-bold text-primary"><?= e($p['assort_number']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= e($p['part_name']) ?></div>
                                                    <span class="badge bg-primary-subtle text-primary border font-mono" style="font-size: 0.72rem;">MFG (પ્રક્રિયા)</span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark font-mono"><?= e($p['purchase_no'] ?: ($p['reassort_lot_no'] ?: '-')) ?></div>
                                                    <small class="text-muted"><?= e($p['party_name'] ?? '-') ?></small>
                                                </td>
                                                <td class="font-mono small text-muted">
                                                    <?= format_date($p['assort_date']) ?>
                                                </td>
                                                <td class="text-center font-mono fw-bold text-dark">
                                                    <?= (int)$p['pcs'] ?>
                                                </td>
                                                <td class="text-end font-mono fw-bold text-primary">
                                                    <?= format_carat($wt) ?>
                                                </td>
                                                <td class="text-end font-mono text-dark">
                                                    <?= format_inr($rate) ?>
                                                </td>
                                                <td class="text-end font-mono fw-semibold text-dark">
                                                    <?= format_inr($totalAmt) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($isAvailable): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border">કાપન બાકી</span>
                                                    <?php elseif ($p['status'] === 'in_kapan'): ?>
                                                        <span class="badge bg-success-subtle text-success border">કાપન બનેલ</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark border"><?= e($p['status']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1.5 align-items-center">
                                                        <?php if ($isAvailable): ?>
                                                            <a href="<?= BASE_URL ?>/modules/kapan/create.php?part_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary fw-bold">
                                                                <?= svg_icon('plus', 'me-1') ?>Kapan બનાવો
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?>/modules/kapan/index.php" class="btn btn-sm btn-outline-secondary">
                                                                <?= svg_icon('gem', 'me-1') ?>કાપન જુઓ
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$p['assort_task_id'] ?>" class="btn btn-sm btn-light border" title="Assort ટાસ્ક જુઓ">
                                                            <?= svg_icon('eye') ?>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. SUB-TAB: MFG LATER TAG -->
            <div class="tab-pane fade <?= $activeSubTab === 'mfg_later' ? 'show active' : '' ?>" id="subtab-mfg-later" role="tabpanel">
                <div class="card-erp">
                    <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <?= svg_icon('clock-rotate-left', 'text-info') ?>
                            <strong class="text-dark">MFG Later Tag લિસ્ટ (ઓછા નંબર / પછી કાપન બનાવવાના ભાગો)</strong>
                            <span class="badge bg-secondary-subtle text-secondary border font-mono"><?= count($mfgLaterParts) ?> ભાગો</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" id="btnMergeMfgLater" class="btn btn-sm btn-info text-white fw-bold shadow-xs d-none" onclick="openMergeModal('mfg_later')">
                                <?= svg_icon('code-branch', 'me-1') ?>🔗 પસંદ કરેલા MFG Later મર્જ કરો (<span class="merge-count">0</span>)
                            </button>
                            <span class="small text-muted d-none d-md-inline">
                                મેન્યુઅલ ક્લીવિંગ / સૉઇંગ કે પછી MFG કરવા માટે અલગ રાખેલ લોટ
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 40px;">
                                            <input type="checkbox" class="form-check-input" id="selectAllMfgLater" onchange="toggleSelectAll('mfg_later', this.checked)" title="બધા પસંદ કરો">
                                        </th>
                                        <th>Assort No (હાયરાર્કી)</th>
                                        <th>ભાગનું નામ</th>
                                        <th>રફ સંદર્ભ / પાર્ટી</th>
                                        <th>તારીખ</th>
                                        <th class="text-center">નંગ (Pcs)</th>
                                        <th class="text-end">વજન (Weight Ct)</th>
                                        <th class="text-end">અંદાજિત ભાવ (₹/Ct)</th>
                                        <th class="text-end">અંદાજિત રકમ (Total ₹)</th>
                                        <th class="text-center">સ્થિતિ</th>
                                        <th class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($mfgLaterParts)): ?>
                                        <tr><td colspan="11" class="text-center py-5 text-muted">હાલ કોઈ MFG Later Tag ભાગ ઉપલબ્ધ નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($mfgLaterParts as $p): ?>
                                            <?php 
                                                 $wt = (float)($p['weight'] ?? 0);
                                                 $rate = (float)($p['cost_per_carat'] ?? 0);
                                                 $totalAmt = round($wt * $rate, 2);
                                                 $isAvailable = ($p['status'] === 'available');
                                                 $lotRef = $p['purchase_no'] ?: ($p['reassort_lot_no'] ?: ($p['rough_number'] ?: '-'));
                                            ?>
                                            <tr>
                                                <td class="text-center">
                                                    <?php if ($isAvailable): ?>
                                                        <input type="checkbox" class="form-check-input part-merge-cb part-merge-mfg_later" 
                                                               value="<?= (int)$p['id'] ?>"
                                                               data-id="<?= (int)$p['id'] ?>"
                                                               data-tag="mfg_later"
                                                               data-assort-no="<?= e($p['assort_number']) ?>"
                                                               data-part-name="<?= e($p['part_name']) ?>"
                                                               data-lot="<?= e($lotRef) ?>"
                                                               data-pcs="<?= (int)$p['pcs'] ?>"
                                                               data-weight="<?= $wt ?>"
                                                               data-rate="<?= $rate ?>"
                                                               data-amount="<?= $totalAmt ?>"
                                                               onchange="onMergeCheckboxChange('mfg_later')">
                                                    <?php else: ?>
                                                        <input type="checkbox" class="form-check-input" disabled>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="font-mono fw-bold text-info"><?= e($p['assort_number']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= e($p['part_name']) ?></div>
                                                    <span class="badge bg-secondary-subtle text-secondary border font-mono" style="font-size: 0.72rem;">MFG Later</span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark font-mono"><?= e($lotRef) ?></div>
                                                    <small class="text-muted"><?= e($p['party_name'] ?? '-') ?></small>
                                                </td>
                                                <td class="font-mono small text-muted">
                                                    <?= !empty($p['assort_date']) ? format_date($p['assort_date']) : (!empty($p['created_at']) ? format_date($p['created_at']) : '-') ?>
                                                </td>
                                                <td class="text-center font-mono fw-bold text-dark">
                                                    <?= (int)$p['pcs'] ?>
                                                </td>
                                                <td class="text-end font-mono fw-bold text-info">
                                                    <?= format_carat($wt) ?>
                                                </td>
                                                <td class="text-end font-mono text-dark">
                                                    <?= format_inr($rate) ?>
                                                </td>
                                                <td class="text-end font-mono fw-semibold text-dark">
                                                    <?= format_inr($totalAmt) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($isAvailable): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border">કાપન બાકી</span>
                                                    <?php elseif ($p['status'] === 'in_kapan'): ?>
                                                        <span class="badge bg-success-subtle text-success border">કાપન બનેલ</span>
                                                    <?php elseif ($p['status'] === 'merged'): ?>
                                                        <span class="badge bg-info-subtle text-info border">🔗 મર્જ થયેલ (Merged)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark border"><?= e($p['status']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1.5 align-items-center">
                                                        <?php if ($isAvailable): ?>
                                                            <a href="<?= BASE_URL ?>/modules/kapan/create.php?part_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary fw-bold">
                                                                <?= svg_icon('plus', 'me-1') ?>Kapan બનાવો
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?>/modules/kapan/index.php" class="btn btn-sm btn-outline-secondary">
                                                                <?= svg_icon('gem', 'me-1') ?>કાપન જુઓ
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!empty($p['assort_task_id'])): ?>
                                                            <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$p['assort_task_id'] ?>" class="btn btn-sm btn-light border" title="Assort ટાસ્ક જુઓ">
                                                                <?= svg_icon('eye') ?>
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
                    </div>
                </div>
            </div>

            <!-- 3. SUB-TAB: SELL TAG -->
            <div class="tab-pane fade <?= $activeSubTab === 'sell' ? 'show active' : '' ?>" id="subtab-sell" role="tabpanel">
                <div class="card-erp">
                    <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <?= svg_icon('handshake', 'text-success') ?>
                            <strong class="text-dark">Sell Tag લિસ્ટ (Assort બાદ સીધા વેચાણ માટેના ભાગો)</strong>
                            <span class="badge bg-success-subtle text-success border font-mono"><?= count($sellParts) ?> ભાગો</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" id="btnMergeSell" class="btn btn-sm btn-success fw-bold shadow-xs d-none" onclick="openMergeModal('sell')">
                                <?= svg_icon('code-branch', 'me-1') ?>🔗 પસંદ કરેલા Sell ભાગો મર્જ કરો (<span class="merge-count">0</span>)
                            </button>
                            <span class="small text-muted d-none d-md-inline">
                                વેચવા યોગ્ય રફ ભાગો — સીધા વેચવા અથવા મર્જ કરીને નવું પેકેટ બનાવવા માટે
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 40px;">
                                            <input type="checkbox" class="form-check-input" id="selectAllSell" onchange="toggleSelectAll('sell', this.checked)" title="બધા પસંદ કરો">
                                        </th>
                                        <th>Assort No (હાયરાર્કી)</th>
                                        <th>ભાગનું નામ</th>
                                        <th>રફ સંદર્ભ / પાર્ટી</th>
                                        <th>તારીખ</th>
                                        <th class="text-center">નંગ (Pcs)</th>
                                        <th class="text-end">વજન (Weight Ct)</th>
                                        <th class="text-end">અંદાજિત ભાવ (₹/Ct)</th>
                                        <th class="text-end">અંદાજિત રકમ (Total ₹)</th>
                                        <th class="text-center">સ્થિતિ</th>
                                        <th class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($sellParts)): ?>
                                        <tr><td colspan="11" class="text-center py-5 text-muted">હાલ કોઈ Sell Tag ભાગ ઉપલબ્ધ નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($sellParts as $p): ?>
                                            <?php 
                                                 $wt = (float)($p['weight'] ?? 0);
                                                 $rate = (float)($p['cost_per_carat'] ?? 0);
                                                 $totalAmt = round($wt * $rate, 2);
                                                 $isAvailable = ($p['status'] === 'available');
                                                 $lotRef = $p['purchase_no'] ?: ($p['reassort_lot_no'] ?: ($p['rough_number'] ?: '-'));
                                            ?>
                                            <tr>
                                                <td class="text-center">
                                                    <?php if ($isAvailable): ?>
                                                        <input type="checkbox" class="form-check-input part-merge-cb part-merge-sell" 
                                                               value="<?= (int)$p['id'] ?>"
                                                               data-id="<?= (int)$p['id'] ?>"
                                                               data-tag="sell"
                                                               data-assort-no="<?= e($p['assort_number']) ?>"
                                                               data-part-name="<?= e($p['part_name']) ?>"
                                                               data-lot="<?= e($lotRef) ?>"
                                                               data-pcs="<?= (int)$p['pcs'] ?>"
                                                               data-weight="<?= $wt ?>"
                                                               data-rate="<?= $rate ?>"
                                                               data-amount="<?= $totalAmt ?>"
                                                               onchange="onMergeCheckboxChange('sell')">
                                                    <?php else: ?>
                                                        <input type="checkbox" class="form-check-input" disabled>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="font-mono fw-bold text-success"><?= e($p['assort_number']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= e($p['part_name']) ?></div>
                                                    <span class="badge bg-success-subtle text-success border font-mono" style="font-size: 0.72rem;">Out Sell</span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark font-mono"><?= e($lotRef) ?></div>
                                                    <small class="text-muted"><?= e($p['party_name'] ?? '-') ?></small>
                                                </td>
                                                <td class="font-mono small text-muted">
                                                    <?= !empty($p['assort_date']) ? format_date($p['assort_date']) : (!empty($p['created_at']) ? format_date($p['created_at']) : '-') ?>
                                                </td>
                                                <td class="text-center font-mono fw-bold text-dark">
                                                    <?= (int)$p['pcs'] ?>
                                                </td>
                                                <td class="text-end font-mono fw-bold text-success">
                                                    <?= format_carat($wt) ?>
                                                </td>
                                                <td class="text-end font-mono text-dark">
                                                    <?= format_inr($rate) ?>
                                                </td>
                                                <td class="text-end font-mono fw-semibold text-dark">
                                                    <?= format_inr($totalAmt) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($isAvailable): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border">વેચાણ બાકી (Unsold)</span>
                                                    <?php elseif ($p['status'] === 'sold'): ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border">વેચાઈ ગયેલ (Sold)</span>
                                                    <?php elseif ($p['status'] === 'merged'): ?>
                                                        <span class="badge bg-info-subtle text-info border">🔗 મર્જ થયેલ (Merged)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark border"><?= e($p['status']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1.5 align-items-center">
                                                        <?php if ($isAvailable): ?>
                                                            <a href="<?= BASE_URL ?>/modules/rough_sales/create.php?part_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-success fw-bold">
                                                                <?= svg_icon('cart-shopping', 'me-1') ?>વેચાણ કરો (Sell)
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?>/modules/rough_sales/index.php" class="btn btn-sm btn-outline-secondary">
                                                                વેચાણ લિસ્ટ જુઓ
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!empty($p['assort_task_id'])): ?>
                                                            <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$p['assort_task_id'] ?>" class="btn btn-sm btn-light border" title="Assort ટાસ્ક જુઓ">
                                                                <?= svg_icon('eye') ?>
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
                    </div>
                </div>
            </div>

            <!-- 4. SUB-TAB: GHAT / WEIGHT DIFFERENCE -->
            <div class="tab-pane fade <?= $activeSubTab === 'ghat' ? 'show active' : '' ?>" id="subtab-ghat" role="tabpanel">
                <div class="card-erp">
                    <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <?= svg_icon('scale-unbalanced', 'text-danger') ?>
                            <strong class="text-dark">ઘટ / તફાવત રિપોર્ટ (Rough Lot Weight Difference & Loss Report)</strong>
                            <span class="badge bg-danger-subtle text-danger border font-mono"><?= count($tasks) ?> રેકોર્ડ</span>
                        </div>
                        <div class="small text-muted">
                            કયા રફ લોટમાં કેટલું વજન હતું અને Assort બાદ કેટલું થયું તેનો ચોક્કસ તફાવત
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>ટાસ્ક નં / તારીખ</th>
                                        <th>રફ ખરીદી સંદર્ભ & પાર્ટી</th>
                                        <th>મૂળ રફ ઇનપુટ</th>
                                        <th>Assort વિભાજિત આઉટપુટ</th>
                                        <th>ઘટ રફ વજન (Loss)</th>
                                        <th>ઘટ ટકાવારી (%)</th>
                                        <th>નંગ તફાવત (Pcs Diff)</th>
                                        <th>ચકાસણી (Status)</th>
                                        <th class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tasks)): ?>
                                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ Assort ટાસ્ક મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($tasks as $t): ?>
                                            <?php 
                                                $inWt = (float)($t['total_input_weight'] ?? 0);
                                                $outWt = (float)($t['total_output_weight'] ?? 0);
                                                $diffWt = (float)($t['weight_difference'] ?? ($inWt - $outWt));
                                                $inPcs = (int)($t['total_input_pcs'] ?? 0);
                                                $outPcs = (int)($t['total_output_pcs'] ?? 0);
                                                $diffPcs = (int)($t['pcs_difference'] ?? ($inPcs - $outPcs));
                                                $lossPct = ($inWt > 0) ? round(($diffWt / $inWt) * 100, 2) : 0.00;
                                                $isMatch = abs($diffWt) <= 0.005;
                                                $isPassed = ($isMatch || ($diffWt > 0 && $lossPct <= 2.0));
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold font-mono text-dark"><?= e($t['task_no']) ?></div>
                                                    <small class="text-muted font-mono"><?= svg_icon('calendar', 'me-1', 11) ?><?= format_date($t['assort_date']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if (($t['source_type'] ?? 'rough_purchase') === 'boil_reassort'): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border font-mono">
                                                            <?= svg_icon('fire-burner', 'me-1', 11) ?>Boil: <?= e($t['lot_no'] ?: $t['reassort_lot_no']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $t['rough_purchase_id'] ?>" class="text-decoration-none fw-semibold font-mono">
                                                            <?= e($t['purchase_no'] ?? '-') ?>
                                                        </a>
                                                        <div class="small text-muted"><?= e($t['party_name'] ?? '-') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold font-mono text-dark"><?= format_carat($inWt) ?></div>
                                                    <small class="text-muted font-mono"><?= $inPcs ?> Pcs</small>
                                                </td>
                                                <td>
                                                    <div class="fw-bold font-mono text-success"><?= format_carat($outWt) ?></div>
                                                    <small class="text-muted font-mono"><?= $outPcs ?> Pcs</small>
                                                </td>
                                                <td>
                                                    <?php if ($isMatch): ?>
                                                        <span class="badge bg-success-subtle text-success border font-mono">0.000 ct (સંપૂર્ણ મેળ)</span>
                                                    <?php elseif ($diffWt > 0): ?>
                                                        <span class="badge bg-danger-subtle text-danger border font-mono">-<?= format_carat($diffWt) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger text-white border font-mono">+<?= format_carat(abs($diffWt)) ?> (વધારો)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isMatch): ?>
                                                        <span class="badge bg-success-subtle text-success border font-mono">0.00%</span>
                                                    <?php elseif ($lossPct > 0 && $lossPct <= 2.0): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border font-mono"><?= number_format($lossPct, 2) ?>% (માન્યા)</span>
                                                    <?php elseif ($lossPct > 2.0): ?>
                                                        <span class="badge bg-danger text-white border font-mono"><?= number_format($lossPct, 2) ?>% (> 2% લિમિટ)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger text-white border font-mono"><?= number_format($lossPct, 2) ?>%</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($diffPcs === 0): ?>
                                                        <span class="badge bg-success-subtle text-success border font-mono">0 Pcs (મેળ)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border font-mono"><?= ($diffPcs > 0 ? "-$diffPcs" : "+".abs($diffPcs)) ?> Pcs તફાવત</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isPassed): ?>
                                                        <span class="badge bg-success text-white border font-mono">✓ PASS</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger text-white border font-mono">⚠ FAILED</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="વિગત જુઓ">
                                                            <?= svg_icon('eye', 'me-1') ?>જુઓ
                                                        </a>
                                                        <a href="<?= BASE_URL ?>/modules/assort/edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Assort એડિટ કરો">
                                                            <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. SUB-TAB: ALL ASSORT TASKS -->
            <div class="tab-pane fade <?= $activeSubTab === 'all_tasks' ? 'show active' : '' ?>" id="subtab-all-tasks" role="tabpanel">
                <div class="card-erp">
                    <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <?= svg_icon('list-check', 'text-dark') ?>
                            <strong class="text-dark">બધા Assort ટાસ્ક (All Tasks History)</strong>
                            <span class="badge bg-secondary-subtle text-secondary border font-mono"><?= count($tasks) ?> Tasks</span>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/assort/create.php" class="btn btn-sm btn-primary">
                            <?= svg_icon('plus', 'me-1') ?>નવો ટાસ્ક
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>ટાસ્ક નં / તારીખ</th>
                                        <th>Rough ખરીદી સંદર્ભ</th>
                                        <th>ઇનપુટ (Pcs & વજન)</th>
                                        <th>આઉટપુટ (Pcs & વજન)</th>
                                        <th>તફાવત (Diff)</th>
                                        <th>ભાગની સંખ્યા</th>
                                        <th>સ્થિતિ</th>
                                        <th class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tasks)): ?>
                                        <tr><td colspan="8" class="text-center py-5 text-muted">કોઈ Assort ટાસ્ક મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($tasks as $t): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark font-mono"><?= e($t['task_no']) ?></div>
                                                    <small class="text-muted font-mono"><?= svg_icon('calendar', 'me-1', 11) ?><?= format_date($t['assort_date']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if (($t['source_type'] ?? 'rough_purchase') === 'boil_reassort'): ?>
                                                        <?php $effLotRate = (float)(($t['lot_rate'] ?? 0) > 0 ? $t['lot_rate'] : ($t['reassort_lot_rate'] ?? 0)); ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border font-mono">
                                                            <?= svg_icon('fire-burner', 'me-1', 11) ?>Boil Re-Assort: <?= e($t['lot_no'] ?: $t['reassort_lot_no']) ?>
                                                        </span>
                                                        <div class="small text-muted font-mono">
                                                            Boil: <?= e($t['boil_batch_no'] ?? '-') ?> • જૂની કાપન: <?= e($t['source_kapan_codes'] ?? '-') ?>
                                                        </div>
                                                        <div class="mt-1">
                                                            <?php if ($effLotRate > 0): ?>
                                                                <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $t['id'] ?>#lotRateCard" class="badge bg-success-subtle text-success border text-decoration-none font-mono" title="લોટ રેટ બદલવા ક્લિક કરો">
                                                                    રેટ: ₹ <?= number_format($effLotRate, 2) ?>/ct
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $t['id'] ?>#lotRateCard" class="badge bg-warning text-dark border text-decoration-none fw-bold">
                                                                    <?= svg_icon('indian-rupee-sign', 'me-1', 11) ?>લોટ રેટ નાખો (Set Rate)
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $t['rough_purchase_id'] ?>" class="text-decoration-none fw-semibold font-mono">
                                                            <?= e($t['purchase_no'] ?? '-') ?>
                                                        </a>
                                                        <div class="small text-muted"><?= e($t['party_name'] ?? '-') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark font-mono"><?= (int)$t['total_input_pcs'] ?> Pcs</div>
                                                    <small class="text-primary font-mono fw-semibold"><?= format_carat($t['total_input_weight']) ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark font-mono"><?= (int)$t['total_output_pcs'] ?> Pcs</div>
                                                    <small class="text-success font-mono fw-semibold"><?= format_carat($t['total_output_weight']) ?></small>
                                                </td>
                                                <td>
                                                    <?php if (abs((float)$t['weight_difference']) <= 0.005): ?>
                                                        <span class="badge bg-success-subtle text-success border font-mono">0.000 ct (સંપૂર્ણ મેળ)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border font-mono"><?= format_carat($t['weight_difference']) ?></span>
                                                    <?php endif; ?>
                                                    <?php 
                                                        $pcsDiff = (int)($t['pcs_difference'] ?? ((int)$t['total_input_pcs'] - (int)$t['total_output_pcs']));
                                                    ?>
                                                    <div class="mt-1">
                                                        <?php if ($pcsDiff === 0): ?>
                                                            <span class="badge bg-success-subtle text-success border font-mono">0 Pcs (મેળ)</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger-subtle text-danger border font-mono"><?= ($pcsDiff > 0 ? "+$pcsDiff" : $pcsDiff) ?> Pcs તફાવત</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border font-mono"><?= (int)$t['total_parts'] ?> ભાગ (Parts)</span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success border">પૂર્ણ (Completed)</span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="વિગત જુઓ">
                                                            <?= svg_icon('eye', 'me-1') ?>જુઓ
                                                        </a>
                                                        <a href="<?= BASE_URL ?>/modules/assort/edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Assort એડિટ કરો">
                                                            <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /assortedSubTabsContent -->

    </div><!-- /tab-assorted -->

    <!-- ───────────────────────────────────────────────────────────── -->
    <!-- TAB 3: ASSORT BAKI (Assort બાકી - Pending Assort)             -->
    <!-- ───────────────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab === 'pending' ? 'show active' : '' ?>" id="tab-pending" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <?= svg_icon('clock', 'text-warning-emphasis') ?>
                    <strong class="text-dark">Assort બાકી લોટ રજિસ્ટર (Pending Assortment Lots)</strong>
                    <span class="badge bg-warning-subtle text-warning-emphasis border font-mono"><?= $totalPendingCount ?> બાકી</span>
                </div>
                <div class="small text-muted">
                    રફ ખરીદી જેનું વિભાજન બાકી છે અથવા અધૂરું છે, તથા Boil Re-Assort પેન્ડિંગ લોટ
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>લોટ / ખરીદી નં</th>
                                <th>પ્રકાર (Source Type)</th>
                                <th>પાર્ટી / બેચ સંદર્ભ</th>
                                <th>તારીખ</th>
                                <th>મૂળ વજન</th>
                                <th>Assort બાકી વજન</th>
                                <th>ખરીદી ભાવ (₹/Ct)</th>
                                <th class="text-end">એક્શન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($totalPendingCount === 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-success mb-2"><?= svg_icon('circle-check', '', 36) ?></div>
                                        <div class="fw-bold text-dark">બધા રફ લોટનું Assortment પૂર્ણ થઈ ગયેલ છે!</div>
                                        <small class="text-muted">હાલ કોઈ પેન્ડિંગ રફ ખરીદી કે Boil Re-Assort લોટ બાકી નથી.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <!-- 1. Boil Re-Assort Pending Lots -->
                                <?php foreach ($pendingReassortLots as $prl): ?>
                                    <tr class="table-warning-subtle">
                                        <td>
                                            <strong class="font-mono text-dark"><?= e($prl['lot_no']) ?></strong>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border ms-1 font-mono">BOIL</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">Boil Re-Assort</span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark font-mono">Boil બેચ: <?= e($prl['batch_no'] ?? '-') ?></div>
                                            <small class="text-muted">જૂની કાપન: <?= e($prl['source_kapan_codes'] ?? '-') ?></small>
                                        </td>
                                        <td class="font-mono small text-muted">
                                            <?= format_date($prl['return_date']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold font-mono text-dark"><?= format_carat($prl['total_weight']) ?></div>
                                            <small class="text-muted font-mono"><?= (int)$prl['total_pcs'] ?> Pcs</small>
                                        </td>
                                        <td>
                                            <div class="fw-bold font-mono text-warning-emphasis"><?= format_carat($prl['total_weight']) ?></div>
                                            <small class="text-warning-emphasis font-mono"><?= (int)$prl['total_pcs'] ?> Pcs બાકી</small>
                                        </td>
                                        <td class="font-mono">
                                            <?php if ((float)($prl['lot_rate'] ?? 0) > 0): ?>
                                                <span class="fw-semibold text-dark">₹ <?= number_format((float)$prl['lot_rate'], 2) ?>/ct</span>
                                            <?php else: ?>
                                                <span class="text-muted small">Assort વખતે નાખો</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/modules/assort/create.php?reassort_lot_id=<?= (int)$prl['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-xs">
                                                <?= svg_icon('layer-group', 'me-1') ?>Assort (ભાગ) પાડો &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- 2. Pending Rough Purchases -->
                                <?php foreach ($pendingRoughPurchases as $prp): ?>
                                    <?php 
                                        $availWt = (float)($prp['available_weight'] ?? 0);
                                        $origWt = (float)($prp['weight'] ?? 0);
                                        $rateInr = (float)($prp['price_per_carat_inr'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $prp['id'] ?>" class="fw-bold font-mono text-primary text-decoration-none">
                                                <?= e($prp['purchase_no']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border">રફ ખરીદી (Rough)</span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= e($prp['party_name'] ?? '-') ?></div>
                                            <?php if (!empty($prp['broker_name'])): ?>
                                                <small class="text-muted"><?= svg_icon('handshake', 'me-1', 11) ?><?= e($prp['broker_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-mono small text-muted">
                                            <?= format_date($prp['purchase_date']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold font-mono text-dark"><?= format_carat($origWt) ?></div>
                                            <small class="text-muted font-mono"><?= (int)$prp['pcs'] ?> Pcs</small>
                                        </td>
                                        <td>
                                            <div class="fw-bold font-mono text-primary"><?= format_carat($availWt) ?></div>
                                            <small class="text-primary font-mono"><?= (int)$prp['available_pcs'] ?> Pcs બાકી</small>
                                        </td>
                                        <td class="font-mono">
                                            <div class="fw-semibold text-dark"><?= format_inr($rateInr) ?>/ct</div>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/modules/assort/create.php?rough_purchase_id=<?= (int)$prp['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-xs">
                                                <?= svg_icon('layer-group', 'me-1') ?>Assort (ભાગ) પાડો &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div><!-- /assortMainTabsContent -->

<!-- ───────────────────────────────────────────────────────────── -->
<!-- MODAL: MERGE SAME-TAG ROUGH PARTS                             -->
<!-- ───────────────────────────────────────────────────────────── -->
<div class="modal fade" id="mergePartsModal" tabindex="-1" aria-labelledby="mergePartsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= BASE_URL ?>/modules/assort/merge_parts.php" method="POST" id="mergePartsForm">
                <?= csrf_field() ?>
                <input type="hidden" name="tag_type" id="mergeTagType" value="">
                <div id="mergeHiddenIdsContainer"></div>

                <div class="modal-header bg-dark text-white py-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fs-5">🔗</span>
                        <div>
                            <h5 class="modal-title fw-bold mb-0" id="mergePartsModalLabel">રફ ભાગો મર્જ કરો (Merge Same Tag Rough)</h5>
                            <small class="text-white-50" id="mergeModalSubtitle">એક જ ટેગ વાળા પસંદ કરેલા ભાગોને જોડીને નવો પેકેટ બનાવો</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3.5">
                    <!-- Selected Summary KPI Strip -->
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-sm-3">
                            <div class="p-2.5 rounded-3 bg-light border text-center">
                                <div class="text-muted small">પસંદ કરેલ ભાગો</div>
                                <div class="fw-bold font-mono fs-5 text-dark" id="modalKpiParts">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-2.5 rounded-3 bg-light border text-center">
                                <div class="text-muted small">કુલ નંગ (Pcs)</div>
                                <div class="fw-bold font-mono fs-5 text-dark" id="modalKpiPcs">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-2.5 rounded-3 bg-primary-subtle border border-primary-subtle text-center">
                                <div class="text-primary small fw-semibold">કુલ વજન (Weight)</div>
                                <div class="fw-bold font-mono fs-5 text-primary" id="modalKpiWeight">0.000 ct</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="p-2.5 rounded-3 bg-success-subtle border border-success-subtle text-center">
                                <div class="text-success small fw-semibold">સરેરાશ ભાવ (₹/Ct)</div>
                                <div class="fw-bold font-mono fs-5 text-success" id="modalKpiRate">₹ 0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Parts Table -->
                    <div class="card border mb-3">
                        <div class="card-header bg-light py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center">
                            <span>પસંદ થયેલ ભાગોની યાદી</span>
                            <span class="badge bg-secondary font-mono" id="modalTotalAmountBadge">કુલ રકમ: ₹ 0.00</span>
                        </div>
                        <div class="table-responsive" style="max-height: 220px;">
                            <table class="table table-sm table-diamond mb-0 align-middle">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Assort / Lot No</th>
                                        <th>ભાગનું નામ</th>
                                        <th class="text-center">નંગ</th>
                                        <th class="text-end">વજન (Ct)</th>
                                        <th class="text-end">પડતર ભાવ (₹/Ct)</th>
                                        <th class="text-end pe-3">કુલ રકમ (₹)</th>
                                    </tr>
                                </thead>
                                <tbody id="modalSelectedPartsBody">
                                    <!-- Rendered dynamically via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- New Packet Details Form Fields -->
                    <div class="row g-2.5">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold small text-dark mb-1">
                                નવો પેકેટ / લોટ નંબર (New Packet No) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="new_packet_no" id="inputNewPacketNo" class="form-control font-mono fw-bold" required placeholder="દા.ત. MRG-SELL-2026-0001">
                            <small class="text-muted" style="font-size: 0.75rem;">આ પેકેટ નંબરથી આગળ વેચાણ અથવા કાપન બનાવતી વખતે દેખાશે.</small>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold small text-dark mb-1">
                                નવા ભાગનું નામ (New Part Name / Label) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="new_part_name" id="inputNewPartName" class="form-control fw-semibold" required placeholder="દા.ત. MERGED SELL PACKET">
                            <small class="text-muted" style="font-size: 0.75rem;">ઓળખ માટે કસ્ટમ નામ આપી શકો છો.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted mb-1">નોંધ / રિમાર્કસ (વૈકલ્પિક)</label>
                            <textarea name="notes" rows="2" class="form-control" placeholder="કયા કારણસર કે કયા લોટ ભેગા કર્યા તેની વિગત..."></textarea>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 mt-3 mb-0 d-flex align-items-center gap-2 small">
                        <?= svg_icon('circle-info', 'text-info flex-shrink-0') ?>
                        <div>
                            <strong>ધ્યાન આપો:</strong> મર્જ કર્યા બાદ જૂના પસંદ કરેલા ભાગોની સ્થિતિ <code>Merged</code> થઈ જશે અને નવો એકીકૃત (Consolidated) પેકેટ સરેરાશ પડતર સાથે લિસ્ટમાં ઉપલબ્ધ બનશે.
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-3">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">રદ્દ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" id="btnConfirmMerge">
                        <?= svg_icon('code-branch', 'me-1') ?>🔗 મર્જ કરો અને નવું પેકેટ બનાવો
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Dynamic Card Click & Section Switcher with Smooth Scroll
function showAssortSection(tabName, subTabName) {
    // 1. Hide all main tab panes
    var mainPanes = document.querySelectorAll('#assortMainTabsContent > .tab-pane');
    mainPanes.forEach(function (pane) {
        pane.classList.remove('show', 'active');
    });

    if (tabName === 'total_rough') {
        var p = document.getElementById('tab-total-rough');
        if (p) p.classList.add('show', 'active');
    } else if (tabName === 'pending') {
        var p = document.getElementById('tab-pending');
        if (p) p.classList.add('show', 'active');
    } else if (tabName === 'assorted') {
        var p = document.getElementById('tab-assorted');
        if (p) p.classList.add('show', 'active');

        var subPanes = document.querySelectorAll('#assortedSubTabsContent > .tab-pane');
        subPanes.forEach(function (sp) {
            sp.classList.remove('show', 'active');
        });

        var effSub = subTabName || 'mfg';
        var targetSubId = 'subtab-' + effSub.replace('_', '-');
        var targetSubPane = document.getElementById(targetSubId);
        if (targetSubPane) {
            targetSubPane.classList.add('show', 'active');
        }
    }

    // Update active highlight on 7 cards
    syncKpiActiveCard(tabName, subTabName);

    // Update URL history state without reloading
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    if (subTabName) {
        url.searchParams.set('subtab', subTabName);
    } else {
        url.searchParams.delete('subtab');
    }
    window.history.replaceState({}, '', url.toString());

    // Smooth scroll down to table view
    var scrollTarget = document.getElementById('assortMainTabsContent');
    if (scrollTarget) {
        scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function syncKpiActiveCard(tabName, subTabName) {
    document.querySelectorAll('.assort-kpi-card').forEach(function(card) {
        card.classList.remove('kpi-active-ring');
    });
    var targetId = 'kpi-card-' + (tabName === 'assorted' && subTabName ? subTabName : tabName);
    var targetCard = document.getElementById(targetId);
    if (targetCard) {
        targetCard.classList.add('kpi-active-ring');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var urlParams = new URLSearchParams(window.location.search);
    var curTab = urlParams.get('tab') || '<?= e($activeTab) ?>';
    var curSubTab = urlParams.get('subtab') || '<?= e($activeSubTab) ?>';
    syncKpiActiveCard(curTab, curSubTab);
});

// Select All toggle
function toggleSelectAll(tag, isChecked) {
    var cbs = document.querySelectorAll('.part-merge-' + tag);
    cbs.forEach(function (cb) {
        if (!cb.disabled) {
            cb.checked = isChecked;
        }
    });
    onMergeCheckboxChange(tag);
}

// Checkbox change handler
function onMergeCheckboxChange(tag) {
    var cbs = document.querySelectorAll('.part-merge-' + tag + ':checked');
    var btn = (tag === 'sell') ? document.getElementById('btnMergeSell') : document.getElementById('btnMergeMfgLater');
    if (!btn) return;
    
    var countBadge = btn.querySelector('.merge-count');
    if (countBadge) countBadge.textContent = cbs.length;

    if (cbs.length >= 2) {
        btn.classList.remove('d-none');
    } else {
        btn.classList.add('d-none');
    }
}

// Open Merge Modal
function openMergeModal(tag) {
    var cbs = document.querySelectorAll('.part-merge-' + tag + ':checked');
    if (cbs.length < 2) {
        alert('મર્જ કરવા માટે ઓછામાં ઓછા ૨ ભાગો પસંદ કરવા જરૂરી છે.');
        return;
    }

    var selectedParts = [];
    var totalPcs = 0;
    var totalWeight = 0;
    var totalAmount = 0;

    cbs.forEach(function (cb) {
        var pcs = parseInt(cb.getAttribute('data-pcs')) || 0;
        var wt = parseFloat(cb.getAttribute('data-weight')) || 0;
        var rate = parseFloat(cb.getAttribute('data-rate')) || 0;
        var amt = parseFloat(cb.getAttribute('data-amount')) || (wt * rate);

        totalPcs += pcs;
        totalWeight += wt;
        totalAmount += amt;

        selectedParts.push({
            id: cb.value,
            assortNo: cb.getAttribute('data-assort-no') || '-',
            partName: cb.getAttribute('data-part-name') || '-',
            lot: cb.getAttribute('data-lot') || '-',
            pcs: pcs,
            weight: wt,
            rate: rate,
            amount: amt
        });
    });

    var weightedRate = totalWeight > 0 ? (totalAmount / totalWeight) : 0;

    // Populate modal KPI
    document.getElementById('mergeTagType').value = tag;
    document.getElementById('modalKpiParts').textContent = selectedParts.length;
    document.getElementById('modalKpiPcs').textContent = totalPcs;
    document.getElementById('modalKpiWeight').textContent = totalWeight.toFixed(3) + ' ct';
    document.getElementById('modalKpiRate').textContent = '₹ ' + weightedRate.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('modalTotalAmountBadge').textContent = 'કુલ રકમ: ₹ ' + totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Populate Hidden Inputs for part_ids
    var hiddenContainer = document.getElementById('mergeHiddenIdsContainer');
    hiddenContainer.innerHTML = '';
    selectedParts.forEach(function (p) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'part_ids[]';
        inp.value = p.id;
        hiddenContainer.appendChild(inp);
    });

    // Render table rows
    var tbody = document.getElementById('modalSelectedPartsBody');
    tbody.innerHTML = '';
    selectedParts.forEach(function (p) {
        var tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <span class="font-mono fw-bold text-dark">${escapeHtml(p.assortNo)}</span>
                <div class="small text-muted font-mono">${escapeHtml(p.lot)}</div>
            </td>
            <td><strong class="text-dark">${escapeHtml(p.partName)}</strong></td>
            <td class="text-center font-mono">${p.pcs}</td>
            <td class="text-end font-mono fw-bold text-primary">${p.weight.toFixed(3)} ct</td>
            <td class="text-end font-mono">₹ ${p.rate.toFixed(2)}</td>
            <td class="text-end font-mono fw-semibold pe-3">₹ ${p.amount.toFixed(2)}</td>
        `;
        tbody.appendChild(tr);
    });

    // Auto-generate suggested Packet No and Part Name
    var currentYear = new Date().getFullYear();
    var randomSuffix = Math.floor(1000 + Math.random() * 9000);
    var tagPrefix = (tag === 'sell') ? 'MRG-SELL' : 'MRG-MFG';
    var defaultPartName = (tag === 'sell') ? 'MERGED SELL PACKET' : 'MERGED MFG LATER PACKET';

    document.getElementById('inputNewPacketNo').value = tagPrefix + '-' + currentYear + '-' + randomSuffix;
    document.getElementById('inputNewPartName').value = defaultPartName;

    // Subtitle & Label based on tag
    var titleTag = (tag === 'sell') ? 'Sell Tag (સીધું વેચાણ)' : 'MFG Later Tag (પછી કાપન)';
    document.getElementById('mergeModalSubtitle').textContent = titleTag + ' ના પસંદ કરેલા ' + selectedParts.length + ' ભાગો એકીકૃત થઈ રહ્યા છે';

    // Show modal
    var modalEl = document.getElementById('mergePartsModal');
    var modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
