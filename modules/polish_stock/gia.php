<?php
/**
 * GIA Diamond Certification & Lab Inventory Tracker (સેક્શન ૬.૬ & ૬.૮)
 * Diamond ERP - Kasyap Gems
 * Tracks: 
 * 1. At GIA Lab (ત્યાં કેટલો માલ છે - Pending Certification)
 * 2. On Hand (હાજર GIA સર્ટિફાઇડ સ્ટોક - In Vault)
 */

declare(strict_types=1);

$pageTitle = 'GIA સર્ટિફિકેશન અને સ્ટોક ટ્રેકર (GIA Lab Tracker)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$user = current_user();

// 1. Action: Send Diamonds to GIA Lab
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_gia') {
    validate_csrf();

    $stockId = (int)($_POST['stock_id'] ?? 0);
    $labPartyId = (int)($_POST['lab_party_id'] ?? 0);
    $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
    $expectedDate = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : date('Y-m-d', strtotime('+15 days'));
    $serviceType = trim($_POST['service_type'] ?? 'Full Diamond Dossier');
    $docketNo = trim($_POST['docket_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $stockStmt = $pdo->prepare("SELECT * FROM polish_stocks WHERE id = ? AND status = 'available'");
    $stockStmt->execute([$stockId]);
    $stk = $stockStmt->fetch();

    if (!$stk || $labPartyId <= 0) {
        set_flash('error', 'અમાન્ય સ્ટોક પેકેટ અથવા GIA લેબ વેન્ડર પસંદ કરેલ નથી.');
    } else {
        try {
            $pdo->beginTransaction();

            $jangadNo = generate_next_code('GIA-JNG', 'jangad_vouchers', 'jangad_no');
            $pcs = (int)$stk['available_pcs'];
            $wt = (float)$stk['available_weight'];

            // 1. Create GIA Jangad Voucher
            $vStmt = $pdo->prepare("
                INSERT INTO jangad_vouchers (jangad_no, voucher_type, category, party_id, issue_date, expected_return_date, total_pcs, total_weight, status, remarks, created_by)
                VALUES (?, 'issue', 'lab_gia', ?, ?, ?, ?, ?, 'issued', ?, ?)
            ");
            $remarksFull = "GIA Service: $serviceType | Docket: $docketNo | " . $notes;
            $vStmt->execute([$jangadNo, $labPartyId, $issueDate, $expectedDate, $pcs, $wt, $remarksFull, $user['id']]);
            $jangadId = (int)$pdo->lastInsertId();

            // 2. Create Jangad Item
            $iStmt = $pdo->prepare("
                INSERT INTO jangad_items (jangad_id, item_type, polish_stock_id, description, issued_pcs, issued_weight, status)
                VALUES (?, 'polish_single', ?, ?, ?, ?, 'issued')
            ");
            $iStmt->execute([$jangadId, $stk['id'], "Stone for GIA: " . $stk['packet_no'] . " ($serviceType)", $pcs, $wt]);

            // 3. Update Polish Stock status and location
            $uStmt = $pdo->prepare("
                UPDATE polish_stocks 
                SET current_location = 'GIA India Lab', status = 'on_jangad' 
                WHERE id = ?
            ");
            $uStmt->execute([$stk['id']]);

            $pdo->commit();
            log_activity('Sent to GIA Lab', 'Stock', $stk['id'], "Packet {$stk['packet_no']} sent to GIA Lab under voucher $jangadNo");
            set_flash('success', "હીરો {$stk['packet_no']} ($wt ct) GIA લેબમાં સફળતાપૂર્વક મોકલાઈ ગયો છે. (વાઉચર: $jangadNo)");
            header('Location: ' . BASE_URL . '/modules/polish_stock/gia.php');
            exit;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            set_flash('error', 'GIA મોકલવામાં ક્ષતિ: ' . $e->getMessage());
        }
    }
}

// 2. Action: Receive Diamond from GIA with Report Number & Final Grades
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_from_gia') {
    validate_csrf();

    $jangadId = (int)($_POST['jangad_id'] ?? 0);
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $giaReportNo = trim($_POST['gia_number'] ?? '');
    $finalColor = trim($_POST['color'] ?? 'G');
    $finalClarity = trim($_POST['clarity'] ?? 'VS1');
    $finalCut = trim($_POST['cut'] ?? 'EX');
    $finalShape = trim($_POST['shape'] ?? 'Round');
    $certFee = (float)($_POST['cert_fee'] ?? 0.00);
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $vaultLocation = trim($_POST['vault_location'] ?? 'Surat Vault');

    if ($stockId <= 0 || empty($giaReportNo)) {
        set_flash('error', 'કૃપા કરીને સાચો GIA સર્ટિફિકેટ નંબર દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $stkStmt = $pdo->prepare("SELECT * FROM polish_stocks WHERE id = ?");
            $stkStmt->execute([$stockId]);
            $stk = $stkStmt->fetch();

            if ($stk) {
                // Update Polish Stock to GIA Certified Single
                $wt = (float)$stk['available_weight'];
                $addedCostPerCt = ($wt > 0 && $certFee > 0) ? round($certFee / $wt, 2) : 0.00;

                $updStk = $pdo->prepare("
                    UPDATE polish_stocks SET
                        stock_type = 'gia_single',
                        gia_number = ?,
                        shape = ?,
                        color = ?,
                        clarity = ?,
                        cut = ?,
                        cost_per_carat = cost_per_carat + ?,
                        current_location = ?,
                        status = 'available'
                    WHERE id = ?
                ");
                $updStk->execute([$giaReportNo, $finalShape, $finalColor, $finalClarity, $finalCut, $addedCostPerCt, $vaultLocation, $stockId]);

                // Update Jangad voucher & item to returned
                if ($jangadId > 0) {
                    $pdo->prepare("UPDATE jangad_vouchers SET status = 'completed', actual_return_date = CURDATE() WHERE id = ?")->execute([$jangadId]);
                    $pdo->prepare("UPDATE jangad_items SET status = 'returned', returned_pcs = issued_pcs, returned_weight = issued_weight WHERE jangad_id = ? AND polish_stock_id = ?")->execute([$jangadId, $stockId]);
                }

                // If GIA Lab fee paid from bank account, record debit
                if ($certFee > 0 && $bankAccId) {
                    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$certFee, $bankAccId]);
                    $pdo->prepare("
                        INSERT INTO account_transactions (bank_account_id, type, amount, related_type, reference_no, transaction_date, notes, created_by)
                        VALUES (?, 'debit', ?, 'outsource', ?, CURDATE(), ?, ?)
                    ")->execute([$bankAccId, $certFee, "GIA-FEE-" . $giaReportNo, "GIA Lab Certification Fee for Report " . $giaReportNo, $user['id']]);
                }

                $pdo->commit();
                log_activity('Received from GIA', 'Stock', $stockId, "GIA Report $giaReportNo received for packet {$stk['packet_no']}");
                set_flash('success', "GIA સર્ટિફિકેટ #$giaReportNo સફળતાપૂર્વક અપડેટ થઈ ગયો છે. હીરો હવે On-Hand સ્ટોકમાં ઉપલબ્ધ છે!");
                header('Location: ' . BASE_URL . '/modules/polish_stock/gia.php?view=on_hand');
                exit;
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            set_flash('error', 'GIA રીટર્ન એન્ટ્રીમાં ક્ષતિ: ' . $e->getMessage());
        }
    }
}

// Data View Filter (at_gia vs on_hand)
$activeTab = $_GET['view'] ?? 'at_gia';

// 1. Fetch Diamonds AT GIA LAB (ત્યાં કેટલો છે)
$atGiaStmt = $pdo->query("
    SELECT ji.*, j.id as voucher_id, j.jangad_no, j.issue_date, j.expected_return_date, j.remarks,
           p.party_name as lab_name,
           ps.id as stock_id, ps.packet_no, ps.shape, ps.color as est_color, ps.clarity as est_clarity,
           DATEDIFF(CURDATE(), j.issue_date) as days_at_lab
    FROM jangad_items ji
    JOIN jangad_vouchers j ON ji.jangad_id = j.id
    JOIN parties p ON j.party_id = p.id
    LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
    WHERE j.category = 'lab_gia' AND j.status IN ('issued', 'partially_returned')
    ORDER BY j.issue_date DESC
");
$atGiaDiamonds = $atGiaStmt->fetchAll();

$atGiaPcs = array_sum(array_column($atGiaDiamonds, 'issued_pcs'));
$atGiaWeight = array_sum(array_column($atGiaDiamonds, 'issued_weight'));

// 2. Fetch Diamonds ON HAND (હાજર GIA સર્ટિફાઇડ સ્ટોક)
$onHandStmt = $pdo->query("
    SELECT ps.*, k.kapan_code
    FROM polish_stocks ps
    LEFT JOIN kapans k ON ps.kapan_id = k.id
    WHERE ps.stock_type = 'gia_single' AND ps.status = 'available'
    ORDER BY ps.id DESC
");
$onHandDiamonds = $onHandStmt->fetchAll();

$onHandPcs = count($onHandDiamonds);
$onHandWeight = array_sum(array_column($onHandDiamonds, 'available_weight'));
$onHandValue = array_sum(array_map(fn($d) => (float)$d['available_weight'] * (float)$d['cost_per_carat'], $onHandDiamonds));

// Available uncertified single stones ready to send to GIA
$availableForGia = $pdo->query("
    SELECT id, packet_no, shape, color, clarity, available_weight, cost_per_carat
    FROM polish_stocks
    WHERE status = 'available' AND (gia_number IS NULL OR gia_number = '') AND stock_type IN ('single', 'parcel')
    ORDER BY available_weight DESC
")->fetchAll();

$giaLabs = $pdo->query("SELECT id, party_name, city FROM parties WHERE party_type = 'lab_gia' ORDER BY party_name ASC")->fetchAll();
$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();
?>

<!-- Master Inventory Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/polish_stock/index.php', 'label' => 'Polish Stock (હાજર)', 'icon' => 'shapes'],
    ['url' => BASE_URL . '/modules/polish_stock/gia.php', 'label' => 'GIA લેબ સર્ટિફિકેશન', 'icon' => 'award', 'count' => count($atGiaDiamonds), 'active' => true],
    ['url' => BASE_URL . '/modules/rough_purchase/index.php?status=in_stock', 'label' => 'Rough In-Stock', 'icon' => 'gem'],
    ['url' => BASE_URL . '/modules/tops/index.php', 'label' => 'Tops (Scrap રદિયો)', 'icon' => 'recycle'],
    ['url' => BASE_URL . '/modules/admin/upload_stock.php', 'label' => 'Excel અપલોડ', 'icon' => 'file-arrow-up'],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'GIA સ્ટોક સર્ટિફિકેશન અને ટ્રેકર (GIA Lab Tracker)',
    'Gemological Institute of America (BKC Mumbai / Surat) લેબમાં મોકલેલ અને હાજર પ્રમાણિત સ્ટોક',
    'award',
    '<button class="btn btn-primary px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#sendToGiaModal">' . svg_icon('paper-plane', 'me-1') . 'હીરો GIA લેબમાં મોકલો (Send to GIA)</button>',
    'gold'
) ?>

<!-- GIA Sub-Workspaces Toggle (At GIA vs On Hand) -->
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link fw-bold px-4 py-2 rounded-pill <?= $activeTab === 'at_gia' ? 'active shadow-sm' : 'bg-white border text-dark' ?>" 
           href="<?= BASE_URL ?>/modules/polish_stock/gia.php?view=at_gia">
            <?= svg_icon('hourglass-half', 'me-2 text-warning') ?>
            ૧. ત્યાં કેટલો છે? (At GIA Lab - <?= count($atGiaDiamonds) ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-bold px-4 py-2 rounded-pill <?= $activeTab === 'on_hand' ? 'active shadow-sm' : 'bg-white border text-dark' ?>" 
           href="<?= BASE_URL ?>/modules/polish_stock/gia.php?view=on_hand">
            <?= svg_icon('vault', 'me-2 text-success') ?>
            ૨. On Hand સર્ટિફાઇડ સ્ટોક (In Vault - <?= $onHandPcs ?>)
        </a>
    </li>
</ul>

<?php if ($activeTab === 'at_gia'): ?>
    <!-- ==========================================
         VIEW 1: AT GIA LAB (ત્યાં કેટલો માલ છે)
         ========================================== -->
    <!-- Donezo Metric Stat Cards (At GIA Lab) -->
    <div class="row g-2.5 mb-3">
        <div class="col-12 col-md-4">
            <?= render_stat_card('GIA લેબમાં વજન (Carats at Lab)', format_carat($atGiaWeight), $atGiaPcs . ' Pcs લેબમાં', 'GIA પ્રમાણીકરણ', 'hero', null, '', 'hourglass-half') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('પેન્ડિંગ વાઉચર્સ (Pending Vouchers)', (string)count($atGiaDiamonds) . ' વાઉચર્સ', 'લેબ સર્ટિફિકેશન', 'સરેરાશ ' . ($atGiaPcs > 0 ? number_format($atGiaWeight / $atGiaPcs, 3) : '0.000') . ' ct', 'white', null, 'pill-coral', 'award') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('લેબ લોકેશન (Laboratory)', 'GIA India', 'BKC Mumbai / Surat', 'પરત આવ્યા પછી Vault માં જશે', 'white', null, 'pill-mint', 'building-columns') ?>
        </div>
    </div>

    <!-- Table: Diamonds at GIA Lab -->
    <div class="card-erp">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('list-check', 'text-warning me-2') ?>GIA લેબમાં સર્ટિફિકેશન હેઠળ હીરા (Pending at GIA)</h6>
            
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th>પેકેટ નં / વાઉચર</th>
                            <th>મોકલ્યા તારીખ</th>
                            <th>GIA લેબ</th>
                            <th>આકાર (Shape)</th>
                            <th class="text-end">વજન (Carats)</th>
                            <th>અંદાજિત ગ્રેડ</th>
                            <th>દિવસો થયા</th>
                            <th>અપેક્ષિત પરત</th>
                            <th class="text-center">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($atGiaDiamonds)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <?= svg_icon('circle-check', 'text-success mb-3 d-block') ?>
                                    હાલ કોઈ હીરો GIA લેબમાં પેન્ડિંગ નથી. બધા પ્રમાણિત હીરા On-Hand છે.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($atGiaDiamonds as $d): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark font-monospace"><?= e($d['packet_no'] ?: 'PKT-' . $d['id']) ?></strong>
                                        <div class="small text-muted font-monospace"><?= e($d['jangad_no']) ?></div>
                                    </td>
                                    <td><?= e($d['issue_date']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= e($d['lab_name']) ?></span>
                                    </td>
                                    <td><strong><?= e($d['shape'] ?: 'Round') ?></strong></td>
                                    <td class="text-end font-monospace fw-bold text-primary">
                                        <?= number_format((float)$d['issued_weight'], 3) ?> ct
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary"><?= e($d['est_color'] ?: 'Color') ?> / <?= e($d['est_clarity'] ?: 'Clarity') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= (int)$d['days_at_lab'] > 20 ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info' ?> font-monospace">
                                            <?= (int)$d['days_at_lab'] ?> દિવસ
                                        </span>
                                    </td>
                                    <td><?= e($d['expected_return_date']) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-success py-1 px-3 shadow-xs" 
                                                onclick="openReceiveModal(<?= (int)$d['voucher_id'] ?>, <?= (int)$d['stock_id'] ?>, '<?= e($d['packet_no']) ?>', '<?= (float)$d['issued_weight'] ?>', '<?= e($d['shape'] ?: 'Round') ?>')">
                                            <?= svg_icon('file-circle-check', 'me-1') ?>પરત લો (Receive)
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ==========================================
         VIEW 2: ON HAND CERTIFIED STOCK (હાજર સ્ટોક)
         ========================================== -->
    <!-- Donezo Metric Stat Cards (On Hand Certified Stock) -->
    <div class="row g-2.5 mb-3">
        <div class="col-12 col-md-4">
            <?= render_stat_card('On Hand સર્ટિફાઇડ (Certified)', (string)$onHandPcs . ' Pcs', 'હાજર સિંગલ સ્ટોક', 'Vault સિક્યોર્ડ', 'hero', null, '', 'gem') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('કુલ પ્રમાણિત વજન (Total Carats)', format_carat($onHandWeight), svg_icon('scale-balanced') . ' સરેરાશ ' . ($onHandPcs > 0 ? number_format($onHandWeight / $onHandPcs, 3) : '0.000') . ' ct', 'કુલ સર્ટિ સ્ટોક', 'white', null, 'pill-mint') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('પ્રમાણિત સ્ટોક કિંમત (Cost Value)', format_inr($onHandValue), 'Surat & Mumbai', 'સરેરાશ ' . ($onHandWeight > 0 ? format_inr($onHandValue / $onHandWeight) : '₹0') . '/ct', 'white', null, 'pill-mint', 'vault') ?>
        </div>
    </div>

    <!-- Table: On Hand Certified Diamonds -->
    <div class="card-erp">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('vault', 'text-success me-2') ?>હાજર GIA સર્ટિફાઇડ સ્ટોક (In Vault)</h6>
            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php" class="btn btn-sm btn-outline-success">
                <?= svg_icon('handshake', 'me-1') ?>વેચાણ એન્ટ્રી કરો (Sell Certified)
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0">
                    <thead>
                        <tr>
                            <th>પેકેટ નં</th>
                            <th>GIA સર્ટિફિકેટ નંબર</th>
                            <th>આકાર (Shape)</th>
                            <th class="text-end">વજન (Cts)</th>
                            <th>Color</th>
                            <th>Clarity</th>
                            <th>Cut</th>
                            <th>લોકેશન</th>
                            <th class="text-end">પડતર / Ct</th>
                            <th class="text-end">વેચાણ ભાવ / Ct</th>
                            <th class="text-center">ચકાસણી (Verify)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($onHandDiamonds)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    હાજર સ્ટોકમાં કોઈ GIA સર્ટિફાઇડ હીરો નથી.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($onHandDiamonds as $s): 
                                $cleanGiaNo = preg_replace('/[^0-9]/', '', (string)$s['gia_number']);
                            ?>
                                <tr>
                                    <td>
                                        <strong class="font-monospace text-dark"><?= e($s['packet_no']) ?></strong>
                                        <?php if (!empty($s['kapan_code'])): ?>
                                            <small class="text-muted d-block"><?= e($s['kapan_code']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary font-monospace fs-7 fw-bold border">
                                            <?= e($s['gia_number']) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= e($s['shape']) ?></strong></td>
                                    <td class="text-end font-monospace fw-bold"><?= number_format((float)$s['available_weight'], 3) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($s['color']) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($s['clarity']) ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success border"><?= e($s['cut']) ?></span></td>
                                    <td><?= svg_icon('location-dot', 'text-danger me-1') ?><?= e($s['current_location']) ?></td>
                                    <td class="text-end font-monospace"><?= format_inr((float)$s['cost_per_carat']) ?></td>
                                    <td class="text-end font-monospace fw-bold text-success"><?= format_inr((float)$s['sale_price_per_carat']) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($cleanGiaNo)): ?>
                                            <a href="https://www.gia.edu/report-check?reportno=<?= $cleanGiaNo ?>" target="_blank" class="btn btn-xs btn-outline-primary py-1 px-2" title="GIA સત્તાવાર વેબસાઇટ પર રિપોર્ટ ચકાસો">
                                                <?= svg_icon('arrow-up-right-from-square', 'me-1') ?>GIA Check
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
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
<?php endif; ?>

<!-- ==========================================
     MODAL 1: SEND DIAMOND TO GIA LAB
     ========================================== -->
<div class="modal fade" id="sendToGiaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/polish_stock/gia.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_to_gia">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark">
                        <?= svg_icon('paper-plane', 'text-primary me-2') ?>હીરો GIA લેબમાં મોકલો (Send to GIA)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સ્ટોકમાંથી હીરો પસંદ કરો *</label>
                        <select name="stock_id" class="form-select fw-semibold" required>
                            <option value="">હીરો પસંદ કરો (Select Diamond)...</option>
                            <?php foreach ($availableForGia as $stk): ?>
                                <option value="<?= $stk['id'] ?>">
                                    <?= e($stk['packet_no']) ?> - <?= number_format((float)$stk['available_weight'], 3) ?> ct (<?= e($stk['shape']) ?> <?= e($stk['color']) ?>/<?= e($stk['clarity']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">હાજર અન-સર્ટિફાઇડ પોલિશ હીરાની યાદી</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">GIA લેબ શાખા પસંદ કરો *</label>
                        <select name="lab_party_id" class="form-select fw-semibold" required>
                            <?php foreach ($giaLabs as $lab): ?>
                                <option value="<?= $lab['id'] ?>"><?= e($lab['party_name']) ?> (<?= e($lab['city']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">મોકલ્યા તારીખ *</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">અપેક્ષિત પરત તારીખ</label>
                            <input type="date" name="expected_return_date" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સર્વિસ પ્રકાર (GIA Service Requested)</label>
                        <select name="service_type" class="form-select">
                            <option value="Diamond Dossier (Full)">Diamond Dossier (Full 4Cs with Inscription)</option>
                            <option value="Diamond Grading Report">Diamond Grading Report (Full Report)</option>
                            <option value="eReport">eReport (Digital Certificate)</option>
                            <option value="Color Origin Verification">Color Origin Verification</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ડોકેટ / રેફરન્સ નંબર (GIA Docket No)</label>
                        <input type="text" name="docket_no" class="form-control" placeholder="દા.ત. GIA-DOC-98210">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold">GIA મોકલો (Confirm Send)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL 2: RECEIVE FROM GIA (REPORT & GRADES)
     ========================================== -->
<div class="modal fade" id="receiveFromGiaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/polish_stock/gia.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="receive_from_gia">
                <input type="hidden" name="jangad_id" id="rcv_jangad_id" value="">
                <input type="hidden" name="stock_id" id="rcv_stock_id" value="">

                <div class="modal-header bg-success-subtle">
                    <h5 class="modal-title fw-bold text-success">
                        <?= svg_icon('file-circle-check', 'me-2') ?>GIA પ્રમાણપત્ર સાથે હીરો પરત લો (Receive)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="p-2 bg-light rounded border mb-3">
                        <div class="small"><strong class="text-muted">પેકેટ:</strong> <span id="rcv_packet_text" class="fw-bold text-dark"></span></div>
                        <div class="small"><strong class="text-muted">વજન:</strong> <span id="rcv_weight_text" class="fw-bold font-monospace text-primary"></span> ct</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">GIA સર્ટિફિકેટ રિપોર્ટ નંબર *</label>
                        <input type="text" name="gia_number" class="form-control font-monospace fw-bold fs-6 text-primary" placeholder="દા.ત. 2489102931" required>
                        <small class="text-muted">આ નંબરથી GIA વેબસાઇટ પર રિપોર્ટ ડાયરેક્ટ ચેક થશે</small>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">આકાર (Shape) *</label>
                            <select name="shape" id="rcv_shape_select" class="form-select fw-semibold">
                                <option value="Round">Round Brilliant</option>
                                <option value="Princess">Princess</option>
                                <option value="Cushion">Cushion</option>
                                <option value="Emerald">Emerald</option>
                                <option value="Oval">Oval</option>
                                <option value="Pear">Pear</option>
                                <option value="Marquise">Marquise</option>
                                <option value="Radiant">Radiant</option>
                                <option value="Heart">Heart</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Final GIA Cut *</label>
                            <select name="cut" class="form-select fw-semibold">
                                <option value="EX" selected>Excellent (EX)</option>
                                <option value="VG">Very Good (VG)</option>
                                <option value="G">Good</option>
                                <option value="Fair">Fair</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Final GIA Color *</label>
                            <select name="color" class="form-select fw-semibold">
                                <option value="D">D (Colorless)</option>
                                <option value="E">E (Colorless)</option>
                                <option value="F">F (Colorless)</option>
                                <option value="G" selected>G (Near Colorless)</option>
                                <option value="H">H (Near Colorless)</option>
                                <option value="I">I (Near Colorless)</option>
                                <option value="J">J</option>
                                <option value="K">K</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Final GIA Clarity *</label>
                            <select name="clarity" class="form-select fw-semibold">
                                <option value="FL">FL (Flawless)</option>
                                <option value="IF">IF (Internally Flawless)</option>
                                <option value="VVS1">VVS1</option>
                                <option value="VVS2">VVS2</option>
                                <option value="VS1" selected>VS1</option>
                                <option value="VS2">VS2</option>
                                <option value="SI1">SI1</option>
                                <option value="SI2">SI2</option>
                                <option value="I1">I1</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">GIA લેબ ફી (INR)</label>
                            <input type="number" step="0.01" name="cert_fee" class="form-control" placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">સેફ / લોકેશન (Vault)</label>
                            <select name="vault_location" class="form-select">
                                <option value="Surat Vault" selected>Surat Vault (તિજોરી)</option>
                                <option value="Mumbai Safe">Mumbai Safe (ઓપેરા હાઉસ)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">લેબ ફી ચૂકવણી Bank ખાતું (જો ફી ચૂકવી હોય)</label>
                        <select name="bank_account_id" class="form-select">
                            <option value="">ખાતું પસંદ કરો...</option>
                            <?php foreach ($bankAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <?= svg_icon('check', 'me-1') ?>On-Hand સ્ટોકમાં જમા કરો (Receive)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReceiveModal(voucherId, stockId, packetNo, weight, shape) {
    document.getElementById('rcv_jangad_id').value = voucherId;
    document.getElementById('rcv_stock_id').value = stockId;
    document.getElementById('rcv_packet_text').innerText = packetNo;
    document.getElementById('rcv_weight_text').innerText = weight;
    const shapeSel = document.getElementById('rcv_shape_select');
    if (shapeSel) {
        shapeSel.value = shape;
    }
    const modal = new bootstrap.Modal(document.getElementById('receiveFromGiaModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
