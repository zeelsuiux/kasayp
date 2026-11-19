<?php
/**
 * Stage Transition & Diamond Processing Engine (સેક્શન ૬.૪)
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
ensure_packets_dynamic_stage_column();
$packetId = (int)($_GET['id'] ?? $_POST['packet_id'] ?? 0);

$stmt = $pdo->prepare("SELECT p.*, k.kapan_code, k.kapan_name, pt.party_name as vendor_name
                       FROM packets p
                       JOIN kapans k ON p.kapan_id = k.id
                       LEFT JOIN parties pt ON p.assigned_party_id = pt.id
                       WHERE p.id = ?");
$stmt->execute([$packetId]);
$packet = $stmt->fetch();

if (!$packet) {
    set_flash('error', 'પેકેટ મળ્યું નથી.');
    header('Location: ' . BASE_URL . '/modules/process/index.php');
    exit;
}

// Fetch Outsource vendors and all parties for searchable factory selection
$allParties = $pdo->query("SELECT id, party_name, party_type, city FROM parties ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$galaxyVendors = $pdo->query("SELECT id, party_name FROM parties WHERE party_type IN ('outsource_galaxy', 'both') ORDER BY party_name ASC")->fetchAll();
$lsVendors = $pdo->query("SELECT id, party_name FROM parties WHERE party_type IN ('outsource_ls', 'both') ORDER BY party_name ASC")->fetchAll();
$factoryVendors = $pdo->query("SELECT id, party_name FROM parties WHERE party_type IN ('outsource_factory', 'both') ORDER BY party_name ASC")->fetchAll();
$activeStagesList = get_active_processing_stages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $newStage = $_POST['target_stage'] ?? $packet['current_stage'];
    $location = $_POST['current_location'] ?? $packet['current_location'];
    $rawPartyId = !empty($_POST['assigned_party_id']) ? (int)$_POST['assigned_party_id'] : 0;
    $validPartyId = null;
    if ($rawPartyId > 0) {
        $chkP = $pdo->prepare("SELECT id FROM parties WHERE id = ?");
        $chkP->execute([$rawPartyId]);
        if ($chkP->fetchColumn()) {
            $validPartyId = $rawPartyId;
        }
    }
    $partyId = $validPartyId;
    $cost = (float)($_POST['stage_cost'] ?? 0.00);
    $remarks = trim($_POST['remarks'] ?? '');

    // Planner specific
    $shape = !empty($_POST['expected_shape']) ? trim($_POST['expected_shape']) : $packet['expected_shape'];
    $canMakeSecond = isset($_POST['can_make_second_part']) ? 1 : 0;
    $secondaryShape = !empty($_POST['secondary_shape']) ? trim($_POST['secondary_shape']) : null;

    // LS specific
    $lsRequired = $_POST['ls_required'] ?? $packet['ls_required'];
    $isKachiProcess = isset($_POST['is_kachi_process']);
    $topsWeight = (float)($_POST['tops_weight'] ?? 0.000);
    $topsRate = !empty($_POST['tops_rate']) ? (float)$_POST['tops_rate'] : 8500.00;
    $usePartWeight = (float)($_POST['use_part_weight'] ?? $packet['weight']);

    // Polish Return specific
    $polishWeight = (float)($_POST['polish_weight'] ?? $packet['polish_weight']);

    try {
        $pdo->beginTransaction();

        if ($newStage === 'reassort') {
            $isBoilReturn = in_array(strtolower((string)($packet['current_stage'] ?? '')), ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'], true);
            if (!$isBoilReturn) {
                throw new \RuntimeException("માત્ર બોઇલ રિટર્ન (Boil Return) થયેલા હીરા જ Re-Assort થઈ શકે છે.");
            }

            $lotNo = trim($_POST['reassort_lot_no'] ?? '');
            if ($lotNo === '') {
                $lotNo = generate_next_reassort_lot_no();
            }
            $lotRate = max(0.0, (float)($_POST['reassort_lot_rate'] ?? 0));
            $returnDate = date('Y-m-d');
            $notes = trim($_POST['remarks'] ?? 'Stage Update to Re-Assort');

            $res = process_boil_return_to_reassort(!empty($packet['boil_batch_id']) ? (int)$packet['boil_batch_id'] : null, [(int)$packet['id']], $lotNo, $returnDate, $notes, $user['id'] ?? null, $lotRate);
            $pdo->commit();

            set_flash('success', "પેકેટ {$packet['packet_no']} જૂની કાપનમાંથી નીકળી નવા Re-Assort લોટ '{$res['lot_no']}' માં ટ્રાન્સફર થઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/assort/create.php?reassort_lot_id=' . $res['reassort_lot_id']);
            exit;
        }

        $lsStatus = $packet['ls_status'];
        $lsStageCodes = ['ls', 'laser', 'laser_sawing', 'sawing'];
        if (in_array($newStage, $lsStageCodes, true)) {
            $lsStatus = 'pending';
        } elseif (in_array($packet['current_stage'], $lsStageCodes, true)) {
            $lsStatus = $isKachiProcess ? 'kachi_process' : 'completed';
        }

        // If tops scrap generated from LS return, insert into tops_records
        if ($topsWeight > 0) {
            $topsStmt = $pdo->prepare("INSERT INTO tops_records (kapan_id, packet_id, source_stage, pcs, weight, rate_per_carat, total_value, status, record_date, notes)
                                       VALUES (?, ?, 'ls_sawing', 1, ?, ?, ?, 'in_stock', CURDATE(), ?)");
            $topsVal = round($topsWeight * $topsRate, 2);
            $topsStmt->execute([$packet['kapan_id'], $packet['id'], $topsWeight, $topsRate, $topsVal, "Tops from " . $packet['packet_no']]);

            // Update Kapan total tops
            $updKapanTops = $pdo->prepare("UPDATE kapans SET total_tops_weight = total_tops_weight + ? WHERE id = ?");
            $updKapanTops->execute([$topsWeight, $packet['kapan_id']]);
        }

        // Update packet record
        $upd = $pdo->prepare("UPDATE packets SET
            current_stage = ?,
            current_location = ?,
            assigned_party_id = ?,
            expected_shape = ?,
            can_make_second_part = ?,
            secondary_shape = ?,
            ls_required = ?,
            ls_status = ?,
            weight = ?,
            polish_weight = ?,
            tops_weight = tops_weight + ?
            WHERE id = ?");

        $finalPktWeight = ($usePartWeight > 0 && $usePartWeight < (float)$packet['weight']) ? $usePartWeight : (float)$packet['weight'];

        $upd->execute([
            $newStage, $location, $partyId, $shape, $canMakeSecond, $secondaryShape,
            $lsRequired, $lsStatus, $finalPktWeight, $polishWeight, $topsWeight, $packet['id']
        ]);

        // Insert into packet_process_history
        $hist = $pdo->prepare("INSERT INTO packet_process_history (packet_id, stage, action, party_id, input_weight, output_weight, tops_weight, cost, details, logged_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $hist->execute([
            $packet['id'], $newStage, 'Stage Update', $partyId, $packet['weight'],
            ($polishWeight > 0 ? $polishWeight : $finalPktWeight), $topsWeight, $cost, $remarks, $user['id']
        ]);

        // Auto update Kapan stage costs
        if ($cost > 0) {
            $factoryCodes = ['factory', 'blocking', 'faceting', 'polishing', 'bruting', 'final_checking', 'boil'];
            if ($newStage === 'galaxy' || $packet['current_stage'] === 'galaxy') {
                $pdo->prepare("UPDATE kapans SET galaxy_cost = galaxy_cost + ?, total_cost = total_cost + ? WHERE id = ?")->execute([$cost, $cost, $packet['kapan_id']]);
            } elseif (in_array($newStage, $lsStageCodes, true) || in_array($packet['current_stage'], $lsStageCodes, true)) {
                $pdo->prepare("UPDATE kapans SET ls_cost = ls_cost + ?, total_cost = total_cost + ? WHERE id = ?")->execute([$cost, $cost, $packet['kapan_id']]);
            } elseif (in_array($newStage, $factoryCodes, true) || in_array($packet['current_stage'], $factoryCodes, true)) {
                $pdo->prepare("UPDATE kapans SET labour_cost = labour_cost + ?, total_cost = total_cost + ? WHERE id = ?")->execute([$cost, $cost, $packet['kapan_id']]);
            }
        }

        // If polish weight entered, accurately update Kapan polish totals & handle modifications
        $prevPolishWeight = (float)$packet['polish_weight'];
        if ($polishWeight > 0) {
            if ($prevPolishWeight == 0) {
                $pdo->prepare("UPDATE kapans SET total_polish_weight = total_polish_weight + ?, total_polish_pcs = total_polish_pcs + 1 WHERE id = ?")->execute([$polishWeight, $packet['kapan_id']]);
            } elseif ($polishWeight != $prevPolishWeight) {
                $weightDelta = $polishWeight - $prevPolishWeight;
                $pdo->prepare("UPDATE kapans SET total_polish_weight = total_polish_weight + ? WHERE id = ?")->execute([$weightDelta, $packet['kapan_id']]);
            }
        }

        // Record Stage-to-Stage weight audit
        $receivedWeight = ($polishWeight > 0) ? $polishWeight : $finalPktWeight;
        $diffClassification = trim($_POST['diff_classification'] ?? ($topsWeight > 0 ? 'tops' : 'loss'));
        $lossWeight = ($diffClassification === 'loss') ? max(0.0, (float)$packet['weight'] - $receivedWeight) : 0.0;

        record_diamond_stage_return(
            (int)$packet['id'],
            $packet['current_stage'],
            $receivedWeight,
            $diffClassification,
            $lossWeight,
            $topsWeight,
            0.0,
            $location,
            $remarks
        );

        if ($newStage !== $packet['current_stage']) {
            $pNameStmt = $pdo->prepare("SELECT party_name FROM parties WHERE id = ?");
            $pNameStmt->execute([$partyId]);
            $partyName = (string)($pNameStmt->fetchColumn() ?: '');

            record_diamond_stage_assignment(
                (int)$packet['id'],
                $newStage,
                ucfirst(str_replace('_', ' ', $newStage)),
                (int)($partyId ?: 0),
                $partyName,
                0,
                '',
                0,
                $receivedWeight,
                $location,
                $remarks
            );
        }

        // Recalculate Kapan Yield and unit costs dynamically
        $pdo->prepare("UPDATE kapans SET 
            yield_percentage = IF(total_weight > 0, ROUND((total_polish_weight / total_weight) * 100, 2), 0),
            cost_per_piece = IF(total_polish_pcs > 0, ROUND(total_cost / total_polish_pcs, 2), 0),
            cost_per_carat = IF(total_polish_weight > 0, ROUND(total_cost / total_polish_weight, 2), 0)
            WHERE id = ?")->execute([$packet['kapan_id']]);

        // Bridge to Polish Stock only when polishing finishes
        if ($newStage === 'polish_stock') {
            sync_packet_to_polish_stock((int)$packet['id'], ($polishWeight > 0 ? $polishWeight : $receivedWeight), $shape);
        }

        $pdo->commit();
        log_activity('Packet Stage Updated', 'Process', $packet['id'], "Moved {$packet['packet_no']} from {$packet['current_stage']} to $newStage");
        set_flash('success', "પેકેટ {$packet['packet_no']} સફળતાપૂર્વક સ્ટેજ '$newStage' માં અપડેટ થઈ ગયું છે.");
        header('Location: ' . BASE_URL . '/modules/process/index.php');
        exit;
    } catch (\Throwable $ex) {
        $pdo->rollBack();
        set_flash('error', 'સ્ટેજ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
}

$pageTitle = 'પેકેટ સ્ટેજ અપડેટ (Stage Update)';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.qmodal-party-item {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 8px 12px !important;
    border-radius: 8px !important;
    border: 1.5px solid #e2e8f0 !important;
    background: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.15s ease-in-out !important;
    user-select: none !important;
    margin-bottom: 5px !important;
}
.qmodal-party-item:hover {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
}
.qmodal-party-item.active {
    background: #ebf4ff !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 1px 4px rgba(59, 130, 246, 0.14) !important;
}
.qmodal-party-item.active .party-name {
    color: #1d4ed8 !important;
    font-weight: 700 !important;
}
.qmodal-party-item .party-avatar {
    width: 32px !important;
    height: 32px !important;
    border-radius: 7px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.85rem !important;
    flex-shrink: 0 !important;
}
.qmodal-party-item .party-avatar.inhouse       { background: #e2e8f0 !important; color: #475569 !important; }
.qmodal-party-item .party-avatar.avatar-factory { background: #e0f2fe !important; color: #0284c7 !important; }
.qmodal-party-item .party-avatar.avatar-ls      { background: #fef3c7 !important; color: #d97706 !important; }
.qmodal-party-item .party-avatar.avatar-galaxy  { background: #ccfbf1 !important; color: #0d9488 !important; }
.qmodal-party-item .party-avatar.avatar-broker  { background: #f3e8ff !important; color: #9333ea !important; }
.qmodal-party-item .party-avatar.avatar-lab     { background: #e0e7ff !important; color: #4f46e5 !important; }
.qmodal-party-item .party-avatar.avatar-party   { background: #dcfce7 !important; color: #16a34a !important; }

.qmodal-party-item .party-info {
    flex-grow: 1 !important;
    min-width: 0 !important;
}
.qmodal-party-item .party-name {
    font-size: 0.86rem !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    line-height: 1.25 !important;
}
.qmodal-party-item .party-meta {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 0.72rem !important;
    color: #64748b !important;
    margin-top: 2px !important;
}
.qmodal-party-item .badge-type {
    font-size: 0.68rem !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    font-weight: 500 !important;
}
.qmodal-party-item .badge-type.type-inhouse     { background: #f1f5f9 !important; color: #475569 !important; }
.qmodal-party-item .badge-type.avatar-factory   { background: #f0f9ff !important; color: #0369a1 !important; border: 1px solid #bae6fd !important; }
.qmodal-party-item .badge-type.avatar-ls        { background: #fffbeb !important; color: #b45309 !important; border: 1px solid #fde68a !important; }
.qmodal-party-item .badge-type.avatar-galaxy    { background: #f0fdfa !important; color: #0f766e !important; border: 1px solid #99f6e4 !important; }
.qmodal-party-item .badge-type.avatar-broker    { background: #faf5ff !important; color: #7e22ce !important; border: 1px solid #e9d5ff !important; }
.qmodal-party-item .badge-type.avatar-lab       { background: #eef2ff !important; color: #4338ca !important; border: 1px solid #c7d2fe !important; }
.qmodal-party-item .badge-type.avatar-party     { background: #f0fdf4 !important; color: #15803d !important; border: 1px solid #bbf7d0 !important; }

.qmodal-party-item .party-check {
    color: #cbd5e1 !important;
    margin-left: auto !important;
    transition: color 0.15s ease !important;
    flex-shrink: 0 !important;
}
.qmodal-party-item.active .party-check {
    color: #2563eb !important;
}
</style>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <div class="finora-card">
            <div class="finora-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('arrows-split-up-and-left', 'text-primary', 18) ?>
                        <span>પેકેટ સ્ટેજ અપડેટ</span>
                    </h5>
                    <small class="text-muted">પેકેટ નં: <strong><?= e($packet['packet_no']) ?></strong> &bull; બારકોડ: <?= e($packet['barcode']) ?></small>
                </div>
                <a href="<?= BASE_URL ?>/modules/process/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">&larr; બોર્ડ</a>
            </div>

            <div class="finora-card-body p-4">
                <!-- Current Packet Banner -->
                <div class="p-3 bg-light rounded-4 border mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="small text-muted">Kapan</div>
                        <strong><?= e($packet['kapan_name']) ?> (<?= e($packet['kapan_code']) ?>)</strong>
                    </div>
                    <div>
                        <div class="small text-muted">હાલનું વજન</div>
                        <strong class="fs-6 text-primary"><?= format_carat($packet['weight']) ?></strong>
                    </div>
                    <div>
                        <div class="small text-muted">હાલનું સ્ટેજ</div>
                        <span class="finora-pill finora-pill-blue text-uppercase"><?= e($packet['current_stage']) ?></span>
                    </div>
                    <div>
                        <div class="small text-muted">જગ્યા</div>
                        <span class="finora-pill finora-pill-slate"><?= e($packet['current_location']) ?></span>
                    </div>
                    <div>
                        <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= $packet['id'] ?>" class="btn btn-sm bg-primary-subtle text-primary border-0 rounded-pill py-1 px-3 fw-semibold" target="_blank">
                            <?= svg_icon('timeline', 'me-1', 13) ?>મુસાફરી હિસાબ
                        </a>
                    </div>
                </div>

                <form action="<?= BASE_URL ?>/modules/process/update_stage.php?id=<?= $packet['id'] ?>" method="POST">
                    <?= csrf_field() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">આગળનું સ્ટેજ પસંદ કરો *</label>
                            <select name="target_stage" id="updateTargetStageSelect" class="form-select fw-bold text-primary" required onchange="document.getElementById('full_reassort_box').style.display = (this.value === 'reassort') ? 'block' : 'none'">
                                <?php foreach ($activeStagesList as $idx => $stg): ?>
                                    <option value="<?= e($stg['stage_code']) ?>" <?= $packet['current_stage'] === $stg['stage_code'] ? 'selected' : '' ?>>
                                        <?= ($idx + 1) ?>. <?= e($stg['stage_name']) ?> (<?= e(($stg['default_location'] ?? '') ?: 'In House') ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (in_array(strtolower((string)($packet['current_stage'] ?? '')), ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'], true)): ?>
                                    <option value="reassort" class="text-warning-emphasis fw-bold">
                                        ⚡ Re-Assort કરો (નવો લોટ પાડો &amp; જૂની કાપનમાંથી એક્ઝિટ)
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">જગ્યા *</label>
                            <select name="current_location" id="updateStageLocationSelect" class="form-select">
                                <option value="Surat" <?= $packet['current_location'] === 'Surat' ? 'selected' : '' ?>>Surat Office</option>
                                <option value="Mumbai" <?= $packet['current_location'] === 'Mumbai' ? 'selected' : '' ?>>Mumbai Office</option>
                                <option value="Galaxy" <?= $packet['current_location'] === 'Galaxy' ? 'selected' : '' ?>>Galaxy Hub</option>
                                <option value="LS" <?= $packet['current_location'] === 'LS' ? 'selected' : '' ?>>LS Sawing Center</option>
                                <option value="Katargam Karkhanu" <?= $packet['current_location'] === 'Katargam Karkhanu' ? 'selected' : '' ?>>Katargam Karkhanu</option>
                                <option value="GIA" <?= $packet['current_location'] === 'GIA' ? 'selected' : '' ?>>GIA Laboratory</option>
                            </select>
                        </div>
                    </div>

                    <!-- Direct Re-Assort Box -->
                    <div id="full_reassort_box" class="p-3 rounded-4 border bg-warning-subtle mb-3" style="display: none;">
                        <div class="fw-bold text-dark mb-2 d-flex align-items-center gap-1.5">
                            <?= svg_icon('fire-burner', 'text-warning-emphasis', 15) ?>
                            <span>Re-Assort લોટ વિગત (નવો લોટ નંબર &amp; રેટ)</span>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark">નવો લોટ નંબર (Lot No) *</label>
                                <input type="text" name="reassort_lot_no" class="form-control fw-bold font-mono text-primary" value="<?= e(generate_next_reassort_lot_no()) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark">લોટ રેટ ₹/Ct (વૈકલ્પિક)</label>
                                <input type="number" step="0.01" min="0" name="reassort_lot_rate" class="form-control font-mono fw-bold" placeholder="0.00">
                            </div>
                        </div>
                        <small class="text-muted d-block">સેવ કરતાં જ આ પેકેટ જૂની કાપનમાંથી નીકળી જશે અને નવા લોટ સાથે Assort પેજ પર જશે.</small>
                    </div>

                    <!-- Searchable Factory / Workshop Section -->
                    <div class="p-3 rounded-4 border mb-3 bg-white">
                        <input type="hidden" name="assigned_party_id" id="assignedPartySelect" value="<?= e((string)($packet['assigned_party_id'] ?? '')) ?>">

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark mb-0 small d-flex align-items-center gap-1.5">
                                <?= svg_icon('industry', 'text-primary', 14) ?>
                                <span>કારખાનું / પાર્ટી પસંદ કરો</span>
                                <span class="text-danger">*</span>
                            </label>
                            <span class="finora-pill finora-pill-blue font-mono" id="upd_party_count">
                                <?= count($allParties) ?> પાર્ટી
                            </span>
                        </div>

                        <!-- Integrated Search Bar -->
                        <div class="position-relative mb-2">
                            <span class="position-absolute top-50 text-muted d-inline-flex" style="left: 12px; transform: translateY(-50%); pointer-events: none;">
                                <?= svg_icon('magnifying-glass', '', 14) ?>
                            </span>
                            <input type="text" id="partySearchInput" class="form-control form-control-sm" 
                                   placeholder="કારખાનું અથવા પાર્ટી સર્ચ કરો..." 
                                   style="padding-left: 36px; padding-right: 32px; height: 38px; border-radius: 10px; border: 1px solid #E2E8F0; font-size: 0.84rem;"
                                   oninput="filterParties(this.value)" autocomplete="off">
                            <button type="button" id="partySearchClearBtn" class="btn btn-link position-absolute top-50 p-0 text-muted text-decoration-none" 
                                    style="right: 10px; transform: translateY(-50%); display: none;"
                                    onclick="clearUpdatePartySearch()" title="Clear">
                                <?= svg_icon('circle-xmark', '', 14) ?>
                            </button>
                        </div>

                        <!-- Interactive Party List Container -->
                        <div id="upd_party_list" style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 5px; padding-right: 2px;">
                            <div class="qmodal-party-item <?= empty($packet['assigned_party_id']) ? 'active' : '' ?>" data-party-id="" data-party-name="ઇન-હાઉસ / પોતાની ઓફિસ" data-city="Surat" onclick="selectUpdateParty(this)">
                                <div class="party-avatar inhouse"><?= svg_icon('building', '', 15) ?></div>
                                <div class="party-info">
                                    <div class="party-name">ઇન-હાઉસ / પોતાની ઓફિસ</div>
                                    <div class="party-meta"><span class="badge-type type-inhouse">ઓફિસ</span> <span class="party-city"><?= svg_icon('location-dot', 'me-1', 11) ?>સુરત</span></div>
                                </div>
                                <div class="party-check"><?= svg_icon('circle-check', '', 16) ?></div>
                            </div>

                            <?php foreach ($allParties as $pt): 
                                $ptType = $pt['party_type'] ?? 'party';
                                $ptTypeBadge = match($ptType) {
                                    'outsource_factory' => 'કારખાનું',
                                    'outsource_ls'      => 'LS',
                                    'outsource_galaxy'  => 'Galaxy',
                                    'broker'            => 'દલાલ',
                                    'lab_gia'           => 'GIA Lab',
                                    default             => 'પાર્ટી'
                                };
                                $avatarClass = match($ptType) {
                                    'outsource_factory' => 'avatar-factory',
                                    'outsource_ls'      => 'avatar-ls',
                                    'outsource_galaxy'  => 'avatar-galaxy',
                                    'broker'            => 'avatar-broker',
                                    'lab_gia'           => 'avatar-lab',
                                    default             => 'avatar-party'
                                };
                                $avatarIcon = match($ptType) {
                                    'outsource_factory' => 'industry',
                                    'outsource_ls'      => 'bolt',
                                    'outsource_galaxy'  => 'atom',
                                    'broker'            => 'handshake',
                                    'lab_gia'           => 'certificate',
                                    default             => 'user-tag'
                                };
                                $cityText = !empty($pt['city']) ? $pt['city'] : 'Surat';
                                $isSelected = ((int)$packet['assigned_party_id'] === (int)$pt['id']);
                            ?>
                                <div class="qmodal-party-item <?= $isSelected ? 'active' : '' ?>" data-party-id="<?= (int)$pt['id'] ?>" data-party-name="<?= e($pt['party_name']) ?>" data-city="<?= e($cityText) ?>" onclick="selectUpdateParty(this)">
                                    <div class="party-avatar <?= $avatarClass ?>"><?= svg_icon($avatarIcon, '', 14) ?></div>
                                    <div class="party-info">
                                        <div class="party-name"><?= e($pt['party_name']) ?></div>
                                        <div class="party-meta"><span class="badge-type <?= $avatarClass ?>"><?= $ptTypeBadge ?></span> <span class="party-city"><?= svg_icon('location-dot', 'me-1', 11) ?><?= e($cityText) ?></span></div>
                                    </div>
                                    <div class="party-check"><?= svg_icon('circle-check', '', 16) ?></div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div id="upd_party_empty" class="text-center py-3 text-muted" style="display: none;">
                                <div class="mb-1 opacity-50"><?= svg_icon('building', '', 24) ?></div>
                                <div class="small fw-semibold">કોઈ કારખાનું કે પાર્ટી મળી નથી</div>
                            </div>
                        </div>
                    </div>

                    <!-- Stage 2: Planner Specific -->
                    <div class="p-3 bg-light rounded-4 border mb-3">
                        <h6 class="fw-bold text-primary mb-2 d-flex align-items-center gap-1.5"><?= svg_icon('compass-drafting', '', 15) ?>Planner નોંધ</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">મુખ્ય શેપ</label>
                                <select name="expected_shape" class="form-select">
                                    <option value="Round" <?= $packet['expected_shape'] === 'Round' ? 'selected' : '' ?>>Round Brilliant</option>
                                    <option value="Princess" <?= $packet['expected_shape'] === 'Princess' ? 'selected' : '' ?>>Princess</option>
                                    <option value="Emerald" <?= $packet['expected_shape'] === 'Emerald' ? 'selected' : '' ?>>Emerald</option>
                                    <option value="Oval" <?= $packet['expected_shape'] === 'Oval' ? 'selected' : '' ?>>Oval</option>
                                    <option value="Pear" <?= $packet['expected_shape'] === 'Pear' ? 'selected' : '' ?>>Pear</option>
                                    <option value="Marquise" <?= $packet['expected_shape'] === 'Marquise' ? 'selected' : '' ?>>Marquise</option>
                                    <option value="Cushion" <?= $packet['expected_shape'] === 'Cushion' ? 'selected' : '' ?>>Cushion</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">બીજો ભાગ શક્ય છે?</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="can_make_second_part" id="can_second" <?= !empty($packet['can_make_second_part']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="can_second">હા, બીજો ભાગ બનશે</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">બીજા ભાગનો શેપ</label>
                                <input type="text" name="secondary_shape" class="form-control" value="<?= e($packet['secondary_shape'] ?? '') ?>" placeholder="દા.ત. Princess, Pear">
                            </div>
                        </div>
                    </div>

                    <!-- Stage 4 & 5: Laser Sawing (LS) & Tops -->
                    <div class="p-3 bg-light rounded-4 border mb-3">
                        <h6 class="fw-bold text-warning-emphasis mb-2 d-flex align-items-center gap-1.5"><?= svg_icon('bolt', 'text-warning', 15) ?>Laser Sawing (LS) & Tops</h6>
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">LS જોઈએ?</label>
                                <select name="ls_required" class="form-select">
                                    <option value="no" <?= $packet['ls_required'] === 'no' ? 'selected' : '' ?>>ના (Direct Final)</option>
                                    <option value="yes" <?= $packet['ls_required'] === 'yes' ? 'selected' : '' ?>>હા (LS Sawing)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted">Use Part વજન</label>
                                <input type="number" step="0.001" name="use_part_weight" class="form-control" value="<?= (float)$packet['weight'] ?>" placeholder="0.000">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">Tops (રદિયો)</label>
                                <input type="number" step="0.001" name="tops_weight" class="form-control" placeholder="0.000">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">Tops ભાવ / Ct</label>
                                <input type="number" step="0.01" name="tops_rate" class="form-control" value="8500.00" placeholder="8500.00">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted">કાચી પ્રોસેસ?</label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_kachi_process" id="kachi">
                                    <label class="form-check-label small text-danger fw-semibold" for="kachi">LS વગર પાછું આવ્યું</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stage 6 & 7: Factory & Polish Return -->
                    <div class="p-3 bg-light rounded-4 border mb-3">
                        <h6 class="fw-bold text-danger mb-2 d-flex align-items-center gap-1.5"><?= svg_icon('industry', '', 15) ?>કારખાનું & Polish Return વજન</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">જવાબદાર પાર્ટી</label>
                                <select name="assigned_party_id" class="form-select">
                                    <option value="">પાર્ટી પસંદ કરો...</option>
                                    <optgroup label="Galaxy Scanners">
                                        <?php foreach ($galaxyVendors as $gv): ?>
                                            <option value="<?= $gv['id'] ?>" <?= $packet['assigned_party_id'] == $gv['id'] ? 'selected' : '' ?>><?= e($gv['party_name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="LS Sawing Vendors">
                                        <?php foreach ($lsVendors as $lv): ?>
                                            <option value="<?= $lv['id'] ?>" <?= $packet['assigned_party_id'] == $lv['id'] ? 'selected' : '' ?>><?= e($lv['party_name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Polish Factories">
                                        <?php foreach ($factoryVendors as $fv): ?>
                                            <option value="<?= $fv['id'] ?>" <?= $packet['assigned_party_id'] == $fv['id'] ? 'selected' : '' ?>><?= e($fv['party_name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">Polish Return વજન (Ct)</label>
                                <input type="number" step="0.001" name="polish_weight" id="stage_polish_weight" class="form-control fw-bold text-primary" value="<?= (float)$packet['polish_weight'] ?>" placeholder="0.000" oninput="calcStageLabour()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">પ્રોસેસ ખર્ચ / Labour (INR)</label>
                                <input type="number" step="0.01" name="stage_cost" id="stage_cost_input" class="form-control fw-bold text-danger" placeholder="0.00">
                            </div>
                        </div>

                        <!-- Diamond Factory Polish Quick Calculator -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small fw-bold text-muted">પોલિશ પ્રકાર:</span>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="stage_polish_type" id="spt_normal" value="normal" checked onchange="calcStageLabour()">
                                        <label class="btn btn-outline-primary py-0 px-2" for="spt_normal">Normal</label>
                                        <input type="radio" class="btn-check" name="stage_polish_type" id="spt_quotin" value="quotin" onchange="calcStageLabour()">
                                        <label class="btn btn-outline-danger py-0 px-2" for="spt_quotin">Quotin</label>
                                    </div>
                                    <span id="stage_rule_badge" class="finora-pill finora-pill-slate">0.30ct નિયમ</span>
                                </div>
                                <div class="small">
                                    <span class="text-muted me-1">ઝડપી ગણતરી:</span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-2" onclick="calcStageLabour(150, 1800)">Normal (₹150pc / ₹1800ct)</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill py-0 px-2" onclick="calcStageLabour(280, 3200)">Quotin (₹280pc / ₹3200ct)</button>
                                </div>
                            </div>
                            <div id="stage_labour_info" class="font-monospace small text-muted mt-1" style="font-size: 0.80rem;">
                                0.30 ct નીચે નંગ દીઠ અને ઉપર કેરેટ દીઠ મજૂરી ગણાશે.
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">નોંધ / રિમાર્ક્સ</label>
                        <input type="text" name="remarks" class="form-control" placeholder="સ્ટેજ વિગત અથવા ઇન્સ્ટ્રક્શન...">
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/process/index.php" class="btn btn-light border rounded-pill px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <?= svg_icon('floppy-disk', 'me-1', 14) ?>સ્ટેજ અપડેટ સેવ કરો
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function selectUpdateParty(el) {
    if (!el) return;
    document.querySelectorAll('#upd_party_list .qmodal-party-item').forEach(item => item.classList.remove('active'));
    el.classList.add('active');

    const partyId = el.getAttribute('data-party-id') || '';
    document.getElementById('assignedPartySelect').value = partyId;

    const city = el.getAttribute('data-city');
    const locSelect = document.getElementById('updateStageLocationSelect');
    if (locSelect && city) {
        for (let i = 0; i < locSelect.options.length; i++) {
            if (locSelect.options[i].value.toLowerCase().includes(city.toLowerCase())) {
                locSelect.selectedIndex = i;
                break;
            }
        }
    }
}

function clearUpdatePartySearch() {
    const input = document.getElementById('partySearchInput');
    if (input) {
        input.value = '';
        filterParties('');
        input.focus();
    }
}

function filterParties(query) {
    const q = (query || '').toLowerCase().trim();
    const clearBtn = document.getElementById('partySearchClearBtn');
    const counter = document.getElementById('upd_party_count');
    const emptyState = document.getElementById('upd_party_empty');
    const items = document.querySelectorAll('#upd_party_list .qmodal-party-item');

    if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

    let matchCount = 0;
    items.forEach(item => {
        const partyId = item.getAttribute('data-party-id');
        if (!partyId && !q) {
            item.style.display = 'flex';
            matchCount++;
            return;
        }

        const name = (item.getAttribute('data-party-name') || '').toLowerCase();
        const city = (item.getAttribute('data-city') || '').toLowerCase();
        const text = item.textContent.toLowerCase();
        const matches = name.includes(q) || city.includes(q) || text.includes(q);
        item.style.display = matches ? 'flex' : 'none';
        if (matches) matchCount++;
    });

    if (emptyState) emptyState.style.display = (matchCount === 0) ? 'block' : 'none';
    if (counter) {
        if (q) {
            counter.textContent = matchCount + ' મળ્યા';
            counter.className = 'finora-pill font-mono ' + (matchCount > 0 ? 'finora-pill-blue' : 'finora-pill-rose');
        } else {
            counter.textContent = (items.length - 1) + ' પાર્ટી';
            counter.className = 'finora-pill finora-pill-blue font-mono';
        }
    }
}

function calcStageLabour(overrideBelow, overrideAbove) {
    const polWtInput = document.getElementById('stage_polish_weight');
    const costInput = document.getElementById('stage_cost_input');
    const badge = document.getElementById('stage_rule_badge');
    const info = document.getElementById('stage_labour_info');
    const isQuotin = document.getElementById('spt_quotin')?.checked || false;

    const wt = parseFloat(polWtInput?.value || '0') || 0;
    if (wt <= 0) return;

    let rate = 0;
    let totalCost = 0;
    let label = '';

    if (wt < 0.30) {
        // Below 0.30ct: Qty wise (1 stone = ₹/pc)
        rate = overrideBelow ? overrideBelow : (isQuotin ? 280 : 150);
        totalCost = 1 * rate;
        if (badge) {
            badge.className = 'finora-pill finora-pill-amber';
            badge.innerText = '0.30ct નીચે: નંગ દીઠ';
        }
        label = `${isQuotin ? 'Quotin' : 'Normal'} (0.30ct નીચે) -> 1 નંગ × ₹${rate} = ₹${totalCost.toFixed(2)}`;
    } else {
        // 0.30ct and above: Per Carat wise
        rate = overrideAbove ? overrideAbove : (isQuotin ? 3200 : 1800);
        totalCost = wt * rate;
        if (badge) {
            badge.className = 'finora-pill finora-pill-green';
            badge.innerText = '0.30ct ઉપર: કેરેટ દીઠ';
        }
        label = `${isQuotin ? 'Quotin' : 'Normal'} (0.30ct ઉપર) -> ${wt.toFixed(3)} ct × ₹${rate}/ct = ₹${totalCost.toFixed(2)}`;
    }

    if (costInput) costInput.value = totalCost.toFixed(2);
    if (info) info.innerHTML = `<strong>ગણતરી:</strong> ${label}`;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
