<?php
/**
 * Weekly Boil Batches (સેક્શન ૬.૫ બોઈલ)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Weekly Boil બેચ રજિસ્ટર (સાપ્તાહિક બોઈલ)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_boil_reassort_columns();

$stmt = $pdo->query("SELECT b.*, u.full_name as created_by_name,
                            (SELECT COUNT(*) FROM packets WHERE boil_batch_id = b.id) as packet_count,
                            (SELECT GROUP_CONCAT(lot_no ORDER BY id SEPARATOR ', ') FROM boil_reassort_lots WHERE boil_batch_id = b.id) as reassort_lots_str
                     FROM boil_batches b
                     LEFT JOIN users u ON b.created_by = u.id
                     ORDER BY b.boil_date DESC, b.id DESC");
$batches = $stmt->fetchAll();

$boilCount = count($batches);

$totBoilWt = 0.000;
$totBoilPcs = 0;
$inBoilCount = 0;
foreach ($batches as $b) {
    $totBoilWt += (float)$b['total_weight'];
    $totBoilPcs += (int)$b['total_pcs'];
    if (($b['status'] ?? '') === 'in_boil') $inBoilCount++;
}

$readyBoilCount = (int)$pdo->query("
    SELECT COUNT(*) FROM packets p
    WHERE COALESCE(NULLIF(p.polish_weight, 0), NULLIF(p.current_weight, 0), p.weight) > 0
      AND p.current_stage IN ('factory', 'blocking', 'faceting', 'polishing', 'final_checking', 'polish_return', 'boil', 'polish_stock')
      AND p.boil_batch_id IS NULL
      AND COALESCE(p.is_reassorted, 0) = 0
      AND p.status NOT IN ('sold', 'reassorted')
")->fetchColumn();
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Weekly Boil બેચ રજિસ્ટર (સાપ્તાહિક બોઈલ)',
    '',
    'fire-burner',
    '<a href="' . BASE_URL . '/modules/boil/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવો Weekly Boil બેચ બનાવો</a>',
    'rose'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ બોઈલ વજન (Boiling Carats)', format_carat($totBoilWt), $totBoilPcs . ' Pcs', 'સાપ્તાહિક બોઈલિંગ', 'hero', BASE_URL . '/modules/boil/create.php', '', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ચાલુ બોઈલ બેચ (In Boil)', (string)$inBoilCount . ' બેચ', 'પ્રોસેસિંગ હેઠળ', 'બોઈલિંગ સ્ટેટસ', 'white', null, 'pill-coral', 'fire-burner') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ બેચ સંખ્યા (Total Batches)', (string)$boilCount . ' બેચ', 'સાપ્તાહિક ઇતિહાસ', 'બોઈલ રજિસ્ટર', 'white', null, 'pill-mint', 'circle-check') ?>
    </div>
</div>

<?php if ($readyBoilCount > 0): ?>
<div class="alert alert-warning py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 rounded-3 border">
    <div class="d-flex align-items-center gap-2">
        <span class="fs-5"><?= svg_icon('fire-burner', 'text-warning-emphasis') ?></span>
        <div>
            <strong class="text-dark">બોઈલિંગ / Re-Assort માટે <?= $readyBoilCount ?> હીરા તૈયાર છે!</strong>
            <small class="text-muted d-block">આ હીરાને Boil માં મોકલી અથવા ઓફિસ Return વખતે Re-Assort કરી નવો લોટ પાડી શકાય છે.</small>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/modules/boil/create.php" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm">
        <?= svg_icon('plus', 'me-1') ?>નવો Weekly Boil બેચ બનાવો
    </a>
</div>
<?php endif; ?>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>બેચ નંબર (Batch No)</th>
                        <th>બોઈલ તારીખ (Boil Date)</th>
                        <th>હીરા નંગ (Pcs)</th>
                        <th>કુલ વજન (Polish Carats)</th>
                        <th>પેકેટ્સ / Re-Assort લોટ</th>
                        <th>પૂર્ણ તારીખ</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end">ઓફિસ Return / એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="mb-2 text-warning-emphasis opacity-75"><?= svg_icon('fire-burner', '', 32) ?></div>
                                <h6 class="fw-bold text-dark">હાલમાં કોઈ Boil બેચ બનેલ નથી.</h6>
                                <p class="small text-muted mb-3">હીરા બોઈલિંગમાં મોકલવા અથવા સીધા Re-Assort કરવા માટે નવો બેચ બનાવો.</p>
                                <a href="<?= BASE_URL ?>/modules/boil/create.php" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm">
                                    <?= svg_icon('plus', 'me-1') ?>નવો Weekly Boil બેચ બનાવો
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $b): ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/boil/view.php?id=<?= $b['id'] ?>" class="text-decoration-none fw-bold text-dark">
                                        <?= e($b['batch_no']) ?>
                                    </a>
                                </td>
                                <td><?= svg_icon('calendar', 'me-1') ?><?= format_date($b['boil_date']) ?></td>
                                <td><?= (int)$b['total_pcs'] ?> Pcs</td>
                                <td><strong class="text-success"><?= format_carat($b['total_weight']) ?></strong></td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= (int)$b['packet_count'] ?> પેકેટ</span>
                                    <?php if (!empty($b['reassort_lots_str'])): ?>
                                        <span class="badge bg-primary-subtle text-primary border font-mono d-inline-block mt-1">
                                            Re-Assort: <?= e($b['reassort_lots_str']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= format_date($b['completed_date']) ?></td>
                                <td>
                                    <?php if ($b['status'] === 'completed'): ?>
                                        <span class="badge bg-success-subtle text-success border"><?= svg_icon('check', 'me-1') ?>Boil પૂર્ણ (Completed)</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">Boil ચાલુ છે (ઓફિસ Return બાકી)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/boil/view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                        <?= svg_icon('arrows-rotate', 'me-1') ?>Return / Re-Assort
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
