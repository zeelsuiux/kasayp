<?php
/**
 * View Assort Task & Issue Parts
 * Diamond ERP
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
ensure_boil_reassort_columns();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT at.*, rp.purchase_no, rp.purchase_date, rp.weight as original_purchase_weight, rp.price_per_carat_inr, p.party_name,
                              brl.lot_no as reassort_lot_no, brl.boil_batch_id, brl.source_kapan_codes, brl.lot_rate as lot_master_rate, bb.batch_no as boil_batch_no
                       FROM assort_tasks at
                       LEFT JOIN rough_purchases rp ON at.rough_purchase_id = rp.id
                       LEFT JOIN parties p ON rp.party_id = p.id
                       LEFT JOIN boil_reassort_lots brl ON at.reassort_lot_id = brl.id
                       LEFT JOIN boil_batches bb ON brl.boil_batch_id = bb.id
                       WHERE at.id = ?");
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    set_flash('error', 'Assort ટાસ્ક મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/assort/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_lot_rate') {
    validate_csrf();
    $newLotRate = max(0.0, round((float)($_POST['lot_rate'] ?? 0), 2));
    $rawPartRates = $_POST['part_rates'] ?? [];
    $partRatesById = [];
    foreach ($rawPartRates as $pId => $rVal) {
        if ($rVal !== '' && $rVal !== null) {
            $partRatesById[(int)$pId] = round((float)$rVal, 2);
        }
    }
    $applyAll = isset($_POST['apply_lot_rate_all']);

    try {
        $pdo->beginTransaction();
        $res = update_reassort_lot_rates($id, $newLotRate, $partRatesById, $applyAll);
        $pdo->commit();
        log_activity('Re-Assort Lot Rate Updated', 'Assort', $id, "Lot {$task['lot_no']} rate updated to ₹{$res['lot_rate']}/ct (Total: ₹{$res['total_lot_value']})");
        set_flash('success', "લોટ રેટ સફળતાપૂર્વક અપડેટ થયો — ₹" . number_format($res['lot_rate'], 2) . "/ct (કુલ કિંમત: " . format_inr($res['total_lot_value']) . ").");
        header('Location: ' . BASE_URL . '/modules/assort/view.php?id=' . $id);
        exit;
    } catch (\Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'લોટ રેટ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
}

$pageTitle = 'Assort Task વિગત (Task Details)';
require_once __DIR__ . '/../../includes/header.php';

$reassortOriginItems = [];
if (($task['source_type'] ?? '') === 'boil_reassort' && !empty($task['reassort_lot_id'])) {
    $roStmt = $pdo->prepare("SELECT * FROM boil_reassort_lot_items WHERE reassort_lot_id = ? ORDER BY id ASC");
    $roStmt->execute([(int)$task['reassort_lot_id']]);
    $reassortOriginItems = $roStmt->fetchAll(PDO::FETCH_ASSOC);
}

$partStmt = $pdo->prepare("SELECT ap.*, k.kapan_code
                          FROM assort_parts ap
                          LEFT JOIN kapans k ON ap.id = k.assort_part_id
                          WHERE ap.assort_task_id = ?
                          ORDER BY ap.id ASC");
$partStmt->execute([$id]);
$parts = $partStmt->fetchAll();

$lotRefTitle = (($task['source_type'] ?? '') === 'boil_reassort')
    ? ('Boil Re-Assort લોટ: ' . ($task['lot_no'] ?: $task['reassort_lot_no']) . ' (Boil: ' . ($task['boil_batch_no'] ?? '-') . ' • જૂની કાપન: ' . ($task['source_kapan_codes'] ?? '-') . ')')
    : ('Rough સંદર્ભ: ' . ($task['purchase_no'] ?? '-') . ' (' . ($task['party_name'] ?? '-') . ')');

// Calculations for 2% Loss validation
$inputRoughWeight = (float)$task['total_input_weight'];
$allocatedWeight = (float)$task['total_output_weight'];
$lossRoughWeight = (float)$task['weight_difference'];
$pcsDiff = (int)($task['pcs_difference'] ?? ((int)$task['total_input_pcs'] - (int)$task['total_output_pcs']));
$allowedLossWeight = round($inputRoughWeight * 0.02, 3);
$lossPercentage = ($inputRoughWeight > 0) ? round(($lossRoughWeight / $inputRoughWeight) * 100, 2) : 0.00;
$isLossValid = ($lossRoughWeight >= 0 && $lossRoughWeight <= $allowedLossWeight + 0.0001);
$isPcsValid = ($pcsDiff === 0);
$isOverallValid = $isLossValid && $isPcsValid;

$isReassort = (($task['source_type'] ?? '') === 'boil_reassort');
$purchaseRatePerCt = $isReassort 
    ? (float)($task['lot_rate'] ?? $task['lot_master_rate'] ?? 0)
    : (float)($task['price_per_carat_inr'] ?? 0);
$purchaseTotalAmount = round($purchaseRatePerCt * $inputRoughWeight, 2);
?>

<?= render_page_header(
    'Assort ટાસ્ક વિગત: ' . $task['task_no'],
    $lotRefTitle . ' • તારીખ: ' . format_date($task['assort_date']),
    'layer-group',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/assort/edit.php?id=' . $id . '" class="btn btn-primary px-3 shadow-sm">' . svg_icon('pen-to-square', 'me-1') . 'Assort એડિટ કરો</a>
        <a href="' . BASE_URL . '/modules/assort/index.php" class="btn btn-outline-secondary px-3">' . svg_icon('arrow-left', 'me-1') . 'Assort યાદી</a>
    </div>',
    'purple'
) ?>

<!-- CARD 1: ASSORT TASK BASIC DETAILS (1 SINGLE LINE TEXT - EXACTLY AS IN EDIT) -->
<div class="card-erp mb-3">
    <div class="card-header bg-white py-1.5 px-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark" style="font-size: 0.875rem;">
            <?= svg_icon('file-lines', 'text-primary me-1.5') ?>૧. ટાસ્ક મૂળભૂત વિગતો & રફ લોટ
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark font-mono border"><?= e($task['task_no']) ?></span>
            <a href="<?= BASE_URL ?>/modules/assort/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.8rem;">
                <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
            </a>
        </div>
    </div>
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-nowrap align-items-center justify-content-between gap-3 text-nowrap" style="overflow-x: auto;">
            <!-- 1. Assort No -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-muted mb-0 d-block" style="font-size: 0.78rem;">Assort No</label>
                <span class="fw-bold font-mono text-dark" style="font-size: 0.95rem;"><?= e($task['task_no']) ?></span>
            </div>

            <!-- 2. Date -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-muted mb-0 d-block" style="font-size: 0.78rem;">તારીખ</label>
                <span class="fw-bold font-mono text-dark" style="font-size: 0.95rem;"><?= format_date($task['assort_date']) ?></span>
            </div>

            <!-- 3. Locked Lot Reference -->
            <div style="flex: 1 1 auto; min-width: 180px;">
                <label class="form-label small fw-bold text-muted mb-0 d-block" style="font-size: 0.78rem;">લોટ / Rough સંદર્ભ</label>
                <span class="fw-bold font-mono text-dark text-truncate d-block" style="font-size: 0.92rem;" title="<?= e($lotRefTitle) ?>"><?= e($lotRefTitle) ?></span>
            </div>

            <!-- 4. Pcs -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-dark mb-0 d-block" style="font-size: 0.78rem;">નંગ (Pcs)</label>
                <span class="fw-bold font-mono text-dark fs-6"><?= (int)$task['total_input_pcs'] ?> Pcs</span>
            </div>

            <!-- 5. Weight (Ct) -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-dark mb-0 d-block" style="font-size: 0.78rem;">રફ વજન (Ct)</label>
                <span class="fw-bold font-mono text-primary fs-6"><?= format_carat($task['total_input_weight']) ?></span>
            </div>

            <!-- 6. Rate ₹/ct -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-dark mb-0 d-block" style="font-size: 0.78rem;">ખરીદી ભાવ</label>
                <span class="fw-bold font-mono text-success fs-6">₹ <?= number_format($purchaseRatePerCt, 2) ?><span class="text-muted small">/ct</span></span>
            </div>

            <!-- 7. Total Amount -->
            <div style="flex: 0 0 auto;">
                <label class="form-label small fw-bold text-dark mb-0 d-block" style="font-size: 0.78rem;">કુલ ખરીદી રકમ</label>
                <span class="fw-bold font-mono text-primary fs-6">₹ <?= number_format($purchaseTotalAmount, 2) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Metric Summary Cards & Validation Terminal -->
<div class="card-erp p-3 mb-4" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px;">
    <!-- Metric Stat Chips Grid -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-2.5 text-center mb-3">
        <div class="col">
            <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">કુલ ઇનપુટ રફ</span>
                <span class="font-mono fs-6 fw-bold text-dark"><?= (int)$task['total_input_pcs'] ?> Pcs</span>
                <span class="small text-primary font-mono fw-bold" style="font-size: 0.75rem;"><?= format_carat($inputRoughWeight) ?></span>
            </div>
        </div>
        <div class="col">
            <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">વિભાજિત ભાગો (Allocated)</span>
                <span class="font-mono fs-6 fw-bold text-dark"><?= (int)$task['total_output_pcs'] ?> Pcs</span>
                <span class="small text-primary font-mono fw-bold" style="font-size: 0.75rem;"><?= format_carat($allocatedWeight) ?></span>
            </div>
        </div>
        <div class="col">
            <div class="p-2.5 rounded-3 border h-100 d-flex flex-column justify-content-center" style="background: <?= $isLossValid ? '#f8fafc' : '#fef2f2' ?>; border-color: <?= $isLossValid ? '#cbd5e1' : '#fca5a5' ?>;">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">Loss Rough (ઘટ રફ)</span>
                <span class="font-mono fs-5 fw-bold" style="color: <?= $isLossValid ? '#0f172a' : '#dc2626' ?>;">
                    <?= format_carat($lossRoughWeight) ?>
                </span>
                <span class="small font-mono fw-semibold" style="color: <?= $isLossValid ? '#107569' : '#dc2626' ?>; font-size: 0.78rem;">
                    <?= number_format($lossPercentage, 2) ?>% ઘટ
                </span>
            </div>
        </div>
        <div class="col">
            <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">મહત્તમ માન્ય ૨% ઘટ</span>
                <span class="font-mono fs-6 fw-bold text-dark"><?= format_carat($allowedLossWeight) ?></span>
                <span class="small text-muted font-mono" style="font-size: 0.75rem;">2.00% Limit</span>
            </div>
        </div>
        <div class="col">
            <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">વાસ્તવિક ઘટ ટકાવારી</span>
                <span class="font-mono fs-5 fw-bold" style="color: <?= $isLossValid ? '#0f172a' : '#dc2626' ?>;">
                    <?= number_format($lossPercentage, 2) ?>%
                </span>
                <span class="small font-mono" style="font-size: 0.75rem; color: <?= $isLossValid ? '#059669' : '#dc2626' ?>;">
                    <?= $isLossValid ? 'મર્યાદાની અંદર' : 'મર્યાદાથી વધુ' ?>
                </span>
            </div>
        </div>
        <div class="col">
            <div class="p-2.5 rounded-3 border h-100 d-flex flex-column justify-content-center" style="background: <?= $isPcsValid ? '#f8fafc' : '#fef2f2' ?>; border-color: <?= $isPcsValid ? '#cbd5e1' : '#fca5a5' ?>;">
                <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">નંગ તફાવત (Pcs Diff)</span>
                <span class="font-mono fs-5 fw-bold" style="color: <?= $isPcsValid ? '#059669' : '#dc2626' ?>;">
                    <?= ($pcsDiff > 0 ? "+$pcsDiff" : $pcsDiff) ?> Pcs
                </span>
                <span class="small font-mono fw-semibold" style="color: <?= $isPcsValid ? '#059669' : '#dc2626' ?>; font-size: 0.75rem;">
                    <?= $isPcsValid ? 'સંપૂર્ણ મેળ (Match)' : 'અસમાન નંગ (Mismatch)' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Integrated Full-Width Validation Banner -->
    <div class="p-3 rounded-4 d-flex flex-wrap align-items-center justify-content-between gap-3" 
         style="background: <?= $isOverallValid ? '#ECFDF5' : '#FEF2F2' ?>; border: 1px solid <?= $isOverallValid ? '#A7F3D0' : '#FECACA' ?>;">
        <div class="d-flex align-items-center gap-2.5">
            <div style="width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: <?= $isOverallValid ? '#D1FAE5' : '#FEE2E2' ?>; flex-shrink: 0;">
                <?= $isOverallValid ? svg_icon('circle-check', 'text-success', 18) : svg_icon('circle-xmark', 'text-danger', 18) ?>
            </div>
            <div>
                <div class="fw-bold fs-6" style="color: <?= $isOverallValid ? '#064e3b' : '#991b1b' ?>;">
                    <?= $isOverallValid ? 'માન્ય ઘટ અને નંગ મેળ (Validation PASS)' : 'વેલિડેશન ભૂલ (Validation FAILED)' ?>
                </div>
                <div class="small" style="color: <?= $isOverallValid ? '#065f46' : '#b91c1c' ?>;">
                    <?php if (!$isLossValid): ?>
                        ઘટ રફ વજન <?= format_carat($lossRoughWeight) ?> (<?= number_format($lossPercentage, 2) ?>%) માન્ય ૨% મર્યાદા (<?= format_carat($allowedLossWeight) ?>) કરતા વધી ગયેલ છે.
                    <?php elseif (!$isPcsValid): ?>
                        ભાગોના કુલ નંગ (<?= (int)$task['total_output_pcs'] ?> Pcs) મૂળ રફના નંગ (<?= (int)$task['total_input_pcs'] ?> Pcs) સાથે મેળ ખાતા નથી (તફાવત: <?= ($pcsDiff > 0 ? "+$pcsDiff" : $pcsDiff) ?> Pcs).
                    <?php else: ?>
                        ઘટ રફ વજન <?= format_carat($lossRoughWeight) ?> (<?= number_format($lossPercentage, 2) ?>%) અને નંગ (<?= (int)$task['total_output_pcs'] ?>/<?= (int)$task['total_input_pcs'] ?> Pcs) સંપૂર્ણ મેળ ખાય છે.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div>
            <?php if ($isOverallValid): ?>
                <span class="badge px-3 py-2 rounded-pill fs-6 fw-bold shadow-sm" style="background: #107569; color: #ffffff;">
                    <?= svg_icon('check', 'me-1.5') ?>PASS (૨% ઘટ & Pcs મેળ)
                </span>
            <?php else: ?>
                <span class="badge px-3 py-2 rounded-pill fs-6 fw-bold shadow-sm" style="background: #dc2626; color: #ffffff;">
                    <?= svg_icon('ban', 'me-1.5') ?>FAILED (<?= !$isLossValid ? '૨% થી વધુ ઘટ' : 'નંગ અસમાન' ?>)
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (($task['source_type'] ?? '') === 'boil_reassort'):
    $currLotRate = (float)($task['lot_rate'] ?? $task['lot_master_rate'] ?? 0);
    $currLotVal  = (float)($task['total_lot_value'] ?? 0);
    if ($currLotVal <= 0 && $currLotRate > 0) {
        $currLotVal = round($currLotRate * $allocatedWeight, 2);
    }
?>
<!-- Re-Assort Lot Rate Entry Card -->
<div class="erp-form-card mb-4 border border-primary" id="lotRateCard">
    <div class="erp-card-header bg-primary-subtle py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="header-title mb-0 text-dark">
                <span class="header-icon icon-emerald"><?= svg_icon('indian-rupee-sign') ?></span>
                Re-Assort લોટ રેટ (Lot Valuation: <?= e($task['lot_no'] ?: $task['reassort_lot_no']) ?>)
            </h6>
            <small class="text-muted">Re-Assort થયા પછી બનેલા આ લોટનો રેટ (₹/Carat) અહીં નાખો કે બદલો — નવી કાપન અને વેચાણમાં આપોઆપ આ રેટ લાગુ પડશે.</small>
        </div>
        <div class="text-end font-mono">
            <span class="badge bg-white text-dark border fs-6 px-3 py-1.5">
                હાલનો રેટ: <strong class="text-success">₹ <?= number_format($currLotRate, 2) ?>/ct</strong>
                &bull; કુલ કિંમત: <strong class="text-primary"><?= format_inr($currLotVal) ?></strong>
            </span>
        </div>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $id ?>" class="erp-card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_lot_rate">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-dark">લોટ રેટ (₹ / Carat) *</label>
                <div class="input-group">
                    <span class="input-group-text font-mono">₹</span>
                    <input type="number" step="0.01" min="0" name="lot_rate" id="view_lot_rate"
                           class="form-control fw-bold font-mono text-success"
                           value="<?= $currLotRate > 0 ? number_format($currLotRate, 2, '.', '') : '' ?>"
                           placeholder="0.00"
                           oninput="previewViewLotRate()" required>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted">અંદાજિત કુલ લોટ કિંમત</label>
                <div class="form-control bg-light font-mono fw-bold text-primary" id="view_lot_value_preview">
                    <?= format_inr($currLotVal) ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="apply_lot_rate_all" id="applyLotRateAll" value="1" checked>
                    <label class="form-check-label small fw-semibold" for="applyLotRateAll">
                        આ લોટના બધા ભાગો પર આ રેટ લાગુ કરો
                    </label>
                </div>
            </div>
            <div class="col-md-3 text-md-end">
                <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm w-100">
                    <?= svg_icon('check', 'me-1') ?>લોટ રેટ સેવ કરો
                </button>
            </div>
        </div>
    </form>
</div>
<script>
function previewViewLotRate() {
    var rateEl = document.getElementById('view_lot_rate');
    var outEl = document.getElementById('view_lot_value_preview');
    if (!rateEl || !outEl) return;
    var r = parseFloat(rateEl.value) || 0;
    var wt = <?= json_encode((float)($allocatedWeight > 0 ? $allocatedWeight : $inputRoughWeight)) ?>;
    outEl.textContent = '₹ ' + (r * wt).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
<?php endif; ?>

<div class="card-erp mb-4">
    <div class="card-header bg-white py-3 px-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark fs-6">
            <?= svg_icon('puzzle-piece', 'text-primary me-2') ?>૨. Rough ના ભાગો (Parts Allocation & Routing)
        </span>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/assort/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                <?= svg_icon('pen-to-square', 'me-1') ?>ભાગો સુધારો (Edit Parts)
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;">Assort No (હાયરાર્કી)</th>
                        <th style="width: 18%;">ભાગનું નામ (Part Name)</th>
                        <th style="width: 8%;" class="text-center">નંગ (PCs)</th>
                        <th style="width: 11%;">વજન (Weight Ct)</th>
                        <th style="width: 13%;">અંદાજિત ભાવ (Rate ₹/Ct)</th>
                        <th style="width: 14%;">અંદાજિત રકમ (Total ₹)</th>
                        <th style="width: 11%;">ટાર્ગેટ / નોંધ</th>
                        <th style="width: 8%;">સ્થિતિ</th>
                        <th style="width: 8%;" class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $viewTotalWeight = 0.0;
                    $viewTotalPcs = 0;
                    $viewTotalVal = 0.0;
                    foreach ($parts as $p): 
                        $displayAssortNo = !empty($p['assort_number']) ? $p['assort_number'] : (($task['lot_no'] ?: $task['purchase_no']) . '.' . $p['id']);
                        $pRate = (float)($p['cost_per_carat'] ?? 0);
                        $pWeight = (float)($p['weight'] ?? 0);
                        $pPcs = (int)($p['pcs'] ?? 0);
                        $pVal = round($pRate * $pWeight, 2);

                        $viewTotalWeight += $pWeight;
                        $viewTotalPcs += $pPcs;
                        $viewTotalVal += $pVal;
                    ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark font-mono fs-6 border px-2.5 py-1">
                                    <?= e($displayAssortNo) ?>
                                </span>
                            </td>
                            <td>
                                <strong class="text-dark"><?= e($p['part_name']) ?></strong>
                                <span class="ms-1"><?= get_assort_tag_badge($p['part_type']) ?></span>
                            </td>
                            <td class="font-mono fw-bold text-center">
                                <?= (int)$p['pcs'] ?>
                            </td>
                            <td class="font-mono fw-bold text-primary">
                                <?= format_carat($p['weight']) ?>
                            </td>
                            <td class="font-mono fw-bold text-success">
                                <?php if ($pRate > 0): ?>
                                    ₹ <?= number_format($pRate, 2) ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-mono fw-bold text-primary">
                                <?php if ($pVal > 0): ?>
                                    ₹ <?= number_format($pVal, 2) ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['target_kapan_name'])): ?>
                                    <span class="badge bg-light text-dark border font-mono"><?= e($p['target_kapan_name']) ?></span>
                                <?php endif; ?>
                                <small class="text-muted d-block"><?= e($p['notes'] ?? '-') ?></small>
                            </td>
                            <td>
                                <?php if ($p['status'] === 'in_kapan'): ?>
                                    <span class="badge bg-success-subtle text-success border">કાપનમાં ગયો</span>
                                <?php elseif ($p['status'] === 'sold'): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">વેચાઈ ગયો</span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border">હાજર</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($p['status'] === 'available'): ?>
                                    <?php if ($p['part_type'] === 'mfg' || $p['part_type'] === 'low_number_mfg'): ?>
                                        <a href="<?= BASE_URL ?>/modules/kapan/create.php?part_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary py-0 px-2" style="font-size: 0.8rem;">
                                            <?= svg_icon('boxes-stacked', 'me-1') ?>Kapan
                                        </a>
                                    <?php elseif ($p['part_type'] === 'out_sell'): ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_sales/create.php?part_id=<?= $p['id'] ?>" class="btn btn-sm btn-success py-0 px-2" style="font-size: 0.8rem;">
                                            <?= svg_icon('hand-holding-dollar', 'me-1') ?>વેચાણ
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small"><?= svg_icon('check', 'me-1') ?>Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; 
                    $viewAvgRate = ($viewTotalWeight > 0 && $viewTotalVal > 0) ? round($viewTotalVal / $viewTotalWeight, 2) : 0.0;
                    ?>
                </tbody>
                <tfoot>
                    <!-- ALLOCATED TOTAL ROW -->
                    <tr class="table-secondary border-top-2 fw-bold" style="background-color: #f1f5f9;">
                        <td colspan="2" class="text-end font-mono">
                            <?= svg_icon('calculator', 'me-1 text-primary') ?>કુલ વિભાજિત ભાગો (Total Allocated):
                        </td>
                        <td class="text-center font-mono fw-bold">
                            <?= number_format($viewTotalPcs) ?>
                        </td>
                        <td class="font-mono fw-bold text-primary">
                            <?= format_carat($viewTotalWeight) ?>
                        </td>
                        <td class="font-mono fw-bold text-success">
                            <?php if ($viewAvgRate > 0): ?>
                                ₹ <?= number_format($viewAvgRate, 2) ?>/ct
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="font-mono fw-bold text-primary">
                            <?php if ($viewTotalVal > 0): ?>
                                ₹ <?= number_format($viewTotalVal, 2) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td colspan="3" class="text-muted small align-middle">
                            સરેરાશ કેરેટ દીઠ ભાવ &amp; કુલ અંદાજિત કિંમત
                        </td>
                    </tr>
                    <!-- SYSTEM GENERATED LOSS ROUGH ROW (ALWAYS LAST ROW) -->
                    <tr class="table-light" style="background-color: #f8fafc;">
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <?= svg_icon('calculator', 'text-muted') ?>
                                <strong class="text-dark font-mono">Loss / Difference</strong>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-secondary border font-mono">ઘટ / તફાવત (Loss Diff)</span>
                        </td>
                        <td class="text-center font-mono fw-bold">
                            <?= $pcsDiff ?>
                        </td>
                        <td class="font-mono fw-bold" style="color: <?= $isLossValid ? '#0f172a' : '#dc2626' ?>;">
                            <?= format_carat($lossRoughWeight) ?>
                        </td>
                        <td colspan="2" class="text-center align-middle font-mono">
                            <span class="badge bg-light text-muted border px-2 py-1">Loss Valuation Excluded (<?= number_format($lossPercentage, 2) ?>%)</span>
                        </td>
                        <td colspan="2">
                            <?php if ($isLossValid): ?>
                                <span class="badge bg-success-subtle text-success border">માન્ય ઘટ (PASS)</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border">અમાન્ય ઘટ (FAILED)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <span class="badge bg-light text-muted border">Auto</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($reassortOriginItems)): ?>
    <div class="erp-form-card mt-4">
        <div class="erp-card-header bg-white py-3 px-3">
            <h6 class="header-title mb-0">
                <span class="header-icon icon-rose"><?= svg_icon('clock-rotate-left') ?></span>
                પૂર્વ ઇતિહાસ — આ Re-Assort લોટ (<?= e($task['lot_no'] ?: $task['reassort_lot_no']) ?>) માં આવેલા જૂની કાપન & Boil પેકેટ્સ
            </h6>
        </div>
        <div class="erp-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>જૂની કાપન (Old Kapan)</th>
                            <th>જૂનો ડાયમંડ નં.</th>
                            <th class="text-end">વજન</th>
                            <th class="text-end">ડાયમંડ જર્ની</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reassortOriginItems as $ori): ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= (int)$ori['old_kapan_id'] ?>" class="badge bg-light text-dark border text-decoration-none font-mono">
                                        <?= e($ori['old_kapan_code']) ?>
                                    </a>
                                </td>
                                <td><strong class="font-mono text-dark"><?= e($ori['old_diamond_no']) ?></strong></td>
                                <td class="text-end font-mono"><?= format_carat($ori['weight']) ?></td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= (int)$ori['packet_id'] ?>" class="btn btn-sm btn-outline-secondary">
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

<?php if (!empty($task['notes'])): ?>
    <div class="card-erp mt-3">
        <div class="card-body p-3">
            <strong class="text-muted small d-block mb-1">સામાન્ય નોંધ (Notes):</strong>
            <p class="mb-0 text-dark"><?= nl2br(e($task['notes'])) ?></p>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
