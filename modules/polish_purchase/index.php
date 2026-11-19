<?php
/**
 * Polish Purchases Register (Unified to Polish Hub)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
$targetTab = (($_GET['type'] ?? '') === 'parcel') ? 'parcel_purchase' : 'gia_purchase';
header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $targetTab . (!empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''));
exit;

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT pp.*, p.party_name, b.party_name as broker_name
        FROM polish_purchases pp
        JOIN parties p ON pp.party_id = p.id
        LEFT JOIN parties b ON pp.broker_id = b.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (pp.purchase_no LIKE ? OR p.party_name LIKE ? OR pp.gia_number LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if (!empty($statusFilter)) {
    $sql .= " AND pp.payment_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY pp.purchase_date DESC, pp.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// Counts for tabs & aggregations
$polishCount = count($purchases);
$roughCount = (int)$pdo->query("SELECT COUNT(*) FROM rough_purchases")->fetchColumn();

$totWeight = 0.000;
$totPcs = 0;
$totNetPayment = 0.00;
$totDueAmount = 0.00;
foreach ($purchases as $pp) {
    $totWeight += (float)$pp['weight'];
    $totPcs += (int)$pp['pcs'];
    $totNetPayment += (float)$pp['net_payment'];
    if (($pp['payment_status'] ?? '') !== 'paid') {
        $totDueAmount += ((float)$pp['net_payment'] - (float)($pp['paid_amount'] ?? 0));
    }
}
?>

<!-- Master Purchases Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/rough_purchase/index.php', 'label' => 'Rough ખરીદી (Rough Buy)', 'icon' => 'gem', 'count' => $roughCount],
    ['url' => BASE_URL . '/modules/polish_purchase/index.php', 'label' => 'Polish ખરીદી (Polish Buy)', 'icon' => 'shapes', 'count' => $polishCount, 'active' => true],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Polish ખરીદી રજિસ્ટર (Polish Diamond Purchases)',
    '',
    'shapes',
    '<a href="' . BASE_URL . '/modules/reports/export.php?type=polish_purchase' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel માં Export</a>' .
    '<a href="' . BASE_URL . '/modules/polish_purchase/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવી Polish ખરીદી</a>'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ પોલિશ વજન (Total Weight)', format_carat($totWeight), $totPcs . ' Pcs', 'તૈયાર હીરા ખરીદી', 'hero', BASE_URL . '/modules/polish_purchase/create.php', '', 'shapes') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ ખરીદી રકમ (Net Purchases)', format_inr($totNetPayment), count($purchases) . ' બિલો', 'કુલ નેટ ચુકવણી', 'white', null, 'pill-mint', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ચુકવવાપાત્ર બાકી રકમ (Due Amount)', format_inr($totDueAmount), 'બાકી રકમ', 'પાર્ટી પેમેન્ટ', 'white', BASE_URL . '/modules/finance/schedule.php', 'pill-coral', 'clock-rotate-left') ?>
    </div>
</div>

<!-- Search & Filters Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/polish_purchase/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="ખરીદી નં, પાર્ટીનું નામ, અથવા GIA નંબર શોધો..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select name="status" class="form-select">
                <option value="">બધી ચુકવણી સ્થિતિ (All Status)</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending (બાકી મુદત)</option>
                <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial (અંશતઃ ચૂકવેલ)</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid (ચૂકવાઈ ગયું)</option>
            </select>
        </div>
        <div class="col-6 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary px-3"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/polish_purchase/index.php" class="btn btn-outline-dark">રીસેટ</a>
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
                        <th>પ્રકાર & વિગત</th>
                        <th>Pcs & વજન</th>
                        <th>ભાવ / Ct</th>
                        <th>Net Payment</th>
                        <th>Due Date</th>
                        <th>ચુકવણી</th>
                        <th class="text-end table-actions">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ Polish ખરીદી રેકોર્ડ નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $p): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= e($p['purchase_no']) ?></strong>
                                    <div class="small text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($p['purchase_date']) ?></div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['party_id'] ?>&tab=polish" class="fw-semibold text-primary text-decoration-none" title="પાર્ટી ખાતાવહી (Polish Purchase Ledger) જુઓ">
                                        <?= e($p['party_name']) ?>
                                    </a>
                                    <?php if (!empty($p['broker_name']) && !empty($p['broker_id'])): ?>
                                        <div class="small text-muted">
                                            દલાલ: <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['broker_id'] ?>&tab=brokerage" class="text-muted text-decoration-none" title="દલાલ ખાતાવહી જુઓ"><?= e($p['broker_name']) ?></a>
                                        </div>
                                    <?php else: ?>
                                        <small class="text-muted d-block"><?= e($p['broker_name'] ?? 'Direct') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border text-uppercase"><?= e($p['stock_type']) ?></span>
                                    <div class="small text-muted"><?= e($p['shape'] ?? 'Round') ?> • <?= e($p['color'] ?? '') ?>/<?= e($p['clarity'] ?? '') ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= (int)$p['pcs'] ?> Pcs</div>
                                    <div class="small text-success font-mono fw-semibold"><?= format_carat($p['weight']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark font-mono"><?= format_inr($p['price_per_carat_inr']) ?></div>
                                    <?php if ((float)($p['dollar_per_carat'] ?? 0) > 0 && (float)($p['dollar_rate'] ?? 0) > 0): ?>
                                        <div class="small text-muted font-mono" style="font-size: 0.78rem;">$<?= number_format((float)$p['dollar_per_carat'], 2) ?> @ ₹<?= number_format((float)$p['dollar_rate'], 2) ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.70rem; padding: 1px 5px;">Stand Rate</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-dark fs-6"><?= format_inr($p['net_payment']) ?></strong>
                                    <div class="small text-muted">દલાલી: <?= format_inr($p['brokerage_amount']) ?></div>
                                </td>
                                <td>
                                    <div><?= format_date($p['due_date']) ?></div>
                                    <small class="text-muted"><?= (int)$p['due_days'] ?> Days</small>
                                </td>
                                <td><?= get_due_badge($p['due_date'], $p['payment_status']) ?></td>
                                <td class="text-end table-actions">
                                    <a href="<?= BASE_URL ?>/modules/polish_purchase/edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="ખરીદી એડિટ કરો">
                                        <?= svg_icon('pen-to-square') ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
