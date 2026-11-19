<?php
/**
 * Rough Purchases Register (Unified to Rough Hub)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=purchase' . (!empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''));
exit;

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT rp.*, p.party_name, b.party_name as broker_name 
        FROM rough_purchases rp
        JOIN parties p ON rp.party_id = p.id
        LEFT JOIN parties b ON rp.broker_id = b.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (rp.purchase_no LIKE ? OR p.party_name LIKE ? OR rp.product_description LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if (!empty($statusFilter)) {
    $sql .= " AND rp.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY rp.purchase_date DESC, rp.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// Count for tabs & aggregations
$roughCount = count($purchases);
$polishCount = (int)$pdo->query("SELECT COUNT(*) FROM polish_purchases")->fetchColumn();

$totWeight = 0.000;
$totPcs = 0;
$totNetPayment = 0.00;
$totDueAmount = 0.00;
foreach ($purchases as $rp) {
    $totWeight += (float)$rp['weight'];
    $totPcs += (int)$rp['pcs'];
    $totNetPayment += (float)$rp['net_payment'];
    if (($rp['party_payment_status'] ?? '') !== 'paid') {
        $totDueAmount += ((float)$rp['net_payment'] - (float)($rp['paid_amount'] ?? 0));
    }
}
?>

<!-- Master Purchases Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/rough_purchase/index.php', 'label' => 'Rough ખરીદી (Rough Buy)', 'icon' => 'gem', 'count' => $roughCount, 'active' => true],
    ['url' => BASE_URL . '/modules/polish_purchase/index.php', 'label' => 'Polish ખરીદી (Polish Buy)', 'icon' => 'shapes', 'count' => $polishCount],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Rough ખરીદી રજિસ્ટર (Rough Diamond Purchases)',
    '',
    'gem',
    '<a href="' . BASE_URL . '/modules/rough_purchase/import.php" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'બલ્ક આયાત (Excel Import)</a>' .
    '<a href="' . BASE_URL . '/modules/reports/export.php?type=rough_purchase' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-secondary px-3 shadow-sm">' . svg_icon('file-arrow-down', 'me-1') . 'Export</a>' .
    '<a href="' . BASE_URL . '/modules/rough_purchase/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવી Rough ખરીદી</a>'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ ખરીદી વજન (Total Weight)', format_carat($totWeight), $totPcs . ' Pcs', 'કુલ રફ ખરીદી', 'hero', BASE_URL . '/modules/rough_purchase/create.php', '', 'shapes') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ ખરીદી રકમ (Net Purchases)', format_inr($totNetPayment), count($purchases) . ' બિલો', 'કુલ નેટ ચુકવણી', 'white', null, 'pill-mint', 'file-invoice') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ચુકવવાપાત્ર બાકી રકમ (Due Amount)', format_inr($totDueAmount), 'બાકી રકમ', 'પાર્ટી પેમેન્ટ', 'white', BASE_URL . '/modules/finance/schedule.php', 'pill-coral', 'clock-rotate-left') ?>
    </div>
</div>

<!-- Search & Filters Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/rough_purchase/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="ખરીદી નં, પાર્ટીનું નામ, અથવા વર્ણન શોધો..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select name="status" class="form-select">
                <option value="">બધી સ્ટોક સ્થિતિ (All Status)</option>
                <option value="in_stock" <?= $statusFilter === 'in_stock' ? 'selected' : '' ?>>In Stock (હાજર સ્ટોક)</option>
                <option value="in_assort" <?= $statusFilter === 'in_assort' ? 'selected' : '' ?>>In Assort (ભાગ પડાય છે)</option>
                <option value="assorted" <?= $statusFilter === 'assorted' ? 'selected' : '' ?>>Assorted (ભાગ પડી ગયા)</option>
                <option value="sold" <?= $statusFilter === 'sold' ? 'selected' : '' ?>>Sold (વેચાઈ ગયો)</option>
            </select>
        </div>
        <div class="col-6 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary px-3"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/rough_purchase/index.php" class="btn btn-outline-dark">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>ખરીદી નં / તારીખ</th>
                        <th>પાર્ટી / દલાલ</th>
                        <th>Pcs & વજન</th>
                        <th>ભાવ / ct</th>
                        <th>Net Payment</th>
                        <th>Due Date</th>
                        <th>ચુકવણી</th>
                        <th>સ્ટોક</th>
                        <th class="text-end table-actions">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ ખરીદી મળી નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= e($p['purchase_no']) ?></div>
                                    <small class="text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($p['purchase_date']) ?></small>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['party_id'] ?>&tab=rough" class="fw-semibold text-primary text-decoration-none text-truncate d-inline-block" style="max-width: 140px;" title="પાર્ટી ખાતાવહી (Rough Purchase Ledger) જુઓ">
                                        <?= e($p['party_name']) ?>
                                    </a>
                                    <?php if (!empty($p['broker_name']) && !empty($p['broker_id'])): ?>
                                        <small class="text-muted text-truncate d-block" style="max-width: 140px;">
                                            દલાલ: <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['broker_id'] ?>&tab=brokerage" class="text-muted text-decoration-none" title="દલાલ ખાતાવહી જુઓ"><?= e($p['broker_name']) ?></a>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted text-truncate d-block" style="max-width: 140px;">દલાલ: <?= e($p['broker_name'] ?? '-') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= (int)$p['pcs'] ?> Pcs</div>
                                    <small class="text-primary font-mono fw-semibold"><?= format_carat($p['weight']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark font-mono"><?= format_inr($p['price_per_carat_inr']) ?></div>
                                    <?php if ((float)($p['dollar_per_carat'] ?? 0) > 0 && (float)($p['dollar_rate'] ?? 0) > 0): ?>
                                        <small class="text-muted font-mono" style="font-size: 0.78rem;">$<?= number_format((float)$p['dollar_per_carat'], 2) ?> @ ₹<?= number_format((float)$p['dollar_rate'], 2) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.70rem; padding: 1px 5px;">Stand Rate</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-success font-mono"><?= format_inr($p['net_payment']) ?></div>
                                    <small class="text-muted font-mono" style="font-size: 0.8125rem;">દલાલી: <?= format_inr($p['brokerage_amount']) ?></small>
                                </td>
                                <td>
                                    <div class="font-mono"><?= format_date($p['due_date']) ?></div>
                                    <small class="text-muted"><?= (int)$p['due_days'] ?> દિવસ</small>
                                </td>
                                <td>
                                    <?= get_due_badge($p['due_date'], $p['party_payment_status']) ?>
                                    <?php if ($p['party_payment_status'] === 'paid'): ?>
                                        <div class="small text-muted mt-1"><?= svg_icon('check-double', 'text-success me-1') ?><?= format_date($p['chukvya_date']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['status'] === 'in_stock'): ?>
                                        <span class="badge bg-primary-subtle text-primary border">In Stock</span>
                                    <?php elseif ($p['status'] === 'assorted'): ?>
                                        <span class="badge bg-success-subtle text-success border">Assorted</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border"><?= e($p['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end table-actions text-nowrap">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <?php if ((float)$p['available_weight'] > 0): ?>
                                            <a href="<?= BASE_URL ?>/modules/assort/create.php?rough_id=<?= $p['id'] ?>" class="btn btn-xs btn-success text-white d-inline-flex align-items-center gap-1 shadow-sm px-2 py-1" title="ભાગ પાડો (Assort)">
                                                <?= svg_icon('layer-group', '', 12) ?>
                                                <span>એસોર્ટ</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary py-1 px-2" title="વિગત જુઓ">
                                            <?= svg_icon('eye') ?>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary py-1 px-2" title="ખરીદી એડિટ કરો">
                                            <?= svg_icon('pen-to-square') ?>
                                        </a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
