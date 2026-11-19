<?php
/**
 * Tops (Scrap / રદિયો) Inventory (સેક્શન ૬.૫)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Tops (Scrap / રદિયો) રજિસ્ટર';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();

// Handle Tops Liquidation / Sale
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sell_tops') {
    validate_csrf();
    $topId = (int)($_POST['top_id'] ?? 0);
    $saleAmount = (float)($_POST['sale_amount'] ?? 0.00);
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;

    $pdo->prepare("UPDATE tops_records SET status = 'sold' WHERE id = ?")->execute([$topId]);
    if ($bankAccId && $saleAmount > 0) {
        record_account_transaction($bankAccId, 'credit', $saleAmount, 'other', $topId, 'TOPS-SALE-' . $topId, date('Y-m-d'), 'Tops scrap liquidation sale', $user['id']);
    }
    set_flash('success', 'Tops રદિયો સફળતાપૂર્વક વેચાઈ ગયો છે અને રકમ જમા થઈ ગઈ છે.');
    header('Location: ' . BASE_URL . '/modules/tops/index.php');
    exit;
}

$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

$stmt = $pdo->query("SELECT t.*, k.kapan_code, k.kapan_name, p.packet_no
                     FROM tops_records t
                     LEFT JOIN kapans k ON t.kapan_id = k.id
                     LEFT JOIN packets p ON t.packet_id = p.id
                     ORDER BY t.record_date DESC, t.id DESC");
$tops = $stmt->fetchAll();

$totWeight = 0.000;
$totValue = 0.00;
foreach ($tops as $t) {
    if ($t['status'] === 'in_stock') {
        $totWeight += (float)$t['weight'];
        $totValue += (float)$t['total_value'];
    }
}

// Counts for tabs
$topsCount = count($tops);
$polishStockCount = (int)$pdo->query("SELECT COUNT(*) FROM polish_stocks WHERE status = 'available'")->fetchColumn();
$roughStockCount = (int)$pdo->query("SELECT COUNT(*) FROM rough_purchases WHERE status = 'in_stock'")->fetchColumn();
?>

<!-- Master Inventory Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/polish_stock/index.php', 'label' => 'Polish Stock (હાજર સ્ટોક)', 'icon' => 'gem', 'count' => $polishStockCount],
    ['url' => BASE_URL . '/modules/polish_stock/gia.php', 'label' => 'GIA સર્ટિફિકેશન (Lab Tracker)', 'icon' => 'award'],
    ['url' => BASE_URL . '/modules/rough_purchase/index.php?status=in_stock', 'label' => 'Rough In-Stock (રફ હાજર)', 'icon' => 'shapes', 'count' => $roughStockCount],
    ['url' => BASE_URL . '/modules/tops/index.php', 'label' => 'Tops (Scrap રદિયો)', 'icon' => 'recycle', 'count' => $topsCount, 'active' => true],
    ['url' => BASE_URL . '/modules/admin/upload_stock.php', 'label' => 'Excel અપલોડ', 'icon' => 'file-arrow-up'],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Tops (Scrap / રદિયો) સ્ટોક રજિસ્ટર',
    '',
    'recycle',
    '<a href="' . BASE_URL . '/modules/tops/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવો Tops રેકોર્ડ ઉમેરો (New Tops)</a>',
    'gold'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('હાજર Tops વજન (Carat)', format_carat($totWeight), 'LS Sawing રદિયો', 'હાજર Scrap સ્ટોક', 'hero', BASE_URL . '/modules/tops/create.php', '', 'recycle') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ Tops મૂલ્ય (Total Value)', format_inr($totValue), 'અંદાજિત કિંમત', 'સરેરાશ મૂલ્યાંકન', 'white', null, 'pill-mint', 'coins') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ Tops લોટ્સ (Total Records)', (string)count($tops) . ' લોટ્સ', 'ઇન-સ્ટોક & સોલ્ડ', 'Tops રજિસ્ટર', 'white', null, 'pill-mint', 'boxes-stacked') ?>
    </div>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>તારીખ</th>
                        <th>Kapan / પેકેટ સંદર્ભ</th>
                        <th>સ્ત્રોત (Source)</th>
                        <th>નંગ (Pcs)</th>
                        <th>વજન (Weight Carats)</th>
                        <th>ભાવ / Ct (Rate)</th>
                        <th>કુલ વેલ્યુ (Value INR)</th>
                        <th>સ્થિતિ</th>
                        <th>નોંધ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tops)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">કોઈ Tops રેકોર્ડ નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tops as $t): ?>
                            <tr>
                                <td><?= format_date($t['record_date']) ?></td>
                                <td>
                                    <?php if (!empty($t['diamond_no'])): ?>
                                        <strong class="font-mono text-dark d-block fs-6"><?= e($t['diamond_no']) ?></strong>
                                    <?php elseif (!empty($t['kapan_name'])): ?>
                                        <div class="fw-semibold text-dark"><?= e($t['kapan_name']) ?></div>
                                    <?php endif; ?>
                                    <small class="text-muted d-block font-mono">
                                        <?= e(!empty($t['assort_number']) ? 'Assort: ' . $t['assort_number'] : ($t['packet_no'] ?? '-')) ?>
                                    </small>
                                </td>
                                <td><span class="badge bg-light text-dark border text-uppercase"><?= e($t['source_stage']) ?></span></td>
                                <td><?= (int)$t['pcs'] ?></td>
                                <td><strong class="text-primary"><?= format_carat($t['weight']) ?></strong></td>
                                <td><?= format_inr($t['rate_per_carat']) ?></td>
                                <td><strong class="text-success"><?= format_inr($t['total_value']) ?></strong></td>
                                <td>
                                    <?php if ($t['status'] === 'in_stock'): ?>
                                        <span class="badge bg-success-subtle text-success border">In Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border">Sold</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= e($t['notes'] ?? '-') ?></small></td>
                                <td class="text-end">
                                    <?php if ($t['status'] === 'in_stock'): ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#sellTopModal<?= $t['id'] ?>" title="વેચાણ કરો">
                                            <?= svg_icon('hand-holding-dollar', 'me-1') ?>વેચો
                                        </button>

                                        <!-- Modal: Sell Tops -->
                                        <div class="modal fade" id="sellTopModal<?= $t['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content text-start">
                                                    <form action="<?= BASE_URL ?>/modules/tops/index.php" method="POST">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="sell_tops">
                                                        <input type="hidden" name="top_id" value="<?= $t['id'] ?>">

                                                        <div class="modal-header">
                                                            <h6 class="modal-title fw-bold">Tops રદિયો વેચાણ: <?= format_carat($t['weight']) ?></h6>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="p-2 bg-light rounded mb-3">
                                                                <div>વજન: <strong><?= format_carat($t['weight']) ?></strong> (<?= (int)$t['pcs'] ?> Pcs)</div>
                                                                <div>અંદાજિત વેલ્યુ: <strong class="text-success"><?= format_inr($t['total_value']) ?></strong></div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted">વેચાણ રકમ (Sale Amount INR) *</label>
                                                                <input type="number" step="0.01" name="sale_amount" class="form-control fw-bold text-success" value="<?= (float)$t['total_value'] ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted">જમા ખાતું (Bank / Cash Account)</label>
                                                                <select name="bank_account_id" class="form-select">
                                                                    <option value="">રોકડા મળ્યા / ખાતામાં જમા નથી કરવું</option>
                                                                    <?php foreach ($bankAccounts as $acc): ?>
                                                                        <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                            <button type="submit" class="btn btn-success btn-sm fw-bold">વેચાણ કન્ફર્મ કરો</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small"><?= svg_icon('check', 'text-success me-1') ?>વેચાઈ ગયું</span>
                                    <?php endif; ?>
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
