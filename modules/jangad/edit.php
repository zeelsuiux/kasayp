<?php
/**
 * Edit Jangad Voucher (જાંગડ પહોંચ એડિટ કરો - Kapan-Wise Diamond Assignment)
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

$jangadId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($jangadId <= 0) {
    set_flash('error', 'અમાન્ય જાંગડ વાઉચર ID.');
    header('Location: ' . BASE_URL . '/modules/jangad/index.php');
    exit;
}

// Fetch Jangad Voucher
$stmt = $pdo->prepare("SELECT jv.*, p.party_name FROM jangad_vouchers jv JOIN parties p ON jv.party_id = p.id WHERE jv.id = ?");
$stmt->execute([$jangadId]);
$voucher = $stmt->fetch();

if (!$voucher) {
    set_flash('error', 'જાંગડ વાઉચર મળ્યું નથી.');
    header('Location: ' . BASE_URL . '/modules/jangad/index.php');
    exit;
}

// Check if any returns or tops have occurred
$retCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM jangad_returns WHERE jangad_id = ?");
$retCheckStmt->execute([$jangadId]);
$returnCount = (int)$retCheckStmt->fetchColumn();
$hasReturns = ($returnCount > 0 || (int)$voucher['returned_pcs'] > 0 || (float)$voucher['returned_weight'] > 0);

// Fetch existing Kapan-wise diamond assignments (legacy)
$existingKapans = get_jangad_kapan_assignments($jangadId);
$isSalesPolishJangad = empty($existingKapans);

// Fetch Sales Polish Items if applicable
$jiEditStmt = $pdo->prepare("
    SELECT ji.*, ps.packet_no, ps.shape, ps.color, ps.clarity, ps.gia_number
    FROM jangad_items ji
    LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
    WHERE ji.jangad_id = ?
    ORDER BY ji.id ASC
");
$jiEditStmt->execute([$jangadId]);
$existingPolishItems = $jiEditStmt->fetchAll(PDO::FETCH_ASSOC);

// Preload all available Kapans only for legacy Kapan Jangads
$availableKapans = [];
$kapanDiamondsMap = [];
if (!$isSalesPolishJangad) {
    $availableKapans = $pdo->query("
        SELECT id, kapan_code, kapan_name, total_pcs, total_weight, status 
        FROM kapans 
        WHERE status != 'completed' OR id IN (SELECT kapan_id FROM jangad_kapans WHERE jangad_id = {$jangadId})
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($availableKapans as $k) {
        $kapanDiamondsMap[$k['id']] = get_kapan_available_diamonds((int)$k['id'], $jangadId);
    }
}

// Preload Parties grouped by Category
$allPartiesByCategory = [
    'all'               => get_parties_by_jangad_category(null),
    'outsource_galaxy'  => get_parties_by_jangad_category('outsource_galaxy'),
    'outsource_ls'      => get_parties_by_jangad_category('outsource_ls'),
    'outsource_factory' => get_parties_by_jangad_category('outsource_factory'),
    'memo_broker'       => get_parties_by_jangad_category('memo_broker'),
    'memo_party'        => get_parties_by_jangad_category('memo_party'),
    'lab_gia'           => get_parties_by_jangad_category('lab_gia')
];

$brokers = $pdo->query("SELECT id, party_name, city FROM parties WHERE (party_type IN ('broker', 'both') OR roles LIKE '%broker%') ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle POST Update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $category   = trim($_POST['category'] ?? $voucher['category']);
    $partyId    = (int)($_POST['party_id'] ?? 0);
    $brokerId   = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $issueDate  = trim($_POST['issue_date'] ?? $voucher['issue_date']);
    $returnDate = !empty($_POST['expected_return_date']) ? trim($_POST['expected_return_date']) : null;
    $remarks    = trim($_POST['remarks'] ?? '');

    if ($partyId <= 0) {
        set_flash('error', 'કૃપા કરીને માન્ય પાર્ટી પસંદ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            if ($hasReturns || $isSalesPolishJangad) {
                // Update voucher metadata cleanly (keeps issued polish stock & returns intact)
                $uStmt = $pdo->prepare("
                    UPDATE jangad_vouchers SET 
                        category = ?, party_id = ?, broker_id = ?, issue_date = ?, 
                        expected_return_date = ?, remarks = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $uStmt->execute([$category, $partyId, $brokerId, $issueDate, $returnDate, $remarks, $jangadId]);

                // Update assigned party on any linked packets
                $pdo->prepare("UPDATE packets SET assigned_party_id = ? WHERE jangad_id = ?")->execute([$partyId, $jangadId]);

                // Allow updating asking rate (charges) on Sales Jangad items
                if ($isSalesPolishJangad && !empty($_POST['item_rates']) && is_array($_POST['item_rates'])) {
                    $updRateStmt = $pdo->prepare("UPDATE jangad_items SET charges = ? WHERE id = ? AND jangad_id = ?");
                    foreach ($_POST['item_rates'] as $itId => $rateVal) {
                        $updRateStmt->execute([max(0.0, round((float)$rateVal, 2)), (int)$itId, $jangadId]);
                    }
                }
            } else {
                // No returns yet: full update of Kapan-wise diamond assignments permitted
                $kapansData = $_POST['kapans'] ?? [];
                if (empty($kapansData)) {
                    throw new \Exception('ઓછામાં ઓછું એક કાપન અને ડાયમંડ ફાળવણી જરૂરી છે.');
                }

                $totalAssignedPcs = 0;
                $totalAssignedWeight = 0.000;
                $validatedKapans = [];
                $allSelectedDiamondIds = [];

                foreach ($kapansData as $kapanRow) {
                    $kId = (int)($kapanRow['kapan_id'] ?? 0);
                    $diamonds = $kapanRow['diamonds'] ?? [];

                    if ($kId <= 0 || empty($diamonds)) {
                        continue;
                    }

                    $kapanAssignedPcs = 0;
                    $kapanAssignedWeight = 0.000;
                    $validDiamonds = [];

                    foreach ($diamonds as $d) {
                        $pktId = (int)($d['diamond_id'] ?? 0);
                        $assignNo = trim((string)($d['assign_number'] ?? ''));
                        $weight = round((float)($d['weight'] ?? 0.000), 3);

                        if ($pktId <= 0 || $weight <= 0.000 || empty($assignNo)) {
                            continue;
                        }

                        if (in_array($pktId, $allSelectedDiamondIds, true)) {
                            throw new \Exception("ડાયમંડ ID #{$pktId} (Assign #{$assignNo}) એક કરતા વધુ વખત પસંદ થયેલ છે.");
                        }
                        $allSelectedDiamondIds[] = $pktId;

                        $validDiamonds[] = [
                            'diamond_id'    => $pktId,
                            'assign_number' => $assignNo,
                            'weight'        => $weight
                        ];

                        $kapanAssignedPcs++;
                        $kapanAssignedWeight += $weight;
                    }

                    if ($kapanAssignedPcs > 0) {
                        $validatedKapans[] = [
                            'kapan_id' => $kId,
                            'pcs'      => $kapanAssignedPcs,
                            'weight'   => round($kapanAssignedWeight, 3),
                            'diamonds' => $validDiamonds
                        ];

                        $totalAssignedPcs += $kapanAssignedPcs;
                        $totalAssignedWeight += $kapanAssignedWeight;
                    }
                }

                if (empty($validatedKapans)) {
                    throw new \Exception('કૃપા કરીને ઓછામાં ઓછો એક હીરો ફાળવો.');
                }

                $totalAssignedWeight = round($totalAssignedWeight, 3);

                // 1. Release previously assigned packets for this Jangad
                $pdo->prepare("UPDATE packets SET jangad_id = NULL, assigned_party_id = NULL, diamond_status = 'available' WHERE jangad_id = ?")->execute([$jangadId]);

                // 2. Remove old assignments and kapans
                $pdo->prepare("DELETE FROM jangad_assignments WHERE jangad_id = ?")->execute([$jangadId]);
                $pdo->prepare("DELETE FROM jangad_kapans WHERE jangad_id = ?")->execute([$jangadId]);

                // 3. Update Jangad Voucher Header
                $uStmt = $pdo->prepare("
                    UPDATE jangad_vouchers SET 
                        category = ?, party_id = ?, broker_id = ?, issue_date = ?, 
                        expected_return_date = ?, total_pcs = ?, total_weight = ?, 
                        pending_weight = ?, remarks = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $uStmt->execute([
                    $category, $partyId, $brokerId, $issueDate, 
                    $returnDate, $totalAssignedPcs, $totalAssignedWeight, 
                    $totalAssignedWeight, $remarks, $jangadId
                ]);

                // 4. Insert new Kapans and Assignments
                $insertKapanStmt = $pdo->prepare("
                    INSERT INTO jangad_kapans (jangad_id, kapan_id, assigned_pcs, assigned_weight, returned_pcs, returned_weight, pending_weight, tops_weight, status, created_at)
                    VALUES (?, ?, ?, ?, 0, 0.000, ?, 0.000, 'assigned', NOW())
                ");

                $insertAssignStmt = $pdo->prepare("
                    INSERT INTO jangad_assignments (
                        jangad_id, jangad_kapan_id, kapan_id, assign_number, diamond_no,
                        original_diamond_id, assigned_weight, returned_pcs, returned_weight,
                        pending_weight, tops_weight, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0.000, ?, 0.000, 'assigned', NOW())
                ");

                $reservePktStmt = $pdo->prepare("
                    UPDATE packets SET jangad_id = ?, assigned_party_id = ?, diamond_status = 'assigned_to_jangad' WHERE id = ?
                ");

                foreach ($validatedKapans as $vk) {
                    $insertKapanStmt->execute([
                        $jangadId,
                        $vk['kapan_id'],
                        $vk['pcs'],
                        $vk['weight'],
                        $vk['weight']
                    ]);
                    $jangadKapanId = (int)$pdo->lastInsertId();

                    foreach ($vk['diamonds'] as $vd) {
                        $insertAssignStmt->execute([
                            $jangadId,
                            $jangadKapanId,
                            $vk['kapan_id'],
                            $vd['assign_number'],
                            $vd['assign_number'],
                            $vd['diamond_id'],
                            $vd['weight'],
                            $vd['weight']
                        ]);

                        $reservePktStmt->execute([$jangadId, $partyId, $vd['diamond_id']]);
                    }
                }
            }

            log_activity('Jangad Updated', 'Jangad', $jangadId, "Updated Jangad Voucher {$voucher['jangad_no']}");
            $pdo->commit();

            set_flash('success', "જાંગડ વાઉચર '{$voucher['jangad_no']}' સફળતાપૂર્વક અપડેટ થઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/jangad/view.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'જાંગડ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'જાંગડ વાઉચર એડિટ: ' . e($voucher['jangad_no']);
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<?= render_page_header(
    'જાંગડ વાઉચર એડિટ (Edit Jangad Voucher)',
    '',
    'pen-to-square',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/jangad/view.php?id=' . $jangadId . '" class="btn btn-outline-secondary btn-sm px-3">
            ' . svg_icon('file-lines', 'me-1') . 'વાઉચર જુઓ
        </a>
        <a href="' . BASE_URL . '/modules/jangad/index.php" class="btn btn-outline-secondary btn-sm px-3">
            ' . svg_icon('arrow-left', 'me-1') . 'યાદી પર પાછા
        </a>
    </div>',
    'primary'
) ?>

<?php if ($hasReturns): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
        <?= svg_icon('lock', 'text-warning-emphasis fs-5', 17) ?>
        <div>
            <strong>પરત માલ લૉક ચેતવણી:</strong> આ વાઉચર સામે <strong><?= $returnCount ?> વખત માલ જમા (Returns)</strong> થઈ ગયેલ છે. 
            ઇન્વેન્ટરી અને હિસાબી અખંડિતતા જાળવવા માટે ફાળવેલ ડાયમંડ ફેરફાર માટે લૉક છે. તમે કેટેગરી, પાર્ટી, દલાલ, તારીખો અને નોંધ એડિટ કરી શકો છો.
        </div>
    </div>
<?php endif; ?>

<form action="<?= BASE_URL ?>/modules/jangad/edit.php?id=<?= $jangadId ?>" method="POST" id="editJangadForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $jangadId ?>">

    <!-- SECTION 1: JANGAD BASIC INFORMATION -->
    <div class="card-erp mb-4">
        <div class="card-header bg-white py-3 px-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <?= svg_icon('file-invoice', 'text-primary fs-5', 17) ?>
                <span class="fw-bold text-dark fs-6">૧. જાંગડ મૂળભૂત વિગતો (Jangad Basic Information)</span>
            </div>
            
        </div>
        <div class="card-body p-3">
            <div class="row g-3">
                <!-- 1. Jangad Number (Strictly Non-Editable) -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">
                        જાંગડ નંબર (Jangad No) 
                    </label>
                    <input type="text" class="form-control font-mono fw-bold bg-light" value="<?= e($voucher['jangad_no']) ?>" readonly title="Jangad Number cannot be modified">
                </div>

                <!-- 2. Jangad Type / Category -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">જાંગડ પ્રકાર (Jangad Type / Category) *</label>
                    <select name="category" id="jangad_category" class="form-select fw-semibold" required>
                        <option value="memo_party" <?= $voucher['category'] === 'memo_party' ? 'selected' : '' ?>>ગ્રાહક સેલિંગ જાંગડ (Party Sales Memo)</option>
                        <option value="memo_broker" <?= $voucher['category'] === 'memo_broker' ? 'selected' : '' ?>>દલાલ જાંગડ (Broker Memo)</option>
                        <option value="lab_gia" <?= $voucher['category'] === 'lab_gia' ? 'selected' : '' ?>>GIA / IGI Lab (લેબ સર્ટિફિકેશન)</option>
                        <?php if (in_array($voucher['category'], ['outsource_galaxy', 'outsource_ls', 'outsource_factory'], true)): ?>
                            <option value="<?= e($voucher['category']) ?>" selected><?= e($voucher['category']) ?> (જૂનો રેકોર્ડ)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- 3. Party Name (Category Based Filtered Dynamic Dropdown) -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">પાર્ટીનું નામ (Party Name) *</label>
                    <select name="party_id" id="party_select" class="form-select" required data-placeholder="કેટેગરી મુજબ પાર્ટી પસંદ કરો...">
                        <option value="">કેટેગરી મુજબ પાર્ટી પસંદ કરો...</option>
                    </select>
                </div>

                <!-- 4. Broker Name -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">દલાલ (Broker) <span class="text-muted fw-normal">(Optional)</span></label>
                    <select name="broker_id" id="broker_select" class="form-select" data-placeholder="દલાલ પસંદ કરો (જો હોય તો)...">
                        <option value="">ડાયરેક્ટ (કોઈ દલાલ નથી)</option>
                        <?php foreach ($brokers as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= (int)($voucher['broker_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= e($b['party_name']) ?> (<?= e($b['city']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 5. Issue Date -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">ઇસ્યુ તારીખ (Issue Date) *</label>
                    <input type="date" name="issue_date" class="form-control font-mono" value="<?= e($voucher['issue_date']) ?>" required>
                </div>

                <!-- 6. Expected Return Date -->
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-dark">પરત આવવાની મુદત (Expected Return)</label>
                    <input type="date" name="expected_return_date" class="form-control font-mono" value="<?= e($voucher['expected_return_date'] ?? '') ?>">
                </div>

                <!-- 7. Remarks -->
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-bold text-dark">વિશેષ નોંધ (Remarks)</label>
                    <input type="text" name="remarks" class="form-control" value="<?= e($voucher['remarks'] ?? '') ?>" placeholder="સેલ્સ મેમો સૂચના, ભાવ ચર્ચા...">
                </div>
            </div>
        </div>
    </div>

    <?php if ($isSalesPolishJangad): ?>
        <!-- SECTION 2: POLISH SALES JANGAD ITEMS -->
        <div class="card-erp mb-4">
            <div class="card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-bold text-dark fs-6">
                        <?= svg_icon('gem', 'text-success me-2') ?>૨. Sales Jangad — તૈયાર પોલિશ હીરા વિગત
                    </span>
                    
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $jangadId ?>" class="btn btn-outline-success btn-sm px-3">
                        <?= svg_icon('rotate-left', 'me-1') ?>માલ જમા / વેચાણ કન્વર્ટ
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>પોલિશ પેકેટ / વિગત</th>
                                <th class="text-center">ઇસ્યુ નંગ</th>
                                <th class="text-end">ઇસ્યુ વજન (Ct)</th>
                                <th class="text-end">પરત / વેચાણ</th>
                                <th class="text-end">બાકી વજન</th>
                                <th class="text-end pe-3" style="width: 170px;">માંગ ભાવ / Ct (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($existingPolishItems)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">કોઈ આઇટમ ઉપલબ્ધ નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($existingPolishItems as $idx => $epi):
                                    $pPendWt = max(0.0, round((float)$epi['issued_weight'] - (float)($epi['returned_weight'] ?? 0), 3));
                                ?>
                                    <tr>
                                        <td class="ps-3 font-mono text-muted"><?= $idx + 1 ?></td>
                                        <td>
                                            <strong><?= e($epi['description']) ?></strong>
                                            <?php if (!empty($epi['gia_number']) && strpos((string)$epi['description'], (string)$epi['gia_number']) === false): ?>
                                                <span class="badge bg-info-subtle text-info border font-mono small ms-1">GIA: <?= e($epi['gia_number']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center font-mono"><?= (int)$epi['issued_pcs'] ?> Pcs</td>
                                        <td class="text-end font-mono fw-bold text-primary"><?= format_carat($epi['issued_weight']) ?></td>
                                        <td class="text-end font-mono text-success"><?= format_carat($epi['returned_weight'] ?? 0) ?></td>
                                        <td class="text-end font-mono fw-bold <?= $pPendWt > 0 ? 'text-danger' : 'text-muted' ?>"><?= format_carat($pPendWt) ?></td>
                                        <td class="text-end pe-3">
                                            <input type="number" step="0.01" min="0" name="item_rates[<?= (int)$epi['id'] ?>]" class="form-control form-control-sm font-mono text-end" value="<?= number_format((float)($epi['charges'] ?? 0), 2, '.', '') ?>">
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
        <!-- SECTION 2: KAPAN WISE DIAMOND ASSIGNMENT & STATUS (LEGACY) -->
        <div class="card-erp mb-4">
            <div class="card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-bold text-dark fs-6">
                        <?= svg_icon('boxes-stacked', 'text-primary me-2') ?>૨. કાપન વાઇઝ ડાયમંડ ફાળવણી (Kapan Wise Diamond Assignment)
                    </span>
                    
                </div>
                <?php if (!$hasReturns): ?>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm px-3 fw-bold shadow-sm" id="openAddKapanModalBtn">
                            <?= svg_icon('plus', 'me-1') ?>નવું Kapan ઉમેરો (Add Kapan)
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <div class="accordion" id="kapanAccordionsContainer">
                    <!-- Kapan Accordions rendered here -->
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- SECTION 3: JANGAD SUMMARY DASHBOARD -->
    <div class="card-erp mb-4 p-3.5" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 18px; box-shadow: 0 4px 16px -4px rgba(6, 52, 54, 0.05);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
            <div>
                <h6 class="fw-bold text-dark mb-0 fs-6">
                    <?= svg_icon('chart-pie', 'text-primary me-2') ?>૩. ઓવરઓલ જાંગડ સમરી ડેશબોર્ડ (Live Summary Dashboard)
                </h6>
                <small class="text-muted">રીયલ-ટાઇમ ગણતરી: ફાળવેલ હીરા, પરત જમા, બાકી પેન્ડિંગ વજન અને Tops</small>
            </div>
            <span class="badge bg-primary-subtle text-primary border font-mono px-2.5 py-1 rounded-pill">
                <?= svg_icon('bolt', 'me-1') ?>Live Real-Time Totals
            </span>
        </div>

        <!-- All 7 Summary Metric Cards in 1 Single Line -->
        <div class="d-flex gap-2 mb-3 pb-1 flex-nowrap overflow-x-auto align-items-stretch" style="scrollbar-width: thin; -webkit-overflow-scrolling: touch;">
            <!-- Card 1: ૧. કુલ Kapans -->
            <div style="flex: 1 1 0; min-width: 120px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૧. કુલ Kapans</span>
                    <div class="font-mono fs-5 fw-bold text-dark" id="dash_total_kapans"><?= count($existingKapans) ?></div>
                </div>
            </div>

            <!-- Card 2: ૨. ફાળવેલ ડાયમંડ્સ (PCs) -->
            <div style="flex: 1 1 0; min-width: 130px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૨. કુલ ઇસ્યુ નંગ (Issued)</span>
                    <div class="font-mono fs-5 fw-bold text-dark" id="dash_assigned_pcs"><?= (int)$voucher['total_pcs'] ?> Pcs</div>
                </div>
            </div>

            <!-- Card 3: ૩. ફાળવેલ વજન (Assigned Wt) -->
            <div style="flex: 1 1 0; min-width: 135px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૩. કુલ ઇસ્યુ વજન (Issued)</span>
                    <div class="font-mono fs-5 fw-bold" style="color: #064e3b;" id="dash_assigned_weight"><?= format_carat($voucher['total_weight']) ?></div>
                </div>
            </div>

            <!-- Card 4: ૪. પરત આવેલ ડાયમંડ્સ (PCs) -->
            <div style="flex: 1 1 0; min-width: 135px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૪. પરત હીરા (PCs)</span>
                    <div class="font-mono fs-5 fw-bold text-success" id="dash_received_pcs"><?= (int)$voucher['returned_pcs'] ?> Pcs</div>
                </div>
            </div>

            <!-- Card 5: ૫. પરત મળેલ વજન (Received Wt) -->
            <div style="flex: 1 1 0; min-width: 135px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૫. પરત વજન (Rec Wt)</span>
                    <div class="font-mono fs-5 fw-bold text-success" id="dash_received_weight"><?= format_carat($voucher['returned_weight']) ?></div>
                </div>
            </div>

            <!-- Card 6: ૬. પેન્ડિંગ વજન (Pending Weight) - Highlighted Card -->
            <div style="flex: 1 1 0; min-width: 140px;">
                <div class="p-2.5 rounded-3 border h-100 shadow-xs" style="background: #fffbeb; border-color: #fde68a !important;">
                    <span class="small d-block mb-1 fw-bold text-truncate" style="font-size: 0.78rem; color: #92400e;">૬. પેન્ડિંગ વજન (Pending)</span>
                    <div class="font-mono fs-5 fw-bold" style="color: #dc2626;" id="dash_pending_weight"><?= format_carat((float)$voucher['total_weight'] - (float)$voucher['returned_weight']) ?></div>
                </div>
            </div>

            <!-- Card 7: ૭. માન્ય થયેલ Tops (Finalized Tops) -->
            <div style="flex: 1 1 0; min-width: 150px;">
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૭. માન્ય Tops (Finalized)</span>
                    <div class="font-mono fs-5 fw-bold text-secondary" id="dash_tops_weight"><?= format_carat((float)($voucher['total_tops_weight'] ?? 0.000)) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Actions -->
    <div class="d-flex justify-content-between align-items-center pt-2">
        <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= $jangadId ?>" class="btn btn-outline-secondary px-4">
            <?= svg_icon('arrow-left', 'me-1') ?>રદ કરો (Cancel)
        </a>
        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="saveJangadBtn">
            <?= svg_icon('floppy-disk', 'me-1') ?>જાંગડ ફેરફારો સેવ કરો (Save Changes)
        </button>
    </div>
</form>

<!-- Modal: Add Kapan Selector -->
<div class="modal fade" id="addKapanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white py-3">
                <h6 class="modal-title fw-bold">
                    <?= svg_icon('boxes-stacked', 'me-2') ?>નવું Kapan ઉમેરો (Select Kapan)
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Kapan પસંદ કરો *</label>
                    <select id="modal_kapan_select" class="form-select form-select-lg">
                        <option value="">-- Kapan પસંદ કરો --</option>
                        <?php foreach ($availableKapans as $ak): ?>
                            <option value="<?= $ak['id'] ?>" data-code="<?= e($ak['kapan_code']) ?>" data-name="<?= e($ak['kapan_name']) ?>" data-pcs="<?= (int)$ak['total_pcs'] ?>" data-weight="<?= (float)$ak['total_weight'] ?>">
                                <?= e($ak['kapan_name']) ?> (<?= e($ak['kapan_code']) ?>) — <?= format_carat($ak['total_weight']) ?> (<?= (int)$ak['total_pcs'] ?> Pcs)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                <button type="button" class="btn btn-primary btn-sm px-4 fw-bold" id="confirmAddKapanBtn">
                    <?= svg_icon('check', 'me-1') ?>ઉમેરો (Add)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const hasReturns = <?= json_encode($hasReturns) ?>;
    const initialCategory = <?= json_encode($voucher['category']) ?>;
    const initialPartyId = <?= (int)$voucher['party_id'] ?>;
    const partiesByCategory = <?= json_encode($allPartiesByCategory, JSON_UNESCAPED_UNICODE) ?>;
    const availableKapans = <?= json_encode($availableKapans, JSON_UNESCAPED_UNICODE) ?>;
    const kapanDiamondsMap = <?= json_encode($kapanDiamondsMap, JSON_UNESCAPED_UNICODE) ?>;
    const existingKapans = <?= json_encode($existingKapans, JSON_UNESCAPED_UNICODE) ?>;

    const categorySelect = document.getElementById('jangad_category');
    const partySelect = document.getElementById('party_select');
    const accordionsContainer = document.getElementById('kapanAccordionsContainer');
    const addKapanModalEl = document.getElementById('addKapanModal');
    const addKapanModal = addKapanModalEl ? new bootstrap.Modal(addKapanModalEl) : null;
    const openAddKapanBtn = document.getElementById('openAddKapanModalBtn');
    const confirmAddKapanBtn = document.getElementById('confirmAddKapanBtn');
    const modalKapanSelect = document.getElementById('modal_kapan_select');

    let partyTomSelect = null;
    let kapanSeq = 0;
    const activeKapans = {}; // kapanId -> { name, code, diamonds: [] }

    // Initialize TomSelect for Party
    function initPartyDropdown(category, selectPartyId = null) {
        if (partyTomSelect) {
            partyTomSelect.destroy();
            partyTomSelect = null;
        }

        const partyList = partiesByCategory[category] || partiesByCategory['all'] || [];
        partySelect.innerHTML = '<option value="">કેટેગરી મુજબ પાર્ટી પસંદ કરો...</option>';

        partyList.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = `${p.party_name} (${p.city || 'Surat'} - ${p.party_type})`;
            if (selectPartyId && parseInt(p.id, 10) === parseInt(selectPartyId, 10)) {
                opt.selected = true;
            }
            partySelect.appendChild(opt);
        });

        if (typeof TomSelect !== 'undefined') {
            try {
                partyTomSelect = new TomSelect(partySelect, {
                    dropdownParent: 'body',
                    placeholder: 'કેટેગરી મુજબ પાર્ટી પસંદ કરો...',
                    allowEmptyOption: true,
                    maxOptions: 500
                });
            } catch (e) {
                console.warn('TomSelect error', e);
            }
        }
    }

    // Category Change Listener
    categorySelect.addEventListener('change', function () {
        const newCat = this.value;
        const currentSelected = partyTomSelect ? partyTomSelect.getValue() : partySelect.value;
        initPartyDropdown(newCat, currentSelected === String(initialPartyId) ? initialPartyId : null);
    });

    // Initialize Party Dropdown with current voucher's party
    initPartyDropdown(initialCategory, initialPartyId);

    if (typeof TomSelect !== 'undefined' && document.getElementById('broker_select')) {
        try {
            new TomSelect('#broker_select', { dropdownParent: 'body', placeholder: 'દલાલ પસંદ કરો...', allowEmptyOption: true });
        } catch (e) {}
    }

    // Render an existing or new Kapan Accordion
    function createKapanAccordion(kId, kName, kCode, preloadedAssignments = []) {
        if (activeKapans[kId]) {
            alert('આ Kapan પહેલેથી જ ઉમેરાયેલ છે.');
            return;
        }

        const kapanIndex = kapanSeq++;
        const accordionId = `kapanAccordion_${kId}`;
        const collapseId = `collapseKapan_${kId}`;

        activeKapans[kId] = {
            id: kId,
            index: kapanIndex,
            name: kName,
            code: kCode,
            assignedDiamonds: []
        };

        const accCard = document.createElement('div');
        accCard.className = 'accordion-item border rounded-3 mb-3 shadow-sm';
        accCard.id = accordionId;

        const isReadOnly = hasReturns;

        accCard.innerHTML = `
            <h2 class="accordion-header" id="heading_${kId}">
                <button class="accordion-button py-2.5 px-3 bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="true" aria-controls="${collapseId}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between w-100 me-3 gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary font-mono">Kapan</span>
                            <strong class="text-dark fs-6">${escapeHtml(kName)}</strong>
                            <span class="text-muted font-mono small">(${escapeHtml(kCode)})</span>
                            <input type="hidden" name="kapans[${kapanIndex}][kapan_id]" value="${kId}">
                        </div>
                        <div class="d-flex align-items-center gap-3 font-mono small">
                            <span class="text-muted">નંગ: <strong class="text-dark kapan-sub-pcs" id="sub_pcs_${kId}">0</strong></span>
                            <span class="text-muted">વજન: <strong class="text-primary kapan-sub-wt" id="sub_wt_${kId}">0.000 Ct</strong></span>
                            ${!isReadOnly ? `
                                <button type="button" class="btn btn-outline-danger btn-sm py-0.5 px-2 ms-2 remove-kapan-btn" title="આ Kapan હટાવો" onclick="removeKapanAccordion(${kId})">
                                    ' + window.svgIcon('trash-can') + '
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </button>
            </h2>
            <div id="${collapseId}" class="accordion-collapse collapse show" aria-labelledby="heading_${kId}">
                <div class="accordion-body p-3">
                    ${!isReadOnly ? `
                        <!-- Diamond Selection Controls -->
                        <div class="p-2.5 rounded-3 bg-light border mb-3">
                            <div class="row g-2 align-items-center">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-bold text-dark mb-1">
                                        ' + window.svgIcon('gem', 'text-primary me-1') + 'ડાયમંડ સિલેક્ટર (Multi-Select Diamonds)
                                    </label>
                                    <select id="select_diamond_${kId}" multiple class="form-select kapan-diamond-multiselect" data-placeholder="Assign નંબર્સ પસંદ કરો (દા.ત. 1, 2, 5)...">
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-bold text-dark mb-1">
                                        ' + window.svgIcon('wand-magic-sparkles', 'text-primary me-1') + 'ક્વિક રેન્જ સિલેક્શન (દા.ત. 1-10 અથવા 1,3,5)
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="quick_range_${kId}" class="form-control font-mono" placeholder="દા.ત. 1-10 અથવા 1,3,5">
                                        <button type="button" class="btn btn-secondary" onclick="applyQuickRange(${kId})">લાગુ કરો</button>
                                    </div>
                                </div>
                                <div class="col-12 col-md-2 text-md-end">
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="selectAllDiamonds(${kId})">
                                        ' + window.svgIcon('check-double', 'me-1') + 'બધા પસંદ કરો
                                    </button>
                                </div>
                            </div>
                        </div>
                    ` : ''}

                    <!-- Diamond Assignment Table -->
                    <div class="table-responsive mb-0">
                        <table class="table table-bordered table-sm align-middle mb-0" id="assignTable_${kId}" style="table-layout: fixed; width: 100%;">
                            <colgroup>
                                <col style="width: 14%;">
                                <col style="width: 26%;">
                                <col style="width: 22%;">
                                <col style="width: 26%;">
                                <col style="width: 12%;">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center font-mono">Assign Number</th>
                                    <th>ડાયમંડ વિગત / પેકેટ નં</th>
                                    <th class="text-end font-mono">Assigned Weight (Ct)</th>
                                    <th>પરત સ્થિતિ (Return Status)</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="assignTbody_${kId}">
                                <!-- Rows injected here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        accordionsContainer.appendChild(accCard);

        if (!isReadOnly) {
            setupDiamondMultiSelect(kId);
        }

        // Populate preloaded assignments
        if (preloadedAssignments && preloadedAssignments.length > 0) {
            preloadedAssignments.forEach(asgn => {
                addAssignmentRow(kId, asgn.original_diamond_id, asgn.assign_number, asgn.packet_no, asgn.barcode, asgn.assigned_weight, asgn);
            });
        }

        recalculateTotals();
    }

    // Populate TomSelect Multi-Select for a Kapan
    function setupDiamondMultiSelect(kId) {
        const selectEl = document.getElementById(`select_diamond_${kId}`);
        if (!selectEl) return;

        const diamonds = kapanDiamondsMap[kId] || [];
        selectEl.innerHTML = '';

        diamonds.forEach(d => {
            if (!d.is_assigned) {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = `Assign #${d.assign_number} (${d.packet_no}) — ${parseFloat(d.weight).toFixed(3)} Ct`;
                opt.dataset.assignNo = d.assign_number;
                opt.dataset.weight = d.weight;
                opt.dataset.packetNo = d.packet_no;
                opt.dataset.barcode = d.barcode;
                selectEl.appendChild(opt);
            }
        });

        if (typeof TomSelect !== 'undefined') {
            try {
                const ts = new TomSelect(selectEl, {
                    plugins: ['remove_button'],
                    dropdownParent: 'body',
                    placeholder: 'Assign નંબર્સ પસંદ કરો (દા.ત. 1, 2, 5)...',
                    maxOptions: 500,
                    onChange: function (values) {
                        syncDiamondsFromMultiSelect(kId, values);
                    }
                });
                selectEl._tomselect = ts;
            } catch (e) {
                console.warn('TomSelect error', e);
            }
        }
    }

    // Sync Diamonds from Multi-Select
    function syncDiamondsFromMultiSelect(kId, selectedDiamondIds) {
        const tbody = document.getElementById(`assignTbody_${kId}`);
        const diamonds = kapanDiamondsMap[kId] || [];
        const existingRows = tbody.querySelectorAll('tr[data-diamond-id]');
        const currentPktIds = Array.from(existingRows).map(r => r.dataset.diamondId);

        selectedDiamondIds.forEach(idStr => {
            if (!currentPktIds.includes(idStr)) {
                const d = diamonds.find(x => String(x.id) === String(idStr));
                if (d) {
                    addAssignmentRow(kId, d.id, d.assign_number, d.packet_no, d.barcode, d.weight);
                }
            }
        });

        existingRows.forEach(r => {
            if (!selectedDiamondIds.includes(r.dataset.diamondId)) {
                r.remove();
            }
        });

        recalculateTotals();
    }

    // Add Assignment Row
    function addAssignmentRow(kId, diamondId, assignNo, packetNo, barcode, weight, existingData = null) {
        const tbody = document.getElementById(`assignTbody_${kId}`);
        if (!tbody) return;

        if (tbody.querySelector(`tr[data-diamond-id="${diamondId}"]`)) {
            return;
        }

        const kObj = activeKapans[kId];
        const kapanIndex = kObj.index;
        const rowIndex = tbody.children.length;
        const isLocked = hasReturns;

        let returnInfo = '<span class="badge bg-light text-muted border">જમા બાકી (Pending)</span>';
        if (existingData && existingData.returns && existingData.returns.length > 0) {
            const count = existingData.returns.length;
            const rWt = parseFloat(existingData.returned_weight || 0).toFixed(3);
            returnInfo = `<span class="badge bg-success-subtle text-success border">${count} Splits (${rWt} ct)</span>`;
            if (existingData.status === 'completed') {
                returnInfo += ` <span class="badge bg-primary-subtle text-primary border">પૂર્ણ</span>`;
            }
        }

        const tr = document.createElement('tr');
        tr.dataset.diamondId = diamondId;
        tr.dataset.kapanId = kId;

        tr.innerHTML = `
            <td class="text-center font-mono fw-bold">
                <span class="badge bg-light text-dark border px-2 py-1">#${escapeHtml(assignNo)}</span>
                <input type="hidden" name="kapans[${kapanIndex}][diamonds][${rowIndex}][diamond_id]" value="${diamondId}">
                <input type="hidden" name="kapans[${kapanIndex}][diamonds][${rowIndex}][assign_number]" value="${escapeHtml(assignNo)}">
            </td>
            <td>
                <strong class="text-dark font-mono small">${escapeHtml(packetNo)}</strong>
                <div class="text-muted font-mono" style="font-size: 0.72rem;">${escapeHtml(barcode || '')}</div>
            </td>
            <td class="text-end">
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" min="0.001" name="kapans[${kapanIndex}][diamonds][${rowIndex}][weight]" class="form-control font-mono fw-bold text-primary text-end diamond-wt-input px-1" value="${parseFloat(weight).toFixed(3)}" ${isLocked ? 'readonly' : ''} oninput="recalculateTotals()">
                    <span class="input-group-text font-mono small text-primary fw-bold px-1">Ct</span>
                </div>
            </td>
            <td>
                ${returnInfo}
            </td>
            <td class="text-center">
                ${!isLocked ? `
                    <button type="button" class="btn btn-outline-danger btn-sm p-1" title="આ ડાયમંડ હટાવો" onclick="removeDiamondRow(this, ${kId}, ${diamondId})">
                        ' + window.svgIcon('trash-can') + '
                    </button>
                ` : `
                    <span class="badge bg-secondary-subtle text-secondary">' + window.svgIcon('lock') + ' Locked</span>
                `}
            </td>
        `;

        tbody.appendChild(tr);
        recalculateTotals();
    }

    // Global Window Functions for Inline Events
    window.removeDiamondRow = function (btn, kId, diamondId) {
        const tr = btn.closest('tr');
        if (tr) tr.remove();

        const selectEl = document.getElementById(`select_diamond_${kId}`);
        if (selectEl && selectEl._tomselect) {
            const vals = selectEl._tomselect.getValue().filter(v => String(v) !== String(diamondId));
            selectEl._tomselect.setValue(vals, true);
        }

        recalculateTotals();
    };

    window.removeKapanAccordion = function (kId) {
        if (!confirm('શું તમે ખરેખર આ Kapan અને તેના ડાયમંડ ફાળવણી હટાવવા માંગો છો?')) return;
        const accEl = document.getElementById(`kapanAccordion_${kId}`);
        if (accEl) accEl.remove();
        delete activeKapans[kId];
        recalculateTotals();
    };

    window.applyQuickRange = function (kId) {
        const input = document.getElementById(`quick_range_${kId}`);
        if (!input || !input.value.trim()) return;

        const val = input.value.trim();
        const diamonds = kapanDiamondsMap[kId] || [];
        const targetAssignNos = [];

        val.split(',').forEach(part => {
            part = part.trim();
            if (part.includes('-')) {
                const [start, end] = part.split('-').map(x => parseInt(x.trim(), 10));
                if (!isNaN(start) && !isNaN(end)) {
                    for (let i = Math.min(start, end); i <= Math.max(start, end); i++) {
                        targetAssignNos.push(String(i));
                    }
                }
            } else if (part) {
                targetAssignNos.push(part);
            }
        });

        const matchedIds = [];
        diamonds.forEach(d => {
            if (targetAssignNos.includes(String(d.assign_number)) && !d.is_assigned) {
                matchedIds.push(String(d.id));
            }
        });

        const selectEl = document.getElementById(`select_diamond_${kId}`);
        if (selectEl && selectEl._tomselect) {
            const currentVals = selectEl._tomselect.getValue();
            const combined = Array.from(new Set([...currentVals, ...matchedIds]));
            selectEl._tomselect.setValue(combined);
        }
        input.value = '';
    };

    window.selectAllDiamonds = function (kId) {
        const diamonds = kapanDiamondsMap[kId] || [];
        const allIds = diamonds.filter(d => !d.is_assigned).map(d => String(d.id));

        const selectEl = document.getElementById(`select_diamond_${kId}`);
        if (selectEl && selectEl._tomselect) {
            selectEl._tomselect.setValue(allIds);
        }
    };

    // Recalculate Totals
    function recalculateTotals() {
        let overallKapans = 0;
        let overallAssignedPcs = 0;
        let overallAssignedWeight = 0.000;

        for (const kId in activeKapans) {
            const tbody = document.getElementById(`assignTbody_${kId}`);
            if (!tbody) continue;

            overallKapans++;
            const rows = tbody.querySelectorAll('tr');
            let kPcs = 0;
            let kWt = 0.000;

            rows.forEach(r => {
                const wtInput = r.querySelector('.diamond-wt-input');
                if (wtInput) {
                    const w = parseFloat(wtInput.value) || 0;
                    kWt += w;
                    kPcs++;
                }
            });

            const subPcsEl = document.getElementById(`sub_pcs_${kId}`);
            const subWtEl = document.getElementById(`sub_wt_${kId}`);
            if (subPcsEl) subPcsEl.textContent = kPcs;
            if (subWtEl) subWtEl.textContent = kWt.toFixed(3) + ' Ct';

            overallAssignedPcs += kPcs;
            overallAssignedWeight += kWt;
        }

        const retPcs = <?= (int)$voucher['returned_pcs'] ?>;
        const retWt = <?= (float)$voucher['returned_weight'] ?>;
        const topsWt = <?= (float)($voucher['total_tops_weight'] ?? 0.000) ?>;
        const pendWt = Math.max(0, overallAssignedWeight - retWt);

        // Update Summary Dashboard
        const dKapans = document.getElementById('dash_total_kapans');
        const dPcs = document.getElementById('dash_assigned_pcs');
        const dWt = document.getElementById('dash_assigned_weight');
        const dPend = document.getElementById('dash_pending_weight');

        if (dKapans) dKapans.textContent = overallKapans;
        if (dPcs) dPcs.textContent = overallAssignedPcs + ' Pcs';
        if (dWt) dWt.textContent = overallAssignedWeight.toFixed(3) + ' Ct';
        if (dPend) dPend.textContent = pendWt.toFixed(3) + ' Ct';
    }

    // Dynamically filter modal dropdown to only show Kapans NOT yet added to this Jangad
    function refreshModalKapanOptions() {
        const sel = modalKapanSelect;
        if (!sel) return;

        sel.innerHTML = '';
        const addedIds = Object.keys(activeKapans).map(id => parseInt(id, 10));
        const unadded = availableKapans.filter(k => !addedIds.includes(parseInt(k.id, 10)));

        if (unadded.length === 0) {
            sel.innerHTML = '<option value="">-- તમામ સક્રિય Kapans ઉમેરાઈ ગયેલ છે --</option>';
            sel.disabled = true;
            if (confirmAddKapanBtn) confirmAddKapanBtn.disabled = true;
            return;
        }

        sel.disabled = false;
        if (confirmAddKapanBtn) confirmAddKapanBtn.disabled = false;

        let html = '<option value="">-- Kapan પસંદ કરો --</option>';
        unadded.forEach(kp => {
            const pkts = (kapanDiamondsMap[kp.id] || []).filter(d => !d.is_assigned);
            const pktCount = pkts.length;
            const pktText = pktCount > 0 ? `${pktCount} Diamonds ઉપલબ્ધ` : '0 Diamonds';
            html += `<option value="${kp.id}" data-code="${escapeHtml(kp.kapan_code)}" data-name="${escapeHtml(kp.kapan_name)}" data-pcs="${kp.total_pcs}" data-weight="${kp.total_weight}">
                ${escapeHtml(kp.kapan_code)} — ${escapeHtml(kp.kapan_name)} (${parseFloat(kp.total_weight).toFixed(3)} ct / ${kp.total_pcs} Pcs • ${pktText})
            </option>`;
        });
        sel.innerHTML = html;
        sel.value = '';
    }

    // Modal Add Kapan Handlers
    if (openAddKapanBtn) {
        openAddKapanBtn.addEventListener('click', function () {
            refreshModalKapanOptions();
            if (addKapanModal) addKapanModal.show();
        });
    }

    if (confirmAddKapanBtn) {
        confirmAddKapanBtn.addEventListener('click', function () {
            const kId = parseInt(modalKapanSelect.value, 10);
            if (!kId) {
                alert('કૃપા કરીને Kapan પસંદ કરો.');
                return;
            }

            const opt = modalKapanSelect.selectedOptions[0];
            const kName = opt.dataset.name;
            const kCode = opt.dataset.code;

            createKapanAccordion(kId, kName, kCode, []);
            modalKapanSelect.value = '';
            if (addKapanModal) addKapanModal.hide();
        });
    }

    // Preload Existing Kapans & Assignments on Page Load
    if (existingKapans && existingKapans.length > 0) {
        existingKapans.forEach(ek => {
            createKapanAccordion(ek.kapan_id, ek.kapan_name, ek.kapan_code, ek.assignments || []);
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
