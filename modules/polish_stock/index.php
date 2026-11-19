<?php
/**
 * Polish Diamonds Stock Register (Unified to Polish Hub)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=stock' . (!empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''));
exit;

$pdo = getDbConnection();

$typeFilter = trim($_GET['type'] ?? '');
$locFilter = trim($_GET['location'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "SELECT ps.*, k.kapan_name 
        FROM polish_stocks ps
        LEFT JOIN kapans k ON ps.kapan_id = k.id
        WHERE ps.available_weight > 0";
$params = [];

if (!empty($typeFilter)) {
    $sql .= " AND ps.stock_type = ?";
    $params[] = $typeFilter;
}

if (!empty($locFilter)) {
    $sql .= " AND ps.current_location = ?";
    $params[] = $locFilter;
}

if (!empty($search)) {
    $sql .= " AND (ps.packet_no LIKE ? OR ps.gia_number LIKE ? OR ps.shape LIKE ? OR ps.size_description LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$sql .= " ORDER BY ps.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

// Aggregations
$totWeight = 0.000;
$totPcs = 0;
$totVal = 0.00;
foreach ($stocks as $s) {
    $totWeight += (float)$s['available_weight'];
    $totPcs += (int)$s['available_pcs'];
    $totVal += ((float)$s['available_weight'] * (float)$s['cost_per_carat']);
}

// Counts for tabs
$polishStockCount = count($stocks);
$roughStockCount = (int)$pdo->query("SELECT COUNT(*) FROM rough_purchases WHERE status = 'in_stock'")->fetchColumn();
$topsCount = (int)$pdo->query("SELECT COUNT(*) FROM tops_records WHERE status = 'in_stock'")->fetchColumn();
?>

<!-- Master Inventory Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/polish_stock/index.php', 'label' => 'Polish Stock (હાજર સ્ટોક)', 'icon' => 'gem', 'count' => $polishStockCount, 'active' => true],
    ['url' => BASE_URL . '/modules/polish_stock/gia.php', 'label' => 'GIA સર્ટિફિકેશન (Lab Tracker)', 'icon' => 'award'],
    ['url' => BASE_URL . '/modules/rough_purchase/index.php?status=in_stock', 'label' => 'Rough In-Stock (રફ હાજર)', 'icon' => 'shapes', 'count' => $roughStockCount],
    ['url' => BASE_URL . '/modules/tops/index.php', 'label' => 'Tops (Scrap રદિયો)', 'icon' => 'recycle', 'count' => $topsCount],
    ['url' => BASE_URL . '/modules/admin/upload_stock.php', 'label' => 'Excel અપલોડ', 'icon' => 'file-arrow-up'],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Polish Stock વ્યવસ્થાપન (Finished Diamonds)',
    '',
    'gem',
    '<a href="' . BASE_URL . '/modules/reports/export.php?type=polish_stock' . (!empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '') . '" class="btn btn-outline-success px-3 shadow-sm text-nowrap">' . svg_icon('file-excel', 'me-1') . 'Excel સ્ટોક Export</a>' .
    '<a href="' . BASE_URL . '/modules/polish_stock/create.php" class="btn btn-primary px-3 shadow-sm text-nowrap">' . svg_icon('plus', 'me-1') . '<span class="d-none d-sm-inline">નવો </span>Polish Stock<span class="d-none d-md-inline"> ઉમેરો</span></a>'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ હાજર વજન (Available Carats)', format_carat($totWeight), $totPcs . ' Pcs હાજર', 'પોલિશ સ્ટોક', 'hero', BASE_URL . '/modules/polish_stock/create.php', '', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ પડતર મૂલ્ય (Inventory Cost)', format_inr($totVal), 'પડતર ભાવ અનુસાર', 'સ્ટોક મૂલ્યાંકન', 'white', null, 'pill-mint', 'calculator') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('સ્ટોક લોટ્સ / પેકેટ્સ', (string)count($stocks) . ' પેકેટ્સ', 'Single, Parcel & GIA', 'હાજર પેકેટો', 'white', null, 'pill-mint', 'boxes-stacked') ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-erp p-2 px-3 mb-3">
    <form method="GET" action="<?= BASE_URL ?>/modules/polish_stock/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-4">
            <input type="text" name="search" class="form-control" placeholder="પેકેટ નં, GIA નં, શેપ શોધો..." value="<?= e($search) ?>">
        </div>
        <div class="col-6 col-md-3">
            <select name="type" class="form-select">
                <option value="">બધા સ્ટોક પ્રકાર (All Types)</option>
                <option value="single" <?= $typeFilter === 'single' ? 'selected' : '' ?>>૧. Single Packet</option>
                <option value="parcel" <?= $typeFilter === 'parcel' ? 'selected' : '' ?>>૨. Parcel (જથ્થો)</option>
                <option value="gia_single" <?= $typeFilter === 'gia_single' ? 'selected' : '' ?>>૩. GIA Single Packet</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select name="location" class="form-select">
                <option value="">બધી જગ્યાઓ (All Locations)</option>
                <option value="Surat" <?= $locFilter === 'Surat' ? 'selected' : '' ?>>Surat</option>
                <option value="Mumbai" <?= $locFilter === 'Mumbai' ? 'selected' : '' ?>>Mumbai</option>
                <option value="Jangad" <?= $locFilter === 'Jangad' ? 'selected' : '' ?>>Jangad પર</option>
                <option value="GIA" <?= $locFilter === 'GIA' ? 'selected' : '' ?>>GIA Lab માં</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($search) || !empty($typeFilter) || !empty($locFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/polish_stock/index.php" class="btn btn-outline-dark">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Stock Table -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>પેકેટ / GIA</th>
                        <th>પ્રકાર</th>
                        <th>શેપ / સ્પેસિફિકેશન</th>
                        <th>હાજર Pcs / Ct</th>
                        <th>પડતર / Ct</th>
                        <th>વેચાણ ભાવ / Ct</th>
                        <th>જગ્યા</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stocks)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ Polish સ્ટોક મળ્યો નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stocks as $stk): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= e($stk['packet_no']) ?></div>
                                    <?php if (!empty($stk['gia_number'])): ?>
                                        <span class="badge bg-warning-subtle text-dark border" style="font-size: 0.80rem;"><?= svg_icon('certificate', 'me-1 text-warning') ?><?= e($stk['gia_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                     $tMap = [
                                         'single' => ['Single Packet', 'badge-soft-primary'],
                                         'parcel' => ['Parcel', 'badge-soft-secondary'],
                                         'gia_single' => ['GIA Single', 'badge-soft-warning']
                                     ];
                                     $badge = $tMap[$stk['stock_type']] ?? [e($stk['stock_type']), 'badge-soft-secondary'];
                                    ?>
                                    <span class="badge <?= $badge[1] ?>"><?= $badge[0] ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark text-truncate" style="max-width: 150px; font-size: 0.875rem;"><?= e($stk['shape'] ?? 'Round') ?> • <?= e($stk['color'] ?? '-') ?> • <?= e($stk['clarity'] ?? '-') ?></div>
                                    <small class="text-muted text-truncate d-block" style="max-width: 150px; font-size: 0.8125rem;"><?= e($stk['size_description'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <strong class="font-mono text-primary" style="font-size: 0.925rem;"><?= format_carat($stk['available_weight']) ?></strong>
                                    <div class="small text-muted" style="font-size: 0.8125rem;"><?= (int)$stk['available_pcs'] ?> Pcs</div>
                                </td>
                                <td class="font-mono"><?= format_inr($stk['cost_per_carat']) ?></td>
                                <td><strong class="font-mono text-dark" style="font-size: 0.925rem;"><?= ((float)$stk['sale_price_per_carat'] > 0) ? format_inr($stk['sale_price_per_carat']) : '-' ?></strong></td>
                                <td>
                                    <span class="badge badge-soft-secondary">
                                        <?= svg_icon('location-dot', 'text-danger me-1') ?><?= e($stk['current_location']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($stk['status'] === 'partially_on_jangad'): ?>
                                        <span class="badge badge-soft-warning">Partial Jangad</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-success">Available</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end table-actions">
                                    <div class="d-flex align-items-center justify-content-end gap-1">
                                        <!-- Section 6.6: Partial Jangad Button -->
                                        <a href="<?= BASE_URL ?>/modules/jangad/create.php?stock_id=<?= $stk['id'] ?>" class="btn btn-sm btn-outline-dark py-1 px-2" title="Sales Jangad (મેમો આપો)">
                                            <?= svg_icon('file-invoice', 'me-1') ?><span class="d-none d-xxl-inline">Jangad</span>
                                        </a>
                                        <!-- Polish Sell Button -->
                                        <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?stock_id=<?= $stk['id'] ?>" class="btn btn-sm btn-primary py-1 px-2" title="વેચાણ કરો">
                                            <?= svg_icon('hand-holding-dollar', 'me-1') ?><span>વેચાણ</span>
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
