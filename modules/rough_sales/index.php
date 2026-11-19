<?php
/**
 * Rough Sales Register (Unified to Rough Hub)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=sell' . (!empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''));
exit;

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT rs.*, p.party_name, b.party_name as broker_name
        FROM rough_sales rs
        JOIN parties p ON rs.party_id = p.id
        LEFT JOIN parties b ON rs.broker_id = b.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (rs.invoice_no LIKE ? OR p.party_name LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term]);
}

if (!empty($statusFilter)) {
    $sql .= " AND rs.payment_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY rs.sale_date DESC, rs.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Count for tabs & aggregations
$roughSalesCount = count($sales);
$polishSalesCount = (int)$pdo->query("SELECT COUNT(*) FROM polish_sales")->fetchColumn();

$totWeight = 0.000;
$totPcs = 0;
$totNetSales = 0.00;
$totReceivable = 0.00;
foreach ($sales as $s) {
    $totWeight += (float)$s['weight'];
    $totPcs += (int)$s['pcs'];
    $totNetSales += (float)$s['net_amount'];
    if (($s['payment_status'] ?? '') !== 'paid') {
        $totReceivable += ((float)$s['net_amount'] - (float)($s['received_amount'] ?? 0));
    }
}
?>

<!-- Master Sales Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/polish_sales/index.php', 'label' => 'Polish વેચાણ (Polish Sales)', 'icon' => 'shapes', 'count' => $polishSalesCount],
    ['url' => BASE_URL . '/modules/rough_sales/index.php', 'label' => 'Rough વેચાણ (Rough Sales)', 'icon' => 'gem', 'count' => $roughSalesCount, 'active' => true],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Rough વેચાણ રજિસ્ટર (Rough Diamond Sales)',
    '',
    'hand-holding-dollar',
    '<a href="' . BASE_URL . '/modules/reports/export.php?type=rough_sales' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel માં Export</a>' .
    '<a href="' . BASE_URL . '/modules/rough_sales/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવું Rough વેચાણ</a>',
    'emerald'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ વેચાણ વજન (Sold Weight)', format_carat($totWeight), $totPcs . ' Pcs', 'રફ વેચાણ વજન', 'hero', BASE_URL . '/modules/rough_sales/create.php', '', 'shapes') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ નેટ વેચાણ (Net Revenue)', format_inr($totNetSales), count($sales) . ' ઇન્વોઇસ', 'કુલ વેચાણ રકમ', 'white', null, 'pill-mint', 'file-invoice-dollar') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ગ્રાહકો પાસેથી બાકી લેણાં (Receivables)', format_inr($totReceivable), 'બાકી રકમ', 'ગ્રાહક લેણાં', 'white', null, 'pill-coral', 'hourglass-half') ?>
    </div>
</div>

<!-- Search & Filters Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/rough_sales/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="ઇન્વોઇસ નં અથવા ગ્રાહકનું નામ શોધો..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select name="status" class="form-select">
                <option value="">બધી ચુકવણી સ્થિતિ (All Status)</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending (લેવાના બાકી)</option>
                <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial (અંશતઃ મળ્યા)</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid (પૂરેપૂરા જમા)</option>
            </select>
        </div>
        <div class="col-6 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-dark px-3"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/rough_sales/index.php" class="btn btn-outline-secondary">રીસેટ</a>
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
                        <th>ઇન્વોઇસ નં / તારીખ</th>
                        <th>ગ્રાહક (Party) / દલાલ</th>
                        <th>Pcs & વજન (Carat)</th>
                        <th>ભાવ / Ct (INR)</th>
                        <th>Net Amount (લેવાની રકમ)</th>
                        <th>Due Date</th>
                        <th>ચુકવણી સ્થિતિ</th>
                        <th class="text-end table-actions">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">કોઈ Rough વેચાણ રેકોર્ડ નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= e($s['invoice_no']) ?></strong>
                                    <div class="small text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($s['sale_date']) ?></div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $s['party_id'] ?>&tab=sales" class="fw-semibold text-primary text-decoration-none" title="ગ્રાહક ખાતાવહી (Rough Sales Ledger) જુઓ">
                                        <?= e($s['party_name']) ?>
                                    </a>
                                    <?php if (!empty($s['broker_name']) && !empty($s['broker_id'])): ?>
                                        <div class="small text-muted">
                                            દલાલ: <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $s['broker_id'] ?>&tab=brokerage" class="text-muted text-decoration-none" title="દલાલ ખાતાવહી જુઓ"><?= e($s['broker_name']) ?></a>
                                        </div>
                                    <?php else: ?>
                                        <small class="text-muted d-block"><?= e($s['broker_name'] ?? 'Direct') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= (int)$s['pcs'] ?> Pcs</div>
                                    <div class="small text-primary font-mono fw-semibold"><?= format_carat($s['weight']) ?></div>
                                </td>
                                <td><?= format_inr($s['price_per_carat_inr']) ?></td>
                                <td><strong class="text-success fs-6"><?= format_inr($s['net_amount']) ?></strong></td>
                                <td>
                                    <div><?= format_date($s['due_date']) ?></div>
                                    <small class="text-muted"><?= (int)$s['due_days'] ?> Days</small>
                                </td>
                                <td>
                                    <?= get_due_badge($s['due_date'], $s['payment_status']) ?>
                                    <?php if ($s['payment_status'] === 'paid'): ?>
                                        <div class="small text-muted mt-1">મળ્યા: <?= format_date($s['received_date']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end table-actions">
                                    <a href="<?= BASE_URL ?>/modules/rough_sales/edit.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="વેચાણ એડિટ કરો">
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
