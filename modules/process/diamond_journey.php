<?php
/**
 * Diamond Journey & Full Multi-Stage Audit Report (ડાયમંડ સ્ટેજ-ટુ-સ્ટેજ મુસાફરી અને ઓડિટ હિસાબ)
 * Diamond ERP (Section 6.4 & Advanced Traceability Architecture)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$user = current_user();
$pdo = getDbConnection();

$packetId = (int)($_GET['id'] ?? $_GET['packet_id'] ?? 0);
$diamondNoQuery = trim($_GET['diamond_no'] ?? '');

if ($packetId <= 0 && !empty($diamondNoQuery)) {
    $stFind = $pdo->prepare("SELECT id FROM packets WHERE diamond_no = ? OR packet_no = ? OR barcode = ? LIMIT 1");
    $stFind->execute([$diamondNoQuery, $diamondNoQuery, $diamondNoQuery]);
    $packetId = (int)($stFind->fetchColumn() ?: 0);
}

if ($packetId <= 0) {
    set_flash('error', 'કૃપા કરીને માન્ય ડાયમંડ સિલેક્ટ કરો.');
    header('Location: ' . BASE_URL . '/modules/process/index.php');
    exit;
}

// Fetch complete Diamond Record
$dStmt = $pdo->prepare("
    SELECT p.*, k.kapan_code, k.kapan_name, k.rough_cost as kapan_rough_cost
    FROM packets p
    JOIN kapans k ON p.kapan_id = k.id
    WHERE p.id = ?
");
$dStmt->execute([$packetId]);
$diamond = $dStmt->fetch(PDO::FETCH_ASSOC);

if (!$diamond) {
    set_flash('error', 'ડાયમંડ રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/process/index.php');
    exit;
}

$diamondNo = !empty($diamond['diamond_no']) ? $diamond['diamond_no'] : $diamond['packet_no'];

// Fetch Journey History
$journeyHistory = get_diamond_journey_report($packetId);

// Fetch Split Children if any
$cStmt = $pdo->prepare("
    SELECT id, diamond_no, packet_no, barcode, weight, current_weight, current_stage, status, current_location
    FROM packets
    WHERE parent_diamond_id = ?
    ORDER BY id ASC
");
$cStmt->execute([$packetId]);
$childDiamonds = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals from Journey History
$totalLoss = 0.0;
$totalTops = 0.0;
$totalOther = 0.0;
$totalStagesCompleted = 0;

foreach ($journeyHistory as $jh) {
    $totalLoss += (float)($jh['loss_weight'] ?? 0.0);
    $totalTops += (float)($jh['tops_weight'] ?? 0.0);
    $totalOther += (float)($jh['other_weight'] ?? 0.0);
    if ($jh['status'] === 'completed') {
        $totalStagesCompleted++;
    }
}

$currWeight = ((float)$diamond['current_weight'] > 0) ? (float)$diamond['current_weight'] : (float)$diamond['weight'];
$origWeight = (float)$diamond['original_weight'] > 0 ? (float)$diamond['original_weight'] : (float)$diamond['weight'];

$pageTitle = "ડાયમંડ જર્ની રિપોર્ટ: {$diamondNo}";
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Header & Navigation -->
<?= render_page_header(
    'ડાયમંડ જર્ની: ' . e($diamondNo),
    '',
    'gem',
    '
    <a href="' . BASE_URL . '/modules/process/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">' . svg_icon('arrow-left', 'me-1', 13) . 'પાછા જાઓ</a>
    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="window.print()">' . svg_icon('print', 'me-1', 13) . 'પ્રિન્ટ</button>
    <a href="' . BASE_URL . '/modules/process/index.php?kapan_id=' . $diamond['kapan_id'] . '" class="btn btn-primary btn-sm rounded-pill px-3">' . svg_icon('boxes-stacked', 'me-1', 13) . 'પ્રોસેસ લિસ્ટ</a>
    '
) ?>

<!-- ================= TIER 1: DIAMOND PROFILE & METRICS ================= -->
<div class="finora-card mb-4 p-4">
    <div class="row g-3 align-items-center mb-3 pb-3 border-bottom">
        <div class="col-12 col-md-6">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: #EFF6FF; color: #2563EB; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <?= svg_icon('gem', '', 24) ?>
                </div>
                <div>
                    <h5 class="fw-bold text-dark mb-1 font-mono"><?= e($diamondNo) ?></h5>
                    <div class="d-flex flex-wrap gap-2 align-items-center small text-muted font-mono">
                        <span>Barcode: <strong><?= e($diamond['barcode'] ?: $diamond['packet_no']) ?></strong></span>
                        <span>&bull; Kapan: <strong><?= e($diamond['kapan_code']) ?></strong></span>
                        <span>&bull; Assort: <?= e($diamond['assort_number'] ?: 'Direct') ?></span>
                        <span class="finora-pill finora-pill-blue"><?= e(ucfirst($diamond['current_stage'])) ?></span>
                        <?php if (!empty($diamond['is_reassorted']) && !empty($diamond['reassort_lot_no'])): ?>
                            <span class="finora-pill finora-pill-amber">
                                Re-Assorted &rarr; Lot <?= e($diamond['reassort_lot_no']) ?>
                                <?php if (!empty($diamond['reassort_task_id'])): ?>
                                    (<a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$diamond['reassort_task_id'] ?>" class="text-dark fw-bold">જુઓ</a>)
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($diamond['origin_reassort_lot_no'])): ?>
                            <span class="finora-pill finora-pill-slate">
                                પૂર્વ Boil Re-Assort Lot: <?= e($diamond['origin_reassort_lot_no']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 text-md-end">
            <span class="text-muted small d-block mb-1">હાલનું લોકેશન</span>
            <span class="finora-pill finora-pill-blue fs-6 px-3 py-1 font-mono">
                <?= svg_icon('location-dot', 'me-1', 14) ?><?= e($diamond['current_location'] ?: 'Surat Stock') ?>
            </span>
        </div>
    </div>

    <!-- 4 Carat Balance Metric Chips -->
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <?= render_stat_card('મૂળ વજન (Original Wt)', format_carat($origWeight), '1 PC', '', 'blue', null, '', 'gem') ?>
        </div>
        <div class="col-6 col-md-3">
            <?= render_stat_card('હાલનું વજન (Current Wt)', format_carat($currWeight), 'Available', '', 'green', null, '', 'layer-group') ?>
        </div>
        <div class="col-6 col-md-3">
            <?= render_stat_card('કુલ ઘટ (Total Loss)', format_carat($totalLoss), 'Loss', '', 'rose', null, '', 'triangle-exclamation') ?>
        </div>
        <div class="col-6 col-md-3">
            <?= render_stat_card('કુલ રદિયો (Total Tops)', format_carat($totalTops), 'Tops', '', 'amber', null, '', 'recycle') ?>
        </div>
    </div>
</div>

<!-- ================= TIER 2: AUDIT TRAIL TABLE (STAGE-BY-STAGE) ================= -->
<div class="finora-card mb-4">
    <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
            <?= svg_icon('timeline', 'text-primary', 16) ?>
            <span>સ્ટેજ-ટુ-સ્ટેજ હિસાબ (<?= count($journeyHistory) ?>)</span>
        </h6>
    </div>
    <div class="finora-card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond align-middle mb-0" style="min-width: 900px;">
                <thead>
                    <tr>
                        <th style="width: 5%;" class="text-center">#</th>
                        <th style="width: 15%;">સ્ટેજ નામ (Stage)</th>
                        <th style="width: 12%;" class="text-end">આપેલ વજન</th>
                        <th style="width: 12%;" class="text-end">પરત મળેલ વજન</th>
                        <th style="width: 10%;" class="text-end">તફાવત</th>
                        <th style="width: 13%;">વર્ગીકરણ</th>
                        <th style="width: 15%;">પાર્ટી / વેન્ડર</th>
                        <th style="width: 10%;">તારીખ</th>
                        <th style="width: 8%;" class="text-center">સ્થિતિ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($journeyHistory)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <div class="mb-2 opacity-50"><?= svg_icon('clock', '', 24) ?></div>
                                હજુ સુધી આ ડાયમંડ માટે કોઈ સ્ટેજ હિસ્ટ્રી નોંધાયેલ નથી.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($journeyHistory as $i => $row): 
                            $diff = (float)$row['difference_weight'];
                            $classBadge = '';
                            switch ($row['diff_classification']) {
                                case 'loss':
                                    $classBadge = '<span class="finora-pill finora-pill-rose">ઘટ (Loss)</span>';
                                    break;
                                case 'tops':
                                    $classBadge = '<span class="finora-pill finora-pill-slate">રદિયો (Tops)</span>';
                                    break;
                                case 'other':
                                    $classBadge = '<span class="finora-pill finora-pill-amber">અન્ય</span>';
                                    break;
                                case 'pending':
                                    $classBadge = '<span class="finora-pill finora-pill-amber">બાકી</span>';
                                    break;
                                default:
                                    $classBadge = '<span class="finora-pill finora-pill-slate">-</span>';
                                    break;
                            }
                        ?>
                            <tr>
                                <td class="text-center font-mono text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width: 30px; height: 30px; border-radius: 8px; background: #EFF6FF; color: #2563EB; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <?= svg_icon('stairs', '', 13) ?>
                                        </div>
                                        <div>
                                            <strong class="text-dark font-mono"><?= e($row['stage_name']) ?></strong>
                                            <small class="text-muted d-block font-mono" style="font-size: 0.72rem;"><?= e($row['stage_code']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end font-mono fw-bold text-dark fs-6">
                                    <?= format_carat((float)$row['given_weight']) ?>
                                </td>
                                <td class="text-end font-mono fw-bold text-success fs-6">
                                    <?= ((float)$row['received_weight'] > 0 || $row['status'] === 'completed') ? format_carat((float)$row['received_weight']) : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td class="text-end font-mono fw-bold text-danger">
                                    <?= $diff > 0 ? format_carat($diff) : '0.000 Ct' ?>
                                </td>
                                <td>
                                    <?= $classBadge ?>
                                    <?php if ((float)($row['loss_weight'] ?? 0) > 0 && $row['diff_classification'] !== 'loss'): ?>
                                        <small class="text-muted d-block font-mono">Loss: <?= format_carat((float)$row['loss_weight']) ?></small>
                                    <?php endif; ?>
                                    <?php if ((float)($row['tops_weight'] ?? 0) > 0 && $row['diff_classification'] !== 'tops'): ?>
                                        <small class="text-muted d-block font-mono">Tops: <?= format_carat((float)$row['tops_weight']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['party_name'])): ?>
                                        <div class="fw-bold text-dark"><?= e($row['party_name']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">ઇન-હાઉસ</span>
                                    <?php endif; ?>
                                    <small class="text-muted d-block font-mono" style="font-size: 0.72rem;"><?= e($row['outgoing_location'] ?: 'Surat') ?></small>
                                </td>
                                <td>
                                    <div class="font-mono small text-dark"><?= e($row['assigned_date']) ?></div>
                                    <?php if (!empty($row['return_date'])): ?>
                                        <small class="text-muted font-mono d-block" style="font-size: 0.72rem;">Ret: <?= e($row['return_date']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status'] === 'completed'): ?>
                                        <span class="finora-pill finora-pill-green">પૂર્ણ</span>
                                    <?php else: ?>
                                        <span class="finora-pill finora-pill-amber">ચાલુ</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= TIER 3: CHILD SUB-DIAMONDS (SPLIT TREE) ================= -->
<?php if (!empty($childDiamonds)): ?>
    <div class="finora-card mb-4">
        <div class="finora-card-header d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                <?= svg_icon('code-fork', 'text-primary', 16) ?>
                <span>વિભાજિત પેટા હીરા (<?= count($childDiamonds) ?>)</span>
            </h6>
        </div>
        <div class="finora-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sub Diamond Number</th>
                            <th>Barcode</th>
                            <th class="text-end">Current Weight (Ct)</th>
                            <th>Current Stage</th>
                            <th>Location</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($childDiamonds as $cd): ?>
                            <tr>
                                <td>
                                    <strong class="font-mono text-dark"><?= e($cd['diamond_no']) ?></strong>
                                </td>
                                <td><span class="font-mono text-secondary small"><?= e($cd['barcode'] ?? $cd['packet_no'] ?? '-') ?></span></td>
                                <td class="text-end font-mono fw-bold text-primary fs-6"><?= format_carat((float)$cd['current_weight']) ?></td>
                                <td><span class="finora-pill finora-pill-blue"><?= e(ucfirst($cd['current_stage'])) ?></span></td>
                                <td><small class="text-muted"><?= e($cd['current_location'] ?: 'Surat Stock') ?></small></td>
                                <td class="text-center">
                                    <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= $cd['id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill px-2.5 py-1">
                                        <?= svg_icon('timeline', 'me-1', 12) ?>મુસાફરી હિસાબ
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
