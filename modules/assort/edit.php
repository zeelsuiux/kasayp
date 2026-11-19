<?php
/**
 * Edit Assort Task (Assort એડિટ & ભાગ સુધારણા)
 * Diamond ERP - Advanced Enterprise Validation & Dynamic Parts Allocation
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
if ($id <= 0) {
    set_flash('error', 'અમાન્ય Assort ટાસ્ક ID.');
    header('Location: ' . BASE_URL . '/modules/assort/index.php');
    exit;
}

// Fetch existing task details
$stmt = $pdo->prepare("
    SELECT at.*, rp.purchase_no, rp.purchase_date, rp.weight as original_rough_weight, 
           rp.available_weight, rp.available_pcs, rp.price_per_carat_inr, rp.net_payment, rp.payment_amount, p.party_name
    FROM assort_tasks at
    LEFT JOIN rough_purchases rp ON at.rough_purchase_id = rp.id
    LEFT JOIN parties p ON rp.party_id = p.id
    WHERE at.id = ?
");
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    set_flash('error', 'Assort ટાસ્ક મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/assort/index.php');
    exit;
}

// Fetch existing parts
$partStmt = $pdo->prepare("SELECT * FROM assort_parts WHERE assort_task_id = ? ORDER BY id ASC");
$partStmt->execute([$id]);
$existingParts = $partStmt->fetchAll();

$isReassortEdit = (($task['source_type'] ?? 'rough_purchase') === 'boil_reassort');
$reassortLotRate = (float)($task['lot_rate'] ?? 0);
if ($isReassortEdit && $reassortLotRate <= 0 && !empty($task['reassort_lot_id'])) {
    $lrSt = $pdo->prepare("SELECT lot_rate FROM boil_reassort_lots WHERE id = ?");
    $lrSt->execute([(int)$task['reassort_lot_id']]);
    $reassortLotRate = (float)($lrSt->fetchColumn() ?: 0);
}

// Calculate purchase rate per carat and total purchase price (non-editable display)
$purchaseRatePerCt = (float)($task['price_per_carat_inr'] ?? 0);
if ($purchaseRatePerCt <= 0 && !empty($task['lot_rate'])) {
    $purchaseRatePerCt = (float)$task['lot_rate'];
}
if ($purchaseRatePerCt <= 0 && $isReassortEdit && $reassortLotRate > 0) {
    $purchaseRatePerCt = $reassortLotRate;
}

$taskInputWeight = (float)($task['total_input_weight'] ?? 0);
$purchaseTotalAmount = round($purchaseRatePerCt * $taskInputWeight, 2);
if ($purchaseTotalAmount <= 0 && !empty($task['total_lot_value'])) {
    $purchaseTotalAmount = (float)$task['total_lot_value'];
}
if ($purchaseTotalAmount <= 0 && !empty($task['net_payment']) && (float)($task['original_rough_weight'] ?? 0) > 0) {
    $purchaseTotalAmount = round(((float)$task['net_payment'] / (float)$task['original_rough_weight']) * $taskInputWeight, 2);
}

// Fetch active master settings for dropdowns
$activePartNames = get_active_assort_part_names();
$activePartTypes = get_active_assort_part_types();

// Handle Form Submission (Save Changes)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();

    // 1. Assort Number - Editable and Unique
    $taskNo = trim($_POST['task_no'] ?? '');
    if (empty($taskNo)) {
        $taskNo = $task['task_no'];
    }

    $assortDate = $_POST['assort_date'] ?? $task['assort_date'];
    $notes = trim($_POST['notes'] ?? '');
    $lotRate = $isReassortEdit ? max(0.0, round((float)($_POST['lot_rate'] ?? $reassortLotRate), 2)) : 0.0;

    $submittedParts = $_POST['parts'] ?? [];

    $errors = [];

    // Validation 1: Assort Number must be valid and unique (excluding current task id)
    if (empty($taskNo)) {
        $errors[] = "Assort નંબર દાખલ કરવો ફરજિયાત છે.";
    } else {
        $dupChk = $pdo->prepare("SELECT COUNT(*) FROM assort_tasks WHERE task_no = ? AND id != ?");
        $dupChk->execute([$taskNo, $id]);
        if ($dupChk->fetchColumn() > 0) {
            $errors[] = "Assort નંબર '$taskNo' અન્ય ટાસ્કમાં વપરાયેલ છે. કૃપા કરીને અનન્ય નંબર આપો.";
        }
    }

    // Base rough weight is the task's original rough weight
    $baseRoughWeight = (float)$task['total_input_weight'];
    $inputPcs = !empty($_POST['input_pcs']) ? (int)$_POST['input_pcs'] : (int)$task['total_input_pcs'];

    // Parts Allocation Validation
    $validParts = [];
    $totalAllocatedWeight = 0.000;
    $totalAllocatedPcs = 0;

    // Build map of part name => tag from master settings
    $partTagMap = [];
    foreach ($activePartNames as $apn) {
        $partTagMap[$apn['name']] = $apn['tag'] ?? 'mfg';
    }

    foreach ($submittedParts as $idx => $p) {
        $pAssortNo = trim($p['assort_number'] ?? '');
        $pName = trim($p['name'] ?? '');
        $pTag = $partTagMap[$pName] ?? 'mfg';
        $pType = map_assort_tag_to_part_type($pTag);
        $pPcs = isset($p['pcs']) && $p['pcs'] !== '' ? (int)$p['pcs'] : 0;
        $pWeight = isset($p['weight']) && $p['weight'] !== '' ? (float)$p['weight'] : 0.000;
        $pRate = isset($p['rate']) && $p['rate'] !== '' ? max(0.0, round((float)$p['rate'], 2)) : ($isReassortEdit ? $lotRate : null);
        $pKapan = trim($p['kapan_name'] ?? '');
        $pNotes = trim($p['notes'] ?? '');

        if (empty($pName) && $pWeight <= 0 && $pPcs <= 0) {
            continue;
        }

        if (empty($pAssortNo)) {
            $lotPrefix = !empty($task['lot_no']) ? $task['lot_no'] : ($task['purchase_no'] ?? 'R');
            $pAssortNo = generate_assort_number($lotPrefix, count($validParts) + 1);
        }

        if (empty($pName)) {
            $errors[] = "લાઇન #" . ($idx + 1) . ": ભાગનું નામ (Part Name) પસંદ કરવું ફરજિયાત છે.";
        }
        if ($pPcs < 0) {
            $errors[] = "લાઇન #" . ($idx + 1) . ": નંગ (PCs) ઋણ (Negative) ન હોઈ શકે.";
        }
        if ($pWeight <= 0) {
            $errors[] = "લાઇન #" . ($idx + 1) . ": વજન (Weight Ct) શૂન્ય કરતા વધુ હોવું જોઈએ.";
        }

        $pWeightRounded = round($pWeight, 3);
        $totalAllocatedWeight = round($totalAllocatedWeight + $pWeightRounded, 3);
        $totalAllocatedPcs += $pPcs;

        $validParts[] = [
            'assort_number' => $pAssortNo,
            'name'          => $pName,
            'type'          => $pType,
            'pcs'           => $pPcs,
            'weight'        => $pWeightRounded,
            'rate'          => $pRate,
            'kapan_name'    => $pKapan,
            'notes'         => $pNotes
        ];
    }

    if (empty($validParts)) {
        $errors[] = "ઓછામાં ઓછો એક ભાગ (Part Row) વજન સાથે ઉમેરવો ફરજિયાત છે.";
    }

    // 2% Loss Calculations
    $lossRoughWeight = round($baseRoughWeight - $totalAllocatedWeight, 3);

    // Rule: Total Allocated Weight must not exceed base rough weight
    if ($totalAllocatedWeight > $baseRoughWeight + 0.0001) {
        $errors[] = "કુલ વિભાજિત વજન (" . format_carat($totalAllocatedWeight) . ") મૂળ રફ વજન (" . format_carat($baseRoughWeight) . ") કરતા વધી જાય છે. કૃપા કરીને ભાગોનું વજન ઘટાડો.";
    }

    // Rule: Loss Rough Weight must not be negative
    if ($lossRoughWeight < -0.0001) {
        $errors[] = "ઘટ રફ વજન (Loss Rough Weight) ઋણ હોઈ શકે નહીં.";
    }

    // Rule: 2% Rough Weight Loss Validation
    $allowedLossWeight = round($baseRoughWeight * 0.02, 3);
    $lossPercentage = ($baseRoughWeight > 0) ? round(($lossRoughWeight / $baseRoughWeight) * 100, 2) : 0.00;

    if ($lossRoughWeight > $allowedLossWeight + 0.0001) {
        $errors[] = "Assort cannot be saved. Rough Weight Loss is exceeding the permitted 2% limit. Please review Parts Allocation. (માન્ય ૨% ઘટ: " . format_carat($allowedLossWeight) . ", વાસ્તવિક ઘટ: " . format_carat($lossRoughWeight) . " / " . number_format($lossPercentage, 2) . "%).";
    }

    // Rule: Pcs Validation (Allocated parts pieces must match input rough pieces)
    $pcsDifference = $inputPcs - $totalAllocatedPcs;
    if ($inputPcs > 0 && $totalAllocatedPcs !== $inputPcs) {
        $diffLabel = ($pcsDifference > 0 ? "+$pcsDifference" : "$pcsDifference");
        $errors[] = "ભાગોના કુલ નંગ ({$totalAllocatedPcs} Pcs) મૂળ રફના નંગ ({$inputPcs} Pcs) સાથે મેચ થવા જોઈએ. કૃપા કરીને નંગનો તફાવત ({$diffLabel} Pcs) સુધારો.";
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
    } else {
        try {
            $pdo->beginTransaction();

            $editSourceType = $task['source_type'] ?? 'rough_purchase';
            $editReassortLotId = !empty($task['reassort_lot_id']) ? (int)$task['reassort_lot_id'] : null;
            $editLotNo = !empty($task['lot_no']) ? $task['lot_no'] : ($task['purchase_no'] ?? null);
            $editCostPerCt = 0.0;
            if (!empty($existingParts)) {
                $editCostPerCt = (float)($existingParts[0]['cost_per_carat'] ?? 0);
            }
            if ($editCostPerCt <= 0 && !empty($task['lot_rate'])) {
                $editCostPerCt = (float)$task['lot_rate'];
            }

            // Compute total lot value across parts and final average rate
            $computedLotValue = 0.0;
            foreach ($validParts as &$vp) {
                $vpRate = ($vp['rate'] !== null && $vp['rate'] > 0)
                    ? (float)$vp['rate']
                    : ($isReassortEdit ? $lotRate : $editCostPerCt);
                $vp['effective_rate'] = $vpRate;
                $computedLotValue = round($computedLotValue + ($vp['weight'] * $vpRate), 2);
            }
            unset($vp);

            $finalAvgRate = ($totalAllocatedWeight > 0 && $computedLotValue > 0)
                ? round($computedLotValue / $totalAllocatedWeight, 2)
                : ($isReassortEdit ? $lotRate : $editCostPerCt);

            // 1. Update assort_tasks
            $updTask = $pdo->prepare("
                UPDATE assort_tasks SET 
                    task_no = ?, assort_date = ?, total_input_pcs = ?, 
                    total_output_pcs = ?, total_output_weight = ?, 
                    weight_difference = ?, pcs_difference = ?, 
                    lot_rate = ?, total_lot_value = ?, notes = ?
                WHERE id = ?
            ");
            $updTask->execute([
                $taskNo,
                $assortDate,
                $inputPcs,
                $totalAllocatedPcs,
                $totalAllocatedWeight,
                $lossRoughWeight,
                $pcsDifference,
                $finalAvgRate,
                $computedLotValue,
                $notes,
                $id
            ]);

            // 2. Replace parts that are not yet processed in kapan/sales
            // Check if any parts in this task are already in_kapan or sold
            $lockedParts = $pdo->prepare("SELECT id, assort_number, part_name, status, pcs, weight FROM assort_parts WHERE assort_task_id = ? AND status IN ('in_kapan', 'sold')");
            $lockedParts->execute([$id]);
            $lockedList = $lockedParts->fetchAll(PDO::FETCH_ASSOC);
            $lockedAssortNos = !empty($lockedList) ? array_filter(array_column($lockedList, 'assort_number')) : [];

            if (!empty($lockedList)) {
                // If parts already progressed, update existing and insert new
                $deleteStmt = $pdo->prepare("DELETE FROM assort_parts WHERE assort_task_id = ? AND status = 'available'");
                $deleteStmt->execute([$id]);
            } else {
                $deleteStmt = $pdo->prepare("DELETE FROM assort_parts WHERE assort_task_id = ?");
                $deleteStmt->execute([$id]);
            }

            $partStmt = $pdo->prepare("
                INSERT INTO assort_parts (
                    assort_task_id, source_type, reassort_lot_id, lot_no, rough_purchase_id, rough_number, assort_number,
                    part_name, part_type, pcs, weight, cost_per_carat, target_kapan_name, status, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?)
            ");

            foreach ($validParts as $part) {
                // Skip re-inserting parts that are already locked in Kapan or Sold
                if (!empty($lockedAssortNos) && in_array($part['assort_number'], $lockedAssortNos, true)) {
                    continue;
                }
                $partCost = (float)($part['effective_rate'] ?? 0.0);
                $partStmt->execute([
                    $id,
                    $editSourceType,
                    $editReassortLotId,
                    $editLotNo,
                    $task['rough_purchase_id'],
                    $editLotNo,
                    $part['assort_number'],
                    $part['name'],
                    $part['type'],
                    $part['pcs'],
                    $part['weight'],
                    $partCost,
                    !empty($part['kapan_name']) ? $part['kapan_name'] : null,
                    $part['notes']
                ]);
            }

            if ($isReassortEdit) {
                update_reassort_lot_rates($id, $lotRate, [], false);
            }

            $pdo->commit();

            log_activity(
                'Assort Task Updated', 
                'Assort', 
                $id, 
                "Updated Assort $taskNo: $baseRoughWeight ct partitioned into $totalAllocatedWeight ct across " . count($validParts) . " parts. Loss: $lossRoughWeight ct ($lossPercentage%)"
            );

            set_flash('success', "Assort ટાસ્ક $taskNo સફળતાપૂર્વક અપડેટ થયો (ઘટ: " . format_carat($lossRoughWeight) . " / " . number_format($lossPercentage, 2) . "% - પાસ).");
            header('Location: ' . BASE_URL . '/modules/assort/view.php?id=' . $id);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'Assort ટાસ્ક અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Assort Task એડિટ કરો: ' . $task['task_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<?php
$editRefDisplay = (($task['source_type'] ?? '') === 'boil_reassort')
    ? ('Boil Re-Assort લોટ: ' . ($task['lot_no'] ?: '-'))
    : (($task['purchase_no'] ?? '-') . ' — ' . ($task['party_name'] ?? '-'));
$editLotShort = (($task['source_type'] ?? '') === 'boil_reassort')
    ? ($task['lot_no'] ?: '-')
    : ($task['purchase_no'] ?? '01');
?>
<!-- Header -->
<?= render_page_header(
    'Assort Task એડિટ કરો: ' . $task['task_no'],
    $editRefDisplay . ' • મૂળ રફ વજન: ' . format_carat($task['total_input_weight']),
    'pen-to-square',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/assort/view.php?id=' . $id . '" class="btn btn-outline-secondary btn-sm">' . svg_icon('arrow-left', 'me-1') . 'પાછા જાઓ</a>
        <a href="' . BASE_URL . '/modules/assort/index.php" class="btn btn-outline-secondary btn-sm">' . svg_icon('layer-group', 'me-1') . 'Assort રજિસ્ટર</a>
    </div>',
    'purple'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-11">
        <form action="<?= BASE_URL ?>/modules/assort/edit.php?id=<?= $id ?>" method="POST" id="editAssortForm" novalidate>
            <?= csrf_field() ?>

            <!-- CARD 1: ASSORT TASK BASIC DETAILS (1 SINGLE LINE AT THE TOP) -->
            <div class="card-erp mb-3">
                <div class="card-header bg-white py-1.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark" style="font-size: 0.875rem;">
                        <?= svg_icon('file-pen', 'text-primary me-1.5') ?>૧. ટાસ્ક મૂળભૂત વિગતો & રફ લોટ
                    </span>
                    <span class="badge bg-light text-dark font-mono border"><?= e($task['task_no']) ?></span>
                </div>
                <div class="card-body py-2 px-3">
                    <div class="d-flex flex-nowrap align-items-end justify-content-between gap-3 text-nowrap" style="overflow-x: auto;">
                        <!-- 1. Assort No -->
                        <div style="flex: 0 0 140px;">
                            <label class="form-label small fw-bold text-muted mb-0.5 text-truncate d-block">
                                Assort No * <span class="badge bg-light text-primary border" style="font-size: 0.65rem;">Edit</span>
                            </label>
                            <input type="text" name="task_no" id="task_no" class="form-control form-control-sm fw-bold font-mono" value="<?= e($task['task_no']) ?>" required>
                        </div>

                        <!-- 2. Date -->
                        <div style="flex: 0 0 135px;">
                            <label class="form-label small fw-bold text-muted mb-0.5 text-truncate d-block">તારીખ *</label>
                            <input type="date" name="assort_date" class="form-control form-control-sm font-mono" value="<?= e($task['assort_date']) ?>" required>
                        </div>

                        <!-- 3. Locked Lot Reference (Only Text) -->
                        <div style="flex: 1 1 200px; min-width: 160px;">
                            <label class="form-label small fw-bold text-muted mb-0.5 text-truncate d-block">લોટ / Rough સંદર્ભ</label>
                            <div class="fw-bold font-mono text-dark text-truncate d-block" style="font-size: 0.92rem; min-height: 31px; line-height: 31px;" title="<?= e($editRefDisplay) ?>">
                                <?= e($editRefDisplay) ?>
                            </div>
                        </div>

                        <!-- 4. Pcs (Editable) -->
                        <div style="flex: 0 0 85px;">
                            <label class="form-label small fw-bold text-dark mb-0.5 text-truncate d-block">નંગ (Pcs) *</label>
                            <input type="number" name="input_pcs" id="input_rough_pcs" 
                                   class="form-control form-control-sm fw-bold font-mono text-center" 
                                   value="<?= (int)$task['total_input_pcs'] ?>" 
                                   required
                                   oninput="recalcAssortCalculations()">
                        </div>

                        <!-- 5. Weight (Ct) (Only Text) -->
                        <div style="flex: 0 0 auto; min-width: 100px;">
                            <label class="form-label small fw-bold text-dark mb-0.5 text-truncate d-block">રફ વજન (Ct)</label>
                            <div class="fw-bold font-mono text-primary fs-6" style="min-height: 31px; line-height: 31px;">
                                <?= format_carat($task['total_input_weight']) ?>
                            </div>
                            <input type="hidden" name="input_weight" id="input_rough_weight" value="<?= (float)$task['total_input_weight'] ?>">
                        </div>

                        <!-- 6. Rate ₹/ct (Only Text) -->
                        <div style="flex: 0 0 auto; min-width: 105px;">
                            <label class="form-label small fw-bold text-dark mb-0.5 text-truncate d-block" title="ખરીદી ભાવ (Locked)">
                                ખરીદી ભાવ <span class="badge bg-secondary-subtle text-secondary px-1 py-0" style="font-size: 0.65rem;">Lock</span>
                            </label>
                            <div class="fw-bold font-mono text-success fs-6" style="min-height: 31px; line-height: 31px;">
                                ₹ <?= number_format($purchaseRatePerCt, 2) ?><span class="text-muted small">/ct</span>
                            </div>
                            <input type="hidden" id="display_purchase_rate_per_ct" value="<?= number_format($purchaseRatePerCt, 2) ?>">
                        </div>

                        <!-- 7. Total Amount (Only Text) -->
                        <div style="flex: 0 0 auto; min-width: 125px;">
                            <label class="form-label small fw-bold text-dark mb-0.5 text-truncate d-block" title="કુલ ખરીદી રકમ (Locked)">
                                કુલ ખરીદી રકમ <span class="badge bg-secondary-subtle text-secondary px-1 py-0" style="font-size: 0.65rem;">Lock</span>
                            </label>
                            <div class="fw-bold font-mono text-primary fs-6" style="min-height: 31px; line-height: 31px;">
                                ₹ <?= number_format($purchaseTotalAmount, 2) ?>
                            </div>
                            <input type="hidden" id="display_purchase_total_amount" value="<?= number_format($purchaseTotalAmount, 2) ?>">
                        </div>
                    </div>

                    <?php if ($isReassortEdit): ?>
                    <div class="row g-2 align-items-end mt-2 pt-2 border-top">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-warning-emphasis mb-1">
                                <?= svg_icon('indian-rupee-sign', 'me-1') ?>Re-Assort લોટ રેટ (₹ / Carat)
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text fw-bold bg-warning-subtle text-warning-emphasis">₹</span>
                                <input type="number" step="0.01" min="0" name="lot_rate" id="edit_lot_rate"
                                       class="form-control fw-bold font-mono text-dark"
                                       value="<?= $reassortLotRate > 0 ? number_format($reassortLotRate, 2, '.', '') : '' ?>"
                                       placeholder="લોટ ભાવ ₹/ct">
                                <button type="button" class="btn btn-outline-warning text-dark fw-semibold" id="btnSyncEditPartRates" title="આ લોટ રેટ બધા ભાગમાં લાગુ કરો">
                                    બધા ભાગમાં લાગુ કરો
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted mb-1">કુલ લોટ કિંમત (Lot Value)</label>
                            <div class="form-control form-control-sm bg-light font-mono fw-bold text-success" id="edit_lot_total_value_display">
                                ₹ <?= number_format($reassortLotRate * (float)$task['total_input_weight'], 2) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CARD 2: DYNAMIC PARTS ALLOCATION & ROUTING TABLE -->
            <div class="card-erp mb-4">
                <div class="card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-bold text-dark fs-6">
                            <?= svg_icon('puzzle-piece', 'text-primary me-2') ?>૨. Rough ના ભાગો (Parts Allocation & Routing)
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" target="_blank" class="btn btn-outline-secondary btn-sm" title="સેટિંગ્સમાંથી નવા ભાગ ઉમેરો">
                            <?= svg_icon('gear', 'me-1') ?>માસ્ટર સેટિંગ્સ
                        </a>
                        <button type="button" class="btn btn-primary btn-sm px-3 fw-bold shadow-sm" id="addPartRowBtn">
                            <?= svg_icon('plus', 'me-1') ?>નવો ભાગ ઉમેરો (Add Row)
                        </button>
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle mb-0" id="partsAllocationTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 18%;">Assort No (હાયરાર્કી) *</th>
                                    <th style="width: 25%;">ભાગનું નામ (Part Name) *</th>
                                    <th style="width: 9%;">નંગ (PCs)</th>
                                    <th style="width: 14%;">વજન (Weight Ct) *</th>
                                    <th style="width: 14%;">અંદાજિત ભાવ (Rate ₹/Ct)</th>
                                    <th style="width: 14%;">અંદાજિત રકમ (Total ₹)</th>
                                    <th style="width: 6%;" class="text-center">એક્શન</th>
                                </tr>
                            </thead>
                            <tbody id="partsTableBody">
                                <!-- Existing and dynamically added rows will appear here -->
                            </tbody>
                            <tfoot>
                                <!-- ALLOCATED TOTAL ROW -->
                                <tr class="table-secondary border-top-2 fw-bold" style="background-color: #f1f5f9;">
                                    <td colspan="2" class="text-end font-mono">કુલ વિભાજિત ભાગો (Total Allocated):</td>
                                    <td>
                                        <input type="text" id="footer_allocated_pcs" class="form-control font-mono fw-bold text-center bg-white" value="0" readonly title="કુલ વિભાજિત નંગ">
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" id="footer_allocated_weight" class="form-control font-mono fw-bold text-end bg-white" value="0.000" readonly title="કુલ વિભાજિત વજન">
                                            <span class="input-group-text font-mono" style="font-size: 0.8rem;">ct</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group" title="કુલ સરેરાશ કેરેટ દીઠ ભાવ (Total Average Ct Price)">
                                            <span class="input-group-text font-mono text-success fw-bold" style="font-size: 0.75rem;">₹/ct</span>
                                            <input type="text" id="footer_avg_rate" class="form-control font-mono fw-bold text-end text-success bg-white" value="0.00" readonly placeholder="Avg ₹">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group" title="કુલ અંદાજિત કિંમત (Total Amount)">
                                            <span class="input-group-text font-mono text-primary fw-bold" style="font-size: 0.75rem;">₹</span>
                                            <input type="text" id="footer_total_amount" class="form-control font-mono fw-bold text-end text-primary bg-white" value="0.00" readonly placeholder="Total ₹">
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <!-- SYSTEM GENERATED LOSS ROUGH ROW (ALWAYS LAST ROW) -->
                                <tr id="lossRoughRow" class="table-light border-top" style="background-color: #f8fafc;">
                                    <td>
                                        <div class="d-flex align-items-center gap-1.5">
                                            <?= svg_icon('calculator', 'text-muted') ?>
                                            <strong class="text-dark font-mono">Loss / Difference</strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary border font-mono">ઘટ / તફાવત (Loss Diff)</span>
                                    </td>
                                    <td>
                                        <input type="text" id="loss_pcs_display" class="form-control font-mono fw-bold text-center" value="0" readonly style="background: #ffffff; cursor: not-allowed;" title="નંગ તફાવત">
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" id="loss_rough_display" class="form-control fw-bold font-mono text-end" value="0.000" readonly style="background: #ffffff; font-size: 1rem; transition: color 0.2s ease;" title="ઘટ રફ વજન">
                                            <span class="input-group-text font-mono" style="font-size: 0.8rem;">ct</span>
                                        </div>
                                    </td>
                                    <td colspan="2" class="text-center align-middle font-mono">
                                        <span class="badge bg-light text-muted border px-2 py-1">Loss Valuation Excluded</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-muted border" title="આ રો સિસ્ટમ જનરેટેડ છે">Auto</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- LIVE REAL-TIME TERMINAL & 2% LOSS VALIDATION BOX -->
                    <div class="card-erp p-3 mb-3" id="validationTerminalCard" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px;">
                        <!-- Metric Stat Chips Grid -->
                        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-2.5 text-center mb-3">
                            <div class="col">
                                <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">મૂળ ખરીદી રફ વજન</span>
                                    <span class="font-mono fs-6 fw-bold text-dark" id="display_purchase_rough"><?= format_carat($task['total_input_weight']) ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">કુલ વિભાજિત વજન</span>
                                    <span class="font-mono fs-6 fw-bold text-primary" id="display_allocated_weight">0.000 ct</span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="p-2.5 rounded-3 border h-100 d-flex flex-column justify-content-center" id="loss_stat_box" style="background: #f8fafc; border-color: #cbd5e1;">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">ઘટ રફ વજન (Loss)</span>
                                    <span class="font-mono fs-5 fw-bold" id="display_loss_weight" style="color: #0f172a;">0.000 ct</span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">મહત્તમ માન્ય ૨% ઘટ</span>
                                    <span class="font-mono fs-6 fw-bold text-dark" id="display_allowed_loss"><?= format_carat(round((float)$task['total_input_weight'] * 0.02, 3)) ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="p-2.5 rounded-3 border bg-light h-100 d-flex flex-column justify-content-center">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">વાસ્તવિક ઘટ ટકાવારી</span>
                                    <span class="font-mono fs-5 fw-bold" id="display_loss_percent" style="color: #0f172a;">0.00%</span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="p-2.5 rounded-3 border h-100 d-flex flex-column justify-content-center" id="pcs_stat_box" style="background: #f8fafc; border-color: #cbd5e1;">
                                    <span class="text-muted small d-block mb-1" style="font-size: 0.78rem;">નંગ તફાવત (Pcs Diff)</span>
                                    <span class="font-mono fs-5 fw-bold" id="display_pcs_diff" style="color: #059669;">0 Pcs</span>
                                    <span class="small font-mono fw-semibold" id="display_pcs_ratio" style="font-size: 0.75rem; color: #059669;">0 / 0 Pcs</span>
                                </div>
                            </div>
                        </div>

                        <!-- Integrated Full-Width Validation Banner (Guaranteed Zero Overlap) -->
                        <div id="validationBanner" class="p-3 rounded-4 d-flex flex-wrap align-items-center justify-content-between gap-3" style="background: #ECFDF5; border: 1px solid #A7F3D0; transition: all 0.25s ease;">
                            <div class="d-flex align-items-center gap-2.5">
                                <div id="validationIconWrap" style="width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #D1FAE5; flex-shrink: 0;">
                                    <span id="validationIcon" style="color: #059669; display: inline-flex;"><?= svg_icon('circle-check', '', 20) ?></span>
                                </div>
                                <div>
                                    <div class="fw-bold fs-6" id="validationTitle" style="color: #064e3b;">માન્ય ઘટ (Validation PASS)</div>
                                    <div class="small" id="validationDesc" style="color: #065f46;">
                                        ઘટ રફ વજન 0.000 ct (0.00%) માન્ય ૨% મર્યાદાની અંદર છે.
                                    </div>
                                </div>
                            </div>
                            <div>
                                <span id="display_validation_badge" class="finora-pill px-3 py-2 rounded-pill fs-6 fw-bold" style="background: #059669; color: #ffffff;">
                                    <?= svg_icon('check', 'me-1', 14) ?>PASS (માન્ય ૨% ઘટ)
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Notes & Actions -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સામાન્ય નોંધ (General Notes)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Assort સંબંધિત કોઈ નોંધ..."><?= e($task['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 border-top">
                        <div class="text-muted small">
                            <?= svg_icon('shield-halved', 'text-primary me-1') ?>
                            સર્વર-સાઇડ સુરક્ષા: ૨% થી વધુ ઘટ અથવા ખોટું વજન હોવા પર સિસ્ટમ સેવ થવા દેશે નહીં.
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $id ?>" class="btn btn-outline-secondary px-4">રદ કરો</a>
                            <button type="submit" id="submit_assort_btn" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <?= svg_icon('check-double', 'me-1') ?>ફેરફારો સેવ કરો (Save Changes)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const availablePartNames = <?= json_encode($activePartNames, JSON_UNESCAPED_UNICODE) ?>;
    const availablePartTypes = <?= json_encode($activePartTypes, JSON_UNESCAPED_UNICODE) ?>;
    const existingParts = <?= json_encode($existingParts, JSON_UNESCAPED_UNICODE) ?>;
    const currentRoughPurchaseNo = <?= json_encode($editLotShort, JSON_UNESCAPED_UNICODE) ?>;
    const isReassortEdit = <?= $isReassortEdit ? 'true' : 'false' ?>;
    const editLotRateEl = document.getElementById('edit_lot_rate');
    const editLotTotalValEl = document.getElementById('edit_lot_total_value_display');
    const btnSyncEditRates = document.getElementById('btnSyncEditPartRates');

    const tbody = document.getElementById('partsTableBody');
    const addRowBtn = document.getElementById('addPartRowBtn');
    const inputWeightEl = document.getElementById('input_rough_weight');
    const submitBtn = document.getElementById('submit_assort_btn');

    let rowIndex = 0;

    function getPartTagBadge(tag) {
        if (tag === 'sale') return ' [🏷️ Sell]';
        if (tag === 'mfg_later') return ' [⏳ MFG Later]';
        return ' [⚙️ MFG]';
    }

    function getPartNameOptionsHtml(selectedVal = '') {
        let html = '<option value="">-- ભાગ પસંદ કરો --</option>';
        let found = false;
        availablePartNames.forEach(function (item) {
            const isSel = (item.name === selectedVal) ? 'selected' : '';
            if (isSel) found = true;
            const tag = item.tag || 'mfg';
            const tagBadge = getPartTagBadge(tag);
            html += `<option value="${escapeHtml(item.name)}" data-tag="${escapeHtml(tag)}" ${isSel}>${escapeHtml(item.name)}${tagBadge}</option>`;
        });
        // If current value is not in active master, still include it for historical accuracy
        if (selectedVal && !found) {
            html += `<option value="${escapeHtml(selectedVal)}" data-tag="mfg" selected>${escapeHtml(selectedVal)} (પહેલાંનો ભાગ)</option>`;
        }
        return html;
    }

    // Function to handle part name change and auto-set hidden type
    window.onPartNameChange = function(selectEl) {
        const selectedOpt = selectEl.options[selectEl.selectedIndex];
        const tag = selectedOpt ? (selectedOpt.dataset.tag || 'mfg') : 'mfg';
        const row = selectEl.closest('tr');
        if (row) {
            const hiddenType = row.querySelector('.part-type-hidden');
            if (hiddenType) {
                let mappedType = 'mfg';
                if (tag === 'sale') mappedType = 'out_sell';
                else if (tag === 'mfg_later') mappedType = 'low_number_mfg';
                hiddenType.value = mappedType;
            }
        }
        recalcAssortCalculations();
    };

    function addPartRow(defaultName = '', defaultType = '', defaultPcs = '', defaultWeight = '', defaultKapan = '', defaultNotes = '', defaultAssortNo = '', defaultRate = '') {
        const tr = document.createElement('tr');
        tr.className = 'part-data-row';
        tr.dataset.rowIndex = rowIndex;

        const currentRows = tbody.querySelectorAll('.part-data-row').length;
        if (!defaultAssortNo) {
            defaultAssortNo = currentRoughPurchaseNo + '.' + (currentRows + 1);
        }

        if (!defaultType && defaultName) {
            const found = availablePartNames.find(p => p.name === defaultName);
            const tag = found ? (found.tag || 'mfg') : 'mfg';
            defaultType = (tag === 'sale') ? 'out_sell' : ((tag === 'mfg_later') ? 'low_number_mfg' : 'mfg');
        } else if (!defaultType) {
            defaultType = 'mfg';
        }

        const effDefaultRate = defaultRate !== '' ? defaultRate : (editLotRateEl && editLotRateEl.value ? editLotRateEl.value : '');

        tr.innerHTML = `
            <td>
                <input type="text" name="parts[${rowIndex}][assort_number]" class="form-control font-mono fw-bold text-dark part-assort-input" value="${escapeHtml(defaultAssortNo)}" placeholder="દા.ત. 01.1" required>
            </td>
            <td>
                <select name="parts[${rowIndex}][name]" class="form-select select-part-name" required onchange="onPartNameChange(this)">
                    ${getPartNameOptionsHtml(defaultName)}
                </select>
                <input type="hidden" name="parts[${rowIndex}][type]" class="part-type-hidden" value="${escapeHtml(defaultType)}">
            </td>
            <td>
                <input type="number" name="parts[${rowIndex}][pcs]" class="form-control font-mono part-pcs-input text-center" min="0" step="1" placeholder="0" value="${defaultPcs}" oninput="recalcAssortCalculations()">
            </td>
            <td>
                <div class="input-group">
                    <input type="number" step="0.001" min="0" name="parts[${rowIndex}][weight]" class="form-control fw-bold font-mono text-primary text-end part-weight-input" placeholder="0.000" value="${defaultWeight}" required oninput="recalcAssortCalculations()">
                    <span class="input-group-text font-mono" style="font-size: 0.75rem;">ct</span>
                </div>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text font-mono text-muted" style="font-size: 0.75rem;">₹</span>
                    <input type="number" step="0.01" min="0" name="parts[${rowIndex}][rate]" class="form-control font-mono text-success fw-bold text-end part-rate-input" placeholder="0.00" value="${escapeHtml(effDefaultRate)}" oninput="this.dataset.manual='1'; recalcAssortCalculations();">
                </div>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text font-mono text-muted" style="font-size: 0.75rem;">₹</span>
                    <input type="text" class="form-control font-mono fw-bold text-end text-dark bg-light part-amount-display" value="0.00" readonly tabindex="-1">
                </div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm remove-row-btn" title="આ ભાગ હટાવો" onclick="removePartRow(this)">
                    ${window.svgIcon('trash-can')}
                </button>
            </td>
        `;

        tbody.appendChild(tr);
        rowIndex++;

        if (window.initSmartSearchableSelects) {
            window.initSmartSearchableSelects();
        }

        recalcAssortCalculations();
    }

    window.removePartRow = function (btn) {
        const row = btn.closest('tr');
        if (row) {
            row.remove();
            recalcAssortCalculations();
        }
    };

    if (addRowBtn) {
        addRowBtn.addEventListener('click', function () {
            const currentRows = tbody.querySelectorAll('.part-data-row').length;
            const suggestedName = (availablePartNames[currentRows]) ? availablePartNames[currentRows].name : '';
            const tag = availablePartNames[currentRows] ? (availablePartNames[currentRows].tag || 'mfg') : 'mfg';
            const suggestedType = (tag === 'sale') ? 'out_sell' : ((tag === 'mfg_later') ? 'low_number_mfg' : 'mfg');
            addPartRow(suggestedName, suggestedType);
        });
    }

    window.recalcAssortCalculations = function () {
        if (!inputWeightEl) return;

        const totalRoughWeight = parseFloat(inputWeightEl.value) || 0.000;

        let totalAllocatedWeight = 0.000;
        let totalAllocatedPcs = 0;
        let totalAllocatedAmount = 0.00;

        const rows = tbody.querySelectorAll('.part-data-row');
        rows.forEach(function (row) {
            const wInput = row.querySelector('.part-weight-input');
            const pInput = row.querySelector('.part-pcs-input');
            const rInput = row.querySelector('.part-rate-input');
            const amtDisplay = row.querySelector('.part-amount-display');

            const w = parseFloat(wInput ? wInput.value : 0) || 0.000;
            const pcs = parseInt(pInput ? pInput.value : 0, 10) || 0;
            const rate = parseFloat(rInput ? rInput.value : 0) || 0.00;
            const rowAmt = Math.round((w * rate) * 100) / 100;

            if (amtDisplay) {
                amtDisplay.value = rowAmt > 0 ? rowAmt.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
            }

            totalAllocatedWeight = Math.round((totalAllocatedWeight + w) * 1000) / 1000;
            totalAllocatedPcs += pcs;
            totalAllocatedAmount = Math.round((totalAllocatedAmount + rowAmt) * 100) / 100;
        });

        // Total Average Ct Price = Total Amount / Total Allocated Weight
        const totalAvgCtRate = (totalAllocatedWeight > 0 && totalAllocatedAmount > 0)
            ? Math.round((totalAllocatedAmount / totalAllocatedWeight) * 100) / 100
            : 0.00;

        // 3. Pcs Calculation & Difference
        const inputPcsEl = document.getElementById('input_rough_pcs');
        const totalRoughPcs = inputPcsEl ? (parseInt(inputPcsEl.value, 10) || 0) : 0;
        const pcsDifference = totalRoughPcs - totalAllocatedPcs;

        const lossRoughWeight = Math.round((totalRoughWeight - totalAllocatedWeight) * 1000) / 1000;
        const allowedLoss = Math.round((totalRoughWeight * 0.02) * 1000) / 1000;
        const lossPercent = (totalRoughWeight > 0) ? Math.round(((lossRoughWeight / totalRoughWeight) * 100) * 100) / 100 : 0.00;

        const footerAllocatedPcs = document.getElementById('footer_allocated_pcs');
        const footerAllocatedWeight = document.getElementById('footer_allocated_weight');
        const footerAvgRate = document.getElementById('footer_avg_rate');
        const footerTotalAmount = document.getElementById('footer_total_amount');
        const lossPcsDisplay = document.getElementById('loss_pcs_display');
        const lossDisplayEl = document.getElementById('loss_rough_display');
        const dispPurchaseRough = document.getElementById('display_purchase_rough');
        const dispAllocated = document.getElementById('display_allocated_weight');
        const dispLoss = document.getElementById('display_loss_weight');
        const dispAllowedLoss = document.getElementById('display_allowed_loss');
        const dispLossPercent = document.getElementById('display_loss_percent');
        const dispPcsDiff = document.getElementById('display_pcs_diff');
        const dispPcsRatio = document.getElementById('display_pcs_ratio');
        const dispValidationBadge = document.getElementById('display_validation_badge');
        const banner = document.getElementById('validationBanner');
        const iconWrap = document.getElementById('validationIconWrap');
        const icon = document.getElementById('validationIcon');
        const title = document.getElementById('validationTitle');
        const desc = document.getElementById('validationDesc');
        const lossBox = document.getElementById('loss_stat_box');
        const pcsBox = document.getElementById('pcs_stat_box');

        if (footerAllocatedPcs) footerAllocatedPcs.value = totalAllocatedPcs;
        if (footerAllocatedWeight) footerAllocatedWeight.value = totalAllocatedWeight.toFixed(3);
        if (footerAvgRate) footerAvgRate.value = totalAvgCtRate > 0 ? totalAvgCtRate.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
        if (footerTotalAmount) footerTotalAmount.value = totalAllocatedAmount > 0 ? totalAllocatedAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
        if (lossPcsDisplay) {
            lossPcsDisplay.value = pcsDifference;
            lossPcsDisplay.style.color = (pcsDifference === 0) ? '#059669' : '#dc2626';
        }

        if (dispPurchaseRough) dispPurchaseRough.innerText = totalRoughWeight.toFixed(3) + ' ct';
        if (dispAllocated) dispAllocated.innerText = totalAllocatedWeight.toFixed(3) + ' ct';
        if (dispLoss) dispLoss.innerText = lossRoughWeight.toFixed(3) + ' ct';
        if (dispAllowedLoss) dispAllowedLoss.innerText = allowedLoss.toFixed(3) + ' ct';
        if (dispLossPercent) dispLossPercent.innerText = lossPercent.toFixed(2) + '%';
        if (lossDisplayEl) lossDisplayEl.value = lossRoughWeight.toFixed(3);

        if (dispPcsDiff) {
            dispPcsDiff.innerText = (pcsDifference > 0 ? '+' : '') + pcsDifference + ' Pcs';
            dispPcsDiff.style.color = (pcsDifference === 0) ? '#059669' : '#dc2626';
        }
        if (dispPcsRatio) {
            dispPcsRatio.innerText = `${totalAllocatedPcs} / ${totalRoughPcs} Pcs`;
            dispPcsRatio.style.color = (pcsDifference === 0) ? '#059669' : '#dc2626';
        }
        if (pcsBox) {
            pcsBox.style.background = (pcsDifference === 0) ? '#f8fafc' : '#fef2f2';
            pcsBox.style.borderColor = (pcsDifference === 0) ? '#cbd5e1' : '#fca5a5';
        }

        // 6. Color Logic & Validation Enforcement
        if (lossRoughWeight < -0.0001) {
            // Negative Loss: Allocated > Total Rough
            lossDisplayEl.style.color = '#dc2626'; // RED
            dispLoss.style.color = '#dc2626';
            if (lossBox) {
                lossBox.style.background = '#fef2f2';
                lossBox.style.borderColor = '#fca5a5';
            }
            if (banner) {
                banner.style.background = '#fef2f2';
                banner.style.borderColor = '#fca5a5';
            }
            if (iconWrap) iconWrap.style.background = '#FEE2E2';
            if (icon) {
                icon.innerHTML = window.svgIcon('triangle-exclamation', '', 14);
                icon.style.color = '#dc2626';
            }
            if (title) {
                title.innerText = 'વજન ભૂલ (Weight Exceeded)';
                title.style.color = '#991b1b';
            }
            if (desc) {
                desc.innerText = `કુલ વિભાજિત વજન (${totalAllocatedWeight.toFixed(3)} ct) મૂળ રફ વજન (${totalRoughWeight.toFixed(3)} ct) કરતા વધારે છે! કૃપા કરીને ભાગોનું વજન ઓછું કરો.`;
                desc.style.color = '#b91c1c';
            }
            if (dispValidationBadge) {
                dispValidationBadge.style.background = '#dc2626';
                dispValidationBadge.innerHTML = window.svgIcon('triangle-exclamation', 'me-1', 11) + 'FAILED (વજન વધી ગયું)';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50');
            }
        } else if (lossRoughWeight > allowedLoss + 0.0001) {
            // Loss exceeds 2%
            lossDisplayEl.style.color = '#dc2626'; // RED
            dispLoss.style.color = '#dc2626';
            if (lossBox) {
                lossBox.style.background = '#fef2f2';
                lossBox.style.borderColor = '#fca5a5';
            }
            if (banner) {
                banner.style.background = '#fef2f2';
                banner.style.borderColor = '#fca5a5';
            }
            if (iconWrap) iconWrap.style.background = '#FEE2E2';
            if (icon) {
                icon.innerHTML = window.svgIcon('circle-xmark', '', 14);
                icon.style.color = '#dc2626';
            }
            if (title) {
                title.innerText = 'Assort cannot be saved (૨% થી વધુ ઘટ)';
                title.style.color = '#991b1b';
            }
            if (desc) {
                desc.innerText = `Rough Weight Loss (${lossRoughWeight.toFixed(3)} ct / ${lossPercent.toFixed(2)}%) is exceeding the permitted 2% limit (${allowedLoss.toFixed(3)} ct). Please review Parts Allocation.`;
                desc.style.color = '#b91c1c';
            }
            if (dispValidationBadge) {
                dispValidationBadge.style.background = '#dc2626';
                dispValidationBadge.innerHTML = window.svgIcon('ban', 'me-1', 11) + 'FAILED (૨% થી વધુ ઘટ)';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50');
            }
        } else if (totalRoughPcs > 0 && pcsDifference !== 0) {
            // Pcs Mismatch
            lossDisplayEl.style.color = '#0f172a';
            dispLoss.style.color = '#0f172a';
            if (lossBox) {
                lossBox.style.background = '#f8fafc';
                lossBox.style.borderColor = '#cbd5e1';
            }
            if (banner) {
                banner.style.background = '#fef2f2';
                banner.style.borderColor = '#fca5a5';
            }
            if (iconWrap) iconWrap.style.background = '#FEE2E2';
            if (icon) {
                icon.innerHTML = window.svgIcon('triangle-exclamation', '', 14);
                icon.style.color = '#dc2626';
            }
            if (title) {
                title.innerText = 'નંગ તફાવત ભૂલ (Pcs Mismatch)';
                title.style.color = '#991b1b';
            }
            const diffSign = pcsDifference > 0 ? '+' : '';
            if (desc) {
                desc.innerText = `ભાગોના કુલ નંગ (${totalAllocatedPcs} Pcs) મૂળ રફના નંગ (${totalRoughPcs} Pcs) સાથે મેચ થતા નથી! તફાવત: ${diffSign}${pcsDifference} Pcs. કૃપા કરીને ભાગોના નંગ સરખા કરો.`;
                desc.style.color = '#b91c1c';
            }
            if (dispValidationBadge) {
                dispValidationBadge.style.background = '#dc2626';
                dispValidationBadge.innerHTML = window.svgIcon('triangle-exclamation', 'me-1', 11) + 'FAILED (નંગ અસમાન)';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50');
            }
        } else {
            // Acceptable Loss (0% to 2%) and Pcs Match: Display in BLACK, status PASS
            lossDisplayEl.style.color = '#0f172a'; // BLACK
            dispLoss.style.color = '#0f172a';
            if (lossBox) {
                lossBox.style.background = '#f8fafc';
                lossBox.style.borderColor = '#cbd5e1';
            }
            if (banner) {
                banner.style.background = '#ECFDF5';
                banner.style.borderColor = '#A7F3D0';
            }
            if (iconWrap) iconWrap.style.background = '#D1FAE5';
            if (icon) {
                icon.innerHTML = window.svgIcon('circle-check', '', 14);
                icon.style.color = '#059669';
            }
            if (title) {
                title.innerText = 'માન્ય ઘટ અને નંગ મેળ (Validation PASS)';
                title.style.color = '#064e3b';
            }
            if (desc) {
                desc.innerText = `ઘટ રફ વજન ${lossRoughWeight.toFixed(3)} ct (${lossPercent.toFixed(2)}%) અને નંગ (${totalAllocatedPcs}/${totalRoughPcs} Pcs) સંપૂર્ણ મેળ ખાય છે. Assort સેવ કરવા માટે તૈયાર છે.`;
                desc.style.color = '#065f46';
            }
            if (dispValidationBadge) {
                dispValidationBadge.style.background = '#059669';
                dispValidationBadge.innerHTML = window.svgIcon('check', 'me-1', 11) + 'PASS (૨% ઘટ & Pcs મેળ)';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50');
            }
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Populate existing parts
    if (existingParts && existingParts.length > 0) {
        existingParts.forEach(function (part, idx) {
            const assortNo = part.assort_number || (currentRoughPurchaseNo + '.' + (idx + 1));
            const partRate = (part.cost_per_carat && parseFloat(part.cost_per_carat) > 0) ? parseFloat(part.cost_per_carat).toFixed(2) : '';
            addPartRow(
                part.part_name, 
                part.part_type, 
                part.pcs, 
                parseFloat(part.weight).toFixed(3), 
                part.target_kapan_name || '', 
                part.notes || '',
                assortNo,
                partRate
            );
        });
    } else {
        // Fallback default rows
        addPartRow('Part 1 (Direct MFG)', 'mfg');
    }

    function updateEditLotValueDisplay() {
        if (!editLotRateEl || !editLotTotalValEl || !inputWeightEl) return;
        const lr = parseFloat(editLotRateEl.value) || 0;
        const wt = parseFloat(inputWeightEl.value) || 0;
        const val = lr * wt;
        editLotTotalValEl.innerText = '₹ ' + val.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (editLotRateEl) {
        editLotRateEl.addEventListener('input', updateEditLotValueDisplay);
    }
    if (btnSyncEditRates && editLotRateEl) {
        btnSyncEditRates.addEventListener('click', function () {
            const lr = parseFloat(editLotRateEl.value) || 0;
            const valStr = lr > 0 ? lr.toFixed(2) : '';
            tbody.querySelectorAll('.part-rate-input').forEach(function (inp) {
                inp.value = valStr;
            });
            updateEditLotValueDisplay();
        });
    }

    const roughPcsInput = document.getElementById('input_rough_pcs');
    if (roughPcsInput) {
        roughPcsInput.addEventListener('input', recalcAssortCalculations);
        roughPcsInput.addEventListener('change', recalcAssortCalculations);
    }

    updateEditLotValueDisplay();
    recalcAssortCalculations();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
