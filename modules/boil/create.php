<?php
/**
 * Create Weekly Boil Batch (સાપ્તાહિક બોઈલ પ્રોસેસ)
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

// Fetch packets ready for boil
$readyPackets = $pdo->query("SELECT p.*, k.kapan_code, k.kapan_name
                             FROM packets p
                             JOIN kapans k ON p.kapan_id = k.id
                             WHERE COALESCE(NULLIF(p.polish_weight, 0), NULLIF(p.current_weight, 0), p.weight) > 0
                               AND p.current_stage IN ('factory', 'blocking', 'faceting', 'polishing', 'final_checking', 'polish_return', 'boil', 'polish_stock')
                               AND p.boil_batch_id IS NULL
                               AND COALESCE(p.is_reassorted, 0) = 0
                               AND p.status NOT IN ('sold', 'reassorted')
                             ORDER BY p.id ASC")->fetchAll();

$currentWeek = date('W');
$defaultBatchNo = 'BOIL-WK-' . $currentWeek . '-' . date('Y');
$defaultLotNo = generate_next_reassort_lot_no();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $batchNo = trim($_POST['batch_no'] ?? $defaultBatchNo);
    $boilDate = $_POST['boil_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $boilMode = trim($_POST['boil_mode'] ?? 'polish_stock');
    if (!in_array($boilMode, ['in_boil', 'polish_stock', 'reassort'], true)) {
        $boilMode = 'polish_stock';
    }
    $reassortLotNo = trim($_POST['reassort_lot_no'] ?? $defaultLotNo);
    $reassortLotRate = max(0.0, (float)($_POST['reassort_lot_rate'] ?? 0));
    $selectedPktIds = $_POST['packet_ids'] ?? [];

    if (empty($selectedPktIds)) {
        set_flash('error', 'કૃપા કરીને Boil માં મોકલવા માટે ઓછામાં ઓછો એક પેકેટ પસંદ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $cleanIds = array_values(array_unique(array_filter(array_map('intval', $selectedPktIds), fn($v) => $v > 0)));
            $inClause = implode(',', $cleanIds);
            $pktRows = $pdo->query("SELECT p.*, k.kapan_code
                                    FROM packets p
                                    JOIN kapans k ON p.kapan_id = k.id
                                    WHERE p.id IN ($inClause)")->fetchAll(PDO::FETCH_ASSOC);

            $totalPcs = count($pktRows);
            $totalWeight = 0.000;
            foreach ($pktRows as $pr) {
                $wt = (float)$pr['polish_weight'] > 0
                    ? (float)$pr['polish_weight']
                    : ((float)$pr['current_weight'] > 0 ? (float)$pr['current_weight'] : (float)$pr['weight']);
                $totalWeight += $wt;
            }

            $initialStatus = ($boilMode === 'in_boil') ? 'in_boil' : 'completed';
            $completedDate = ($boilMode === 'in_boil') ? null : $boilDate;
            $officeReturnDate = ($boilMode === 'in_boil') ? null : $boilDate;

            $stmt = $pdo->prepare("INSERT INTO boil_batches (batch_no, boil_date, total_pcs, total_weight, status, completed_date, office_return_date, notes, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$batchNo, $boilDate, $totalPcs, round($totalWeight, 3), $initialStatus, $completedDate, $officeReturnDate, $notes, $user['id'] ?? null]);
            $batchId = (int)$pdo->lastInsertId();

            if ($boilMode === 'in_boil') {
                $updPkt = $pdo->prepare("UPDATE packets SET boil_batch_id = ?, current_stage = 'boil', current_location = 'Boil Dept', assigned_party_id = NULL, status = 'in_process' WHERE id = ?");
                $insDsh = $pdo->prepare("
                    INSERT INTO diamond_stage_history (
                        packet_id, diamond_no, stage_code, stage_name, given_weight, received_weight,
                        difference_weight, pending_weight, diff_classification, assigned_date,
                        outgoing_location, receiving_location, status, notes, logged_by
                    ) VALUES (?, ?, 'boil', 'Weekly Boil (બોઈલિંગ)', ?, 0.000, 0.000, ?, 'pending', ?, 'Surat (Office)', 'Boil Dept', 'assigned', ?, ?)
                ");
                $insPph = $pdo->prepare("
                    INSERT INTO packet_process_history (
                        packet_id, diamond_no, stage, action, input_weight, output_weight,
                        process_date, location, status, details, logged_by
                    ) VALUES (?, ?, 'boil', 'Sent to Weekly Boil', ?, 0.000, ?, 'Boil Dept', 'assigned', ?, ?)
                ");

                foreach ($pktRows as $pr) {
                    $wt = (float)$pr['polish_weight'] > 0
                        ? (float)$pr['polish_weight']
                        : ((float)$pr['current_weight'] > 0 ? (float)$pr['current_weight'] : (float)$pr['weight']);
                    $dNo = !empty($pr['diamond_no']) ? $pr['diamond_no'] : $pr['packet_no'];
                    $updPkt->execute([$batchId, $pr['id']]);
                    $msg = "Boil Batch {$batchNo} માં મોકલેલ." . ($notes !== '' ? " {$notes}" : '');
                    $insDsh->execute([$pr['id'], $dNo, $wt, $wt, $boilDate, $msg, $user['id'] ?? null]);
                    $insPph->execute([$pr['id'], $dNo, $wt, $boilDate, $msg, $user['id'] ?? null]);
                }

                $pdo->commit();
                log_activity('Boil Batch Sent', 'Production', $batchId, "Boil batch $batchNo sent ($totalPcs pcs, $totalWeight ct)");
                set_flash('success', "Boil બેચ $batchNo મોકલાઈ ગયો છે. ઓફિસ પર પરત આવે ત્યારે નીચે 'ઓફિસ Return' માંથી Polish Stock અથવા Re-Assort પસંદ કરો.");
                header('Location: ' . BASE_URL . '/modules/boil/view.php?id=' . $batchId);
                exit;
            } elseif ($boilMode === 'reassort') {
                $res = process_boil_return_to_reassort($batchId, $cleanIds, $reassortLotNo, $boilDate, $notes, $user['id'] ?? null, $reassortLotRate);
                $pdo->commit();
                log_activity('Boil Batch Created & Re-Assorted', 'Production', $batchId, "Boil batch $batchNo returned & sent to Re-Assort Lot {$res['lot_no']} ({$res['pcs']} pcs, {$res['weight']} ct)");
                set_flash('success', "Boil બેચ $batchNo પૂર્ણ — નવો લોટ નંબર {$res['lot_no']} પડ્યો અને જૂની કાપન ({$res['kapan_codes']}) માંથી નીકળી ગયા છે. હવે નીચે Assort (ભાગ) પાડો.");
                header('Location: ' . BASE_URL . '/modules/assort/create.php?reassort_lot_id=' . $res['reassort_lot_id']);
                exit;
            } else {
                process_boil_return_to_polish_stock($batchId, $cleanIds, $boilDate, $notes, $user['id'] ?? null);
                $pdo->commit();
                log_activity('Boil Batch Created', 'Production', $batchId, "Boil batch $batchNo with $totalPcs packets ($totalWeight ct) completed");
                set_flash('success', "Weekly Boil બેચ $batchNo સફળતાપૂર્વક પૂર્ણ થયો અને હીરા Polish Stock માટે તૈયાર થઈ ગયા છે. જો કોઈ હીરા ફરીથી Assort કરવાના હોય તો નીચેથી પસંદ કરી શકો છો.");
                header('Location: ' . BASE_URL . '/modules/boil/view.php?id=' . $batchId);
                exit;
            }
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Boil બેચ સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવો Weekly Boil બેચ (New Boil Batch)';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'નવો Weekly Boil બેચ (Diamond Acid Boiling)',
    '',
    'fire-burner',
    '<a href="' . BASE_URL . '/modules/boil/index.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'બોઈલ લિસ્ટ પર પાછા જાઓ</a>',
    'rose'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form action="<?= BASE_URL ?>/modules/boil/create.php" method="POST">
            <?= csrf_field() ?>

            <!-- Card 1: Batch Details -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-rose"><?= svg_icon('fire-burner') ?></span>
                        ૧. બેચ વિગત (Boil Batch Information)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">બેચ નંબર (Batch No) *</label>
                            <input type="text" name="batch_no" class="form-control fw-bold font-mono" value="<?= e($defaultBatchNo) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">બોઈલ તારીખ (Boil Date) *</label>
                            <input type="date" name="boil_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Packet Selection -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('check-to-slot') ?></span>
                        ૨. Boil માટે તૈયાર હીરા પસંદ કરો (Ready Polish Packets)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <?php if (empty($readyPackets)): ?>
                        <div class="alert alert-info py-4 text-center my-2">
                            <?= svg_icon('circle-check', 'mb-2 text-success', 22) ?>
                            <h6 class="fw-bold">હાલમાં Boil માટે કોઈ પેકેટ બાકી નથી.</h6>
                            <p class="small text-muted mb-0">કારખાનામાંથી Return નોંધાયેલા હીરા અહીં દેખાશે.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive border rounded mb-3" style="max-height: 380px;">
                            <table class="table table-hover align-middle mb-0" style="min-width: 600px;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 45px;">
                                            <input type="checkbox" id="selectAll" class="form-check-input" onclick="document.querySelectorAll('.pkt-check').forEach(c => c.checked = this.checked)">
                                        </th>
                                        <th>પેકેટ નં</th>
                                        <th>બારકોડ</th>
                                        <th>Kapan</th>
                                        <th>શેપ (Shape)</th>
                                        <th class="text-end">Polish વજન (Carat)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($readyPackets as $rp): 
                                        $rpDisplayWt = (float)$rp['polish_weight'] > 0 ? (float)$rp['polish_weight'] : ((float)$rp['current_weight'] > 0 ? (float)$rp['current_weight'] : (float)$rp['weight']);
                                    ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="packet_ids[]" value="<?= $rp['id'] ?>" class="form-check-input pkt-check" checked>
                                            </td>
                                            <td><strong class="text-dark font-mono"><?= e($rp['packet_no']) ?></strong></td>
                                            <td><span class="font-mono small text-muted"><?= e($rp['barcode']) ?></span></td>
                                            <td><span class="badge bg-light text-dark border"><?= e($rp['kapan_name']) ?></span></td>
                                            <td><?= e($rp['expected_shape'] ?? 'Round') ?></td>
                                            <td class="text-end"><strong class="text-success font-mono"><?= format_carat($rpDisplayWt) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <label class="form-label small fw-bold text-muted">બોઈલ પરિણામ / આઉટપુટ વિકલ્પ (Boil Action)</label>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="border rounded-3 p-2.5 d-flex align-items-start gap-2 bg-light cursor-pointer h-100">
                                    <input type="radio" name="boil_mode" value="in_boil" class="form-check-input mt-1" onchange="toggleReassortLotBox()">
                                    <span>
                                        <strong class="d-block small text-dark">૧. Boil માટે મોકલો (In Boil)</strong>
                                        <small class="text-muted" style="font-size: 0.76rem;">ઓફિસ Return આવ્યા બાદ Polish Stock કે Re-Assort નક્કી થશે</small>
                                    </span>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="border rounded-3 p-2.5 d-flex align-items-start gap-2 bg-light cursor-pointer h-100">
                                    <input type="radio" name="boil_mode" value="polish_stock" class="form-check-input mt-1" checked onchange="toggleReassortLotBox()">
                                    <span>
                                        <strong class="d-block small text-success">૨. Boil પૂર્ણ &rarr; Polish Stock</strong>
                                        <small class="text-muted" style="font-size: 0.76rem;">સીધા તૈયાર Polish Stock માં જમા કરો (પછીથી પણ Re-Assort થઈ શકશે)</small>
                                    </span>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="border rounded-3 p-2.5 d-flex align-items-start gap-2 bg-warning-subtle cursor-pointer h-100">
                                    <input type="radio" name="boil_mode" value="reassort" class="form-check-input mt-1" onchange="toggleReassortLotBox()">
                                    <span>
                                        <strong class="d-block small text-warning-emphasis">૩. Boil પરત &rarr; ફરીથી Assort (Re-Assort)</strong>
                                        <small class="text-muted" style="font-size: 0.76rem;">નવો લોટ નંબર પડશે અને જૂની કાપનમાંથી નીકળી જશે</small>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div id="reassortLotBox" class="p-3 rounded-3 border bg-warning-subtle mb-3" style="display: none;">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-dark">નવો Re-Assort લોટ નંબર (New Lot No) *</label>
                                    <input type="text" name="reassort_lot_no" class="form-control fw-bold font-mono" value="<?= e($defaultLotNo) ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-dark">લોટ રેટ ₹/Ct (Optional)</label>
                                    <input type="number" step="0.01" min="0" name="reassort_lot_rate" class="form-control fw-bold font-mono" placeholder="0.00">
                                </div>
                                <div class="col-md-5 small text-dark pt-md-3">
                                    <?= svg_icon('circle-info', 'me-1 text-warning-emphasis') ?>
                                    સેવ કરતાં જ પસંદ કરેલ પેકેટ્સ જૂની કાપનના સ્ટોકમાંથી નીકળી જશે (ઇતિહાસ સચવાશે) — રેટ Assort પછી પણ નાખી શકશો.
                                </div>
                            </div>
                        </div>

                        <label class="form-label small fw-bold text-muted">બોઈલ વિગત / નોંધ (Notes)</label>
                        <input type="text" name="notes" class="form-control" placeholder="સાપ્તાહિક બોઈલ પ્રોસેસ પૂર્ણ નોંધ...">
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-end gap-2 pt-2">
                <a href="<?= BASE_URL ?>/modules/boil/index.php" class="btn btn-outline-secondary px-4">
                    રદ કરો
                </a>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" <?= empty($readyPackets) ? 'disabled' : '' ?>>
                    <?= svg_icon('fire', 'me-1') ?>Boil બેચ સેવ કરો
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleReassortLotBox() {
    var checked = document.querySelector('input[name="boil_mode"]:checked');
    var box = document.getElementById('reassortLotBox');
    if (box) {
        box.style.display = (checked && checked.value === 'reassort') ? 'block' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
