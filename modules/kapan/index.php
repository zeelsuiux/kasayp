<?php
/**
 * Kapan Batches Register (સેક્શન ૬.૩ & Units & Rough Cost Merging)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Kapan અને Packets રજિસ્ટર (MFG Kapan)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_boil_reassort_columns();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT k.*, rp.purchase_no,
               (SELECT COUNT(*) FROM packets WHERE kapan_id = k.id AND COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted') as packet_count,
               (SELECT COUNT(*) FROM packets WHERE kapan_id = k.id AND current_stage = 'polish_stock' AND COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted') as finished_count,
               (SELECT COUNT(*) FROM packets WHERE kapan_id = k.id AND COALESCE(is_reassorted, 0) = 1) as reassort_count,
               (SELECT COUNT(*) FROM packets WHERE kapan_id = k.id AND (current_stage IN ('factory', 'polish_stock') OR polish_weight > 0)) as advanced_count
        FROM kapans k
        LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (k.kapan_code LIKE ? OR k.kapan_name LIKE ? OR k.rough_numbers LIKE ? OR rp.purchase_no LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

if (!empty($statusFilter)) {
    $sql .= " AND k.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY k.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kapans = $stmt->fetchAll();

$kapanCount = count($kapans);

$totWeight = 0.000;
$totPackets = 0;
$totFinished = 0;
foreach ($kapans as $kp) {
    $totWeight += (float)$kp['total_weight'];
    $totPackets += (int)($kp['packet_count'] ?? 0);
    $totFinished += (int)($kp['finished_count'] ?? 0);
}
?>

<!-- Page Header with Action Buttons -->
<?= render_page_header(
    'Kapan રજિસ્ટર અને પેકેટ્સ મેનેજમેન્ટ (Units & Rough Cost)',
    '',
    'boxes-stacked',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/kapan/create.php" class="btn btn-primary px-3 shadow-sm fw-bold">
            ' . svg_icon('plus', 'me-1') . 'નવા Kapan બનાવો (Units & Rough Cost)
        </a>
    </div>',
    'primary'
) ?>

<!-- Top Stat Cards (Donezo Design System) -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('સક્રિય Kapan બેચ', (string)$kapanCount, 'કુલ એન્ટ્રીઓ', 'કાપન યાદી', 'hero', null) ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ રફ વજન (Carats)', format_carat($totWeight), 'મેન્યુફેક્ચરિંગ ઇનપુટ', 'કુલ વજન', 'white', null, 'pill-mint') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ પેકેટ્સ (Packets)', (string)$totPackets, 'બારકોડ લેબલ્સ', 'ઉત્પાદન પેકેટ્સ', 'white', null, 'pill-mint') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('તૈયાર Polish નંગ', (string)$totFinished, 'સ્ટોકમાં પૂર્ણ થયેલ', 'આખરી આઉટપુટ', 'white', null, 'pill-coral') ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="erp-filter-card mb-3">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control" placeholder="કાપન કોડ, નામ અથવા Rough પેકેટ નં..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select name="status" class="form-select">
                <option value="">બધી સ્થિતિ (All Status)</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active (ચાલુ)</option>
                <option value="in_production" <?= $statusFilter === 'in_production' ? 'selected' : '' ?>>In Production</option>
                <option value="boil_completed" <?= $statusFilter === 'boil_completed' ? 'selected' : '' ?>>Boil Completed</option>
                <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed (બંધ)</option>
            </select>
        </div>
        <div class="col-6 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-dark px-3"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/kapan/index.php" class="btn btn-outline-secondary">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 16%;">કાપન કોડ / નામ</th>
                        <th style="width: 20%;">Rough Number(s) સંદર્ભ</th>
                        <th style="width: 9%;" class="text-center">કુલ નંગ</th>
                        <th style="width: 11%;" class="text-end">કુલ વજન (Ct)</th>
                        <th style="width: 12%;" class="text-end">Rough Cost</th>
                        <th style="width: 12%;">બારકોડ પેકેટ્સ</th>
                        <th style="width: 8%;">Yield %</th>
                        <th style="width: 6%;">સ્થિતિ</th>
                        <th style="width: 6%;" class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kapans)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ કાપન મળ્યો નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($kapans as $k): ?>
                            <?php 
                            $canDelRow = ($k['status'] !== 'closed' && (int)($k['advanced_count'] ?? 0) === 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark font-mono fs-6"><?= e($k['kapan_code']) ?></div>
                                    <div class="text-primary fw-semibold small"><?= e($k['kapan_name']) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($k['rough_numbers'])): ?>
                                        <div class="badge bg-light text-dark border font-mono small text-wrap text-start p-1.5" style="max-width: 220px; line-height: 1.4;">
                                            <?= svg_icon('gem', 'text-primary me-1') ?><?= e($k['rough_numbers']) ?>
                                        </div>
                                    <?php elseif (!empty($k['purchase_no'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $k['rough_purchase_id'] ?>" class="badge bg-light text-primary border text-decoration-none">
                                            <?= e($k['purchase_no']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono"><?= (int)$k['total_pcs'] ?> Pcs</td>
                                <td class="text-end font-mono fw-bold text-primary"><?= format_carat($k['total_weight']) ?></td>
                                <td class="text-end font-mono fw-bold text-dark"><?= format_inr($k['rough_cost']) ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border font-mono">
                                        <?= svg_icon('barcode', 'me-1 text-primary') ?><?= (int)$k['packet_count'] ?> Packets
                                    </span>
                                    <div class="small text-muted font-mono"><?= (int)$k['finished_count'] ?> Polish માં</div>
                                    <?php if ((int)($k['reassort_count'] ?? 0) > 0): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border font-mono mt-1" style="font-size: 0.7rem;">
                                            Re-Assort: <?= (int)$k['reassort_count'] ?> Pcs (નીકળેલ)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((float)$k['yield_percentage'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border fw-bold"><?= number_format((float)$k['yield_percentage'], 2) ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted small">પ્રોસેસમાં</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($k['status'] === 'boil_completed'): ?>
                                        <span class="badge bg-success-subtle text-success border">Boil પૂર્ણ</span>
                                    <?php elseif ($k['status'] === 'in_production'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">પ્રોડક્શન</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border"><?= e($k['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary" title="વિગત જુઓ">
                                            <?= svg_icon('eye') ?>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/kapan/edit.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-secondary" title="કાપન એડિટ કરો">
                                            <?= svg_icon('pen-to-square') ?>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/kapan/print_barcode.php?kapan_id=<?= $k['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="બારકોડ પ્રિન્ટ">
                                            <?= svg_icon('print') ?>
                                        </a>
                                        <?php if ($canDelRow): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="કાપન ડિલીટ કરો" onclick="confirmDeleteKapan(<?= $k['id'] ?>, '<?= addslashes(e($k['kapan_code'])) ?>', '<?= addslashes(e($k['kapan_name'])) ?>')">
                                                <?= svg_icon('trash') ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal for Quick Delete Confirmation from Index -->
<div class="modal fade" id="indexDeleteKapanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <?= svg_icon('triangle-exclamation', 'me-2') ?>કાપન ડિલીટ કરવાની પુષ્ટિ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= BASE_URL ?>/modules/kapan/delete.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="modal_delete_kapan_id" value="">
                <div class="modal-body py-3">
                    <p class="mb-2">શું તમે ખરેખર કાપન <strong class="text-dark font-mono" id="modal_delete_kapan_code"></strong> (<span id="modal_delete_kapan_name"></span>) ને ડિલીટ કરવા માંગો છો?</p>
                    <div class="alert alert-warning small mb-0">
                        <?= svg_icon('shield-halved', 'me-1') ?>
                        આ કાપનમાં ફાળવેલા તમામ <strong>Rough પેકેટ્સ આપમેળે મુક્ત (Available)</strong> થઈને ફરીથી નવા કાપન બનાવવા માટે ઉપલબ્ધ થઈ જશે.
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-danger px-4 fw-bold">
                        <?= svg_icon('trash', 'me-1') ?>હા, ડિલીટ કરો
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDeleteKapan(id, code, name) {
    document.getElementById('modal_delete_kapan_id').value = id;
    document.getElementById('modal_delete_kapan_code').innerText = code;
    document.getElementById('modal_delete_kapan_name').innerText = name;
    const modal = new bootstrap.Modal(document.getElementById('indexDeleteKapanModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
