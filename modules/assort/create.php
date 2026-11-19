<?php
/**
 * Create Assort Task (સેક્શન ૬.૨ ભાગ પાડવા)
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

// Fetch active master settings for dropdowns
$activePartNames = get_active_assort_part_names();
$activePartTypes = get_active_assort_part_types();

// Fetch available rough purchases
$roughList = $pdo->query("SELECT rp.id, rp.purchase_no, rp.purchase_date, rp.weight, rp.pcs, rp.available_weight, rp.available_pcs, p.party_name
                          FROM rough_purchases rp
                          JOIN parties p ON rp.party_id = p.id
                          WHERE rp.status IN ('in_stock', 'in_assort') AND rp.available_weight > 0
                          ORDER BY rp.purchase_date DESC")->fetchAll();

// Fetch pending Boil Re-Assort lots
$pendingReassortLots = $pdo->query("
    SELECT brl.*, bb.batch_no, bb.boil_date
    FROM boil_reassort_lots brl
    LEFT JOIN boil_batches bb ON brl.boil_batch_id = bb.id
    WHERE brl.status = 'pending_assort'
    ORDER BY brl.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedReassortLotId = (int)($_GET['reassort_lot_id'] ?? $_POST['reassort_lot_id'] ?? 0);
$sourceMode = trim((string)($_GET['source'] ?? $_POST['source_type'] ?? 'rough_purchase'));
if ($selectedReassortLotId > 0 && isset($_GET['source']) && $_GET['source'] === 'boil_reassort') {
    $sourceMode = 'boil_reassort';
} else {
    $sourceMode = 'rough_purchase';
}

$selectedRoughId = (int)($_GET['rough_id'] ?? $_POST['rough_purchase_id'] ?? 0);
$selectedRough = null;
$selectedReassortLot = null;
$selectedReassortItems = [];

if ($sourceMode === 'boil_reassort') {
    if ($selectedReassortLotId <= 0 && !empty($pendingReassortLots)) {
        $selectedReassortLotId = (int)$pendingReassortLots[0]['id'];
    }
    if ($selectedReassortLotId > 0) {
        $rlStmt = $pdo->prepare("
            SELECT brl.*, bb.batch_no, bb.boil_date
            FROM boil_reassort_lots brl
            LEFT JOIN boil_batches bb ON brl.boil_batch_id = bb.id
            WHERE brl.id = ?
        ");
        $rlStmt->execute([$selectedReassortLotId]);
        $selectedReassortLot = $rlStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selectedReassortLot) {
            $riStmt = $pdo->prepare("
                SELECT brli.*, p.rough_purchase_id, p.expected_shape
                FROM boil_reassort_lot_items brli
                LEFT JOIN packets p ON brli.packet_id = p.id
                WHERE brli.reassort_lot_id = ?
                ORDER BY brli.id ASC
            ");
            $riStmt->execute([$selectedReassortLotId]);
            $selectedReassortItems = $riStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} else {
    if ($selectedRoughId > 0) {
        $st = $pdo->prepare("SELECT rp.*, p.party_name FROM rough_purchases rp JOIN parties p ON rp.party_id = p.id WHERE rp.id = ?");
        $st->execute([$selectedRoughId]);
        $selectedRough = $st->fetch();
    } elseif (!empty($roughList)) {
        $selectedRough = $roughList[0];
        $selectedRoughId = (int)$selectedRough['id'];
    }
}

$hasActiveLot = ($sourceMode === 'boil_reassort' && !empty($selectedReassortLot)) || ($sourceMode === 'rough_purchase' && !empty($selectedRough));
$defaultTaskNo = generate_next_code('ASRT', 'assort_tasks', 'task_no');

// Handle Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();

    $postSourceType = trim((string)($_POST['source_type'] ?? 'rough_purchase'));
    if (!in_array($postSourceType, ['rough_purchase', 'boil_reassort'], true)) {
        $postSourceType = 'rough_purchase';
    }

    // 1. Assort Number - Editable and Unique
    $taskNo = trim($_POST['task_no'] ?? '');
    if (empty($taskNo)) {
        $taskNo = $defaultTaskNo;
    }

    $roughPurchaseId = (int)($_POST['rough_purchase_id'] ?? 0);
    $postReassortLotId = (int)($_POST['reassort_lot_id'] ?? 0);
    $postLotNo = trim((string)($_POST['lot_no'] ?? ''));
    $postLotRate = max(0.0, round((float)($_POST['lot_rate'] ?? 0), 2));
    $assortDate = $_POST['assort_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    $roughInfo = null;
    $reassortLotInfo = null;
    $reassortItems = [];

    if ($postSourceType === 'boil_reassort') {
        $rlStmt = $pdo->prepare("SELECT * FROM boil_reassort_lots WHERE id = ? AND status = 'pending_assort'");
        $rlStmt->execute([$postReassortLotId]);
        $reassortLotInfo = $rlStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($reassortLotInfo) {
            $riStmt = $pdo->prepare("
                SELECT brli.*, p.rough_purchase_id
                FROM boil_reassort_lot_items brli
                LEFT JOIN packets p ON brli.packet_id = p.id
                WHERE brli.reassort_lot_id = ?
            ");
            $riStmt->execute([$postReassortLotId]);
            $reassortItems = $riStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $st = $pdo->prepare("SELECT * FROM rough_purchases WHERE id = ?");
        $st->execute([$roughPurchaseId]);
        $roughInfo = $st->fetch();
    }

    $submittedParts = $_POST['parts'] ?? [];

    // Server-Side Validations
    $errors = [];

    // Validation 1: Assort Number must be valid and unique
    if (empty($taskNo)) {
        $errors[] = "Assort નંબર દાખલ કરવો ફરજિયાત છે.";
    } else {
        $dupChk = $pdo->prepare("SELECT COUNT(*) FROM assort_tasks WHERE task_no = ?");
        $dupChk->execute([$taskNo]);
        if ($dupChk->fetchColumn() > 0) {
            $errors[] = "Assort નંબર '$taskNo' પહેલેથી સિસ્ટમમાં હાજર છે. કૃપા કરીને અનન્ય નંબર આપો.";
        }
    }

    if ($postSourceType === 'boil_reassort') {
        if (!$reassortLotInfo) {
            $errors[] = "પસંદ કરેલ Boil Re-Assort લોટ મળ્યો નથી અથવા પહેલેથી Assort થઈ ગયેલ છે.";
        }
        if ($postLotNo === '') {
            $postLotNo = $reassortLotInfo['lot_no'] ?? generate_next_reassort_lot_no();
        }
        $lotDup = $pdo->prepare("SELECT COUNT(*) FROM boil_reassort_lots WHERE lot_no = ? AND id != ?");
        $lotDup->execute([$postLotNo, $postReassortLotId]);
        if ((int)$lotDup->fetchColumn() > 0) {
            $errors[] = "લોટ નંબર '$postLotNo' પહેલેથી વપરાયેલ છે. કૃપા કરીને અનન્ય લોટ નંબર આપો.";
        }
        $originalPurchaseRoughWeight = (float)($reassortLotInfo['total_weight'] ?? 0.000);
        $availableRoughWeight = $originalPurchaseRoughWeight;
        $availableRoughPcs = (int)($reassortLotInfo['total_pcs'] ?? 0);
        $inputWeight = $availableRoughWeight;
        $inputPcs = !empty($_POST['input_pcs']) ? (int)$_POST['input_pcs'] : $availableRoughPcs;
    } else {
        // Validation 2: Rough Purchase lot check
        if (!$roughInfo) {
            $errors[] = "પસંદ કરેલ Rough ખરીદી સ્ટોક મળ્યો નથી.";
        }

        // Determine Total Rough Weight (Original Purchase Rough Weight / Input Weight)
        $originalPurchaseRoughWeight = (float)($roughInfo['weight'] ?? 0.000);
        $availableRoughWeight = (float)($roughInfo['available_weight'] ?? 0.000);
        $availableRoughPcs = (int)($roughInfo['available_pcs'] ?? 0);

        // If lot is full lot, base weight is original purchase weight; if partial lot, base weight is available weight
        $inputWeight = !empty($_POST['input_weight']) ? (float)$_POST['input_weight'] : $availableRoughWeight;
        $inputPcs = !empty($_POST['input_pcs']) ? (int)$_POST['input_pcs'] : $availableRoughPcs;

        if ($inputWeight <= 0) {
            $errors[] = "આ ટાસ્કમાં વપરાતું રફ વજન શૂન્ય કરતા વધુ હોવું જોઈએ.";
        }
        if ($inputWeight > $availableRoughWeight + 0.0001) {
            $errors[] = "આ ટાસ્કનું રફ વજન (" . format_carat($inputWeight) . ") હાજર સ્ટોક (" . format_carat($availableRoughWeight) . ") કરતા વધી જાય છે.";
        }
    }

    // Validation 3 & 4: Parts allocation validation
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
        $pRate = isset($p['rate']) && $p['rate'] !== '' ? max(0.0, round((float)$p['rate'], 2)) : null;
        $pKapan = trim($p['kapan_name'] ?? '');
        $pNotes = trim($p['notes'] ?? '');

        // Ignore completely empty rows
        if (empty($pName) && $pWeight <= 0 && $pPcs <= 0) {
            continue;
        }

        // Generate automatic hierarchical Assort Number if not provided: e.g. 01.1, 01.2
        if (empty($pAssortNo)) {
            $lotPrefix = ($postSourceType === 'boil_reassort') ? $postLotNo : ($roughInfo['purchase_no'] ?? 'R');
            $pAssortNo = generate_assort_number($lotPrefix, count($validParts) + 1);
        }

        // Validate row fields
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

    // Validation 8, 9 & 10: Automatic Loss Rough & 2% Limit
    // Formula: Loss Rough Weight = Total Rough Weight - Allocated Rough Weight
    // Use $originalPurchaseRoughWeight if whole lot, or $inputWeight as the active rough lot weight
    $baseRoughWeight = ($originalPurchaseRoughWeight > 0 && abs($inputWeight - $originalPurchaseRoughWeight) <= 0.005) 
                        ? $originalPurchaseRoughWeight 
                        : $inputWeight;

    $lossRoughWeight = round($baseRoughWeight - $totalAllocatedWeight, 3);

    // Rule 8: Total Allocated Weight must not exceed Total Purchase Rough Weight
    if ($totalAllocatedWeight > $baseRoughWeight + 0.0001) {
        $errors[] = "કુલ વિભાજિત વજન (" . format_carat($totalAllocatedWeight) . ") મૂળ રફ વજન (" . format_carat($baseRoughWeight) . ") કરતા વધી જાય છે. કૃપા કરીને ભાગોનું વજન ઘટાડો.";
    }

    // Rule 9: Loss Rough Weight must not be negative
    if ($lossRoughWeight < -0.0001) {
        $errors[] = "ઘટ રફ વજન (Loss Rough Weight) ઋણ હોઈ શકે નહીં.";
    }

    // Rule 10: 2% Rough Weight Loss Validation
    // Allowed Loss = Total Purchase Rough Weight * 2 / 100
    $allowedLossWeight = round($baseRoughWeight * 0.02, 3);
    $lossPercentage = ($baseRoughWeight > 0) ? round(($lossRoughWeight / $baseRoughWeight) * 100, 2) : 0.00;

    if ($lossRoughWeight > $allowedLossWeight + 0.0001) {
        $errors[] = "Assort cannot be saved. Rough Weight Loss is exceeding the permitted 2% limit. Please review Parts Allocation. (માન્ય ૨% ઘટ: " . format_carat($allowedLossWeight) . ", વાસ્તવિક ઘટ: " . format_carat($lossRoughWeight) . " / " . number_format($lossPercentage, 2) . "%).";
    }

    // Rule 11: Pcs Difference Validation (Allocated Pcs must match Input Rough Pcs)
    $pcsDifference = $inputPcs - $totalAllocatedPcs;
    if ($inputPcs > 0 && $totalAllocatedPcs !== $inputPcs) {
        $diffLabel = ($pcsDifference > 0 ? "+$pcsDifference" : "$pcsDifference");
        $errors[] = "ભાગોના કુલ નંગ ({$totalAllocatedPcs} Pcs) મૂળ રફના નંગ ({$inputPcs} Pcs) સાથે મેચ થવા જોઈએ. કૃપા કરીને નંગનો તફાવત ({$diffLabel} Pcs) સુધારો.";
    }

    // If validation fails, halt save and flash errors
    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
    } else {
        try {
            $pdo->beginTransaction();

            $taskSourceType = ($postSourceType === 'boil_reassort') ? 'boil_reassort' : 'rough_purchase';
            $taskReassortLotId = ($taskSourceType === 'boil_reassort') ? $postReassortLotId : null;
            $taskLotNo = ($taskSourceType === 'boil_reassort') ? $postLotNo : ($roughInfo['purchase_no'] ?? null);

            $inheritedRoughPurchaseId = ($taskSourceType === 'rough_purchase') ? $roughPurchaseId : null;
            $inheritedCostPerCt = ($taskSourceType === 'rough_purchase') ? (float)($roughInfo['price_per_carat_inr'] ?? 0) : 0.0;

            if ($taskSourceType === 'boil_reassort' && !empty($reassortItems)) {
                $rpIds = array_values(array_unique(array_filter(array_map(fn($it) => (int)($it['rough_purchase_id'] ?? 0), $reassortItems))));
                if (count($rpIds) === 1) {
                    $inheritedRoughPurchaseId = $rpIds[0];
                }
                $wtSumCost = 0.0;
                $wtSum = 0.0;
                foreach ($reassortItems as $it) {
                    $w = (float)($it['weight'] ?? 0);
                    $c = (float)($it['cost_per_carat'] ?? 0);
                    $wtSum += $w;
                    $wtSumCost += ($w * $c);
                }
                $inheritedCostPerCt = ($wtSum > 0) ? round($wtSumCost / $wtSum, 2) : 0.0;
            }

            $finalLotRate = ($taskSourceType === 'boil_reassort')
                ? ($postLotRate > 0 ? $postLotRate : $inheritedCostPerCt)
                : $inheritedCostPerCt;

            // Compute total lot value across parts (respecting individual part rates when provided)
            $computedLotValue = 0.0;
            foreach ($validParts as &$vp) {
                $vpRate = ($vp['rate'] !== null && $vp['rate'] > 0) ? (float)$vp['rate'] : $finalLotRate;
                $vp['effective_rate'] = $vpRate;
                $computedLotValue = round($computedLotValue + ($vp['weight'] * $vpRate), 2);
            }
            unset($vp);

            if ($totalAllocatedWeight > 0 && $computedLotValue > 0) {
                if ($postLotRate <= 0 || $taskSourceType !== 'boil_reassort') {
                    $finalLotRate = round($computedLotValue / $totalAllocatedWeight, 2);
                }
            }

            // Insert into assort_tasks
            $stmt = $pdo->prepare("INSERT INTO assort_tasks (
                task_no, source_type, rough_purchase_id, reassort_lot_id, lot_no, lot_rate, total_lot_value, assort_date, total_input_pcs, total_input_weight, 
                total_output_pcs, total_output_weight, weight_difference, pcs_difference, status, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)");

            $stmt->execute([
                $taskNo,
                $taskSourceType,
                $inheritedRoughPurchaseId,
                $taskReassortLotId,
                $taskLotNo,
                $finalLotRate,
                $computedLotValue,
                $assortDate,
                $inputPcs,
                $baseRoughWeight,
                $totalAllocatedPcs,
                $totalAllocatedWeight,
                $lossRoughWeight,
                $pcsDifference,
                $notes,
                $user['id'] ?? null
            ]);
            $taskId = (int)$pdo->lastInsertId();

            // Insert allocated parts into assort_parts with hierarchical Assort Number and Rough/Lot links
            $partStmt = $pdo->prepare("INSERT INTO assort_parts (
                assort_task_id, source_type, reassort_lot_id, lot_no, rough_purchase_id, rough_number, assort_number,
                part_name, part_type, pcs, weight, cost_per_carat, target_kapan_name, status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?)");

            foreach ($validParts as $part) {
                $partStmt->execute([
                    $taskId,
                    $taskSourceType,
                    $taskReassortLotId,
                    $taskLotNo,
                    $inheritedRoughPurchaseId,
                    $taskLotNo,
                    $part['assort_number'],
                    $part['name'],
                    $part['type'],
                    $part['pcs'],
                    $part['weight'],
                    $part['effective_rate'],
                    !empty($part['kapan_name']) ? $part['kapan_name'] : null,
                    $part['notes']
                ]);
            }

            if ($taskSourceType === 'boil_reassort') {
                // Finalize Re-Assort Lot -> sync Lot No & Lot Rate back to lot, packets, polish_stocks and log history
                $pdo->prepare("
                    UPDATE boil_reassort_lots SET
                        lot_no = ?,
                        lot_rate = ?,
                        total_lot_value = ?,
                        assort_task_id = ?,
                        status = 'assorted',
                        assorted_at = NOW()
                    WHERE id = ?
                ")->execute([$taskLotNo, $finalLotRate, $computedLotValue, $taskId, $postReassortLotId]);

                $pdo->prepare("UPDATE packets SET reassort_task_id = ?, reassort_lot_no = ? WHERE reassort_lot_id = ?")
                    ->execute([$taskId, $taskLotNo, $postReassortLotId]);
                $pdo->prepare("UPDATE polish_stocks SET reassort_lot_no = ? WHERE reassort_lot_id = ?")
                    ->execute([$taskLotNo, $postReassortLotId]);

                $insPph = $pdo->prepare("
                    INSERT INTO packet_process_history (
                        packet_id, diamond_no, stage, action, input_weight, output_weight,
                        process_date, location, status, details, logged_by
                    ) VALUES (?, ?, 'reassort_exit', 'Assort Task Created (New Lot)', ?, ?, ?, 'Assort Dept', 'completed', ?, ?)
                ");
                foreach ($reassortItems as $it) {
                    $insPph->execute([
                        (int)$it['packet_id'],
                        $it['old_diamond_no'],
                        (float)$it['weight'],
                        (float)$it['weight'],
                        $assortDate,
                        "નવો Assort ટાસ્ક {$taskNo} (Lot: {$taskLotNo}) બન્યો — " . count($validParts) . " ભાગમાં વિભાજિત.",
                        $user['id'] ?? null
                    ]);
                }
            } else {
                // Deduct allocated weight & pcs from rough purchase stock
                $remWeight = max(0.000, round($availableRoughWeight - $inputWeight, 3));
                $remPcs = max(0, $availableRoughPcs - $inputPcs);
                $newStatus = ($remWeight <= 0.001) ? 'assorted' : 'in_assort';

                $upd = $pdo->prepare("UPDATE rough_purchases SET available_weight = ?, available_pcs = ?, status = ? WHERE id = ?");
                $upd->execute([$remWeight, $remPcs, $newStatus, $roughPurchaseId]);
            }

            $pdo->commit();

            log_activity(
                $taskSourceType === 'boil_reassort' ? 'Boil Re-Assort Created' : 'Assort Task Created', 
                'Assort', 
                $taskId, 
                "Created Assort $taskNo (Lot: $taskLotNo): $baseRoughWeight ct partitioned into $totalAllocatedWeight ct across " . count($validParts) . " parts. Loss: $lossRoughWeight ct ($lossPercentage%)"
            );

            $lotSuffix = ($taskSourceType === 'boil_reassort') ? " (નવો લોટ: $taskLotNo)" : "";
            set_flash('success', "Assort ટાસ્ક $taskNo{$lotSuffix} સફળતાપૂર્વક બની ગયો છે (ઘટ: " . format_carat($lossRoughWeight) . " / " . number_format($lossPercentage, 2) . "% - પાસ).");
            header('Location: ' . BASE_URL . '/modules/assort/view.php?id=' . $taskId);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'Assort ટાસ્ક સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવો Assort Task (રફ ભાગ પાડવા & ૨% ઘટ ચકાસણી)';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<?= render_page_header(
    'નવો Assort Task (Rough ભાગ પાડવા & રૂટીંગ)',
    '',
    'layer-group',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/admin/settings.php?tab=part_names" class="btn btn-outline-secondary btn-sm">' . svg_icon('sliders', 'me-1') . 'ભાગ સેટિંગ્સ</a>
        <a href="' . BASE_URL . '/modules/assort/index.php" class="btn btn-outline-secondary btn-sm">' . svg_icon('arrow-left', 'me-1') . 'Assort રજિસ્ટર</a>
    </div>',
    'purple'
) ?>

<?php
$formActionQuery = ($sourceMode === 'boil_reassort')
    ? ('?source=boil_reassort' . ($selectedReassortLotId > 0 ? '&reassort_lot_id=' . $selectedReassortLotId : ''))
    : ($selectedRoughId > 0 ? '?rough_id=' . $selectedRoughId : '');
?>
<div class="row justify-content-center">
    <div class="col-12 col-xl-11">
        <form action="<?= BASE_URL ?>/modules/assort/create.php<?= $formActionQuery ?>" method="POST" id="assortForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="source_type" value="<?= e($sourceMode) ?>">

            <!-- CARD 1: ASSORT TASK & LOT SELECTION (1 SINGLE LINE AT THE TOP) -->
            <div class="card-erp mb-3">
                <div class="card-header bg-white py-1.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark" style="font-size: 0.875rem;">
                        <?= svg_icon('file-signature', 'text-primary me-1.5') ?>૧. ટાસ્ક મૂળભૂત વિગતો & રફ લોટ
                    </span>
                    <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.72rem;"><?= $sourceMode === 'boil_reassort' ? 'Boil Re-Assort' : 'Rough Purchase' ?></span>
                </div>
                <div class="card-body py-2 px-3">
                    <?php if ($sourceMode === 'boil_reassort'): ?>
                        <?php
                            $origRoughWeight = (float)($selectedReassortLot['total_weight'] ?? 0);
                            $availRoughWeight = $origRoughWeight;
                            $availRoughPcs = (int)($selectedReassortLot['total_pcs'] ?? 0);
                            $allowedLossMax = round($origRoughWeight * 0.02, 3);
                            $prefillLotRate = (float)($selectedReassortLot['lot_rate'] ?? 0);
                            if ($prefillLotRate <= 0 && !empty($selectedReassortItems)) {
                                $sw = 0.0; $sc = 0.0;
                                foreach ($selectedReassortItems as $sri) {
                                    $sw += (float)$sri['weight'];
                                    $sc += ((float)$sri['weight'] * (float)($sri['cost_per_carat'] ?? 0));
                                }
                                if ($sw > 0 && $sc > 0) $prefillLotRate = round($sc / $sw, 2);
                            }
                        ?>
                        <div class="row g-2 align-items-end">
                            <div class="col-6 col-md-2" style="min-width: 130px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">Assort No *</label>
                                <input type="text" name="task_no" id="task_no" class="form-control form-control-sm fw-bold font-mono" value="<?= e($defaultTaskNo) ?>" required placeholder="ASRT-2026-002">
                            </div>
                            <div class="col-6 col-md-2" style="min-width: 125px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">તારીખ *</label>
                                <input type="date" name="assort_date" class="form-control form-control-sm font-mono" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12 col-md" style="min-width: 200px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">Boil પરત Re-Assort લોટ *</label>
                                <select name="reassort_lot_id" id="reassort_lot_id" class="form-select form-select-sm select-tom fw-semibold" onchange="window.location.href='?source=boil_reassort&reassort_lot_id=' + this.value" required>
                                    <option value="">-- લોટ પસંદ કરો --</option>
                                    <?php foreach ($pendingReassortLots as $rl): ?>
                                        <option value="<?= (int)$rl['id'] ?>" <?= $selectedReassortLotId === (int)$rl['id'] ? 'selected' : '' ?>>
                                            <?= e($rl['lot_no']) ?> (<?= format_carat($rl['total_weight']) ?> / <?= (int)$rl['total_pcs'] ?> Pcs)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md" style="min-width: 120px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">નવો લોટ નં *</label>
                                <input type="text" name="lot_no" id="lot_no_input" class="form-control form-control-sm fw-bold font-mono text-primary" value="<?= e($selectedReassortLot['lot_no'] ?? '') ?>" required>
                            </div>
                            <div class="col-6 col-md" style="min-width: 110px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">વજન (Ct) *</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.001" name="input_weight" id="input_rough_weight"
                                           class="form-control fw-bold text-primary font-mono bg-light"
                                           value="<?= $availRoughWeight ?>"
                                           data-original-weight="<?= $origRoughWeight ?>"
                                           readonly
                                           oninput="recalcAssortCalculations()">
                                    <span class="input-group-text font-mono" style="font-size: 0.75rem;">ct</span>
                                </div>
                                <span id="allowed_loss_display" class="d-none"><?= format_carat($allowedLossMax) ?></span>
                            </div>
                            <div class="col-6 col-md" style="min-width: 80px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">નંગ (Pcs) *</label>
                                <input type="number" name="input_pcs" id="input_rough_pcs"
                                       class="form-control form-control-sm fw-bold font-mono text-center"
                                       value="<?= $availRoughPcs ?>"
                                       required
                                       oninput="recalcAssortCalculations()">
                            </div>
                            <div class="col-6 col-md" style="min-width: 110px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">લોટ રેટ ₹/Ct</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white font-mono" style="font-size: 0.75rem;">₹</span>
                                    <input type="number" step="0.01" min="0" name="lot_rate" id="lot_rate_input"
                                           class="form-control fw-bold font-mono text-success bg-white"
                                           value="<?= $prefillLotRate > 0 ? number_format($prefillLotRate, 2, '.', '') : '' ?>"
                                           placeholder="0.00"
                                           oninput="syncPartRatesFromLotRate()">
                                </div>
                            </div>
                            <div class="col-6 col-md" style="min-width: 125px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">કુલ કિંમત</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light font-mono" style="font-size: 0.75rem;">₹</span>
                                    <input type="text" id="lot_total_value_display"
                                           class="form-control fw-bold font-mono text-dark bg-light"
                                           value="<?= number_format($prefillLotRate * $availRoughWeight, 2) ?>"
                                           readonly tabindex="-1" style="cursor: not-allowed;">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php 
                            $origRoughWeight = (float)($selectedRough['weight'] ?? 0);
                            $availRoughWeight = (float)($selectedRough['available_weight'] ?? 0);
                            $availRoughPcs = (int)($selectedRough['available_pcs'] ?? 0);
                            $allowedLossMax = round($origRoughWeight * 0.02, 3);
                            $purchaseRatePerCt = (float)($selectedRough['price_per_carat_inr'] ?? 0);
                            $purchaseTotalAmount = round($purchaseRatePerCt * $availRoughWeight, 2);
                        ?>
                        <div class="row g-2 align-items-end">
                            <!-- 1. Assort No -->
                            <div class="col-6 col-md-2" style="min-width: 135px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">Assort No *</label>
                                <input type="text" name="task_no" id="task_no" class="form-control form-control-sm fw-bold font-mono" value="<?= e($defaultTaskNo) ?>" required placeholder="ASRT-2026-002">
                            </div>

                            <!-- 2. Date -->
                            <div class="col-6 col-md-2" style="min-width: 125px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">તારીખ *</label>
                                <input type="date" name="assort_date" class="form-control form-control-sm font-mono" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <!-- 3. Rough Lot Dropdown -->
                            <div class="col-12 col-md" style="min-width: 220px;">
                                <label class="form-label small fw-bold text-muted mb-1 text-truncate d-block">Rough ખરીદી લોટ *</label>
                                <select name="rough_purchase_id" id="rough_purchase_id" class="form-select form-select-sm select-tom fw-semibold" onchange="window.location.href='?rough_id=' + this.value" required>
                                    <option value="">-- લોટ પસંદ કરો --</option>
                                    <?php foreach ($roughList as $rl): ?>
                                        <option value="<?= $rl['id'] ?>" <?= $selectedRoughId == $rl['id'] ? 'selected' : '' ?>>
                                            <?= e($rl['purchase_no']) ?> — <?= e($rl['party_name']) ?> (<?= format_carat($rl['available_weight']) ?> / <?= (int)$rl['available_pcs'] ?> Pcs)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 4. Pcs -->
                            <div class="col-6 col-md" style="min-width: 80px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">નંગ (Pcs) *</label>
                                <input type="number" name="input_pcs" id="input_rough_pcs" 
                                       class="form-control form-control-sm fw-bold font-mono text-center" 
                                       value="<?= $availRoughPcs > 0 ? $availRoughPcs : '' ?>" 
                                       <?= $availRoughPcs > 0 ? 'max="' . $availRoughPcs . '"' : '' ?> 
                                       required
                                       <?= !$selectedRough ? 'disabled placeholder="0"' : '' ?>
                                       oninput="recalcAssortCalculations()">
                            </div>

                            <!-- 5. Weight -->
                            <div class="col-6 col-md" style="min-width: 110px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block">રફ વજન (Ct) *</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.01" min="0" name="input_weight" id="input_rough_weight" 
                                           class="form-control fw-bold text-primary font-mono bg-white" 
                                           value="<?= $availRoughWeight > 0 ? $availRoughWeight : '' ?>" 
                                           data-original-weight="<?= $origRoughWeight ?>"
                                           data-purchase-rate="<?= $purchaseRatePerCt ?>"
                                           <?= $availRoughWeight > 0 ? 'max="' . $availRoughWeight . '"' : '' ?> 
                                           required 
                                           <?= !$selectedRough ? 'disabled placeholder="0.00"' : '' ?>
                                           oninput="recalcAssortCalculations()">
                                    <span class="input-group-text font-mono" style="font-size: 0.75rem;">ct</span>
                                </div>
                                <span id="allowed_loss_display" class="d-none"><?= format_carat($allowedLossMax) ?></span>
                            </div>

                            <!-- 6. Rate ₹/ct (Locked) -->
                            <div class="col-6 col-md" style="min-width: 115px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block" title="ખરીદી ભાવ (Locked)">
                                    ખરીદી ભાવ <span class="badge bg-secondary-subtle text-secondary px-1 py-0" style="font-size: 0.65rem;">Lock</span>
                                </label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text font-mono text-muted" style="font-size: 0.75rem;">₹</span>
                                    <input type="text" id="display_purchase_rate_per_ct" 
                                           class="form-control font-mono fw-bold text-success bg-light" 
                                           value="<?= number_format($purchaseRatePerCt, 2) ?>" 
                                           readonly tabindex="-1" style="cursor: not-allowed;">
                                    <span class="input-group-text font-mono text-muted" style="font-size: 0.70rem;">/ct</span>
                                </div>
                            </div>

                            <!-- 7. Total Amount (Locked) -->
                            <div class="col-6 col-md" style="min-width: 135px;">
                                <label class="form-label small fw-bold text-dark mb-1 text-truncate d-block" title="કુલ ખરીદી રકમ (Locked)">
                                    કુલ ખરીદી રકમ <span class="badge bg-secondary-subtle text-secondary px-1 py-0" style="font-size: 0.65rem;">Lock</span>
                                </label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text font-mono text-muted" style="font-size: 0.75rem;">₹</span>
                                    <input type="text" id="display_purchase_total_amount" 
                                           class="form-control font-mono fw-bold text-primary bg-light" 
                                           value="<?= number_format($purchaseTotalAmount, 2) ?>" 
                                           readonly tabindex="-1" style="cursor: not-allowed;">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CARD 2: DYNAMIC PARTS ALLOCATION & ROUTING TABLE -->
            <?php if ($hasActiveLot): ?>
                <div class="card-erp mb-4">
                    <div class="card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="fw-bold text-dark fs-6">
                                <?= svg_icon('puzzle-piece', 'text-primary me-2') ?>૨. Rough ના ભાગ પાડવા (Parts Allocation & Routing)
                            </span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" target="_blank" class="btn btn-outline-secondary btn-sm" title="સેટિંગ્સમાંથી નવા ભાગ ઉમેરો">
                                <?= svg_icon('gear', 'me-1') ?>માસ્ટર સેટિંગ્સ
                            </a>
                            <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" id="addPartRowBtn" onclick="window.addPartRow && window.addPartRow()">
                                <?= svg_icon('plus', 'me-1') ?>નવો ભાગ ઉમેરો (Add Row)
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered align-middle mb-0" id="partsAllocationTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 17%;">Assort No (હાયરાર્કી) *</th>
                                        <th style="width: 25%;">ભાગનું નામ (Part Name) *</th>
                                        <th style="width: 9%;">નંગ (PCs)</th>
                                        <th style="width: 14%;">વજન (Weight Ct) *</th>
                                        <th style="width: 15%;">અંદાજિત ભાવ (Rate ₹/Ct)</th>
                                        <th style="width: 15%;">અંદાજિત રકમ (Total ₹)</th>
                                        <th style="width: 5%;" class="text-center">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="partsTableBody">
                                    <!-- Dynamic rows will be inserted here -->
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
                                                <span class="input-group-text font-mono">ct</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group" title="કુલ સરેરાશ કેરેટ દીઠ ભાવ (Total Average Ct Price)">
                                                <span class="input-group-text font-mono text-success fw-bold">₹/ct</span>
                                                <input type="text" id="footer_avg_rate" class="form-control font-mono fw-bold text-end text-success bg-white" value="0.00" readonly placeholder="Avg ₹">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group" title="કુલ અંદાજિત કિંમત (Total Amount)">
                                                <span class="input-group-text font-mono text-primary fw-bold">₹</span>
                                                <input type="text" id="footer_total_amount" class="form-control font-mono fw-bold text-end text-primary bg-white" value="0.00" readonly placeholder="Total ₹">
                                            </div>
                                        </td>
                                        <td></td>
                                    </tr>
                                    <!-- SYSTEM-GENERATED LOSS ROUGH ROW (ALWAYS LAST ROW) -->
                                    <tr id="lossRoughRow" class="table-light border-top" style="background-color: #f8fafc;">
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <?= svg_icon('calculator', 'text-muted') ?>
                                                <strong class="text-dark font-mono">Loss / Difference</strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary border font-mono">ઘટ / તફાવત (Loss Diff)</span>
                                        </td>
                                        <td>
                                            <input type="text" id="loss_pcs_display" class="form-control font-mono fw-bold text-center" value="0" readonly style="background: #ffffff; cursor: not-allowed;">
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <input type="text" id="loss_rough_display" class="form-control fw-bold font-mono text-end" value="0.000" readonly style="background: #ffffff; transition: color 0.2s ease;">
                                                <span class="input-group-text font-mono">ct</span>
                                            </div>
                                        </td>
                                        <td colspan="2" class="text-center align-middle font-mono">
                                            <span class="badge bg-light text-muted border px-2 py-1">Loss Valuation Excluded</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-muted border" title="આ રો સિસ્ટમ જનરેટેડ છે અને ડિલીટ થઈ શકતી નથી">Auto</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- LIVE REAL-TIME TERMINAL & 2% LOSS VALIDATION BOX -->
                        <div class="p-3 rounded-3 mb-4" id="validationTerminalCard" style="background: #ffffff; border: 1.5px solid #e2e8f0;">
                            <!-- Integrated Full-Width Validation Banner -->
                            <div id="validationBanner" class="py-2 px-3 rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0; transition: all 0.25s ease;">
                                <div class="d-flex align-items-center gap-3">
                                    <div id="validationIconWrap" style="width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #D1FAE5; flex-shrink: 0;">
                                        <span id="validationIcon" style="color: #059669; display: inline-flex;"><?= svg_icon('circle-check', '', 20) ?></span>
                                    </div>
                                    <div>
                                        <div class="fw-bold" id="validationTitle" style="color: #064e3b; font-size: 1rem;">માન્ય ઘટ (Validation PASS)</div>
                                        <div id="validationDesc" style="color: #065f46; font-size: 0.85rem;">
                                            ઘટ રફ વજન 0.000 ct (0.00%) માન્ય ૨% મર્યાદાની અંદર છે.
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <span id="display_validation_badge" class="badge rounded-pill fw-bold px-3 py-2" style="background: #059669; color: #ffffff; font-size: 0.85rem;">
                                        <?= svg_icon('check', 'me-1', 14) ?>PASS (માન્ય ૨% ઘટ)
                                    </span>
                                </div>
                            </div>

                            <!-- Metric Stat Chips Grid -->
                            <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-2 text-center">
                                <div class="col">
                                    <div class="p-2 rounded-2 border bg-light h-100 d-flex flex-column justify-content-center">
                                        <span class="text-muted small d-block mb-1">મૂળ ખરીદી રફ વજન</span>
                                        <span class="font-mono fw-bold fs-6 text-dark" id="display_purchase_rough">0.000 ct</span>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-2 rounded-2 border bg-light h-100 d-flex flex-column justify-content-center">
                                        <span class="text-muted small d-block mb-1">કુલ વિભાજિત વજન</span>
                                        <span class="font-mono fw-bold fs-6 text-primary" id="display_allocated_weight">0.000 ct</span>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-2 rounded-2 border h-100 d-flex flex-column justify-content-center" id="loss_stat_box" style="background: #f8fafc; border-color: #cbd5e1;">
                                        <span class="text-muted small d-block mb-1">ઘટ રફ વજન (Loss)</span>
                                        <span class="font-mono fw-bold fs-6" id="display_loss_weight" style="color: #0f172a;">0.000 ct</span>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-2 rounded-2 border bg-light h-100 d-flex flex-column justify-content-center">
                                        <span class="text-muted small d-block mb-1">મહત્તમ માન્ય ૨% ઘટ</span>
                                        <span class="font-mono fw-bold fs-6 text-dark" id="display_allowed_loss">0.000 ct</span>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-2 rounded-2 border bg-light h-100 d-flex flex-column justify-content-center">
                                        <span class="text-muted small d-block mb-1">વાસ્તવિક ઘટ ટકાવારી</span>
                                        <span class="font-mono fw-bold fs-6" id="display_loss_percent" style="color: #0f172a;">0.00%</span>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-2 rounded-2 border h-100 d-flex flex-column justify-content-center" id="pcs_stat_box" style="background: #f8fafc; border-color: #cbd5e1;">
                                        <span class="text-muted small d-block mb-1">નંગ તફાવત (Pcs Diff)</span>
                                        <span class="font-mono fw-bold fs-6" id="display_pcs_diff" style="color: #059669;">0 Pcs</span>
                                        <span class="font-mono small fw-semibold d-none" id="display_pcs_ratio">0 / 0 Pcs</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes & Actions -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">સામાન્ય નોંધ (General Notes)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Assort ટાસ્ક, ક્વોલિટી કે પાર્ટ્સ વિભાજન સંબંધિત કોઈ નોંધ..."></textarea>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 border-top">
                            <div class="text-muted small">
                                <?= svg_icon('shield-halved', 'text-primary me-1') ?>
                                સર્વર-સાઇડ સુરક્ષા: ૨% થી વધુ ઘટ અથવા ખોટું વજન હોવા પર સિસ્ટમ સેવ થવા દેશે નહીં.
                            </div>
                            <div class="d-flex gap-2">
                                <a href="<?= BASE_URL ?>/modules/assort/index.php" class="btn btn-outline-secondary px-4">રદ કરો</a>
                                <button type="submit" id="submit_assort_btn" class="btn btn-primary px-4 fw-bold shadow-sm">
                                    <?= svg_icon('check-double', 'me-1') ?>Assort સેવ કરો & Task Issue કરો
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card-erp mb-4">
                    <div class="alert alert-warning p-4 rounded-3 text-center mb-0">
                        <?= svg_icon('circle-exclamation', 'fs-3 text-warning mb-2 d-block', 22) ?>
                        <?php if ($sourceMode === 'boil_reassort'): ?>
                            <h5>હાલમાં કોઈ Boil પરત Re-Assort લોટ બાકી નથી!</h5>
                            <p class="text-muted mb-3">Boil બેચમાંથી ઓફિસ Return સમયે 'ફરીથી Assort માં મોકલો' પસંદ કરશો એટલે નવો લોટ અહીં દેખાશે.</p>
                            <a href="<?= BASE_URL ?>/modules/boil/index.php" class="btn btn-primary fw-bold">
                                <?= svg_icon('fire-burner', 'me-1') ?>Boil બેચ રજિસ્ટર જુઓ
                            </a>
                        <?php else: ?>
                            <h5>કોઈ ઉપલબ્ધ Rough ખરીદી લોટ નથી!</h5>
                            <p class="text-muted mb-3">Assort ટાસ્ક બનાવવા માટે પહેલાં સ્ટોકમાં હાજર Rough ખરીદી હોવી જરૂરી છે.</p>
                            <a href="<?= BASE_URL ?>/modules/rough_purchase/create.php" class="btn btn-primary fw-bold">
                                <?= svg_icon('plus', 'me-1') ?>નવી Rough ખરીદી નોંધો
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Dynamic Part Row Template and Client Calculation Logic -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Master data loaded dynamically from settings
    const availablePartNames = <?= json_encode($activePartNames, JSON_UNESCAPED_UNICODE) ?>;
    const availablePartTypes = <?= json_encode($activePartTypes, JSON_UNESCAPED_UNICODE) ?>;
    <?php
        $jsLotPrefix = ($sourceMode === 'boil_reassort' && $selectedReassortLot)
            ? ($selectedReassortLot['lot_no'] ?? 'LOT')
            : ($selectedRough['purchase_no'] ?? '01');
    ?>
    let currentRoughPurchaseNo = <?= json_encode($jsLotPrefix, JSON_UNESCAPED_UNICODE) ?>;
    const isReassortMode = <?= json_encode($sourceMode === 'boil_reassort') ?>;
    const lotRateEl = document.getElementById('lot_rate_input');
    const lotValDisplayEl = document.getElementById('lot_total_value_display');
    const lotNoInputEl = document.getElementById('lot_no_input');
    if (lotNoInputEl) {
        lotNoInputEl.addEventListener('input', function () {
            const val = this.value.trim();
            if (val !== '') {
                currentRoughPurchaseNo = val;
                const rows = document.querySelectorAll('#partsTableBody .part-assort-input');
                rows.forEach((inp, i) => {
                    inp.value = currentRoughPurchaseNo + '.' + (i + 1);
                });
            }
        });
    }

    const tbody = document.getElementById('partsTableBody');
    const addRowBtn = document.getElementById('addPartRowBtn');
    const inputWeightEl = document.getElementById('input_rough_weight');
    const submitBtn = document.getElementById('submit_assort_btn');

    let rowIndex = 0;

    // Function to generate options HTML for Part Names with Tag Label
    function getPartTagBadge(tag) {
        if (tag === 'sale') return ' [🏷️ Sell]';
        if (tag === 'mfg_later') return ' [⏳ MFG Later]';
        return ' [⚙️ MFG]';
    }

    function getPartNameOptionsHtml(selectedVal = '') {
        let html = '<option value="">-- ભાગ પસંદ કરો --</option>';
        availablePartNames.forEach(function (item) {
            const isSel = (item.name === selectedVal) ? 'selected' : '';
            const tag = item.tag || 'mfg';
            const tagBadge = getPartTagBadge(tag);
            html += `<option value="${escapeHtml(item.name)}" data-tag="${escapeHtml(tag)}" ${isSel}>${escapeHtml(item.name)}${tagBadge}</option>`;
        });
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

    // Function to add a new allocation row with hierarchical Assort Number (e.g. 01.1, 01.2)
    function addPartRow(defaultName = '', defaultType = '', defaultPcs = '', defaultWeight = '', defaultKapan = '', defaultAssortNo = '', defaultRate = '') {
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

        const initialRate = (defaultRate !== '' && defaultRate !== null) ? defaultRate : (isReassortMode && lotRateEl && lotRateEl.value ? lotRateEl.value : '');

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
                    <input type="number" step="0.01" min="0" name="parts[${rowIndex}][rate]" class="form-control font-mono text-success fw-bold text-end part-rate-input" placeholder="0.00" value="${escapeHtml(initialRate)}" oninput="this.dataset.manual='1'; recalcAssortCalculations();">
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
                    ${window.svgIcon ? window.svgIcon('trash-can', '', 14) : 'X'}
                </button>
            </td>
        `;

        tbody.appendChild(tr);
        rowIndex++;

        // Initialize searchable select on new selects if TomSelect available
        if (window.initSmartSearchableSelects) {
            window.initSmartSearchableSelects();
        }

        recalcAssortCalculations();
    }

    window.addPartRow = function (name, type, pcs, wt, kapan, assortNo, rate) {
        if (!name && availablePartNames && availablePartNames.length) {
            const currentRows = tbody ? tbody.querySelectorAll('.part-data-row').length : 0;
            name = availablePartNames[currentRows] ? availablePartNames[currentRows].name : '';
            const tag = availablePartNames[currentRows] ? (availablePartNames[currentRows].tag || 'mfg') : 'mfg';
            type = (tag === 'sale') ? 'out_sell' : ((tag === 'mfg_later') ? 'low_number_mfg' : 'mfg');
        }
        addPartRow(name || '', type || '', pcs || '', wt || '', kapan || '', assortNo || '', rate || '');
    };

    // Function to remove a row
    window.removePartRow = function (btn) {
        const row = btn.closest('tr');
        if (row) {
            row.remove();
            recalcAssortCalculations();
        }
    };

    // Add Row Click Event
    if (addRowBtn) {
        addRowBtn.addEventListener('click', function () {
            window.addPartRow();
        });
    }

    // Main Calculation & Validation Function
    window.recalcAssortCalculations = function () {
        if (!inputWeightEl) return;

        // 1. Total Rough Weight (Base Weight)
        const totalRoughWeight = parseFloat(inputWeightEl.value) || 0.000;
        const origWeightAttr = parseFloat(inputWeightEl.dataset.originalWeight) || totalRoughWeight;

        // 2. Sum up all allocated rows (Weight, Pcs, Approx Amount)
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

        // 4. Automatic Loss Rough Calculation
        // Loss Rough Weight = Total Rough Weight - Allocated Rough Weight
        const lossRoughWeight = Math.round((totalRoughWeight - totalAllocatedWeight) * 1000) / 1000;

        // 5. Allowed 2% Loss based on Purchase Rough Weight
        const allowedLoss = Math.round((totalRoughWeight * 0.02) * 1000) / 1000;
        const lossPercent = (totalRoughWeight > 0) ? Math.round(((lossRoughWeight / totalRoughWeight) * 100) * 100) / 100 : 0.00;

        // 6. Update UI Elements
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
        const allowedLossHeader = document.getElementById('allowed_loss_display');
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
        
        const dispPurchaseTotal = document.getElementById('display_purchase_total_amount');
        if (dispPurchaseTotal && inputWeightEl && inputWeightEl.dataset.purchaseRate) {
            const pRate = parseFloat(inputWeightEl.dataset.purchaseRate) || 0;
            const curWt = parseFloat(inputWeightEl.value) || 0;
            dispPurchaseTotal.value = (pRate * curWt).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (lossPcsDisplay) {
            lossPcsDisplay.value = pcsDifference;
            lossPcsDisplay.style.color = (pcsDifference === 0) ? '#059669' : '#dc2626';
        }

        if (allowedLossHeader) {
            allowedLossHeader.innerText = allowedLoss.toFixed(3) + ' ct';
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

        // 7. Color Logic & 2% Weight & Pcs Validation Enforcement
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

        if (!isReassortMode) return;
        updateReassortValueDisplay();
    };

    window.syncPartRatesFromLotRate = function () {
        if (!isReassortMode || !lotRateEl) return;
        const val = lotRateEl.value;
        document.querySelectorAll('#partsTableBody .part-rate-input').forEach(function (inp) {
            if (!inp.dataset.manual || inp.value === '') {
                inp.value = val;
            }
        });
        updateReassortValueDisplay();
    };

    window.updateReassortValueDisplay = function () {
        if (!isReassortMode || !lotValDisplayEl) return;
        const baseRate = parseFloat(lotRateEl ? lotRateEl.value : '0') || 0;
        let totalVal = 0;
        let hasPartWeights = false;
        document.querySelectorAll('#partsTableBody .part-data-row').forEach(function (row) {
            const wt = parseFloat(row.querySelector('.part-weight-input')?.value || '0') || 0;
            const rt = parseFloat(row.querySelector('.part-rate-input')?.value || '') || baseRate;
            if (wt > 0) {
                hasPartWeights = true;
                totalVal += (wt * rt);
            }
        });
        if (!hasPartWeights && inputWeightEl) {
            totalVal = (parseFloat(inputWeightEl.value) || 0) * baseRate;
        }
        lotValDisplayEl.textContent = '₹ ' + totalVal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    // Wrap recalcAssortCalculations to also refresh lot value
    const _origRecalc = window.recalcAssortCalculations;
    window.recalcAssortCalculations = function () {
        _origRecalc();
        if (window.updateReassortValueDisplay) window.updateReassortValueDisplay();
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Seed default rows on load (e.g. 3 initial rows)
    <?php if ($hasActiveLot): ?>
        addPartRow(
            availablePartNames[0] ? availablePartNames[0].name : '', 
            '', '', '', ''
        );
        addPartRow(
            availablePartNames[1] ? availablePartNames[1].name : '', 
            '', '', '', ''
        );
        addPartRow(
            availablePartNames[2] ? availablePartNames[2].name : '', 
            '', '', '', ''
        );
    <?php endif; ?>

    const roughPcsInput = document.getElementById('input_rough_pcs');
    if (roughPcsInput) {
        roughPcsInput.addEventListener('input', recalcAssortCalculations);
        roughPcsInput.addEventListener('change', recalcAssortCalculations);
    }

    recalcAssortCalculations();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
