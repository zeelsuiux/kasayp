<?php
/**
 * Jangad Vouchers Register (આઉટસોર્સ અને મેમો જાંગડ)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Jangad રજિસ્ટર (જાંગડ / મેમો હિસાબ)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_jangad_sales_columns();

$statusFilter = trim($_GET['status'] ?? '');
$catFilter = trim($_GET['category'] ?? '');

$sql = "SELECT jv.*, p.party_name, p.city, b.party_name as broker_name
        FROM jangad_vouchers jv
        JOIN parties p ON jv.party_id = p.id
        LEFT JOIN parties b ON jv.broker_id = b.id
        WHERE 1=1";
$params = [];

if (!empty($statusFilter)) {
    $sql .= " AND jv.status = ?";
    $params[] = $statusFilter;
}

if (!empty($catFilter)) {
    $sql .= " AND jv.category = ?";
    $params[] = $catFilter;
}

$sql .= " ORDER BY jv.issue_date DESC, jv.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allVouchers = $stmt->fetchAll();

$activeJangadCount = count($allVouchers);
$outstandingCount = (int)$pdo->query("SELECT COUNT(DISTINCT party_id) FROM jangad_vouchers WHERE status IN ('issued', 'partially_returned')")->fetchColumn();

$totIssueWt = 0.000;
$totSoldWt = 0.000;
$totSoldPcs = 0;
$totReturnWt = 0.000;
$totReturnPcs = 0;
$totPendingWt = 0.000;
$totTopsWt = 0.000;
$totPendingPcs = 0;

foreach ($allVouchers as $jv) {
    $soldWt = (float)($jv['sold_weight'] ?? 0);
    $soldPcs = (int)($jv['sold_pcs'] ?? 0);
    $retWt = (float)$jv['returned_weight'];
    $retPcs = (int)$jv['returned_pcs'];

    $totIssueWt += (float)$jv['total_weight'];
    $totSoldWt += $soldWt;
    $totSoldPcs += $soldPcs;
    $totReturnWt += $retWt;
    $totReturnPcs += $retPcs;
    $jvPend = max(0.0, (float)$jv['total_weight'] - $soldWt - $retWt - (float)($jv['total_tops_weight'] ?? 0));
    $totPendingWt += $jvPend;
    $totPendingPcs += max(0, (int)$jv['total_pcs'] - $soldPcs - $retPcs);
    $totTopsWt += (float)($jv['total_tops_weight'] ?? 0.000);
}

$pagData = get_pagination_data(count($allVouchers), 25);
$vouchers = array_slice($allVouchers, $pagData['offset'], $pagData['per_page']);
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Sales Jangad (પોલિશ હીરા વેચાણ / મેમો)',
    'માત્ર પોલિશ તૈયાર થયા બાદ વેચાણ જોવા માટે, બ્રોકર મેમો અને GIA લેબ જાંગડ (કારખાના પ્રોસેસ Job Work માં થાય છે)',
    'file-invoice',
    '<a href="' . BASE_URL . '/modules/jangad/create.php" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવો Sales Jangad ઇસ્યુ કરો</a>'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-3">
        <?= render_stat_card('જાંગડ પર બાકી માલ (Pending)', format_carat($totPendingWt), $totPendingPcs . ' Pcs બાકી', 'પાર્ટી/દલાલ પાસે બાકી', 'hero', BASE_URL . '/modules/jangad/create.php', '', 'hourglass-half') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ ઇસ્યુ વજન (Issued)', format_carat($totIssueWt), count($vouchers) . ' વાઉચર્સ', 'કુલ ઇસ્યુ કરેલ પોલિશ', 'white', null, 'pill-mint', 'file-invoice') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('વેચાણ vs કાપન પરત', format_carat($totSoldWt), svg_icon('cart-shopping') . ' વેચાણ (' . $totSoldPcs . ' Pcs) • પરત: ' . format_carat($totReturnWt), 'વેચાણ અને કાપન સ્ટોક પરત', 'white', BASE_URL . '/modules/jangad/return.php', 'pill-mint') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('સક્રિય જાંગડ પાર્ટીઓ', (string)$outstandingCount . ' પાર્ટી', 'બાકી માલ ધરાવનાર', 'પાર્ટી આઉટસ્ટેન્ડિંગ', 'white', BASE_URL . '/modules/jangad/outstanding.php', 'pill-coral', 'users') ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="erp-filter-card">
    <form method="GET" action="<?= BASE_URL ?>/modules/jangad/index.php" class="row g-2 align-items-center">
        <div class="col-12 col-md-4">
            <select name="category" class="form-select">
                <option value="">બધી કેટેગરી (All Categories)</option>
                <option value="memo_party" <?= $catFilter === 'memo_party' ? 'selected' : '' ?>>Party Memo (પાર્ટી સેલિંગ જાંગડ)</option>
                <option value="memo_broker" <?= $catFilter === 'memo_broker' ? 'selected' : '' ?>>Broker Memo (દલાલ સેલિંગ જાંગડ)</option>
                <option value="lab_gia" <?= $catFilter === 'lab_gia' ? 'selected' : '' ?>>GIA / IGI Lab (લેબ સર્ટિફિકેશન)</option>
                <optgroup label="જૂના પ્રોસેસ રેકોર્ડ્સ (Legacy)">
                    <option value="outsource_galaxy" <?= $catFilter === 'outsource_galaxy' ? 'selected' : '' ?>>Galaxy Scanning (જૂનું)</option>
                    <option value="outsource_ls" <?= $catFilter === 'outsource_ls' ? 'selected' : '' ?>>Laser Sawing - LS (જૂનું)</option>
                    <option value="outsource_factory" <?= $catFilter === 'outsource_factory' ? 'selected' : '' ?>>Polish Factory (જૂનું)</option>
                </optgroup>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <select name="status" class="form-select">
                <option value="">બધી સ્થિતિ (All Status)</option>
                <option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>બાકી માલ (Issued Pending)</option>
                <option value="partially_returned" <?= $statusFilter === 'partially_returned' ? 'selected' : '' ?>>અંશતઃ પરત (Partially Returned)</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>સંપૂર્ણ જમા (Completed)</option>
            </select>
        </div>
        <div class="col-12 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1"><?= svg_icon('filter', 'me-1') ?>ફિલ્ટર</button>
            <?php if (!empty($catFilter) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/jangad/index.php" class="btn btn-outline-dark">રીસેટ</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Jangad Vouchers Table -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>Jangad નં / તારીખ</th>
                        <th>કેટેગરી</th>
                        <th>પાર્ટી / વેન્ડર / દલાલ</th>
                        <th>ઇસ્યુ માલ</th>
                        <th>વેચાણ (Sold)</th>
                        <th>કાપન પરત (Ret)</th>
                        <th>બાકી માલ</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">કોઈ Jangad વાઉચર મળ્યું નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): ?>
                            <?php
                            $soldPcs = (int)($v['sold_pcs'] ?? 0);
                            $soldWt  = (float)($v['sold_weight'] ?? 0);
                            $retPcs  = (int)$v['returned_pcs'];
                            $retWt   = (float)$v['returned_weight'];
                            $topsWt  = (float)($v['total_tops_weight'] ?? 0.000);
                            $pendPcs = max(0, (int)$v['total_pcs'] - $soldPcs - $retPcs);
                            $pendWt  = max(0.0, round((float)$v['total_weight'] - $soldWt - $retWt - $topsWt, 3));
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= e($v['jangad_no']) ?></div>
                                    <small class="text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($v['issue_date']) ?></small>
                                </td>
                                <td>
                                    <?php
                                    $catMap = [
                                        'outsource_galaxy'  => ['Galaxy', 'bg-info-subtle text-info'],
                                        'outsource_ls'      => ['LS Sawing', 'bg-warning-subtle text-warning-emphasis'],
                                        'outsource_factory' => ['કારખાનું', 'bg-danger-subtle text-danger'],
                                        'memo_broker'       => ['Broker Sales Memo', 'bg-primary-subtle text-primary'],
                                        'memo_party'        => ['Party Sales Memo', 'bg-success-subtle text-success'],
                                        'lab_gia'           => ['GIA Lab', 'bg-secondary-subtle text-secondary']
                                    ];
                                    $catBadge = $catMap[$v['category']] ?? [e($v['category']), 'bg-light text-dark'];
                                    ?>
                                    <span class="badge <?= $catBadge[1] ?> border"><?= $catBadge[0] ?></span>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $v['party_id'] ?>&tab=jangad" class="fw-semibold text-primary text-decoration-none" title="પાર્ટી ખાતાવહી (Jangad History) જુઓ">
                                        <?= e($v['party_name']) ?>
                                    </a>
                                    <div class="small text-muted">
                                        <?= e($v['city']) ?>
                                        <?php if (!empty($v['broker_name']) && !empty($v['broker_id'])): ?>
                                            • દલાલ: <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $v['broker_id'] ?>&tab=brokerage" class="text-muted text-decoration-none" title="દલાલ ખાતાવહી જુઓ"><?= e($v['broker_name']) ?></a>
                                        <?php elseif (!empty($v['broker_name'])): ?>
                                            • દલાલ: <?= e($v['broker_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark font-mono"><?= (int)$v['total_pcs'] ?> Pcs</div>
                                    <small class="text-primary font-mono fw-semibold"><?= format_carat($v['total_weight']) ?></small>
                                </td>
                                <td>
                                    <?php if ($soldPcs > 0 || $soldWt > 0): ?>
                                        <div class="fw-bold text-primary font-mono"><?= $soldPcs ?> Pcs Sold</div>
                                        <small class="text-muted font-mono"><?= format_carat($soldWt) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted font-mono small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($retPcs > 0 || $retWt > 0): ?>
                                        <div class="fw-bold text-success font-mono"><?= $retPcs ?> Pcs કાપન જમા</div>
                                        <small class="text-muted font-mono"><?= format_carat($retWt) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted font-mono small">—</span>
                                    <?php endif; ?>
                                    <?php if ($topsWt > 0): ?>
                                        <div><small class="badge bg-light text-secondary border font-mono">Tops: <?= format_carat($topsWt) ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pendWt > 0.0005): ?>
                                        <strong class="text-danger font-mono"><?= $pendPcs ?> Pcs બાકી</strong>
                                        <div class="small text-danger font-mono fw-semibold"><?= format_carat($pendWt) ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success">પૂર્ણ હિસાબ (0.00 ct)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($v['status'] === 'completed'): ?>
                                        <span class="badge bg-success-subtle text-success border">જમા પૂર્ણ</span>
                                    <?php elseif ($v['status'] === 'partially_returned'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ પરત</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">બાકી (Issued)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-secondary" title="પ્રિન્ટ વાઉચર">
                                            <?= svg_icon('print') ?>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/jangad/edit.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary" title="વાઉચર એડિટ કરો">
                                            <?= svg_icon('pen-to-square') ?>
                                        </a>
                                        <?php if ($v['status'] !== 'completed'): ?>
                                            <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-success" title="માલ જમા / Splitting">
                                                <?= svg_icon('rotate-left', 'me-1') ?>જમા
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_table_pagination($pagData, BASE_URL . '/modules/jangad/index.php', (!empty($search) ? ['search' => $search] : []) + (!empty($statusFilter) ? ['status' => $statusFilter] : []) + (!empty($catFilter) ? ['category' => $catFilter] : [])) ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
