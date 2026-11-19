<?php
/**
 * Detailed Kapan Costing & Shape Distribution (સેક્શન ૬.૭)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Kapan Costing & Shape વિશ્લેષણ';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$id = (int)($_GET['id'] ?? $_GET['kapan_id'] ?? 0);

$stmt = $pdo->prepare("SELECT k.*, rp.purchase_no, rp.purchase_date, p.party_name
                       FROM kapans k
                       LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
                       LEFT JOIN parties p ON rp.party_id = p.id
                       WHERE k.id = ?");
$stmt->execute([$id]);
$kapan = $stmt->fetch();

if (!$kapan) {
    set_flash('error', 'Kapan રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/costing/index.php');
    exit;
}

// Shape-wise breakdown of packets under this Kapan
$shapeStmt = $pdo->prepare("SELECT 
    COALESCE(expected_shape, 'Unassigned') as shape,
    COUNT(*) as total_pcs,
    COALESCE(SUM(weight), 0) as rough_weight,
    COALESCE(SUM(polish_weight), 0) as polish_weight
FROM packets
WHERE kapan_id = ?
GROUP BY expected_shape
ORDER BY total_pcs DESC");
$shapeStmt->execute([$id]);
$shapes = $shapeStmt->fetchAll();

// Tops recovery value from this Kapan
$topsStmt = $pdo->prepare("SELECT COALESCE(SUM(total_value), 0) FROM tops_records WHERE kapan_id = ?");
$topsStmt->execute([$id]);
$topsRecoveryValue = (float)$topsStmt->fetchColumn();

// Cost calculations
$processCost = (float)$kapan['galaxy_cost'] + (float)$kapan['ls_cost'] + (float)$kapan['labour_cost'] + (float)$kapan['other_cost'];
$grossCost = (float)$kapan['rough_cost'] + $processCost;
$netCost = max(0, $grossCost - $topsRecoveryValue);

$roughWt = (float)$kapan['total_weight'];
$polWt   = (float)$kapan['total_polish_weight'];
$polPcs  = (int)$kapan['total_polish_pcs'];
$topsWt  = (float)$kapan['total_tops_weight'];

$yield = ($roughWt > 0 && $polWt > 0) ? round(($polWt / $roughWt) * 100, 2) : (float)$kapan['yield_percentage'];
$perPc = ($polPcs > 0) ? round($netCost / $polPcs, 2) : 0;
$perCt = ($polWt > 0) ? round($netCost / $polWt, 2) : 0;
?>

<?= render_page_header(
    $kapan['kapan_name'] . ' (' . $kapan['kapan_code'] . ') Costing & Shape વિશ્લેષણ',
    '',
    'calculator',
    '<button onclick="window.print()" class="btn btn-dark px-3 shadow-sm me-2">' . svg_icon('print', 'me-1') . 'પ્રિન્ટ રિપોર્ટ</button><a href="' . BASE_URL . '/modules/costing/index.php" class="btn btn-outline-secondary px-3">' . svg_icon('arrow-left', 'me-1') . 'Costing યાદી</a>',
    'purple'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-3">
        <?= render_stat_card('ચોખ્ખો પડતર ખર્ચ (Net Cost)', format_inr($netCost), 'Gross - Tops ઉપજ', 'ચોખ્ખી પડતર', 'hero', null, '', 'calculator') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('Yield % (ઉત્પાદન ટકા)', $yield . '%', 'Rough &rarr; Polish', 'રિકવરી ગુણોત્તર', 'white', null, 'pill-mint', 'chart-line') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('પ્રતિ નંગ ખર્ચ (Per Pc)', format_inr($perPc), 'Polish હીરા દીઠ', 'નંગ દીઠ પડતર', 'white', null, 'pill-mint', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('પ્રતિ Carat ખર્ચ (Per Ct)', format_inr($perCt), 'કેરેટ દીઠ સરેરાશ', 'કેરેટ દીઠ ભાવ', 'white', null, 'pill-mint', 'scale-balanced') ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Cost Breakdown Table -->
    <div class="col-12 col-lg-6">
        <div class="erp-form-card h-100">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon icon-purple"><?= svg_icon('receipt') ?></span>
                    વિગતવાર ખર્ચ વિશ્લેષણ (Cost Elements)
                </h6>
            </div>
            <div class="erp-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond table-sm-fit mb-0">
                        <tbody>
                            <tr>
                                <td><strong>Rough ખરીદી પડતર:</strong></td>
                                <td class="text-end fw-bold"><?= format_inr($kapan['rough_cost']) ?></td>
                            </tr>
                            <tr>
                                <td>Galaxy 3D સ્કેનિંગ ખર્ચ:</td>
                                <td class="text-end"><?= format_inr($kapan['galaxy_cost']) ?></td>
                            </tr>
                            <tr>
                                <td>Laser Sawing (LS) ખર્ચ:</td>
                                <td class="text-end"><?= format_inr($kapan['ls_cost']) ?></td>
                            </tr>
                            <tr>
                                <td>કારખાનું ઘસાઈ Labour ખર્ચ:</td>
                                <td class="text-end"><?= format_inr($kapan['labour_cost']) ?></td>
                            </tr>
                            <tr>
                                <td>અન્ય પરચૂરણ / બોઈલ ખર્ચ:</td>
                                <td class="text-end"><?= format_inr($kapan['other_cost']) ?></td>
                            </tr>
                            <tr class="table-light">
                                <td class="fw-bold fs-6">કુલ Kapan પડતર (Gross Cost):</td>
                                <td class="text-end fw-bold fs-6 text-primary"><?= format_inr($grossCost) ?></td>
                            </tr>
                            <tr>
                                <td>Tops (Scrap) રદિયો મૂલ્ય કપાત:</td>
                                <td class="text-end fw-bold text-success">- <?= format_inr($topsRecoveryValue) ?></td>
                            </tr>
                            <tr class="table-success">
                                <td class="fw-bold fs-6">ચોખ્ખી Kapan પડતર (Net Cost):</td>
                                <td class="text-end fw-bold fs-6 text-success"><?= format_inr($netCost) ?></td>
                            </tr>
                            <tr>
                                <td>Tops (Scrap) રદિયો વજન:</td>
                                <td class="text-end fw-bold text-warning"><?= format_carat($topsWt) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Shape-wise Distribution Table -->
    <div class="col-12 col-lg-6">
        <div class="erp-form-card h-100">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon icon-emerald"><?= svg_icon('shapes') ?></span>
                    Kapan માંથી કયા Shape ના કેટલા હીરા આવ્યા
                </h6>
            </div>
            <div class="erp-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond table-sm-fit mb-0">
                        <thead>
                            <tr>
                                <th>આકાર (Shape)</th>
                                <th>પેકેટ્સ / નંગ</th>
                                <th>Rough વજન</th>
                                <th class="text-end">Polish વજન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shapes)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">કોઈ Shape ડેટા નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($shapes as $sh): ?>
                                    <tr>
                                        <td><strong><?= e($sh['shape']) ?></strong></td>
                                        <td><?= (int)$sh['total_pcs'] ?> Pcs</td>
                                        <td><?= format_carat($sh['rough_weight']) ?></td>
                                        <td class="text-end fw-bold text-success"><?= format_carat($sh['polish_weight']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
