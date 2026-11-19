<?php
/**
 * Polish Sales Register (Unified to Polish Hub)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
$targetTab = (($_GET['type'] ?? '') === 'parcel') ? 'parcel_sell' : 'gia_sell';
header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $targetTab . (!empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''));
exit;

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT ps.*, p.party_name, b.party_name as broker_name, stk.packet_no, stk.shape
        FROM polish_sales ps
        JOIN parties p ON ps.party_id = p.id
        LEFT JOIN parties b ON ps.broker_id = b.id
        JOIN polish_stocks stk ON ps.polish_stock_id = stk.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (ps.invoice_no LIKE ? OR p.party_name LIKE ? OR stk.packet_no LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if (!empty($statusFilter)) {
    $sql .= " AND ps.payment_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY ps.sale_date DESC, ps.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Count for tabs & aggregations
$polishSalesCount = count($sales);
$roughSalesCount = (int)$pdo->query("SELECT COUNT(*) FROM rough_sales")->fetchColumn();

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
    ['url' => BASE_URL . '/modules/polish_sales/index.php', 'label' => 'Polish વેચાણ (Polish Sales)', 'icon' => 'shapes', 'count' => $polishSalesCount, 'active' => true],
    ['url' => BASE_URL . '/modules/rough_sales/index.php', 'label' => 'Rough વેચાણ (Rough Sales)', 'icon' => 'gem', 'count' => $roughSalesCount],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Polish વેચાણ રજિસ્ટર (Polish Diamond Sales)',
    '',
    'hand-holding-dollar',
    '<a href="' . BASE_URL . '/modules/reports/export.php?type=polish_sales' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel માં Export</a>' .
    '<a href="' . BASE_URL . '/modules/polish_sales/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવું Polish વેચાણ</a>',
    'emerald'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ વેચાણ વજન (Sold Carats)', format_carat($totWeight), $totPcs . ' Pcs', 'તૈયાર હીરા વેચાણ', 'hero', BASE_URL . '/modules/polish_sales/create.php', '', 'shapes') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ નેટ વેચાણ (Net Revenue)', format_inr($totNetSales), count($sales) . ' ઇન્વોઇસ', 'કુલ વેચાણ આવક', 'white', null, 'pill-mint', 'file-invoice-dollar') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ગ્રાહકો પાસેથી બાકી લેણાં (Receivables)', format_inr($totReceivable), 'વસૂલવાની બાકી રકમ', 'ગ્રાહક લેણાં', 'white', null, 'pill-coral', 'hourglass-half') ?>
    </div>
</div>

<!-- Search & Filters Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/polish_sales/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><?= svg_icon('magnifying-glass', 'text-muted') ?></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="ઇન્વોઇસ નં, ગ્રાહકનું નામ, અથવા પેકેટ નં શોધો..." value="<?= e($search) ?>">
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
                <a href="<?= BASE_URL ?>/modules/polish_sales/index.php" class="btn btn-outline-secondary">રીસેટ</a>
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
                        <th>ઇન્વોઇસ / તારીખ</th>
                        <th>ગ્રાહક / દલાલ</th>
                        <th>સ્ટોક પેકેટ</th>
                        <th>Pcs & વજન</th>
                        <th>ભાવ / Ct</th>
                        <th>Net Amount</th>
                        <th>Due Date</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end table-actions">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ Polish વેચાણ મળ્યું નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= e($s['invoice_no']) ?></strong>
                                    <div class="small text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($s['sale_date']) ?></div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $s['party_id'] ?>&tab=sales" class="fw-semibold text-primary text-decoration-none" title="ગ્રાહક ખાતાવહી (Polish Sales Ledger) જુઓ">
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
                                    <span class="badge bg-light text-dark border"><?= e($s['packet_no']) ?></span>
                                    <small class="text-muted d-block"><?= e($s['shape']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= (int)$s['pcs'] ?> Pcs</div>
                                    <div class="small text-success font-mono fw-semibold"><?= format_carat($s['weight']) ?></div>
                                </td>
                                <td><?= format_inr($s['price_per_carat_inr']) ?></td>
                                <td><strong class="text-primary fs-6"><?= format_inr($s['net_amount']) ?></strong></td>
                                <td>
                                    <div><?= format_date($s['due_date']) ?></div>
                                    <small class="text-muted"><?= (int)$s['due_days'] ?> Days</small>
                                </td>
                                <td><?= get_due_badge($s['due_date'], $s['payment_status']) ?></td>
                                <td class="text-end table-actions">
                                    <a href="<?= BASE_URL ?>/modules/polish_sales/edit.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="વેચાણ એડિટ કરો">
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
