<?php
/**
 * Diamond Costing Engine (સેક્શન ૬.૭ ખર્ચ અને હિસાબ)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Costing & Yield % હિસાબ (Diamond Costing)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');

$sql = "SELECT k.*, rp.purchase_no, rp.price_per_carat_inr as rough_rate
        FROM kapans k
        LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (k.kapan_code LIKE ? OR k.kapan_name LIKE ? OR rp.purchase_no LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

$sql .= " ORDER BY k.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kapans = $stmt->fetchAll();

$totRoughInput = 0.000;
$totPolishOutput = 0.000;
$totCost = 0.00;
foreach ($kapans as $kp) {
    $totRoughInput += (float)$kp['total_weight'];
    $totPolishOutput += (float)($kp['polish_weight'] ?? 0);
    $totCost += (float)($kp['total_cost'] ?? 0);
}
$avgYield = $totRoughInput > 0 ? (($totPolishOutput / $totRoughInput) * 100) : 0;
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Costing & Yield % એન્જિન (Diamond Costing)',
    '',
    'chart-line',
    '
    <a href="' . BASE_URL . '/modules/reports/export.php?type=costing" class="btn btn-outline-success px-3 shadow-sm fw-semibold">
        ' . svg_icon('file-excel', 'me-1') . 'Excel Costing Export
    </a>
    <button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ Costing રિપોર્ટ
    </button>
    ',
    'purple'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ કાપન ઇનપુટ (Rough Input)', format_carat($totRoughInput), count($kapans) . ' કાપન', 'ઉત્પાદનમાં રફ', 'hero', BASE_URL . '/modules/kapan/index.php', '', 'shapes') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('સરેરાશ યીલ્ડ % (Average Yield)', number_format($avgYield, 2) . '%', 'રફથી પોલિશ ટકાવારી', format_carat($totPolishOutput) . ' તૈયાર', 'white', null, 'pill-mint', 'chart-pie') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ પડતર ખર્ચ (Production Cost)', format_inr($totCost), 'મજૂરી & પ્રોસેસ ખર્ચ', 'લેબર + ગેલેક્સી', 'white', null, 'pill-mint', 'calculator') ?>
    </div>
</div>

<!-- Search Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/costing/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Kapan કોડ, નામ અથવા Rough ખરીદી નંબર શોધો..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary px-3"><?= svg_icon('filter', 'me-1') ?>શોધો</button>
            <?php if (!empty($search)): ?>
                <a href="<?= BASE_URL ?>/modules/costing/index.php" class="btn btn-outline-dark">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>Kapan કોડ / નામ</th>
                        <th>Rough ઇનપુટ (Carat)</th>
                        <th>Rough પડતર</th>
                        <th>પ્રોસેસ ખર્ચ (Galaxy + LS + Labour)</th>
                        <th>કુલ પડતર (Total Cost)</th>
                        <th>Polish આઉટપુટ (Pcs/Ct)</th>
                        <th>Yield %</th>
                        <th>પ્રતિ નંગ ખર્ચ (Per Pc)</th>
                        <th>પ્રતિ કેરેટ ખર્ચ (Per Ct)</th>
                        <th class="text-end table-actions">વિગતવાર</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kapans)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">કોઈ કાપન ડેટા નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($kapans as $k): ?>
                            <?php
                            $processCost = (float)$k['galaxy_cost'] + (float)$k['ls_cost'] + (float)$k['labour_cost'] + (float)$k['other_cost'];
                            $totCost = (float)$k['rough_cost'] + $processCost;

                            $polWt = (float)$k['total_polish_weight'];
                            $polPcs = (int)$k['total_polish_pcs'];
                            $roughWt = (float)$k['total_weight'];

                            $yield = ($roughWt > 0 && $polWt > 0) ? round(($polWt / $roughWt) * 100, 2) : (float)$k['yield_percentage'];
                            $perPc = ($polPcs > 0) ? round($totCost / $polPcs, 2) : 0;
                            $perCt = ($polWt > 0) ? round($totCost / $polWt, 2) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong class="text-dark font-mono"><?= e($k['kapan_name']) ?></strong>
                                    <div class="small text-muted font-mono" style="font-size: 0.8125rem;"><?= e($k['kapan_code']) ?></div>
                                </td>
                                <td>
                                    <strong class="font-mono"><?= format_carat($roughWt) ?></strong>
                                    <small class="text-muted d-block" style="font-size: 0.8125rem;"><?= (int)$k['total_pcs'] ?> Pcs</small>
                                </td>
                                <td class="font-mono"><?= format_inr($k['rough_cost']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark font-mono"><?= format_inr($processCost) ?></div>
                                    <small class="text-muted" style="font-size: 0.8125rem;">G:<?= (int)$k['galaxy_cost'] ?> • LS:<?= (int)$k['ls_cost'] ?> • L:<?= (int)$k['labour_cost'] ?></small>
                                </td>
                                <td><strong class="text-dark font-mono"><?= format_inr($totCost) ?></strong></td>
                                <td>
                                    <?php if ($polWt > 0): ?>
                                        <strong class="text-success font-mono"><?= format_carat($polWt) ?></strong>
                                        <div class="small text-muted" style="font-size: 0.8125rem;"><?= $polPcs ?> Pcs</div>
                                    <?php else: ?>
                                        <span class="text-muted small">પ્રોસેસમાં</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($yield > 0): ?>
                                        <span class="badge bg-success-subtle text-success border fw-bold"><?= $yield ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-mono">
                                    <?php if ($perPc > 0): ?>
                                        <strong class="text-primary"><?= format_inr($perPc) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-mono">
                                    <?php if ($perCt > 0): ?>
                                        <strong class="text-success"><?= format_inr($perCt) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end table-actions">
                                    <a href="<?= BASE_URL ?>/modules/costing/view_kapan_costing.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="કાપન પડતર વિશ્લેષણ">
                                        <?= svg_icon('chart-pie') ?><span class="d-none d-xxl-inline ms-1">Costing</span>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
