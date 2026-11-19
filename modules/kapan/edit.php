<?php
/**
 * Edit Kapan Entry (કાપન વિગત એડિટ કરો & Rough Packet Merging)
 * Diamond ERP - Units & Rough Cost Module
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

$kapanId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($kapanId <= 0) {
    set_flash('error', 'અમાન્ય કાપન ID.');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

// Fetch Kapan
$stmt = $pdo->prepare("SELECT k.*, rp.purchase_no, p.party_name 
                       FROM kapans k
                       LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
                       LEFT JOIN parties p ON rp.party_id = p.id
                       WHERE k.id = ?");
$stmt->execute([$kapanId]);
$kapan = $stmt->fetch();

if (!$kapan) {
    set_flash('error', 'કાપન રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

// Fetch currently mapped rough packets for this Kapan
$currentMappings = get_kapan_rough_mapping($kapanId);

// Fetch all available rough packets (including currently mapped to this Kapan)
$availableRoughPackets = get_available_rough_packets($kapanId);

// Packets in production count
$pktCountStmt = $pdo->prepare("SELECT COUNT(*) FROM packets WHERE kapan_id = ?");
$pktCountStmt->execute([$kapanId]);
$pktCount = (int)$pktCountStmt->fetchColumn();

$actProcStmt = $pdo->prepare("SELECT COUNT(*) FROM packets WHERE kapan_id = ? AND current_stage != 'boil' AND polish_weight > 0");
$actProcStmt->execute([$kapanId]);
$activeProcessCount = (int)$actProcStmt->fetchColumn();

// Build options array for JavaScript
$packetsOptionsJs = [];
foreach ($availableRoughPackets as $p) {
    $packetsOptionsJs[$p['key']] = [
        'key' => $p['key'],
        'type' => $p['type'],
        'id' => (int)$p['id'],
        'rough_number' => $p['rough_number'],
        'pcs' => (int)$p['pcs'],
        'weight' => (float)$p['weight'],
        'price_per_carat' => (float)$p['price_per_carat'],
        'rough_cost' => (float)$p['rough_cost'],
        'label' => $p['rough_number'] . ' | ' . number_format((float)$p['weight'], 3) . ' ct (' . (int)$p['pcs'] . ' pcs) - ₹' . number_format((float)$p['rough_cost'], 2),
    ];
}

// Currently selected keys
$selectedKeys = [];
foreach ($currentMappings as $m) {
    $selectedKeys[] = $m['rough_packet_type'] . '_' . $m['rough_packet_id'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $kapanName   = trim($_POST['kapan_name'] ?? '');
    $selectedPackets = $_POST['rough_packets'] ?? [];
    if (!is_array($selectedPackets)) {
        $selectedPackets = array_filter(array_map('trim', explode(',', (string)$selectedPackets)));
    }
    
    $status      = in_array($_POST['status'] ?? '', ['active', 'in_production', 'boil_completed', 'closed']) ? $_POST['status'] : 'active';
    $galaxyCost  = (float)($_POST['galaxy_cost'] ?? 0.00);
    $lsCost      = (float)($_POST['ls_cost'] ?? 0.00);
    $labourCost  = (float)($_POST['labour_cost'] ?? 0.00);
    $otherCost   = (float)($_POST['other_cost'] ?? 0.00);

    // Validation
    if (empty($kapanName)) {
        set_flash('error', 'કૃપા કરીને કાપનનું નામ દાખલ કરો.');
    } elseif (empty($selectedPackets)) {
        set_flash('error', 'કૃપા કરીને ઓછામાં ઓછું એક Rough Packet પસંદ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate aggregated Pcs, Weight, Rough Cost & Rough Numbers from valid packets
            $newTotalPcs = 0;
            $newTotalWeight = 0.0;
            $newRoughCost = 0.0;
            $newRoughNumbersList = [];
            $newPrimaryRoughPurchaseId = null;

            $validSelectedMappings = [];

            foreach ($selectedPackets as $pktKey) {
                if (!isset($packetsOptionsJs[$pktKey])) {
                    // Check if packet exists in DB and is available or currently in this Kapan
                    continue;
                }
                $pkt = $packetsOptionsJs[$pktKey];
                $newTotalPcs += $pkt['pcs'];
                $newTotalWeight += $pkt['weight'];
                $newRoughCost += $pkt['rough_cost'];
                $newRoughNumbersList[] = $pkt['rough_number'];

                if ($pkt['type'] === 'rough_purchase' && $newPrimaryRoughPurchaseId === null) {
                    $newPrimaryRoughPurchaseId = $pkt['id'];
                }

                $validSelectedMappings[] = [
                    'kapan_id' => $kapanId,
                    'rough_packet_id' => $pkt['id'],
                    'rough_packet_type' => $pkt['type'],
                    'rough_number' => $pkt['rough_number'],
                    'allocated_pcs' => $pkt['pcs'],
                    'allocated_weight' => $pkt['weight'],
                    'allocated_cost' => $pkt['rough_cost']
                ];
            }

            if (empty($validSelectedMappings)) {
                throw new \Exception('પસંદ કરેલ કોઈ પણ Rough Packet માન્ય નથી.');
            }

            // Identify removed and added packets compared to currentMappings
            $oldKeys = [];
            foreach ($currentMappings as $cm) {
                $oldKeys[$cm['rough_packet_type'] . '_' . $cm['rough_packet_id']] = $cm;
            }

            $newKeys = [];
            foreach ($validSelectedMappings as $vm) {
                $newKeys[$vm['rough_packet_type'] . '_' . $vm['rough_packet_id']] = $vm;
            }

            // 1. Release removed packets
            foreach ($oldKeys as $k => $oldPkt) {
                if (!isset($newKeys[$k])) {
                    if ($oldPkt['rough_packet_type'] === 'assort_part') {
                        $upd = $pdo->prepare("UPDATE assort_parts SET status = 'available' WHERE id = ?");
                        $upd->execute([(int)$oldPkt['rough_packet_id']]);
                    } elseif ($oldPkt['rough_packet_type'] === 'rough_purchase') {
                        $upd = $pdo->prepare("UPDATE rough_purchases SET 
                            available_weight = available_weight + ?,
                            available_pcs = available_pcs + ?
                            WHERE id = ?");
                        $upd->execute([(float)$oldPkt['allocated_weight'], (int)$oldPkt['allocated_pcs'], (int)$oldPkt['rough_packet_id']]);
                    }
                }
            }

            // 2. Reserve newly added packets (and verify not allocated to another active kapan)
            foreach ($newKeys as $k => $newPkt) {
                if (!isset($oldKeys[$k])) {
                    if ($newPkt['rough_packet_type'] === 'assort_part') {
                        // Check if already in another active Kapan
                        $chk = $pdo->prepare("SELECT k.kapan_code FROM kapan_rough_mapping m JOIN kapans k ON m.kapan_id = k.id WHERE m.rough_packet_type = 'assort_part' AND m.rough_packet_id = ? AND k.status != 'closed' AND k.id != ?");
                        $chk->execute([(int)$newPkt['rough_packet_id'], $kapanId]);
                        $occ = $chk->fetch();
                        if ($occ) {
                            throw new \Exception("Rough પેકેટ '{$newPkt['rough_number']}' પહેલેથી Kapan {$occ['kapan_code']} માં ફાળવેલ છે.");
                        }

                        $upd = $pdo->prepare("UPDATE assort_parts SET status = 'in_kapan' WHERE id = ?");
                        $upd->execute([(int)$newPkt['rough_packet_id']]);
                    } elseif ($newPkt['rough_packet_type'] === 'rough_purchase') {
                        $upd = $pdo->prepare("UPDATE rough_purchases SET 
                            available_weight = GREATEST(0, available_weight - ?),
                            available_pcs = GREATEST(0, available_pcs - ?)
                            WHERE id = ?");
                        $upd->execute([(float)$newPkt['allocated_weight'], (int)$newPkt['allocated_pcs'], (int)$newPkt['rough_packet_id']]);
                    }
                }
            }

            // 3. Clear existing mappings in kapan_rough_mapping and insert updated list
            $delMap = $pdo->prepare("DELETE FROM kapan_rough_mapping WHERE kapan_id = ?");
            $delMap->execute([$kapanId]);

            $insMap = $pdo->prepare("INSERT INTO kapan_rough_mapping 
                (kapan_id, rough_packet_id, rough_packet_type, rough_number, allocated_pcs, allocated_weight, allocated_cost) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($validSelectedMappings as $m) {
                $insMap->execute([
                    $kapanId,
                    $m['rough_packet_id'],
                    $m['rough_packet_type'],
                    $m['rough_number'],
                    $m['allocated_pcs'],
                    $m['allocated_weight'],
                    $m['allocated_cost']
                ]);
            }

            // 4. Update Kapan table
            $totalCost = $newRoughCost + $galaxyCost + $lsCost + $labourCost + $otherCost;
            $polWeight = (float)$kapan['total_polish_weight'];
            $polPcs = (int)$kapan['total_polish_pcs'];
            $costPerCarat = $polWeight > 0 ? round($totalCost / $polWeight, 2) : 0.00;
            $costPerPiece = $polPcs > 0 ? round($totalCost / $polPcs, 2) : 0.00;
            $roughNumbersStr = implode(', ', $newRoughNumbersList);

            $uStmt = $pdo->prepare("UPDATE kapans SET 
                kapan_name = ?,
                rough_numbers = ?,
                rough_purchase_id = ?,
                total_pcs = ?,
                total_weight = ?,
                rough_cost = ?,
                galaxy_cost = ?,
                ls_cost = ?,
                labour_cost = ?,
                other_cost = ?,
                total_cost = ?,
                cost_per_carat = ?,
                cost_per_piece = ?,
                status = ?
                WHERE id = ?");

            $uStmt->execute([
                $kapanName,
                $roughNumbersStr,
                $newPrimaryRoughPurchaseId,
                $newTotalPcs,
                $newTotalWeight,
                $newRoughCost,
                $galaxyCost,
                $lsCost,
                $labourCost,
                $otherCost,
                $totalCost,
                $costPerCarat,
                $costPerPiece,
                $status,
                $kapanId
            ]);

            // 5. Synchronize individual 1 PC diamond records (Zero duplicates guaranteed)
            foreach ($oldKeys as $k => $oldPkt) {
                if (!isset($newKeys[$k])) {
                    if ($oldPkt['rough_packet_type'] === 'assort_part') {
                        $pdo->prepare("DELETE FROM packets WHERE kapan_id = ? AND assort_part_id = ? AND current_stage = 'galaxy' AND jangad_id IS NULL AND (polish_weight = 0 OR polish_weight IS NULL)")->execute([$kapanId, (int)$oldPkt['rough_packet_id']]);
                    } elseif ($oldPkt['rough_packet_type'] === 'rough_purchase') {
                        $pdo->prepare("DELETE FROM packets WHERE kapan_id = ? AND rough_purchase_id = ? AND assort_part_id IS NULL AND current_stage = 'galaxy' AND jangad_id IS NULL AND (polish_weight = 0 OR polish_weight IS NULL)")->execute([$kapanId, (int)$oldPkt['rough_packet_id']]);
                    }
                }
            }
            generate_kapan_diamonds_from_rough_packets($kapanId, $kapan['kapan_code'], $validSelectedMappings);

            log_activity('Kapan Updated', 'Kapan', $kapanId, "Updated Kapan {$kapan['kapan_code']} ($kapanName) - Merged Packets: $roughNumbersStr, Weight: $newTotalWeight ct");
            $pdo->commit();

            set_flash('success', "કાપન '{$kapan['kapan_code']}' સફળતાપૂર્વક અપડેટ થઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/kapan/view.php?id=' . $kapanId);
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'કાપન અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'કાપન એડિટ કરો: ' . e($kapan['kapan_code']);
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<?= render_page_header(
    'કાપન એડિટ કરો: ' . $kapan['kapan_code'],
    'Units & Rough Cost - Rough પેકેટ્સ મર્જિંગ અને પડતર અપડેટ',
    'pen-to-square',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/kapan/view.php?id=' . $kapanId . '" class="btn btn-outline-primary btn-sm px-3">
            ' . svg_icon('eye', 'me-1') . 'Kapan વિગત
        </a>
        <a href="' . BASE_URL . '/modules/kapan/index.php" class="btn btn-outline-secondary btn-sm px-3">
            ' . svg_icon('arrow-left', 'me-1') . 'યાદી પર પાછા
        </a>
    </div>',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-11">
        <form action="<?= BASE_URL ?>/modules/kapan/edit.php?id=<?= $kapanId ?>" method="POST" id="kapanEditForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $kapanId ?>">

            <!-- Card 1: Basic Identity & Status -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon"><?= svg_icon('layer-group') ?></span>
                        ૧. કાપન ઓળખ અને સ્થિતિ (Kapan Identity & Status)
                    </h6>
                    <span class="badge bg-light text-dark border font-mono"><?= e($kapan['kapan_code']) ?></span>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">કાપન કોડ (Kapan Code)</label>
                            <input type="text" class="form-control font-mono fw-bold bg-light" value="<?= e($kapan['kapan_code']) ?>" readonly>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">કાપનનું નામ (Kapan Name) *</label>
                            <input type="text" name="kapan_name" id="kapan_name" class="form-control fw-bold" value="<?= e($kapan['kapan_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">કાપન સ્થિતિ (Kapan Status) *</label>
                            <select name="status" class="form-select fw-semibold" required>
                                <option value="active" <?= $kapan['status'] === 'active' ? 'selected' : '' ?>>Active (નવું / ચાલુ)</option>
                                <option value="in_production" <?= $kapan['status'] === 'in_production' ? 'selected' : '' ?>>In Production (પ્રોડક્શનમાં)</option>
                                <option value="boil_completed" <?= $kapan['status'] === 'boil_completed' ? 'selected' : '' ?>>Boil Completed (બોઇલ પૂર્ણ)</option>
                                <option value="closed" <?= $kapan['status'] === 'closed' ? 'selected' : '' ?>>Closed (બંધ)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: UNITS & ROUGH COST (MULTIPLE ROUGH PACKETS SELECTION & MERGING) -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-bold text-dark fs-6">
                            <?= svg_icon('table-cells', 'text-primary me-2') ?>Units & Rough Cost (Rough પેકેટ્સ સિલેક્શન અને મર્જિંગ)
                        </span>
                        
                    </div>
                    <div class="small text-muted">
                        <?= svg_icon('info-circle', 'text-primary me-1') ?>
                        એક અથવા વધુ Rough પેકેટ્સ પસંદ કરવાથી Pcs, Weight અને Rough Cost આપમેળે ગણાશે.
                    </div>
                </div>
                <div class="erp-card-body p-3">
                    <div class="table-responsive mb-3" style="overflow-x: auto !important;">
                        <table class="table table-bordered align-middle mb-0" style="table-layout: fixed; width: 100%; min-width: 900px;">
                            <colgroup>
                                <col style="width: 22%;">
                                <col style="width: 44%;">
                                <col style="width: 10%;">
                                <col style="width: 11%;">
                                <col style="width: 13%;">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>Kapan Name</th>
                                    <th>Rough Number(s) (સમાવિષ્ટ પેકેટ્સ) *</th>
                                    <th class="text-center">Total Pcs</th>
                                    <th class="text-end">Total Weight (Ct)</th>
                                    <th class="text-end">Rough Cost (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6" id="disp_kapan_name"><?= e($kapan['kapan_name']) ?></div>
                                        <div class="small font-mono text-muted"><?= e($kapan['kapan_code']) ?></div>
                                    </td>
                                    <td>
                                        <select name="rough_packets[]" id="rough_packets_select" multiple class="form-select" placeholder="Rough પેકેટ્સ શોધો અથવા પસંદ કરો..." required>
                                            <?php foreach ($availableRoughPackets as $p): ?>
                                                <?php $isSel = in_array($p['key'], $selectedKeys); ?>
                                                <option value="<?= e($p['key']) ?>" <?= $isSel ? 'selected' : '' ?>>
                                                    <?= e($p['rough_number']) ?> | <?= format_carat($p['weight']) ?> (<?= (int)$p['pcs'] ?> pcs) - <?= format_inr($p['rough_cost']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text small mt-1 text-muted" style="font-size: 0.72rem;">
                                            ડ્રોપડાઉનમાંથી ગમે તેટલા Rough પેકેટ્સ ઉમેરી અથવા રદ કરી શકો છો.
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="text" id="disp_total_pcs" class="form-control font-mono fw-bold text-center bg-light px-1" readonly value="<?= (int)$kapan['total_pcs'] ?>">
                                            <span class="input-group-text font-mono small px-1">Pcs</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="text" id="disp_total_weight" class="form-control font-mono fw-bold text-primary text-end bg-light px-1" readonly value="<?= format_carat($kapan['total_weight']) ?>">
                                            <span class="input-group-text font-mono small text-primary fw-bold px-1">Ct</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text font-mono small px-1">₹</span>
                                            <input type="text" id="disp_rough_cost" class="form-control font-mono fw-bold text-dark text-end bg-light px-1" readonly value="<?= number_format((float)$kapan['rough_cost'], 2) ?>">
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Selected Packets Breakdown Details Table -->
                    <div class="card border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-dark">
                                <?= svg_icon('list-check', 'text-primary me-1') ?>સમાવિષ્ટ રફ પેકેટ્સ બ્રેકડાઉન (Selected Rough Breakdown):
                            </span>
                            <span class="badge bg-secondary font-mono" id="selected_packets_count">0 પેકેટ્સ પસંદ કરેલ</span>
                        </div>
                        <div class="table-responsive bg-white rounded border">
                            <table class="table table-sm table-striped mb-0 align-middle">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Rough Number</th>
                                        <th>પ્રકાર / સ્ત્રોત</th>
                                        <th class="text-center">નંગ (Pcs)</th>
                                        <th class="text-end">વજન (Ct)</th>
                                        <th class="text-end">ભાવ / Ct (₹)</th>
                                        <th class="text-end">Rough પડતર (₹)</th>
                                    </tr>
                                </thead>
                                <tbody id="selectedPacketsBreakdownBody">
                                    <tr><td colspan="6" class="text-center py-2 text-muted small">કોઈ પેકેટ પસંદ કરેલ નથી.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Cost Breakdown & Calculations -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('coins') ?></span>
                        ૩. ઉત્પાદન ખર્ચ વિભાજન અને કુલ પડતર (Additional Manufacturing Costs)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">Galaxy સ્કેનિંગ ખર્ચ (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="galaxy_cost" id="galaxy_cost" class="form-control font-mono cost-input" value="<?= (float)($kapan['galaxy_cost'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">Laser Sawing (LS) ખર્ચ (₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="ls_cost" id="ls_cost" class="form-control font-mono cost-input" value="<?= (float)($kapan['ls_cost'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">મજૂરી / કારીગર ખર્ચ (Labour ₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="labour_cost" id="labour_cost" class="form-control font-mono cost-input" value="<?= (float)($kapan['labour_cost'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">પરચુરણ / અન્ય ખર્ચ (Other ₹)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="other_cost" id="other_cost" class="form-control font-mono cost-input" value="<?= (float)($kapan['other_cost'] ?? 0) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="p-3 rounded-3 border d-flex flex-wrap justify-content-between align-items-center gap-2" style="background: #f8fafc;">
                        <div>
                            <span class="text-muted small d-block">કુલ ઉત્પાદન પડતર (Rough Cost + તમામ પ્રોસેસ ખર્ચ)</span>
                            <span class="fs-4 fw-bold text-primary font-mono" id="total_cost_display">₹ <?= number_format((float)($kapan['total_cost'] ?? 0), 2) ?></span>
                        </div>
                        <div class="text-muted small text-end">
                            હાલના બારકોડ પેકેટ્સ: <strong class="text-dark font-mono"><?= $pktCount ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= $kapanId ?>" class="btn btn-outline-secondary px-4">
                            <?= svg_icon('xmark', 'me-1') ?>રદ કરો
                        </a>
                        <button type="submit" class="btn btn-primary px-4 py-2 fw-bold shadow-sm">
                            <?= svg_icon('floppy-disk', 'me-2') ?>કાપન ફેરફારો સાચવો (Update Kapan)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Available rough packets data passed from PHP
const availableRoughPacketsData = <?= json_encode($packetsOptionsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', function() {
    const nameInput = document.getElementById('kapan_name');
    const dispName = document.getElementById('disp_kapan_name');
    if (nameInput && dispName) {
        nameInput.addEventListener('input', function() {
            dispName.innerText = this.value || 'કાપનનું નામ';
        });
    }

    const selectEl = document.getElementById('rough_packets_select');
    let tsControl = null;

    function recalculateAllocation() {
        let selectedVals = [];
        if (tsControl) {
            selectedVals = tsControl.getValue();
            if (!Array.isArray(selectedVals)) {
                selectedVals = selectedVals ? [selectedVals] : [];
            }
        } else if (selectEl) {
            selectedVals = Array.from(selectEl.selectedOptions).map(o => o.value);
        }

        let totalPcs = 0;
        let totalWeight = 0;
        let roughCost = 0;
        const tbody = document.getElementById('selectedPacketsBreakdownBody');
        let rowsHtml = '';

        selectedVals.forEach(k => {
            const pkt = availableRoughPacketsData[k];
            if (pkt) {
                totalPcs += pkt.pcs;
                totalWeight += pkt.weight;
                roughCost += pkt.rough_cost;

                const typeBadge = pkt.type === 'assort_part' 
                    ? '<span class="badge bg-primary-subtle text-primary border">Assort Part</span>'
                    : '<span class="badge bg-success-subtle text-success border">Direct Purchase</span>';

                rowsHtml += `
                    <tr>
                        <td class="font-mono fw-bold text-dark">${pkt.rough_number}</td>
                        <td>${typeBadge}</td>
                        <td class="text-center font-mono">${pkt.pcs}</td>
                        <td class="text-end font-mono fw-bold text-primary">${pkt.weight.toFixed(3)}</td>
                        <td class="text-end font-mono">₹ ${pkt.price_per_carat.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                        <td class="text-end font-mono fw-bold text-dark">₹ ${pkt.rough_cost.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                    </tr>
                `;
            }
        });

        if (selectedVals.length === 0) {
            rowsHtml = '<tr><td colspan="6" class="text-center py-2 text-muted small">કોઈ પેકેટ પસંદ કરેલ નથી.</td></tr>';
        }

        if (tbody) tbody.innerHTML = rowsHtml;

        document.getElementById('disp_total_pcs').value = totalPcs;
        document.getElementById('disp_total_weight').value = totalWeight.toFixed(3);
        document.getElementById('disp_rough_cost').value = roughCost.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('selected_packets_count').innerText = `${selectedVals.length} પેકેટ્સ પસંદ કરેલ`;

        // Update overall total cost
        recalcTotalCost(roughCost);
    }

    function recalcTotalCost(rc) {
        if (typeof rc === 'undefined') {
            const rawRc = (document.getElementById('disp_rough_cost').value || '0').replace(/,/g, '');
            rc = parseFloat(rawRc) || 0;
        }

        const galaxy = parseFloat(document.getElementById('galaxy_cost').value) || 0;
        const ls = parseFloat(document.getElementById('ls_cost').value) || 0;
        const labour = parseFloat(document.getElementById('labour_cost').value) || 0;
        const other = parseFloat(document.getElementById('other_cost').value) || 0;

        const total = rc + galaxy + ls + labour + other;
        document.getElementById('total_cost_display').innerText = '₹ ' + total.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Initialize TomSelect on multiple rough packets select
    if (typeof TomSelect !== 'undefined' && selectEl) {
        try {
            tsControl = new TomSelect(selectEl, {
                plugins: ['remove_button'],
                dropdownParent: 'body',
                persist: false,
                create: false,
                maxItems: 50,
                placeholder: 'Rough પેકેટ્સ શોધો અથવા પસંદ કરો...',
                searchField: ['text'],
                render: {
                    item: function (data, escape) {
                        let text = data.text || '';
                        let label = text;
                        const match = text.match(/^([^\—\-]+)[\—\-]\s*([0-9\.]+\s*ct)/i);
                        if (match) {
                            label = match[1].trim() + ' (' + match[2].trim() + ')';
                        }
                        return '<div class="item" title="' + escape(text) + '">' + escape(label) + '</div>';
                    },
                    option: function (data, escape) {
                        return '<div class="py-1 px-2 font-mono small text-dark">' + escape(data.text) + '</div>';
                    }
                }
            });

            tsControl.on('change', recalculateAllocation);
        } catch (e) {
            console.warn('TomSelect init error on edit rough select', e);
        }
    } else if (selectEl) {
        selectEl.addEventListener('change', recalculateAllocation);
    }

    // Cost input event listeners
    ['galaxy_cost', 'ls_cost', 'labour_cost', 'other_cost'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => recalcTotalCost());
    });

    // Run initial calculation to populate breakdown table
    recalculateAllocation();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
