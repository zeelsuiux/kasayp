<?php
/**
 * Job Work Stock Report (કયા જોબ વર્કમાં કેટલો સ્ટોક છે તે)
 * Kasyap Diamond ERP - Surat & Mumbai
 * Real-time breakdown of diamonds/packets across manufacturing stages & parties
 */

declare(strict_types=1);

$pageTitle = 'જોબવર્ક વાઇઝ સ્ટોક (Job Work Stock Report)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_packets_dynamic_stage_column();

// Active stages from DB
$dynamicStages = get_active_processing_stages();

// Normalized stage definition map
$stageDefinitions = [
    'galaxy'         => ['name' => 'Galaxy', 'gu' => 'ગેલેક્સી સ્કેનિંગ', 'icon' => 'atom', 'color' => '#0284c7', 'bg' => '#e0f2fe'],
    'planner'        => ['name' => 'Planning', 'gu' => 'શેપ પ્લાનિંગ (Planner)', 'icon' => 'compass-drafting', 'color' => '#d97706', 'bg' => '#fef3c7'],
    'sarin'          => ['name' => 'Sarin', 'gu' => 'સરીન (Pre-Sign)', 'icon' => 'microscope', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
    'laser'          => ['name' => 'LS', 'gu' => 'લેસર સોઇંગ (Laser Sawing)', 'icon' => 'bolt', 'color' => '#dc2626', 'bg' => '#fee2e2'],
    'blocking'       => ['name' => 'Mfg', 'gu' => 'મેન્યુફેક્ચરિંગ (બ્લોકિંગ/ઘાટ)', 'icon' => 'cubes', 'color' => '#475569', 'bg' => '#f1f5f9'],
    'polishing'      => ['name' => 'Mfg Polish', 'gu' => 'પોલિશિંગ (આખરી ઘસાઈ)', 'icon' => 'wand-magic-sparkles', 'color' => '#059669', 'bg' => '#d1fae5'],
    'final_checking' => ['name' => 'QC', 'gu' => 'ક્વોલિટી ચેકિંગ (તપાસ)', 'icon' => 'clipboard-check', 'color' => '#107569', 'bg' => '#ccfbf1'],
    'boil'           => ['name' => 'Boil Polish', 'gu' => 'બોઇલિંગ (ચાલુ)', 'icon' => 'fire-burner', 'color' => '#ea580c', 'bg' => '#ffedd5'],
    'polish_return'  => ['name' => 'Polish Return', 'gu' => 'બોઇલ રિટર્ન / Re-Assort', 'icon' => 'rotate-left', 'color' => '#4f46e5', 'bg' => '#eef2ff']
];

// Stage aliases to map DB values to primary keys
$stageMap = [
    'galaxy'         => 'galaxy',
    'planner'        => 'planner',
    'planning'       => 'planner',
    'sarin'          => 'sarin',
    'sarin_final'    => 'blocking',
    'laser'          => 'laser',
    'ls'             => 'laser',
    'ls_return'      => 'laser',
    'laser_sawing'   => 'laser',
    'sawing'         => 'laser',
    'bruting'        => 'blocking',
    'blocking'       => 'blocking',
    'faceting'       => 'polishing',
    'polishing'      => 'polishing',
    'mfg'            => 'blocking',
    'mfg_polish'     => 'polishing',
    'factory'        => 'polishing',
    'final_checking' => 'final_checking',
    'qc'             => 'final_checking',
    'boil'           => 'boil',
    'weekly_boil'    => 'boil',
    'boil_polish'    => 'boil',
    'polish_return'  => 'polish_return',
    'boil_return'    => 'polish_return'
];

// Filters
$filterStage = trim((string)($_GET['stage'] ?? ''));
if (isset($stageMap[$filterStage])) {
    $filterStage = $stageMap[$filterStage];
}
$filterKapan = (int)($_GET['kapan_id'] ?? 0);
$filterParty = (int)($_GET['party_id'] ?? 0);
$searchQuery = trim((string)($_GET['q'] ?? ''));

// Fetch Kapans & Parties for filter dropdowns
$kapans = $pdo->query("SELECT id, kapan_code, kapan_name FROM kapans ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$parties = $pdo->query("SELECT id, party_name, party_type FROM parties ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 1. Live Aggregates across all Job Work stages (excludes completed Polish Stock & Sold)
$stmtAgg = $pdo->query("
    SELECT 
        p.current_stage,
        COUNT(p.id) as pcs,
        COALESCE(SUM(COALESCE(p.current_weight, p.weight)), 0) as weight
    FROM packets p
    WHERE (p.status = 'in_process' OR p.diamond_status IN ('available', 'assigned_to_jangad', 'in_process', 'partially_returned'))
      AND p.current_stage != 'polish_stock'
      AND p.status NOT IN ('completed', 'in_polish_stock', 'boil_ready', 'sold', 'reassorted')
      AND COALESCE(p.is_reassorted, 0) = 0
    GROUP BY p.current_stage
");
$rawAgg = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);

// Map aggregate counts to standard 8 stages
$stageStats = [];
foreach ($stageDefinitions as $k => $def) {
    $stageStats[$k] = [
        'def'    => $def,
        'pcs'    => 0,
        'weight' => 0.000,
        'parties'=> []
    ];
}

$totPcs = 0;
$totWeight = 0.000;

foreach ($rawAgg as $row) {
    $dbStage = (string)($row['current_stage'] ?? '');
    $mappedKey = $stageMap[$dbStage] ?? 'galaxy';
    if (!isset($stageStats[$mappedKey])) {
        $mappedKey = 'galaxy';
    }
    $pcs = (int)$row['pcs'];
    $wt = (float)$row['weight'];
    
    $stageStats[$mappedKey]['pcs'] += $pcs;
    $stageStats[$mappedKey]['weight'] += $wt;
    
    $totPcs += $pcs;
    $totWeight += $wt;
}

// 2. Fetch Detailed Diamond List with optional filters
$detailSql = "
    SELECT 
        p.id,
        p.packet_no,
        p.diamond_no,
        p.barcode,
        p.weight as orig_weight,
        COALESCE(p.current_weight, p.weight) as cur_weight,
        p.current_stage,
        p.current_location,
        p.expected_shape,
        p.status,
        p.diamond_status,
        p.kapan_id,
        k.kapan_code,
        k.kapan_name,
        pt.id as party_id,
        pt.party_name,
        jv.id as jv_id,
        jv.jangad_no,
        jv.issue_date
    FROM packets p
    LEFT JOIN kapans k ON p.kapan_id = k.id
    LEFT JOIN parties pt ON p.assigned_party_id = pt.id
    LEFT JOIN jangad_vouchers jv ON p.jangad_id = jv.id
    WHERE (p.status = 'in_process' OR p.diamond_status IN ('available', 'assigned_to_jangad', 'in_process', 'partially_returned'))
      AND p.current_stage != 'polish_stock'
      AND p.status NOT IN ('completed', 'in_polish_stock', 'boil_ready', 'sold', 'reassorted')
      AND COALESCE(p.is_reassorted, 0) = 0
";
$params = [];

if ($filterStage !== '') {
    // Find all db keys that map to this filter
    $matchingDbStages = [];
    foreach ($stageMap as $dStage => $mStage) {
        if ($mStage === $filterStage) {
            $matchingDbStages[] = $dStage;
        }
    }
    if (!empty($matchingDbStages)) {
        $inPlaceholders = implode(',', array_fill(0, count($matchingDbStages), '?'));
        $detailSql .= " AND p.current_stage IN ($inPlaceholders)";
        $params = array_merge($params, $matchingDbStages);
    }
}

if ($filterKapan > 0) {
    $detailSql .= " AND p.kapan_id = ?";
    $params[] = $filterKapan;
}

if ($filterParty > 0) {
    $detailSql .= " AND p.assigned_party_id = ?";
    $params[] = $filterParty;
}

if ($searchQuery !== '') {
    $detailSql .= " AND (p.packet_no LIKE ? OR p.diamond_no LIKE ? OR p.barcode LIKE ? OR k.kapan_name LIKE ? OR pt.party_name LIKE ?)";
    $qParam = '%' . $searchQuery . '%';
    $params[] = $qParam;
    $params[] = $qParam;
    $params[] = $qParam;
    $params[] = $qParam;
    $params[] = $qParam;
}

$detailSql .= " ORDER BY p.id DESC LIMIT 300";
$stmtDetail = $pdo->prepare($detailSql);
$stmtDetail->execute($params);
$diamondRecords = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

// Count active stages with stock
$activeStageCount = 0;
foreach ($stageStats as $s) {
    if ($s['pcs'] > 0) {
        $activeStageCount++;
    }
}
?>

<!-- Finora Page Header -->
<?= render_page_header(
    'Job Work Stock',
    '',
    'boxes-stacked',
    '
    <a href="' . BASE_URL . '/modules/process/barcode_scanner.php?mode=rapid" class="btn btn-outline-primary rounded-pill">' . svg_icon('bolt', 'me-1', 14) . 'સળંગ સ્કેન</a>
    <a href="' . BASE_URL . '/modules/polish/index.php?tab=stock" class="btn btn-outline-primary rounded-pill">' . svg_icon('gem', 'me-1', 15) . 'Polish સ્ટોક</a>
    <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill">' . svg_icon('print', 'me-1', 15) . 'પ્રિન્ટ</button>
    '
) ?>

<!-- Top 4 Pastel Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ નંગ', number_format($totPcs) . ' Pcs', 'ઉત્પાદન હેઠળ', '', 'blue', null, '', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ વજન', format_carat($totWeight), 'ચાલુ પ્રોસેસ', '', 'green', null, '', 'scale-balanced') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('સક્રિય સ્ટેજ', (string)$activeStageCount . ' / ' . count($stageDefinitions), 'સ્ટેજ', '', 'amber', null, '', 'layer-group') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('જોબવર્ક પાર્ટીઓ', (string)count($parties), 'કારખાના', '', 'rose', null, '', 'users') ?>
    </div>
</div>

<!-- Job Work Stage Cards Grid: 2 Balanced Workflow Rows -->
<?php
$allStages = ['galaxy', 'planner', 'sarin', 'laser', 'blocking', 'polishing', 'final_checking', 'boil', 'polish_return'];
?>
<!-- Single Line: All 9 Process Stages -->
<div class="d-flex gap-2 mb-3 pb-1 flex-nowrap overflow-x-auto align-items-stretch" style="scrollbar-width: thin; -webkit-overflow-scrolling: touch;">
    <?php foreach ($allStages as $stCode): 
        if (!isset($stageStats[$stCode])) continue;
        $st = $stageStats[$stCode];
        $def = $st['def'];
        $isActiveFilter = ($filterStage === $stCode);
        $pct = $totWeight > 0 ? round(($st['weight'] / $totWeight) * 100, 1) : 0;
    ?>
        <div style="flex: 1 1 0; min-width: 100px;">
            <a href="<?= BASE_URL ?>/modules/process/jobwork_stock.php?stage=<?= urlencode($stCode) ?>" class="text-decoration-none d-block h-100">
                <div class="stat-card-finora <?= $isActiveFilter ? 'kpi-tone-blue' : 'bg-white border' ?> h-100 p-2 rounded-3 <?= ($stCode === 'polish_return') ? 'border-primary-subtle' : '' ?>" style="border-left: 3px solid <?= $def['color'] ?> !important;">
                    <div class="d-flex align-items-center justify-content-between mb-1 gap-1">
                        <div class="d-flex align-items-center gap-1 text-truncate">
                            <div class="kpi-icon-tile rounded-2" style="width: 24px; height: 24px; background: <?= $def['bg'] ?>; color: <?= $def['color'] ?>; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;">
                                <?= svg_icon($def['icon'], '', 12) ?>
                            </div>
                            <strong class="text-dark text-truncate" style="font-size: 0.76rem;"><?= e($def['name']) ?></strong>
                        </div>
                        <span class="font-mono text-muted fw-semibold" style="font-size: 0.65rem;"><?= $pct ?>%</span>
                    </div>

                    <div class="d-flex justify-content-between align-items-baseline pt-1 border-top border-light-subtle">
                        <span class="text-muted font-mono" style="font-size: 0.70rem;"><?= number_format($st['pcs']) ?> Pcs</span>
                        <strong class="font-mono fw-bold" style="color: <?= $def['color'] ?>; font-size: 0.88rem;"><?= format_carat($st['weight']) ?></strong>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php
$jwReportKapans = [];
foreach ($diamondRecords as $pkt) {
    $kId = (int)($pkt['kapan_id'] ?? 0);
    $kName = !empty($pkt['kapan_name']) ? $pkt['kapan_name'] : 'અન્ય હીરા';
    $kCode = !empty($pkt['kapan_code']) ? $pkt['kapan_code'] : 'NO-KAPAN';
    
    if (!isset($jwReportKapans[$kId])) {
        $jwReportKapans[$kId] = [
            'kapan_id'     => $kId,
            'kapan_name'   => $kName,
            'kapan_code'   => $kCode,
            'total_pcs'    => 0,
            'total_weight' => 0.000,
            'packets'      => []
        ];
    }
    
    $pWt = (float)($pkt['cur_weight'] ?? $pkt['orig_weight']);
    $jwReportKapans[$kId]['total_pcs']++;
    $jwReportKapans[$kId]['total_weight'] += $pWt;
    $jwReportKapans[$kId]['packets'][] = $pkt;
}
?>

<div class="finora-card mb-4">
    <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2.5 py-2.5">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2" style="font-size: 0.92rem;">
                <?= svg_icon('boxes-stacked', 'text-primary', 16) ?>
                <span>કાપન વાઇઝ વિગત (<?= count($diamondRecords) ?>)</span>
            </h6>
            <?php if ($filterStage !== ''): ?>
                <span class="finora-pill finora-pill-blue font-mono"><?= e($stageDefinitions[$filterStage]['name'] ?? $filterStage) ?></span>
            <?php endif; ?>
            <div class="d-inline-flex gap-1 ms-1">
                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2.5 py-1" onclick="toggleAllJwStockKapans(true)">
                    <?= svg_icon('angles-down', 'me-1', 11) ?>ખોલો
                </button>
                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2.5 py-1" onclick="toggleAllJwStockKapans(false)">
                    <?= svg_icon('angles-up', 'me-1', 11) ?>બંધ
                </button>
            </div>
        </div>
        
        <form method="GET" action="" class="d-flex align-items-center gap-2 mb-0 flex-nowrap overflow-x-auto pb-1 pb-md-0" style="scrollbar-width: thin;">
            <div class="input-group input-group-sm" style="width: 170px; flex-shrink: 0;">
                <span class="input-group-text bg-white"><?= svg_icon('search', 'text-muted', 13) ?></span>
                <input type="text" name="q" class="form-control" placeholder="પેકેટ / બારકોડ..." value="<?= e($searchQuery) ?>">
            </div>

            <select name="stage" class="form-select form-select-sm" style="width: 120px; flex-shrink: 0;" onchange="this.form.submit()">
                <option value="">બધા સ્ટેજ</option>
                <?php foreach ($stageDefinitions as $stCode => $def): ?>
                    <option value="<?= $stCode ?>" <?= $filterStage === $stCode ? 'selected' : '' ?>>
                        <?= e($def['name']) ?> (<?= $stageStats[$stCode]['pcs'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="kapan_id" class="form-select form-select-sm" style="width: 130px; flex-shrink: 0;" onchange="this.form.submit()">
                <option value="0">બધા Kapans</option>
                <?php foreach ($kapans as $kp): ?>
                    <option value="<?= $kp['id'] ?>" <?= $filterKapan === (int)$kp['id'] ? 'selected' : '' ?>>
                        <?= e($kp['kapan_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="party_id" class="form-select form-select-sm" style="width: 130px; flex-shrink: 0;" onchange="this.form.submit()">
                <option value="0">બધી પાર્ટી</option>
                <?php foreach ($parties as $pt): ?>
                    <option value="<?= $pt['id'] ?>" <?= $filterParty === (int)$pt['id'] ? 'selected' : '' ?>>
                        <?= e($pt['party_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($filterStage !== '' || $filterKapan > 0 || $filterParty > 0 || $searchQuery !== ''): ?>
                <a href="<?= BASE_URL ?>/modules/process/jobwork_stock.php" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center" style="width: 31px; height: 31px; flex-shrink: 0;" title="Reset Filters">
                    <?= svg_icon('xmark', '', 14) ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="p-3" style="background: #f8fafc;">
        <?php if (empty($jwReportKapans)): ?>
            <div class="text-center py-5 text-muted bg-white rounded-3 border">કોઈ ડાયમંડ મળ્યા નથી.</div>
        <?php else: ?>
            <div class="d-flex flex-column gap-2.5">
                <?php 
                $repCounter = 0;
                foreach ($jwReportKapans as $kId => $kData): 
                    $repCounter++;
                    $collapseId = 'jw_rep_kapan_' . $kId;
                    $avgWt = $kData['total_pcs'] > 0 ? ($kData['total_weight'] / $kData['total_pcs']) : 0;
                    $isExpanded = ($repCounter === 1 || !empty($searchQuery));
                ?>
                    <div class="card rounded-4 border overflow-hidden bg-white shadow-xs">
                        <div class="p-3 d-flex flex-wrap justify-content-between align-items-center jw-rep-header-bar <?= $isExpanded ? '' : 'collapsed' ?>"
                             role="button"
                             data-bs-toggle="collapse"
                             data-bs-target="#<?= $collapseId ?>"
                             aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                             style="cursor: pointer;">
                            
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="kpi-icon-tile kpi-icon-blue" style="width: 36px; height: 36px; border-radius: 10px;">
                                    <?= svg_icon('boxes-stacked', '', 17) ?>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.92rem;"><?= e($kData['kapan_name']) ?></h6>
                                    <small class="text-muted font-mono"><?= e($kData['kapan_code']) ?></small>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3 gap-md-4 mt-2 mt-md-0 flex-nowrap">
                                <div class="text-end px-2 border-end border-light-subtle">
                                    <small class="text-muted d-block font-mono" style="font-size: 0.70rem;">નંગ</small>
                                    <strong class="font-mono text-dark" style="font-size: 0.92rem;"><?= number_format($kData['total_pcs']) ?> Pcs</strong>
                                </div>
                                <div class="text-end px-2 border-end border-light-subtle">
                                    <small class="text-muted d-block font-mono" style="font-size: 0.70rem;">કુલ વજન</small>
                                    <strong class="font-mono fw-bold text-primary" style="font-size: 1.05rem;"><?= format_carat($kData['total_weight']) ?></strong>
                                </div>
                                <div class="text-end px-2 d-none d-sm-block border-end border-light-subtle">
                                    <small class="text-muted d-block font-mono" style="font-size: 0.70rem;">સરેરાશ</small>
                                    <span class="font-mono text-secondary" style="font-size: 0.85rem;"><?= format_carat($avgWt) ?></span>
                                </div>
                                <button type="button"
                                        class="btn btn-sm rounded-pill px-2.5 py-1 fw-semibold bg-success-subtle text-success border-0 d-inline-flex align-items-center gap-1.5 ms-1"
                                        style="font-size: 0.75rem; white-space: nowrap;"
                                        title="આ કાપનના બધા હીરા સીધા આગળના સ્ટેજમાં મોકલો"
                                        onclick="event.stopPropagation(); bulkAdvanceKapan('<?= $collapseId ?>', '<?= e(addslashes($kData['kapan_name'])) ?>')">
                                    <?= svg_icon('arrow-right', '', 12) ?>
                                    <span>બધા નેક્સ્ટ</span>
                                </button>
                                <div class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 d-inline-flex align-items-center gap-1.5" style="font-size: 0.78rem; white-space: nowrap;">
                                    <span><?= $kData['total_pcs'] ?> પેકેટ્સ</span>
                                    <?= svg_icon('chevron-down', 'jw-rep-chevron', 13) ?>
                                </div>
                            </div>
                        </div>

                        <div class="collapse <?= $isExpanded ? 'show' : '' ?> jw-rep-collapse" id="<?= $collapseId ?>">
                            <div class="border-top bg-light px-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <label class="d-inline-flex align-items-center gap-2 small fw-semibold text-dark mb-0 cursor-pointer">
                                    <input type="checkbox" class="form-check-input mt-0 kapan-select-all"
                                           onchange="toggleKapanSelectAll(this, '<?= $collapseId ?>')">
                                    <span>બધા સિલેક્ટ કરો (<?= $kData['total_pcs'] ?>)</span>
                                </label>
                                <div class="d-inline-flex align-items-center gap-1.5">
                                    <span class="small text-muted">રેન્જ:</span>
                                    <input type="text" class="form-control form-control-sm font-mono" style="width: 120px; height: 28px; font-size: 0.76rem;"
                                           placeholder="1-10, 12"
                                           onkeydown="if(event.key==='Enter'){event.preventDefault(); applyKapanRangeSelect(this, '<?= $collapseId ?>');}">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2.5" style="height: 28px; font-size: 0.74rem;"
                                            onclick="applyKapanRangeSelect(this.previousElementSibling, '<?= $collapseId ?>')">ટિક કરો</button>
                                </div>
                            </div>
                            <div class="border-top p-0 bg-white">
                                <div class="table-responsive">
                                    <table class="table table-diamond mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-3" style="width: 38px;"></th>
                                                <th style="width: 45px;">#</th>
                                                <th>પેકેટ / ડાયમંડ</th>
                                                <th>બારકોડ</th>
                                                <th>સ્ટેજ</th>
                                                <th class="text-end">મૂળ વજન</th>
                                                <th class="text-end">હાજર વજન</th>
                                                <th>સ્થળ / પાર્ટી</th>
                                                <th>સ્થિતિ</th>
                                                <th class="text-end pe-4">એક્શન</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($kData['packets'] as $pIdx => $row): 
                                                $rawSt = $row['current_stage'] ?? '';
                                                $mappedSt = $stageMap[$rawSt] ?? 'galaxy';
                                                $stDef = $stageDefinitions[$mappedSt] ?? ['name' => $rawSt, 'color' => '#2563eb', 'bg' => '#eff6ff'];
                                                $rNoDisplay = $row['packet_no'] ?: ($row['diamond_no'] ?: '#' . $row['id']);
                                                $rCurWt = (float)($row['cur_weight'] ?? $row['orig_weight'] ?? 0);
                                            ?>
                                                <tr>
                                                    <td class="ps-3" data-label="પસંદ">
                                                        <input type="checkbox" class="form-check-input pkt-bulk-cb"
                                                               data-id="<?= (int)$row['id'] ?>"
                                                               data-row-idx="<?= $pIdx + 1 ?>"
                                                               data-packet-no="<?= e($rNoDisplay) ?>"
                                                               data-barcode="<?= e($row['barcode'] ?? '') ?>"
                                                               data-weight="<?= number_format($rCurWt, 3, '.', '') ?>"
                                                               data-stage="<?= e($rawSt) ?>"
                                                               data-location="<?= e($row['current_location'] ?: 'Surat') ?>"
                                                               data-party-id="<?= (int)($row['party_id'] ?? 0) ?>"
                                                               onchange="syncKapanMasterCheckbox('<?= $collapseId ?>')">
                                                    </td>
                                                    <td class="text-muted font-mono small"><?= $pIdx + 1 ?></td>
                                                    <td>
                                                        <a href="javascript:void(0)" onclick="openDiamondHistoryModal(<?= (int)$row['id'] ?>)" class="text-dark text-decoration-none font-mono fw-bold">
                                                            <?= e($rNoDisplay) ?>
                                                        </a>
                                                    </td>
                                                    <td class="font-mono small text-muted"><?= e($row['barcode']) ?></td>
                                                    <td>
                                                        <span class="finora-pill" style="background: <?= $stDef['bg'] ?>; color: <?= $stDef['color'] ?>;">
                                                            <?= e($stDef['name']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end font-mono text-muted"><?= format_carat((float)$row['orig_weight']) ?></td>
                                                    <td class="text-end font-mono fw-bold text-primary"><?= format_carat((float)$row['cur_weight']) ?></td>
                                                    <td>
                                                        <?php if (!empty($row['party_name'])): ?>
                                                            <div class="fw-semibold text-dark small"><?= e($row['party_name']) ?></div>
                                                        <?php endif; ?>
                                                        <small class="text-muted"><?= e($row['current_location'] ?: 'Surat') ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($row['jangad_no'])): ?>
                                                            <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= $row['jv_id'] ?>" class="finora-pill finora-pill-amber text-decoration-none font-mono"><?= e($row['jangad_no']) ?></a>
                                                        <?php else: ?>
                                                            <span class="finora-pill finora-pill-slate">પ્રોસેસમાં</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-4 text-nowrap">
                                                        <div class="d-inline-flex align-items-center gap-1">
                                                            <button type="button"
                                                                    class="btn btn-sm rounded-pill px-2 py-1 fw-semibold bg-success-subtle text-success border-0"
                                                                    style="font-size: 0.74rem;"
                                                                    title="સીધું આગળના સ્ટેજમાં મોકલો"
                                                                    onclick="quickAdvanceSinglePacket(<?= (int)$row['id'] ?>, '<?= e(addslashes($rNoDisplay)) ?>')">
                                                                <?= svg_icon('arrow-right', '', 12) ?>
                                                                <span>નેક્સ્ટ</span>
                                                            </button>
                                                            <button type="button" class="btn btn-sm rounded-pill btn-outline-primary py-1 px-2.5"
                                                                    style="font-size: 0.74rem;"
                                                                    onclick='openQuickStageModal(<?= json_encode([
                                                                        "id"        => (int)$row["id"],
                                                                        "packet_no" => $rNoDisplay,
                                                                        "barcode"   => $row["barcode"] ?? "",
                                                                        "weight"    => $rCurWt,
                                                                        "stage"     => $rawSt,
                                                                        "location"  => $row["current_location"] ?: "Surat",
                                                                        "party_id"  => (int)($row["party_id"] ?? 0)
                                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                                <?= svg_icon('arrows-split-up-and-left', '', 12) ?>
                                                                <span>સ્ટેજ</span>
                                                            </button>
                                                            <?php 
                                                            $canReassort = in_array(strtolower($rawSt), ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'], true);
                                                            if ($canReassort): 
                                                            ?>
                                                                <button type="button" 
                                                                        class="btn btn-sm rounded-pill px-2.5 py-1 fw-bold d-inline-flex align-items-center gap-1 shadow-none bg-warning text-dark border-0" 
                                                                        style="font-size: 0.74rem;"
                                                                        title="Re-Assort કરો (નવો લોટ પાડો & કાપનમાંથી એક્ઝિટ)"
                                                                        onclick='openQuickReassortModal(<?= json_encode([
                                                                            "id"        => (int)$row["id"],
                                                                            "packet_no" => $rNoDisplay,
                                                                            "barcode"   => $row["barcode"] ?? "",
                                                                            "weight"    => $rCurWt,
                                                                            "stage"     => $rawSt,
                                                                            "location"  => $row["current_location"] ?: "Surat",
                                                                            "party_id"  => (int)($row["party_id"] ?? 0)
                                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                                    <?= svg_icon('fire-burner', 'text-dark', 12) ?>
                                                                    <span>⚡ Re-Assort</span>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm rounded-pill btn-outline-secondary py-1 px-2" onclick="openDiamondHistoryModal(<?= (int)$row['id'] ?>)" title="હિસ્ટ્રી">
                                                                <?= svg_icon('clock-rotate-left', '', 12) ?>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAllJwStockKapans(expand) {
    document.querySelectorAll('.jw-rep-collapse').forEach(function(el) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            var c = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
            if (expand) {
                c.show();
            } else {
                c.hide();
            }
        } else {
            if (expand) {
                el.classList.add('show');
            } else {
                el.classList.remove('show');
            }
        }
    });
}
</script>

<style>
.jw-rep-header-bar:hover {
    background: #f8fafc !important;
}
.jw-rep-header-bar:not(.collapsed) .jw-rep-chevron {
    transform: rotate(180deg);
}
.jw-rep-header-bar[aria-expanded="true"] .jw-rep-chevron {
    transform: rotate(180deg);
}
.jw-rep-chevron {
    transition: transform 0.25s ease;
}
</style>

<!-- Quick Stage & Factory Transfer Modal -->
<?php require_once __DIR__ . '/_quick_stage_modal.php'; ?>
<!-- Diamond History & Multi-Stage Journey Modal -->
<?php require_once __DIR__ . '/_diamond_history_modal.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
