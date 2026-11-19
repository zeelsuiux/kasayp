<?php
/**
 * Boil Batch Details, Office Return & Send to Re-Assort
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
if ($id <= 0) {
    set_flash('error', 'અમાન્ય Boil બેચ ID.');
    header('Location: ' . BASE_URL . '/modules/boil/index.php');
    exit;
}

$bStmt = $pdo->prepare("
    SELECT b.*, u.full_name as created_by_name
    FROM boil_batches b
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = ?
");
$bStmt->execute([$id]);
$batch = $bStmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    set_flash('error', 'Boil બેચ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/boil/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $action = trim($_POST['action'] ?? '');
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['packet_ids'] ?? []), fn($v) => $v > 0)));
    $returnDate = trim($_POST['return_date'] ?? date('Y-m-d'));
    if ($returnDate === '') {
        $returnDate = date('Y-m-d');
    }
    $notes = trim($_POST['notes'] ?? '');

    if (empty($selectedIds)) {
        set_flash('error', 'કૃપા કરીને ઓછામાં ઓછું એક પેકેટ પસંદ કરો.');
        header('Location: ' . BASE_URL . '/modules/boil/view.php?id=' . $id);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'to_polish_stock') {
            $res = process_boil_return_to_polish_stock($id, $selectedIds, $returnDate, $notes, $user['id'] ?? null);
            $pdo->commit();
            log_activity('Boil Return to Polish Stock', 'Production', $id, "Boil batch {$batch['batch_no']}: {$res['count']} pcs ({$res['weight']} ct) moved to Polish Stock");
            set_flash('success', "{$res['count']} પેકેટ્સ (" . format_carat($res['weight']) . ") ઓફિસ પર પરત મેળવી Polish Stock માં જમા કરાયા.");
            header('Location: ' . BASE_URL . '/modules/boil/view.php?id=' . $id);
            exit;
        } elseif ($action === 'to_reassort') {
            $lotNo = trim($_POST['lot_no'] ?? '');
            $lotRate = max(0.0, (float)($_POST['lot_rate'] ?? 0));
            $goToAssort = isset($_POST['go_to_assort']);
            $res = process_boil_return_to_reassort($id, $selectedIds, $lotNo, $returnDate, $notes, $user['id'] ?? null, $lotRate);
            $pdo->commit();
            log_activity('Boil Return -> Re-Assort', 'Production', $id, "Boil batch {$batch['batch_no']}: {$res['pcs']} pcs ({$res['weight']} ct) exited Old Kapan ({$res['kapan_codes']}) to Re-Assort Lot {$res['lot_no']}");
            set_flash('success', "નવો લોટ નંબર {$res['lot_no']} બન્યો અને {$res['pcs']} પેકેટ્સ (" . format_carat($res['weight']) . ") જૂની કાપન ({$res['kapan_codes']}) ના હાજર સ્ટોકમાંથી નીકળી ગયા છે (ઇતિહાસ સચવાયેલ છે).");
            if ($goToAssort) {
                header('Location: ' . BASE_URL . '/modules/assort/create.php?reassort_lot_id=' . $res['reassort_lot_id']);
            } else {
                header('Location: ' . BASE_URL . '/modules/boil/view.php?id=' . $id);
            }
            exit;
        } else {
            $pdo->rollBack();
            set_flash('error', 'અમાન્ય એક્શન.');
        }
    } catch (\Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'પ્રોસેસ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    }
}

// Refresh batch & packets
$bStmt->execute([$id]);
$batch = $bStmt->fetch(PDO::FETCH_ASSOC);

$pStmt = $pdo->prepare("
    SELECT p.*, k.kapan_code, k.kapan_name,
           ps.status as ps_status, ps.packet_no as ps_packet_no,
           brli.reassort_lot_id as item_lot_id, brl.lot_no as item_lot_no, brl.assort_task_id as item_assort_task_id, brl.status as item_lot_status
    FROM packets p
    JOIN kapans k ON p.kapan_id = k.id
    LEFT JOIN polish_stocks ps ON p.polish_stock_id = ps.id
    LEFT JOIN boil_reassort_lot_items brli ON brli.packet_id = p.id AND brli.boil_batch_id = ?
    LEFT JOIN boil_reassort_lots brl ON brl.id = COALESCE(p.reassort_lot_id, brli.reassort_lot_id)
    WHERE p.boil_batch_id = ? OR brli.boil_batch_id = ?
    ORDER BY p.id ASC
");
$pStmt->execute([$id, $id, $id]);
$packets = $pStmt->fetchAll(PDO::FETCH_ASSOC);

$lotsStmt = $pdo->prepare("
    SELECT brl.*, at.task_no as assort_task_no
    FROM boil_reassort_lots brl
    LEFT JOIN assort_tasks at ON brl.assort_task_id = at.id
    WHERE brl.boil_batch_id = ?
    ORDER BY brl.id DESC
");
$lotsStmt->execute([$id]);
$reassortLots = $lotsStmt->fetchAll(PDO::FETCH_ASSOC);

$defaultLotNo = generate_next_reassort_lot_no();

$eligibleCount = 0;
$inBoilCount = 0;
$polishReadyCount = 0;
$reassortedCount = 0;
foreach ($packets as $pkt) {
    $isRea = !empty($pkt['is_reassorted']) || ($pkt['status'] ?? '') === 'reassorted';
    $isSold = ($pkt['status'] ?? '') === 'sold' || ($pkt['ps_status'] ?? '') === 'sold';
    $isOnJangad = ($pkt['diamond_status'] ?? '') === 'assigned_to_jangad' || in_array(($pkt['ps_status'] ?? ''), ['on_jangad', 'partially_on_jangad'], true);
    if ($isRea) {
        $reassortedCount++;
    } else {
        if (($pkt['current_stage'] ?? '') === 'boil') {
            $inBoilCount++;
        } else {
            $polishReadyCount++;
        }
        if (!$isSold && !$isOnJangad) {
            $eligibleCount++;
        }
    }
}

$pageTitle = 'Boil બેચ વિગત & ઓફિસ Return: ' . $batch['batch_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Boil બેચ: ' . $batch['batch_no'],
    'તારીખ: ' . format_date($batch['boil_date']) . ' • કુલ: ' . (int)$batch['total_pcs'] . ' Pcs (' . format_carat($batch['total_weight']) . ')',
    'fire-burner',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/assort/index.php" class="btn btn-outline-primary btn-sm">' . svg_icon('layer-group', 'me-1') . 'Assort રજિસ્ટર</a>
        <a href="' . BASE_URL . '/modules/boil/index.php" class="btn btn-outline-secondary btn-sm">' . svg_icon('arrow-left', 'me-1') . 'બોઈલ લિસ્ટ</a>
    </div>',
    'rose'
) ?>

<!-- Summary Cards -->
<div class="row g-2.5 mb-4">
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">કુલ Boil બેચ</small>
            <strong class="fs-5 text-dark font-mono"><?= (int)$batch['total_pcs'] ?> Pcs</strong>
            <div class="small text-muted font-mono"><?= format_carat($batch['total_weight']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">Boil ચાલુ (Pending Return)</small>
            <strong class="fs-5 text-warning-emphasis font-mono"><?= $inBoilCount ?> Pcs</strong>
            <div class="small text-muted">ઓફિસ પરત બાકી</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">Polish Stock માં</small>
            <strong class="fs-5 text-success font-mono"><?= $polishReadyCount ?> Pcs</strong>
            <div class="small text-muted font-mono"><?= format_carat($batch['polish_weight'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="erp-form-card p-3">
            <small class="text-muted d-block">Re-Assort થયેલ (નવા લોટ)</small>
            <strong class="fs-5 text-primary font-mono"><?= $reassortedCount ?> Pcs</strong>
            <div class="small text-muted font-mono"><?= format_carat($batch['reassort_weight'] ?? 0) ?></div>
        </div>
    </div>
</div>

<?php if (!empty($reassortLots)): ?>
<!-- Re-Assort Lots Created From This Boil Batch -->
<div class="erp-form-card mb-4">
    <div class="erp-card-header bg-white py-3 px-3">
        <h6 class="header-title mb-0">
            <span class="header-icon icon-purple"><?= svg_icon('layer-group') ?></span>
            આ Boil બેચમાંથી Re-Assort માટે બનેલા નવા લોટ (Re-Assort Lots)
        </h6>
    </div>
    <div class="erp-card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0 align-middle">
                <thead>
                    <tr>
                        <th>નવો લોટ નંબર (New Lot No)</th>
                        <th>ઓફિસ Return તારીખ</th>
                        <th>જૂની કાપન (Old Kapan History)</th>
                        <th>નંગ & વજન</th>
                        <th>Assort સ્થિતિ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reassortLots as $lot): ?>
                        <tr>
                            <td><strong class="font-mono text-primary fs-6"><?= e($lot['lot_no']) ?></strong></td>
                            <td><?= format_date($lot['return_date']) ?></td>
                            <td><span class="badge bg-light text-dark border font-mono"><?= e($lot['source_kapan_codes'] ?: '-') ?></span></td>
                            <td class="font-mono"><strong><?= format_carat($lot['total_weight']) ?></strong> <small class="text-muted">(<?= (int)$lot['total_pcs'] ?> Pcs)</small></td>
                            <td>
                                <?php if (($lot['status'] ?? '') === 'assorted' && !empty($lot['assort_task_id'])): ?>
                                    <span class="badge bg-success-subtle text-success border">Assort પૂર્ણ (<?= e($lot['assort_task_no'] ?? '') ?>)</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border">Assort (ભાગ પાડવા) બાકી</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (($lot['status'] ?? '') === 'assorted' && !empty($lot['assort_task_id'])): ?>
                                    <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$lot['assort_task_id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <?= svg_icon('eye', 'me-1') ?>Assort ટાસ્ક જુઓ
                                    </a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?>/modules/assort/create.php?reassort_lot_id=<?= (int)$lot['id'] ?>" class="btn btn-sm btn-primary fw-bold">
                                        <?= svg_icon('layer-group', 'me-1') ?>આ લોટનો Assort (ભાગ) પાડો &rarr;
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Packets Form for Office Return -> Polish Stock OR Re-Assort -->
<form method="POST" action="<?= BASE_URL ?>/modules/boil/view.php?id=<?= $id ?>" id="boilReturnForm">
    <?= csrf_field() ?>

    <div class="erp-form-card mb-4">
        <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="header-title mb-0">
                    <span class="header-icon icon-rose"><?= svg_icon('gem') ?></span>
                    બેચના હીરા / પેકેટ્સ (Select Packets for Office Return / Re-Assort)
                </h6>
                <small class="text-muted">Boil થઈને ઓફિસ પર આવ્યા બાદ અહીંથી હીરાને Polish Stock માં જમા કરી અથવા નવો લોટ નંબર આપી ફરીથી Assort માં મોકલી શકાય છે.</small>
            </div>
            <?php if ($eligibleCount > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllEligible()">બધા પસંદ / અનસિલેક્ટ કરો</button>
            <?php endif; ?>
        </div>
        <div class="erp-card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width: 45px;">
                                <input type="checkbox" id="selectAllElig" class="form-check-input" onclick="document.querySelectorAll('.elig-pkt-check').forEach(c => { if(!c.disabled) c.checked = this.checked; })">
                            </th>
                            <th>ડાયમંડ નં.</th>
                            <th>જૂની કાપન (Old Kapan)</th>
                            <th>શેપ</th>
                            <th class="text-end">Polish વજન</th>
                            <th>હાલની સ્થિતિ (Status)</th>
                            <th>Re-Assort લોટ / ટ્રેક</th>
                            <th class="text-end">ઇતિહાસ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packets)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">આ બેચમાં કોઈ પેકેટ મળ્યું નથી.</td></tr>
                        <?php else: ?>
                            <?php foreach ($packets as $pkt):
                                $dNo = !empty($pkt['diamond_no']) ? $pkt['diamond_no'] : $pkt['packet_no'];
                                $wt = (float)$pkt['polish_weight'] > 0 ? (float)$pkt['polish_weight'] : (float)($pkt['current_weight'] ?: $pkt['weight']);
                                $isRea = !empty($pkt['is_reassorted']) || ($pkt['status'] ?? '') === 'reassorted';
                                $isSold = ($pkt['status'] ?? '') === 'sold' || ($pkt['ps_status'] ?? '') === 'sold';
                                $isOnJangad = ($pkt['diamond_status'] ?? '') === 'assigned_to_jangad' || in_array(($pkt['ps_status'] ?? ''), ['on_jangad', 'partially_on_jangad'], true);
                                $canSelect = !$isRea && !$isSold && !$isOnJangad;
                            ?>
                                <tr class="<?= $isRea ? 'table-light' : '' ?>">
                                    <td>
                                        <input type="checkbox" name="packet_ids[]" value="<?= (int)$pkt['id'] ?>"
                                               class="form-check-input elig-pkt-check"
                                               <?= $canSelect ? (($pkt['current_stage'] === 'boil') ? 'checked' : '') : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <strong class="font-mono text-dark"><?= e($dNo) ?></strong>
                                        <small class="text-muted d-block font-mono"><?= e($pkt['barcode']) ?></small>
                                    </td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= (int)$pkt['kapan_id'] ?>" class="badge bg-light text-dark border text-decoration-none font-mono">
                                            <?= e($pkt['kapan_code']) ?> — <?= e($pkt['kapan_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= e($pkt['expected_shape'] ?? 'Round') ?></td>
                                    <td class="text-end"><strong class="font-mono text-success"><?= format_carat($wt) ?></strong></td>
                                    <td>
                                        <?php if ($isRea): ?>
                                            <span class="badge bg-primary-subtle text-primary border">જૂની કાપનમાંથી નીકળી Re-Assort થયેલ</span>
                                        <?php elseif ($isSold): ?>
                                            <span class="badge bg-dark-subtle text-dark border">વેચાઈ ગયેલ (Sold)</span>
                                        <?php elseif ($isOnJangad): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">સેલ્સ જાંગડ પર</span>
                                        <?php elseif (($pkt['current_stage'] ?? '') === 'boil'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">Boil ચાલુ (ઓફિસ Return બાકી)</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border">Polish Stock (<?= e($pkt['ps_packet_no'] ?? 'Ready') ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isRea && !empty($pkt['item_lot_no'])): ?>
                                            <strong class="font-mono text-primary"><?= e($pkt['item_lot_no']) ?></strong>
                                            <?php if (!empty($pkt['item_assort_task_id'])): ?>
                                                <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= (int)$pkt['item_assort_task_id'] ?>" class="small d-block text-decoration-none">Assort વિગત &rarr;</a>
                                            <?php elseif (!empty($pkt['item_lot_id'])): ?>
                                                <a href="<?= BASE_URL ?>/modules/assort/create.php?reassort_lot_id=<?= (int)$pkt['item_lot_id'] ?>" class="small d-block text-decoration-none text-warning-emphasis fw-bold">ભાગ પાડવા બાકી &rarr;</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= (int)$pkt['id'] ?>" class="btn btn-sm btn-outline-secondary" title="ડાયમંડ જર્ની / ઇતિહાસ">
                                            <?= svg_icon('clock-rotate-left', 'me-1') ?>ઇતિહાસ
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

    <?php if ($eligibleCount > 0): ?>
    <!-- Office Return Action Terminal -->
    <div class="erp-form-card mb-4">
        <div class="erp-card-header bg-white py-3 px-3">
            <h6 class="header-title mb-0">
                <span class="header-icon icon-emerald"><?= svg_icon('arrows-rotate') ?></span>
                ઓફિસ Return એક્શન — Polish Stock અથવા ફરીથી Assort (Re-Assort)
            </h6>
        </div>
        <div class="erp-card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">ઓફિસ Return તારીખ *</label>
                    <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-dark">
                        નવો Re-Assort લોટ નં. <span class="badge bg-light text-primary border ms-1">Editable</span>
                    </label>
                    <input type="text" name="lot_no" class="form-control fw-bold font-mono" value="<?= e($defaultLotNo) ?>" placeholder="દા.ત. LOT-2026-0001">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-dark">લોટ રેટ ₹/Ct (Optional)</label>
                    <input type="number" step="0.01" min="0" name="lot_rate" class="form-control fw-bold font-mono" placeholder="0.00 (પછી પણ નાખી શકાય)">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">નોંધ (Return / Re-Assort Notes)</label>
                    <input type="text" name="notes" class="form-control" placeholder="ઓફિસ ચેકિંગ / કારણ...">
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 border-top">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="go_to_assort" id="goToAssortCheck" value="1" checked>
                    <label class="form-check-label small fw-semibold text-dark" for="goToAssortCheck">
                        Re-Assort સેવ કર્યા પછી તરત જ <strong>Assort ભાગ પાડવા (Create Assort)</strong> પેજ ખોલો
                    </label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($inBoilCount > 0): ?>
                        <button type="submit" name="action" value="to_polish_stock" class="btn btn-success px-4 fw-bold shadow-sm">
                            <?= svg_icon('gem', 'me-1') ?>પસંદ કરેલ &rarr; Polish Stock માં જમા કરો
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="to_reassort" class="btn btn-primary px-4 fw-bold shadow-sm"
                            onclick="return confirm('શું તમે પસંદ કરેલ પેકેટ્સને નવો લોટ નંબર આપી ફરીથી Assort માં મોકલવા માંગો છો? તે જૂની કાપનના હાજર સ્ટોકમાંથી નીકળી જશે અને સંપૂર્ણ ઇતિહાસ સચવાશે.');">
                        <?= svg_icon('layer-group', 'me-1') ?>ફરીથી Assort માં મોકલો (New Lot &amp; Exit Old Kapan)
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</form>

<script>
function toggleAllEligible() {
    var checks = Array.from(document.querySelectorAll('.elig-pkt-check')).filter(c => !c.disabled);
    var anyUnchecked = checks.some(c => !c.checked);
    checks.forEach(c => c.checked = anyUnchecked);
    var master = document.getElementById('selectAllElig');
    if (master) master.checked = anyUnchecked;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
