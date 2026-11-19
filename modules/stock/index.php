<?php
/**
 * Stock Hub (સ્ટોક વિગત & મેનેજમેન્ટ)
 * Kasyap Diamond ERP - Surat & Mumbai
 * Executive AeuxGlobal / Donezo Design
 * Displays Job Work Live Stock, Polish Stock, GIA Lab Stock, and Tops Stock
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$tab = trim((string)($_GET['tab'] ?? 'jobwork'));

// Seamlessly forward to unified modules based on reorganized navigation
if ($tab === 'jobwork' || empty($_GET['tab'])) {
    header('Location: ' . BASE_URL . '/modules/process/jobwork_stock.php');
    exit;
} elseif ($tab === 'polish') {
    header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=stock');
    exit;
} elseif ($tab === 'rough') {
    header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=stock');
    exit;
}

if (!in_array($tab, ['gia', 'tops'], true)) {
    $tab = 'gia';
}

$pageTitle = 'સ્ટોક મેનેજમેન્ટ (Stock Hub)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// 1. Live Aggregates for Top Cards
// A. Job Work Stock
$jwAgg = $pdo->query("
    SELECT 
        COUNT(p.id) as pcs,
        COALESCE(SUM(COALESCE(p.current_weight, p.weight)), 0) as weight
    FROM packets p
    WHERE (p.status = 'in_process' OR p.diamond_status IN ('available', 'assigned_to_jangad', 'in_process', 'partially_returned'))
      AND COALESCE(p.is_reassorted, 0) = 0
      AND p.status != 'reassorted'
")->fetch(PDO::FETCH_ASSOC);

// B. Polish Available Stock
$plAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight
    FROM polish_stocks 
    WHERE status IN ('available', 'partially_on_jangad')
")->fetch(PDO::FETCH_ASSOC);

// C. GIA Lab Stock
$giaAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(available_pcs), 0) as pcs,
        COALESCE(SUM(available_weight), 0) as weight
    FROM polish_stocks 
    WHERE (gia_number IS NOT NULL AND gia_number != '') OR stock_type = 'gia_single'
")->fetch(PDO::FETCH_ASSOC);

// D. Tops Stock
$topsAgg = $pdo->query("
    SELECT 
        COUNT(*) as cnt,
        COALESCE(SUM(weight), 0) as weight
    FROM tops_records
")->fetch(PDO::FETCH_ASSOC);

$totJwWeight  = (float)($jwAgg['weight'] ?? 0);
$totJwPcs     = (int)($jwAgg['pcs'] ?? 0);

$totPlWeight  = (float)($plAgg['weight'] ?? 0);
$totPlPcs     = (int)($plAgg['pcs'] ?? 0);

$totGiaWeight = (float)($giaAgg['weight'] ?? 0);
$totGiaPcs    = (int)($giaAgg['pcs'] ?? 0);

$totTopsWeight= (float)($topsAgg['weight'] ?? 0);
$totTopsCount = (int)($topsAgg['cnt'] ?? 0);

// Search & Filter
$search = trim((string)($_GET['search'] ?? ''));
?>

<!-- Standardized Finora Page Header -->
<?= render_page_header(
    'સ્ટોક મેનેજમેન્ટ (Stock Hub)',
    '',
    'boxes-stacked',
    '
    <a href="' . BASE_URL . '/modules/reports/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">' . svg_icon('chart-line', 'me-1', 13) . 'રિપોર્ટ્સ</a>
    <a href="' . BASE_URL . '/modules/process/jobwork_stock.php" class="btn btn-primary btn-sm rounded-pill px-3">' . svg_icon('boxes-stacked', 'me-1', 13) . 'જોબવર્ક સ્ટોક</a>
    '
) ?>

<!-- Top Category Cards (Finora Pastel Tiles) -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('Job Work Stock', format_carat($totJwWeight), number_format($totJwPcs) . ' Pcs', 'કુલ જોબવર્ક', 'amber', BASE_URL . '/modules/stock/index.php?tab=jobwork', '', 'boxes-stacked') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('Polish Stock', format_carat($totPlWeight), number_format($totPlPcs) . ' Pcs', 'તૈયાર પોલિશ', 'green', BASE_URL . '/modules/stock/index.php?tab=polish', '', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('GIA Lab Stock', format_carat($totGiaWeight), number_format($totGiaPcs) . ' Pcs', 'સર્ટિફાઇડ હીરા', 'blue', BASE_URL . '/modules/stock/index.php?tab=gia', '', 'certificate') ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card('Tops Stock', format_carat($totTopsWeight), number_format($totTopsCount) . ' Rec', 'રદિયો / વેસ્ટ', 'rose', BASE_URL . '/modules/stock/index.php?tab=tops', '', 'recycle') ?>
    </div>
</div>

<?php if ($tab === 'jobwork'): ?>
    <!-- ================= TAB 1: JOB WORK STOCK ================= -->
    <?php
    $stageDefs = [
        'galaxy'         => ['name' => 'Galaxy', 'gu' => 'ગેલેક્સી સ્કેનિંગ', 'icon' => 'atom', 'color' => '#0284c7', 'bg' => '#e0f2fe'],
        'planner'        => ['name' => 'Planning', 'gu' => 'શેપ પ્લાનિંગ (Planner)', 'icon' => 'compass-drafting', 'color' => '#d97706', 'bg' => '#fef3c7'],
        'sarin'          => ['name' => 'Sarin', 'gu' => 'સરીન (Pre-Sign)', 'icon' => 'microscope', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
        'laser'          => ['name' => 'LS', 'gu' => 'લેસર સોઇંગ (Laser Sawing)', 'icon' => 'bolt', 'color' => '#dc2626', 'bg' => '#fee2e2'],
        'blocking'       => ['name' => 'Mfg', 'gu' => 'મેન્યુફેક્ચરિંગ (બ્લોકિંગ/ઘાટ)', 'icon' => 'cubes', 'color' => '#475569', 'bg' => '#f1f5f9'],
        'polishing'      => ['name' => 'Mfg Polish', 'gu' => 'પોલિશિંગ (આખરી ઘસાઈ)', 'icon' => 'wand-magic-sparkles', 'color' => '#059669', 'bg' => '#d1fae5'],
        'final_checking' => ['name' => 'QC', 'gu' => 'ક્વોલિટી ચેકિંગ (તપાસ)', 'icon' => 'clipboard-check', 'color' => '#2563EB', 'bg' => '#EFF6FF'],
        'boil'           => ['name' => 'Boil Polish', 'gu' => 'બોઇલિંગ (ચાલુ)', 'icon' => 'fire-burner', 'color' => '#ea580c', 'bg' => '#ffedd5'],
        'polish_return'  => ['name' => 'Polish Return', 'gu' => 'બોઇલ રિટર્ન / Re-Assort', 'icon' => 'rotate-left', 'color' => '#4f46e5', 'bg' => '#eef2ff']
    ];

    $stageNorm = [
        'galaxy' => 'galaxy', 'planner' => 'planner', 'planning' => 'planner', 'sarin' => 'sarin',
        'laser' => 'laser', 'ls' => 'laser', 'laser_sawing' => 'laser', 'sawing' => 'blocking',
        'bruting' => 'blocking', 'blocking' => 'blocking', 'faceting' => 'polishing', 'polishing' => 'polishing',
        'mfg' => 'blocking', 'mfg_polish' => 'polishing', 'factory' => 'polishing',
        'final_checking' => 'final_checking', 'qc' => 'final_checking',
        'boil' => 'boil', 'weekly_boil' => 'boil', 'boil_polish' => 'boil',
        'polish_return' => 'polish_return', 'boil_return' => 'polish_return'
    ];

    $stStats = [];
    foreach ($stageDefs as $k => $d) {
        $stStats[$k] = ['def' => $d, 'pcs' => 0, 'weight' => 0.000];
    }
    $rawStmts = $pdo->query("SELECT current_stage, COUNT(id) as pcs, COALESCE(SUM(COALESCE(current_weight, weight)), 0) as weight FROM packets WHERE status = 'in_process' OR diamond_status IN ('available', 'assigned_to_jangad', 'in_process', 'partially_returned') GROUP BY current_stage")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rawStmts as $r) {
        $stKey = (string)($r['current_stage'] ?? '');
        $mapped = $stageNorm[$stKey] ?? 'galaxy';
        if (isset($stStats[$mapped])) {
            $stStats[$mapped]['pcs'] += (int)$r['pcs'];
            $stStats[$mapped]['weight'] += (float)$r['weight'];
        }
    }

    $jwDetailSql = "SELECT p.*, COALESCE(p.current_weight, p.weight) as cur_wt, k.kapan_code, k.kapan_name, pt.party_name 
                    FROM packets p 
                    LEFT JOIN kapans k ON p.kapan_id = k.id 
                    LEFT JOIN parties pt ON p.assigned_party_id = pt.id 
                    WHERE (p.status = 'in_process' OR p.diamond_status IN ('available', 'assigned_to_jangad', 'in_process', 'partially_returned'))";
    if (!empty($search)) {
        $jwDetailSql .= " AND (p.packet_no LIKE " . $pdo->quote("%$search%") . " OR p.diamond_no LIKE " . $pdo->quote("%$search%") . " OR p.barcode LIKE " . $pdo->quote("%$search%") . " OR k.kapan_name LIKE " . $pdo->quote("%$search%") . ")";
    }
    $jwDetailSql .= " ORDER BY p.id DESC LIMIT 200";
    $jwDiamonds = $pdo->query($jwDetailSql)->fetchAll(PDO::FETCH_ASSOC);

    $jwKapans = [];
    foreach ($jwDiamonds as $pkt) {
        $kId = (int)($pkt['kapan_id'] ?? 0);
        $kName = !empty($pkt['kapan_name']) ? $pkt['kapan_name'] : 'અન્ય / કાપન વગરના હીરા';
        $kCode = !empty($pkt['kapan_code']) ? $pkt['kapan_code'] : 'NO-KAPAN';
        if (!isset($jwKapans[$kId])) {
            $jwKapans[$kId] = ['kapan_id' => $kId, 'kapan_name' => $kName, 'kapan_code' => $kCode, 'total_pcs' => 0, 'total_weight' => 0.000, 'packets' => []];
        }
        $pWt = (float)($pkt['cur_wt'] ?? $pkt['weight']);
        $jwKapans[$kId]['total_pcs']++;
        $jwKapans[$kId]['total_weight'] += $pWt;
        $jwKapans[$kId]['packets'][] = $pkt;
    }
    ?>

    <div class="d-flex align-items-center justify-content-between mb-2 px-1">
        <span class="fw-bold text-dark font-mono d-flex align-items-center gap-2" style="font-size: 0.84rem;">
            <?= svg_icon('chart-simple', 'text-primary', 15) ?>
            <span>સ્ટેજ વાઇઝ જોબવર્ક સ્ટોક</span>
        </span>
    </div>
    <?php
    $allJwStages = ['galaxy', 'planner', 'sarin', 'laser', 'blocking', 'polishing', 'final_checking', 'boil', 'polish_return'];
    ?>
    <!-- Single Line: All 9 Process Stages -->
    <div class="d-flex gap-2 mb-3 pb-1 flex-nowrap overflow-x-auto align-items-stretch" style="scrollbar-width: thin; -webkit-overflow-scrolling: touch;">
        <?php foreach ($allJwStages as $code): 
            if (!isset($stStats[$code])) continue;
            $st = $stStats[$code];
            $d = $st['def'];
            $pct = $totJwWeight > 0 ? round(($st['weight'] / $totJwWeight) * 100, 1) : 0;
        ?>
            <div style="flex: 1 1 0; min-width: 100px;">
                <a href="<?= BASE_URL ?>/modules/process/jobwork_stock.php?stage=<?= urlencode($code) ?>" class="text-decoration-none d-block h-100">
                    <div class="p-2 rounded-3 border bg-white h-100 transition-all <?= ($code === 'polish_return') ? 'border-primary-subtle' : '' ?>" style="border-left: 3px solid <?= $d['color'] ?> !important;">
                        <div class="d-flex align-items-center justify-content-between mb-1 gap-1">
                            <div class="d-flex align-items-center gap-1 text-truncate">
                                <div class="rounded-2 d-flex align-items-center justify-content-center"
                                     style="width: 24px; height: 24px; background: <?= $d['bg'] ?>; color: <?= $d['color'] ?>; flex-shrink: 0;">
                                    <?= svg_icon($d['icon'], '', 12) ?>
                                </div>
                                <strong class="text-dark text-truncate" style="font-size: 0.76rem;"><?= e($d['name']) ?></strong>
                            </div>
                            <span class="finora-pill font-mono px-1 py-0" style="background: <?= $d['bg'] ?>; color: <?= $d['color'] ?>; font-size: 0.65rem;"><?= $pct ?>%</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline pt-1 border-top border-light-subtle">
                            <span class="text-muted font-mono" style="font-size: 0.70rem;"><?= $st['pcs'] ?> Pcs</span>
                            <strong class="font-mono fw-bold" style="color: <?= $d['color'] ?> !important; font-size: 0.88rem;"><?= format_carat($st['weight']) ?></strong>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="finora-card mb-4">
        <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                <?= svg_icon('gem', 'text-primary', 16) ?>
                <span>જોબવર્ક કાપન લિસ્ટ (<?= count($jwKapans) ?> &bull; <?= format_carat($totJwWeight) ?>)</span>
            </h6>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm rounded-pill bg-white border text-secondary" onclick="toggleAllStockKapans(true)" style="font-size: 0.75rem;">
                    <?= svg_icon('angles-down', 'me-1', 12) ?>બધા ખોલો
                </button>
                <button type="button" class="btn btn-sm rounded-pill bg-white border text-secondary" onclick="toggleAllStockKapans(false)" style="font-size: 0.75rem;">
                    <?= svg_icon('angles-up', 'me-1', 12) ?>બધા બંધ કરો
                </button>
                <form method="GET" action="" class="d-flex gap-2 mb-0">
                    <input type="hidden" name="tab" value="jobwork">
                    <div class="input-group input-group-sm" style="width: 210px;">
                        <span class="input-group-text bg-white"><?= svg_icon('magnifying-glass', 'text-muted', 13) ?></span>
                        <input type="text" name="search" class="form-control" placeholder="કાપન / હીરા નં..." value="<?= e($search) ?>">
                    </div>
                    <?php if (!empty($search)): ?>
                        <a href="<?= BASE_URL ?>/modules/stock/index.php?tab=jobwork" class="btn btn-sm btn-outline-danger rounded-pill"><?= svg_icon('xmark', '', 13) ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="finora-card-body p-3" style="background: #F8FAFC;">
            <?php if (empty($jwKapans)): ?>
                <div class="text-center py-4 text-muted bg-white rounded-4 border">
                    <div class="mb-2 opacity-50"><?= svg_icon('box-open', '', 26) ?></div>
                    <div>કોઈ જોબવર્ક કાપન કે હીરા મળ્યા નથી.</div>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-2.5">
                    <?php 
                    $jwCounter = 0;
                    foreach ($jwKapans as $kId => $kData): 
                        $jwCounter++;
                        $collapseId = 'stock_jw_kapan_' . $kId;
                        $avgWt = $kData['total_pcs'] > 0 ? ($kData['total_weight'] / $kData['total_pcs']) : 0;
                        $isExpanded = ($jwCounter === 1 || !empty($search));
                    ?>
                        <div class="rounded-4 border overflow-hidden bg-white">
                            <div class="p-3 d-flex flex-wrap justify-content-between align-items-center cursor-pointer transition-all stock-kapan-header-bar <?= $isExpanded ? '' : 'collapsed' ?>"
                                 role="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                                 style="background: #ffffff; border-left: 4px solid #3B82F6; cursor: pointer;">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; background: #EFF6FF; color: #2563EB; flex-shrink: 0;">
                                        <?= svg_icon('boxes-stacked', '', 16) ?>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2">
                                            <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.92rem;"><?= e($kData['kapan_name']) ?></h6>
                                            <span class="finora-pill finora-pill-slate font-mono"><?= e($kData['kapan_code']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3 mt-2 mt-md-0">
                                    <div class="text-end"><small class="text-muted d-block" style="font-size: 0.68rem;">કુલ નંગ</small><strong class="font-mono text-dark" style="font-size: 0.92rem;"><?= number_format($kData['total_pcs']) ?> Pcs</strong></div>
                                    <div class="text-end"><small class="text-muted d-block" style="font-size: 0.68rem;">કુલ વજન (Ct)</small><strong class="font-mono fw-bold text-primary" style="font-size: 1.15rem;"><?= format_carat($kData['total_weight']) ?></strong></div>
                                    <div class="text-end d-none d-sm-block"><small class="text-muted d-block" style="font-size: 0.68rem;">સરેરાશ વજન</small><span class="font-mono text-secondary" style="font-size: 0.85rem;"><?= format_carat($avgWt, false) ?> ct/pc</span></div>
                                    <div class="btn btn-sm bg-primary-subtle text-primary rounded-pill px-3 py-1 font-mono d-flex align-items-center gap-1.5" style="font-size: 0.76rem;">
                                        <span>પેકેટ્સ (<?= $kData['total_pcs'] ?>)</span>
                                        <?= svg_icon('chevron-down', 'stock-kapan-chevron transition-all', 12) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="collapse <?= $isExpanded ? 'show' : '' ?> stock-kapan-collapse" id="<?= $collapseId ?>">
                                <div class="border-top p-0 bg-white">
                                    <div class="table-responsive">
                                        <table class="table table-diamond align-middle mb-0 font-sm">
                                            <thead>
                                                <tr>
                                                    <th class="ps-4" style="width: 45px;">#</th>
                                                    <th>પેકેટ / ડાયમંડ નં.</th>
                                                    <th>બારકોડ</th>
                                                    <th>જોબ વર્ક સ્ટેજ</th>
                                                    <th class="text-end">હાજર વજન (Ct)</th>
                                                    <th>સ્થળ / પાર્ટી</th>
                                                    <th class="text-end pe-4">એક્શન</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($kData['packets'] as $pIdx => $pkt): 
                                                    $mSt = $stageNorm[$pkt['current_stage'] ?? ''] ?? 'galaxy';
                                                    $def = $stageDefs[$mSt] ?? ['name' => $pkt['current_stage'], 'color' => '#2563EB', 'bg' => '#EFF6FF'];
                                                ?>
                                                    <tr>
                                                        <td class="ps-4 text-muted font-mono" style="font-size: 0.75rem;"><?= $pIdx + 1 ?></td>
                                                        <td class="font-mono fw-bold text-dark">
                                                            <a href="javascript:void(0)" onclick="openDiamondHistoryModal(<?= (int)$pkt['id'] ?>)" class="text-dark text-decoration-none">
                                                                <?= svg_icon('gem', 'text-primary me-1', 12) ?><?= e($pkt['packet_no'] ?: ($pkt['diamond_no'] ?: '#' . $pkt['id'])) ?>
                                                            </a>
                                                        </td>
                                                        <td><span class="font-mono text-secondary small"><?= svg_icon('barcode', 'text-muted me-1', 12) ?><?= e($pkt['barcode']) ?></span></td>
                                                        <td><span class="finora-pill font-mono" style="background: <?= $def['bg'] ?>; color: <?= $def['color'] ?>;"><?= e($def['name']) ?></span></td>
                                                        <td class="text-end font-mono fw-bold text-primary"><?= format_carat((float)$pkt['cur_wt']) ?></td>
                                                        <td>
                                                            <?php if (!empty($pkt['party_name'])): ?><span class="fw-semibold text-dark"><?= e($pkt['party_name']) ?></span> &bull; <?php endif; ?>
                                                            <small class="text-muted"><?= e($pkt['current_location'] ?: 'Surat') ?></small>
                                                        </td>
                                                        <td class="text-end pe-4 text-nowrap">
                                                            <div class="d-inline-flex align-items-center gap-1.5">
                                                                <button type="button" class="btn btn-sm rounded-pill px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1 shadow-none bg-primary-subtle text-primary border-0" style="font-size: 0.76rem;"
                                                                        onclick='openQuickStageModal(<?= json_encode(["id" => (int)$pkt["id"], "packet_no" => $pkt["packet_no"] ?: ($pkt["diamond_no"] ?: "#" . $pkt["id"]), "barcode" => $pkt["barcode"] ?? "", "weight" => (float)($pkt["cur_wt"] ?? $pkt["weight"]), "stage" => $pkt["current_stage"], "location" => $pkt["current_location"] ?: "Surat", "party_id" => (int)($pkt["assigned_party_id"] ?? 0)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                                    <?= svg_icon('arrows-split-up-and-left', '', 12) ?><span>સ્ટેજ અપડેટ</span>
                                                                </button>
                                                                <button type="button" class="btn btn-sm rounded-pill px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1 shadow-none bg-light text-secondary border" style="font-size: 0.76rem;" onclick="openDiamondHistoryModal(<?= (int)$pkt['id'] ?>)">
                                                                    <?= svg_icon('clock-rotate-left', '', 12) ?><span>હિસ્ટ્રી</span>
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

<?php elseif ($tab === 'polish'): ?>
    <!-- ================= TAB 2: POLISH STOCK ================= -->
    <?php
    $plSql = "SELECT * FROM polish_stocks WHERE status IN ('available', 'partially_on_jangad')";
    if (!empty($search)) {
        $plSql .= " AND (packet_no LIKE " . $pdo->quote("%$search%") . " OR shape LIKE " . $pdo->quote("%$search%") . " OR gia_number LIKE " . $pdo->quote("%$search%") . ")";
    }
    $plSql .= " ORDER BY id DESC LIMIT 150";
    $polishStocks = $pdo->query($plSql)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                <?= svg_icon('gem', 'text-primary', 16) ?>
                <span>પોલિશ હાજર સ્ટોક (<?= count($polishStocks) ?>)</span>
            </h6>
            <a href="<?= BASE_URL ?>/modules/polish_stock/create.php" class="btn btn-primary btn-sm rounded-pill px-3">
                <?= svg_icon('plus', 'me-1', 13) ?>નવો સ્ટોક
            </a>
        </div>
        <div class="finora-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond align-middle mb-0 font-sm">
                    <thead>
                        <tr>
                            <th class="ps-3">પેકેટ નં.</th>
                            <th>સ્ટોક પ્રકાર</th>
                            <th>શેપ (Shape)</th>
                            <th class="text-end">હાજર નંગ</th>
                            <th class="text-end">હાજર વજન (Ct)</th>
                            <th>કલર / ક્લેરિટી</th>
                            <th>સ્થળ / Vault</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($polishStocks)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">કોઈ પોલિશ સ્ટોક મળ્યો નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($polishStocks as $stk): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold"><?= e($stk['packet_no']) ?></td>
                                    <td><span class="finora-pill finora-pill-slate"><?= e($stk['stock_type']) ?></span></td>
                                    <td><?= e($stk['shape'] ?? 'Round') ?></td>
                                    <td class="text-end font-mono"><?= (int)$stk['available_pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-primary"><?= format_carat((float)$stk['available_weight']) ?></td>
                                    <td><?= e($stk['color'] ?? '—') ?> / <?= e($stk['clarity'] ?? '—') ?></td>
                                    <td><small class="text-muted"><?= svg_icon('location-dot', 'text-danger me-1', 11) ?><?= e($stk['current_location'] ?? 'Surat') ?></small></td>
                                    <td class="text-end pe-3">
                                        <a href="<?= BASE_URL ?>/modules/polish_stock/index.php" class="btn btn-sm rounded-pill btn-outline-primary" style="font-size: 0.75rem; padding: 2px 10px;">
                                            <?= svg_icon('eye', 'me-1', 12) ?>વિગત
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

<?php elseif ($tab === 'gia'): ?>
    <!-- ================= TAB 3: GIA LAB STOCK ================= -->
    <?php
    $giaSql = "SELECT * FROM polish_stocks WHERE (gia_number IS NOT NULL AND gia_number != '') OR stock_type = 'gia_single' ORDER BY id DESC LIMIT 150";
    $giaStocks = $pdo->query($giaSql)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                <?= svg_icon('certificate', 'text-primary', 16) ?>
                <span>GIA લેબ સ્ટોક (<?= count($giaStocks) ?>)</span>
            </h6>
            <a href="<?= BASE_URL ?>/modules/polish_stock/gia.php" class="btn btn-primary btn-sm rounded-pill px-3">
                <?= svg_icon('arrow-right', 'me-1', 13) ?>GIA મોડ્યુલ
            </a>
        </div>
        <div class="finora-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond align-middle mb-0 font-sm">
                    <thead>
                        <tr>
                            <th class="ps-3">પેકેટ નં.</th>
                            <th>GIA રિપોર્ટ નં.</th>
                            <th>શેપ</th>
                            <th class="text-end">વજન (Ct)</th>
                            <th>કલર</th>
                            <th>ક્લેરિટી</th>
                            <th>કટ / પોલિશ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($giaStocks)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">કોઈ GIA પ્રમાણિત સ્ટોક મળ્યો નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($giaStocks as $g): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold"><?= e($g['packet_no']) ?></td>
                                    <td><span class="finora-pill finora-pill-blue font-mono">GIA: <?= e($g['gia_number']) ?></span></td>
                                    <td><?= e($g['shape']) ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$g['available_weight']) ?></td>
                                    <td><?= e($g['color']) ?></td>
                                    <td><?= e($g['clarity']) ?></td>
                                    <td><?= e($g['cut_grade'] ?? 'EX') ?></td>
                                    <td class="text-end pe-3">
                                        <a href="<?= BASE_URL ?>/modules/polish_stock/gia.php" class="btn btn-sm rounded-pill btn-outline-primary" style="font-size: 0.75rem; padding: 2px 10px;">
                                            <?= svg_icon('certificate', 'me-1', 12) ?>સર્ટિફિકેટ
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

<?php else: ?>
    <!-- ================= TAB 4: TOPS RESIDUAL STOCK ================= -->
    <?php
    $topsSql = "SELECT tr.*, k.kapan_name, p.packet_no 
                FROM tops_records tr 
                LEFT JOIN kapans k ON tr.kapan_id = k.id 
                LEFT JOIN packets p ON tr.packet_id = p.id 
                ORDER BY tr.id DESC LIMIT 150";
    $topsList = $pdo->query($topsSql)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                <?= svg_icon('recycle', 'text-secondary', 16) ?>
                <span>Tops રદિયો સ્ટોક (<?= count($topsList) ?>)</span>
            </h6>
            <a href="<?= BASE_URL ?>/modules/tops/create.php" class="btn btn-primary btn-sm rounded-pill px-3">
                <?= svg_icon('plus', 'me-1', 13) ?>નવું Tops
            </a>
        </div>
        <div class="finora-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond align-middle mb-0 font-sm">
                    <thead>
                        <tr>
                            <th class="ps-3">#</th>
                            <th>કાપન (Kapan)</th>
                            <th>મૂળ પેકેટ / હીરા નં.</th>
                            <th class="text-end">Tops વજન (Ct)</th>
                            <th>કારણ / પ્રોસેસ</th>
                            <th>તારીખ</th>
                            <th class="text-end pe-3">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topsList)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">કોઈ Tops સ્ટોક રેકોર્ડ મળ્યો નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($topsList as $idx => $t): ?>
                                <tr>
                                    <td class="ps-3 text-muted font-mono"><?= $idx + 1 ?></td>
                                    <td class="fw-semibold text-dark"><?= e($t['kapan_name'] ?? '—') ?></td>
                                    <td class="font-mono"><?= e($t['packet_no'] ?? '—') ?></td>
                                    <td class="text-end font-mono fw-bold text-danger"><?= format_carat((float)$t['weight']) ?></td>
                                    <td><span class="finora-pill finora-pill-slate"><?= e($t['source_stage'] ?: ($t['notes'] ?: 'Loss Recovery')) ?></span></td>
                                    <td><?= format_date($t['created_at']) ?></td>
                                    <td class="text-end pe-3">
                                        <a href="<?= BASE_URL ?>/modules/tops/index.php" class="btn btn-sm rounded-pill btn-outline-secondary" style="font-size: 0.75rem; padding: 2px 10px;">
                                            વિગત &rarr;
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
<?php endif; ?>

<script>
// Helper: Toggle expand / collapse for all Kapan sections in Stock
function toggleAllStockKapans(expand) {
    document.querySelectorAll('.stock-kapan-collapse').forEach(function(el) {
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
.stock-kapan-header-bar:hover {
    background: #f8fafc !important;
}
.stock-kapan-header-bar:not(.collapsed) .stock-kapan-chevron {
    transform: rotate(180deg);
}
.stock-kapan-header-bar[aria-expanded="true"] .stock-kapan-chevron {
    transform: rotate(180deg);
}
.stock-kapan-chevron {
    transition: transform 0.25s ease;
}
</style>

<!-- Quick Stage & Factory Transfer Modal -->
<?php require_once __DIR__ . '/../process/_quick_stage_modal.php'; ?>
<!-- Diamond History & Multi-Stage Journey Modal -->
<?php require_once __DIR__ . '/../process/_diamond_history_modal.php'; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
