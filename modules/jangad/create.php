<?php
/**
 * Create Sales Jangad Voucher (માત્ર પોલિશ તૈયાર હીરા વેચાણ / મેમો જાંગડ)
 * Diamond ERP - Surat & Mumbai
 * Business Rule: Manufacturing / factory stages (Job Work) do NOT use Jangad.
 * Jangad is issued ONLY after Polishing is completed, when diamonds go out for Sales / Approval / GIA.
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
ensure_jangad_sales_columns();

// Optional preselected Polish Stock ID from Polish Stock screen
$preselectedStockId = (int)($_GET['stock_id'] ?? 0);

// Fetch only COMPLETED Polish Diamonds eligible for Sales Jangad
$readyPolishStocks = get_ready_polish_stocks_for_jangad($preselectedStockId > 0 ? [$preselectedStockId] : []);

// Format for JS lookup
$polishStocksJs = [];
foreach ($readyPolishStocks as $ps) {
    $sourceLabel = !empty($ps['kapan_code'])
        ? $ps['kapan_code']
        : (!empty($ps['purchase_no']) ? $ps['purchase_no'] : 'Direct');
    $specs = trim(($ps['shape'] ?: 'Round') . ' ' . ($ps['color'] ?? '') . ' ' . ($ps['clarity'] ?? ''));
    $giaLabel = !empty($ps['gia_number']) ? " • GIA: {$ps['gia_number']}" : '';

    $polishStocksJs[] = [
        'id'                  => (int)$ps['id'],
        'packet_no'           => (string)$ps['packet_no'],
        'stock_type'          => (string)($ps['stock_type'] ?? 'single'),
        'gia_number'          => (string)($ps['gia_number'] ?? ''),
        'source_label'        => $sourceLabel,
        'specs'               => $specs,
        'available_pcs'       => (int)($ps['available_pcs'] ?? 0),
        'available_weight'    => round((float)($ps['available_weight'] ?? 0), 3),
        'cost_per_carat'      => round((float)($ps['cost_per_carat'] ?? 0), 2),
        'sale_price_per_carat'=> round((float)($ps['sale_price_per_carat'] ?? 0), 2),
        'location'            => (string)($ps['current_location'] ?: 'Surat'),
        'linked_packet_id'    => (int)($ps['linked_packet_id'] ?? 0),
        'display_text'        => "{$ps['packet_no']} — {$specs} [{$sourceLabel}]{$giaLabel} | હાજર: " . (int)$ps['available_pcs'] . " Pcs / " . number_format((float)$ps['available_weight'], 3) . " Ct"
    ];
}

// Fetch Parties grouped by Sales Jangad categories
$partiesByCategory = [
    'memo_party'  => get_parties_by_jangad_category('memo_party'),
    'memo_broker' => get_parties_by_jangad_category('memo_broker'),
    'lab_gia'     => get_parties_by_jangad_category('lab_gia'),
    'all'         => get_parties_by_jangad_category(null)
];

// Brokers for optional broker reference (Active only)
$brokers = $pdo->query("SELECT id, party_name, city FROM parties WHERE (status != 'blocked' OR status IS NULL) AND (party_type IN ('broker', 'both') OR roles LIKE '%broker%') ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Non-editable auto-generated Jangad No
$defaultJangadNo = generate_next_code('JNG', 'jangad_vouchers', 'jangad_no');

$allowedSalesCategories = ['memo_party', 'memo_broker', 'lab_gia'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $jangadNo   = generate_next_code('JNG', 'jangad_vouchers', 'jangad_no');
    $category   = trim((string)($_POST['category'] ?? 'memo_party'));
    $partyId    = (int)($_POST['party_id'] ?? 0);
    $brokerId   = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $issueDate  = !empty($_POST['issue_date']) ? $_POST['issue_date'] : date('Y-m-d');
    $returnDate = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : null;
    $remarks    = trim((string)($_POST['remarks'] ?? ''));

    $submittedItems = $_POST['items'] ?? [];
    $errors = [];

    if (!in_array($category, $allowedSalesCategories, true)) {
        $errors[] = 'કારખાનાના સ્ટેપ્સ માટે જાંગડ બનતું નથી. માત્ર પોલિશ વેચાણ / બ્રોકર / GIA મેમો કેટેગરી પસંદ કરો.';
    }

    if ($partyId <= 0) {
        $errors[] = 'કૃપા કરીને માન્ય ગ્રાહક પાર્ટી અથવા દલાલ પસંદ કરો.';
    } elseif (is_party_blocked($partyId)) {
        $errors[] = '🛑 પસંદ કરેલ પાર્ટી બ્લોક (Blocked) છે! તેને જાંગડ આપી શકાશે નહીં.';
    }

    if (!empty($brokerId) && is_party_blocked((int)$brokerId)) {
        $errors[] = '🛑 પસંદ કરેલ દલાલ બ્લોક (Blocked) છે! તેના દ્વારા જાંગડ બની શકશે નહીં.';
    }

    if (empty($submittedItems) || !is_array($submittedItems)) {
        $errors[] = 'કૃપા કરીને ઓછામાં ઓછો એક તૈયાર પોલિશ હીરો / પેકેટ ઉમેરો.';
    }

    $validatedItems = [];
    $seenStockIds = [];
    $totalJangadPcs = 0;
    $totalJangadWeight = 0.000;

    if (empty($errors)) {
        $stkLookupStmt = $pdo->prepare("
            SELECT ps.*, k.kapan_code, p.id as linked_packet_id, p.kapan_id as pkt_kapan_id
            FROM polish_stocks ps
            LEFT JOIN packets p ON p.polish_stock_id = ps.id
            LEFT JOIN kapans k ON COALESCE(ps.kapan_id, p.kapan_id) = k.id
            WHERE ps.id = ?
        ");

        foreach ($submittedItems as $rowIdx => $itemRow) {
            $lineNo = (int)$rowIdx + 1;
            $stockId = (int)($itemRow['polish_stock_id'] ?? 0);
            $issuePcs = (int)($itemRow['issued_pcs'] ?? 0);
            $issueWt = round((float)($itemRow['issued_weight'] ?? 0), 3);
            $askingRate = round((float)($itemRow['asking_rate'] ?? 0), 2);

            if ($stockId <= 0 && $issuePcs <= 0 && $issueWt <= 0) {
                continue;
            }

            if ($stockId <= 0) {
                $errors[] = "લાઇન #{$lineNo}: તૈયાર પોલિશ હીરો / પેકેટ પસંદ કરો.";
                continue;
            }

            if (in_array($stockId, $seenStockIds, true)) {
                $errors[] = "લાઇન #{$lineNo}: એક જ પોલિશ પેકેટ એકથી વધુ વખત પસંદ કરી શકાતું નથી.";
                continue;
            }
            $seenStockIds[] = $stockId;

            if ($issuePcs <= 0 || $issueWt <= 0) {
                $errors[] = "લાઇન #{$lineNo}: ઇસ્યુ નંગ અને વજન શૂન્ય કરતા વધુ હોવું જોઈએ.";
                continue;
            }

            $stkLookupStmt->execute([$stockId]);
            $stk = $stkLookupStmt->fetch(PDO::FETCH_ASSOC);
            if (!$stk) {
                $errors[] = "લાઇન #{$lineNo}: પસંદ કરેલ પોલિશ સ્ટોક મળ્યો નથી (માત્ર તૈયાર પોલિશ હીરા જ જાંગડ પર જઈ શકે છે).";
                continue;
            }

            $availPcs = (int)$stk['available_pcs'];
            $availWt = round((float)$stk['available_weight'], 3);

            if ($issuePcs > $availPcs || $issueWt > $availWt + 0.0005) {
                $errors[] = "લાઇન #{$lineNo} ({$stk['packet_no']}): ઇસ્યુ નંગ/વજન હાજર પોલિશ સ્ટોક કરતાં વધુ છે (હાજર: {$availPcs} Pcs / {$availWt} Ct).";
                continue;
            }

            $itemType = (!empty($stk['gia_number']) || $stk['stock_type'] === 'gia_single')
                ? 'polish_gia'
                : (($stk['stock_type'] === 'parcel' || $availPcs > 1) ? 'polish_parcel' : 'polish_single');

            $specStr = trim(($stk['shape'] ?: 'Round') . ' ' . ($stk['color'] ?? '') . '/' . ($stk['clarity'] ?? ''));
            $desc = "{$stk['packet_no']} ({$specStr})" . (!empty($stk['kapan_code']) ? " [{$stk['kapan_code']}]" : '');
            if (!empty($stk['gia_number'])) {
                $desc .= " GIA: {$stk['gia_number']}";
            }

            $totalJangadPcs += $issuePcs;
            $totalJangadWeight = round($totalJangadWeight + $issueWt, 3);

            $validatedItems[] = [
                'stock'       => $stk,
                'stock_id'    => $stockId,
                'item_type'   => $itemType,
                'description' => $desc,
                'issued_pcs'  => $issuePcs,
                'issued_wt'   => $issueWt,
                'asking_rate' => $askingRate
            ];
        }

        if (empty($validatedItems) && empty($errors)) {
            $errors[] = 'કૃપા કરીને ઓછામાં ઓછો એક માન્ય પોલિશ હીરો પસંદ કરો.';
        }
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insert Jangad Voucher (Sales / Post-Polish Approval Memo)
            $insVoucher = $pdo->prepare("
                INSERT INTO jangad_vouchers (
                    jangad_no, voucher_type, category, party_id, broker_id,
                    issue_date, expected_return_date, total_pcs, total_weight,
                    returned_pcs, returned_weight, pending_weight, total_tops_weight,
                    status, remarks, created_by
                ) VALUES (
                    ?, 'issue', ?, ?, ?,
                    ?, ?, ?, ?,
                    0, 0.000, ?, 0.000,
                    'issued', ?, ?
                )
            ");
            $insVoucher->execute([
                $jangadNo, $category, $partyId, $brokerId,
                $issueDate, $returnDate, $totalJangadPcs, $totalJangadWeight,
                $totalJangadWeight, $remarks, (int)$user['id']
            ]);
            $jangadId = (int)$pdo->lastInsertId();

            $pNameStmt = $pdo->prepare("SELECT party_name FROM parties WHERE id = ?");
            $pNameStmt->execute([$partyId]);
            $partyName = (string)($pNameStmt->fetchColumn() ?: '');

            $lockStkStmt = $pdo->prepare("SELECT id, available_pcs, available_weight FROM polish_stocks WHERE id = ? FOR UPDATE");
            $updStkStmt = $pdo->prepare("UPDATE polish_stocks SET available_pcs = ?, available_weight = ?, status = ? WHERE id = ?");

            $insItemStmt = $pdo->prepare("
                INSERT INTO jangad_items (
                    jangad_id, item_type, packet_id, polish_stock_id, kapan_id, description,
                    issued_pcs, issued_weight, sold_pcs, sold_weight, returned_pcs, returned_weight,
                    loss_weight, charges, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, 0, 0.000, 0, 0.000,
                    0.000, ?, 'issued'
                )
            ");

            $updPktStmt = $pdo->prepare("
                UPDATE packets SET jangad_id = ?, assigned_party_id = ?, current_location = ?, diamond_status = 'assigned_to_jangad'
                WHERE id = ?
            ");

            foreach ($validatedItems as $vi) {
                $lockStkStmt->execute([$vi['stock_id']]);
                $lockedStk = $lockStkStmt->fetch(PDO::FETCH_ASSOC);

                $curAvailPcs = (int)$lockedStk['available_pcs'];
                $curAvailWt  = round((float)$lockedStk['available_weight'], 3);

                if ($vi['issued_pcs'] > $curAvailPcs || $vi['issued_wt'] > $curAvailWt + 0.0005) {
                    throw new \RuntimeException("સ્ટોક '{$vi['stock']['packet_no']}' નો હાજર જથ્થો બદલાઈ ગયો છે.");
                }

                $linkedPktId = !empty($vi['stock']['linked_packet_id']) ? (int)$vi['stock']['linked_packet_id'] : null;
                $sourceKapanId = !empty($vi['stock']['kapan_id']) ? (int)$vi['stock']['kapan_id'] : (!empty($vi['stock']['pkt_kapan_id']) ? (int)$vi['stock']['pkt_kapan_id'] : null);

                $insItemStmt->execute([
                    $jangadId,
                    $vi['item_type'],
                    $linkedPktId,
                    $vi['stock_id'],
                    $sourceKapanId,
                    $vi['description'],
                    $vi['issued_pcs'],
                    $vi['issued_wt'],
                    $vi['asking_rate']
                ]);

                $remPcs = max(0, $curAvailPcs - $vi['issued_pcs']);
                $remWt  = max(0.0, round($curAvailWt - $vi['issued_wt'], 3));
                $newStkStatus = ($remWt > 0.0005 && $remPcs > 0) ? 'partially_on_jangad' : 'on_jangad';

                $updStkStmt->execute([$remPcs, $remWt, $newStkStatus, $vi['stock_id']]);

                // If linked to an individual packet record, log Sales Jangad issuance in diamond journey
                if ($linkedPktId) {
                    $updPktStmt->execute([$jangadId, $partyId, "Sales Jangad: {$partyName}", $linkedPktId]);
                    $pdo->prepare("
                        INSERT INTO diamond_stage_history (
                            packet_id, diamond_no, stage_code, stage_name, party_id, party_name,
                            jangad_id, jangad_no, assignment_id, given_weight, received_weight,
                            difference_weight, pending_weight, diff_classification,
                            assigned_date, outgoing_location, receiving_location, status, notes, created_at
                        ) VALUES (
                            ?, ?, 'sales_jangad', 'Sales Jangad (Approval)', ?, ?,
                            ?, ?, 0, ?, 0.000,
                            0.000, ?, 'pending',
                            ?, ?, '', 'in_process', ?, NOW()
                        )
                    ")->execute([
                        $linkedPktId,
                        $vi['stock']['packet_no'],
                        $partyId,
                        $partyName,
                        $jangadId,
                        $jangadNo,
                        $vi['issued_wt'],
                        $vi['issued_wt'],
                        $issueDate,
                        "Sales Jangad: {$partyName}",
                        "પોલિશ તૈયાર થયા બાદ વેચાણ મેમો #{$jangadNo} પર આપેલ"
                    ]);
                }
            }

            $pdo->commit();
            log_activity('Sales Jangad Created', 'Jangad', $jangadId, "Created Sales Jangad $jangadNo: $totalJangadWeight ct ($totalJangadPcs Pcs) to Party #$partyId");
            set_flash('success', "Sales Jangad વાઉચર '$jangadNo' સફળતાપૂર્વક ઇસ્યુ થઈ ગયો છે ($totalJangadPcs Pcs / " . format_carat($totalJangadWeight) . ").");
            header('Location: ' . BASE_URL . '/modules/jangad/view.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Jangad સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવો Sales Jangad ઇસ્યુ (પોલિશ હીરા વેચાણ મેમો)';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'નવો Sales Jangad વાઉચર (માત્ર તૈયાર પોલિશ હીરા માટે)',
    'પોલિશ તૈયાર થઈ ગયેલા હીરા ગ્રાહક, પાર્ટી અથવા દલાલને વેચાણ / જોવા (Approval Memo) માટે આપો',
    'file-invoice',
    '<a href="' . BASE_URL . '/modules/polish/index.php?tab=stock" class="btn btn-outline-primary btn-sm px-3">' . svg_icon('gem', 'me-1') . 'તૈયાર પોલિશ સ્ટોક જુઓ</a>
     <a href="' . BASE_URL . '/modules/jangad/index.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'Jangad લિસ્ટ</a>',
    'primary'
) ?>

<!-- Clear Business Rule Notice Banner -->
<div class="alert alert-success border d-flex align-items-center gap-3 rounded-3 mb-3 py-2.5 px-3" style="background: #f0fdf4; border-color: #bbf7d0 !important;">
    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background: #dcfce7; color: #15803d;">
        <?= svg_icon('circle-info') ?>
    </div>
    <div class="small text-dark">
        <strong>નિયમ (Sales Jangad Rule):</strong> કારખાનાના પ્રોસેસ સ્ટેપ્સ (Galaxy, Planning, Sarin, LS, Mfg, Polish) દરમિયાન જાંગડ બનતું નથી — તે <strong>Job Work</strong> વિભાગમાં ટ્રેક થાય છે.
        હીરો <strong>સંપૂર્ણ પોલિશ તૈયાર (Polish Stock)</strong> થઈ જાય ત્યાર બાદ જ અહીંથી ગ્રાહક / દલાલને વેચાણ માટે <strong>Jangad</strong> પર જાય છે.
    </div>
</div>

<?php if (empty($polishStocksJs)): ?>
    <div class="card-erp p-5 text-center">
        <div class="mb-3"><?= svg_icon('gem', 'text-muted opacity-50', 30) ?></div>
        <h5 class="fw-bold text-dark">હાલમાં કોઈ તૈયાર પોલિશ હીરા (Polish Stock) જાંગડ માટે ઉપલબ્ધ નથી</h5>
        <p class="text-muted small mb-4">
            હીરા જ્યાં સુધી કારખાનામાં પ્રોસેસમાં હોય ત્યાં સુધી જાંગડ પર જઈ શકતા નથી.
            પહેલા <strong>Job Work</strong> માં હીરાને <strong>Polish Stock</strong> સ્ટેજમાં કમ્પ્લીટ કરો અથવા નવી <strong>Polish Purchase</strong> એન્ટ્રી કરો.
        </p>
        <div class="d-flex justify-content-center gap-2">
            <a href="<?= BASE_URL ?>/modules/process/jobwork_stock.php" class="btn btn-outline-primary px-4">
                <?= svg_icon('diagram-project', 'me-1') ?>Job Work ચેક કરો
            </a>
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=stock" class="btn btn-primary px-4">
                <?= svg_icon('award', 'me-1') ?>Polish Stock માં જાઓ
            </a>
        </div>
    </div>
<?php else: ?>
    <form action="<?= BASE_URL ?>/modules/jangad/create.php" method="POST" id="salesJangadForm" novalidate>
        <?= csrf_field() ?>

        <!-- ================= SECTION 1: SALES JANGAD VOUCHER & PARTY INFO ================= -->
        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon"><?= svg_icon('file-lines') ?></span>
                    ૧. Sales Jangad મૂળભૂત વિગતો (Buyer / Broker Memo Details)
                </h6>
                
            </div>
            <div class="erp-card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">Jangad નંબર (Auto) *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted"><?= svg_icon('lock') ?></span>
                            <input type="text" name="jangad_no" class="form-control fw-bold font-mono bg-light text-dark" value="<?= e($defaultJangadNo) ?>" readonly required>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">ઇસ્યુ તારીખ (Issue Date) *</label>
                        <input type="date" name="issue_date" class="form-control font-mono" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">જાંગડ પ્રકાર (Category) *</label>
                        <select name="category" id="category_select" class="form-select fw-semibold" required>
                            <option value="memo_party">૧. ગ્રાહક સેલિંગ જાંગડ (Party Sales Memo)</option>
                            <option value="memo_broker">૨. દલાલ જાંગડ (Broker Sales Memo)</option>
                            <option value="lab_gia">૩. GIA / IGI લેબ સર્ટિફિકેશન</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">અપેક્ષિત પરત તારીખ (Expected Return)</label>
                        <input type="date" name="expected_return_date" class="form-control font-mono" value="<?= date('Y-m-d', strtotime('+5 days')) ?>">
                    </div>

                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">પાર્ટી / ગ્રાહકનું નામ (Buyer / Receiver Party) *</label>
                        <select name="party_id" id="party_select" class="form-select" required>
                            <option value="">પાર્ટી શોધો અથવા પસંદ કરો...</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">દલાલ મારફત (Broker Reference - Optional)</label>
                        <select name="broker_id" id="broker_select" class="form-select">
                            <option value="">Direct (કોઈ દલાલ નથી)</option>
                            <?php foreach ($brokers as $br): ?>
                                <option value="<?= (int)$br['id'] ?>"><?= e($br['party_name']) ?><?= !empty($br['city']) ? ' (' . e($br['city']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">નોંધ (Remarks)</label>
                        <input type="text" name="remarks" class="form-control" placeholder="દા.ત. Approval માટે, ભાવ ચર્ચા...">
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= SECTION 2: POLISH STOCK SELECTION ================= -->
        <div class="erp-form-card mb-4">
            <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="header-title mb-0">
                        <span class="header-icon icon-green"><?= svg_icon('gem') ?></span>
                        ૨. તૈયાર પોલિશ હીરા પસંદ કરો (Select Ready Polish Stock)
                    </h6>
                    <small class="text-muted">માત્ર પોલિશ કમ્પ્લીટ થયેલા (<?= count($polishStocksJs) ?> પેકેટ્સ ઉપલબ્ધ) હીરા જ અહીં દેખાશે</small>
                </div>
                <button type="button" class="btn btn-primary btn-sm px-3 fw-bold shadow-xs" id="addPolishRowBtn">
                    <?= svg_icon('plus', 'me-1') ?>પોલિશ હીરો ઉમેરો
                </button>
            </div>

            <div class="erp-card-body p-3">
                <div class="table-responsive mb-3" style="min-height: 160px;">
                    <table class="table table-bordered align-middle mb-0" id="polishJangadTable" style="min-width: 780px;">
                        <colgroup>
                            <col style="width: 38%;">
                            <col style="width: 14%;">
                            <col style="width: 10%;">
                            <col style="width: 12%;">
                            <col style="width: 12%;">
                            <col style="width: 10%;">
                            <col style="width: 4%;">
                        </colgroup>
                        <thead class="table-light">
                            <tr>
                                <th>પોલિશ પેકેટ / હીરો (Ready Polish Stock) *</th>
                                <th class="text-center">હાજર સ્ટોક</th>
                                <th class="text-center">જાંગડ નંગ *</th>
                                <th class="text-end">જાંગડ વજન (Ct) *</th>
                                <th class="text-end">માંગ ભાવ / Ct (₹)</th>
                                <th class="text-end">અંદાજિત રકમ (₹)</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="polishJangadBody"></tbody>
                        <tfoot class="table-light fw-bold" style="background: #f8fafc; border-top: 2px solid #cbd5e1;">
                            <tr>
                                <td colspan="2" class="text-end text-dark">કુલ જાંગડ સમરી (<span id="sum_item_count" class="font-mono text-primary">0</span> આઇટમ):</td>
                                <td class="text-center font-mono text-dark" id="sum_total_pcs">0 Pcs</td>
                                <td class="text-end font-mono text-primary fs-6" id="sum_total_wt">0.000 ct</td>
                                <td class="text-end text-muted small">કુલ અંદાજિત મૂલ્ય:</td>
                                <td class="text-end font-mono text-success fs-6" id="sum_total_val">₹ 0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                    <a href="<?= BASE_URL ?>/modules/jangad/index.php" class="btn btn-outline-secondary px-4">રદ કરો</a>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <?= svg_icon('file-invoice', 'me-1') ?>Sales Jangad ઇસ્યુ કરો & પ્રિન્ટ જુઓ
                    </button>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const polishStocks = <?= json_encode($polishStocksJs, JSON_UNESCAPED_UNICODE) ?>;
    const preselectedStockId = <?= (int)$preselectedStockId ?>;
    const partiesByCategory = <?= json_encode($partiesByCategory, JSON_UNESCAPED_UNICODE) ?>;

    // 1. Category-driven Party Select
    const catSelect = document.getElementById('category_select');
    const partySelect = document.getElementById('party_select');
    let partyTs = null;

    function populatePartyDropdown(category) {
        if (!partySelect) return;
        let list = partiesByCategory[category] || [];
        if (list.length === 0) {
            list = partiesByCategory['all'] || [];
        }

        if (partyTs) {
            partyTs.destroy();
            partyTs = null;
        }
        partySelect.innerHTML = '<option value="">પાર્ટી શોધો અથવા પસંદ કરો...</option>';
        list.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.party_name + (p.city ? ` (${p.city})` : '');
            partySelect.appendChild(opt);
        });

        if (typeof TomSelect !== 'undefined') {
            partyTs = new TomSelect(partySelect, {
                dropdownParent: 'body',
                create: false,
                placeholder: 'પાર્ટી શોધો અથવા પસંદ કરો...',
                searchField: ['text']
            });
        }
    }

    if (catSelect) {
        populatePartyDropdown(catSelect.value);
        catSelect.addEventListener('change', () => populatePartyDropdown(catSelect.value));
    }

    if (typeof TomSelect !== 'undefined' && document.getElementById('broker_select')) {
        new TomSelect('#broker_select', {
            dropdownParent: 'body',
            allowEmptyOption: true,
            placeholder: 'દલાલ પસંદ કરો...'
        });
    }

    // 2. Dynamic Polish Stock Rows
    const tbody = document.getElementById('polishJangadBody');
    const addBtn = document.getElementById('addPolishRowBtn');
    if (!tbody || !addBtn) return;

    let rowIdx = 0;

    function getStockOptionsHtml(selectedId = 0) {
        let html = '<option value="">તૈયાર પોલિશ હીરો શોધો અથવા પસંદ કરો...</option>';
        polishStocks.forEach(s => {
            const sel = (s.id === selectedId) ? 'selected' : '';
            html += `<option value="${s.id}" ${sel}>${escapeHtml(s.display_text)}</option>`;
        });
        return html;
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function addPolishRow(defaultStockId = 0) {
        const idx = rowIdx++;
        const tr = document.createElement('tr');
        tr.className = 'polish-jangad-row';
        tr.innerHTML = `
            <td>
                <select name="items[${idx}][polish_stock_id]" class="form-select form-select-sm polish-stock-select" required>
                    ${getStockOptionsHtml(defaultStockId)}
                </select>
            </td>
            <td class="text-center">
                <span class="badge bg-light text-dark border font-mono avail-badge">—</span>
            </td>
            <td>
                <input type="number" name="items[${idx}][issued_pcs]" class="form-control form-control-sm font-mono text-center fw-bold issue-pcs-input" min="1" step="1" value="0" required>
            </td>
            <td>
                <input type="number" name="items[${idx}][issued_weight]" class="form-control form-control-sm font-mono text-end fw-bold text-primary issue-wt-input" min="0.001" step="0.01" value="0.000" required>
            </td>
            <td>
                <input type="number" name="items[${idx}][asking_rate]" class="form-control form-control-sm font-mono text-end asking-rate-input" min="0" step="0.01" value="0.00">
            </td>
            <td class="text-end font-mono fw-bold text-success row-value-cell">₹ 0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm p-1 remove-row-btn" title="હટાવો">
                    ' + window.svgIcon('trash-can') + '
                </button>
            </td>
        `;
        tbody.appendChild(tr);

        const selectEl = tr.querySelector('.polish-stock-select');
        const pcsIn = tr.querySelector('.issue-pcs-input');
        const wtIn = tr.querySelector('.issue-wt-input');
        const rateIn = tr.querySelector('.asking-rate-input');

        function onStockPicked(val) {
            const sid = parseInt(val || '0', 10);
            const stk = polishStocks.find(p => p.id === sid);
            const badge = tr.querySelector('.avail-badge');
            if (stk) {
                badge.textContent = `${stk.available_pcs} Pcs / ${stk.available_weight.toFixed(3)} ct`;
                badge.className = 'badge bg-success-subtle text-success border font-mono avail-badge';
                pcsIn.max = stk.available_pcs;
                wtIn.max = stk.available_weight;
                pcsIn.value = stk.available_pcs;
                wtIn.value = stk.available_weight.toFixed(3);
                rateIn.value = (stk.sale_price_per_carat > 0 ? stk.sale_price_per_carat : stk.cost_per_carat).toFixed(2);
            } else {
                badge.textContent = '—';
                badge.className = 'badge bg-light text-dark border font-mono avail-badge';
                pcsIn.value = 0;
                wtIn.value = '0.000';
                rateIn.value = '0.00';
            }
            recalcTotals();
        }

        if (typeof TomSelect !== 'undefined') {
            const ts = new TomSelect(selectEl, {
                dropdownParent: 'body',
                create: false,
                maxOptions: 500,
                placeholder: 'તૈયાર પોલિશ હીરો શોધો...'
            });
            ts.on('change', onStockPicked);
            tr._tomselect = ts;
        } else {
            selectEl.addEventListener('change', () => onStockPicked(selectEl.value));
        }

        [pcsIn, wtIn, rateIn].forEach(inp => inp.addEventListener('input', recalcTotals));
        tr.querySelector('.remove-row-btn').addEventListener('click', () => {
            if (tbody.querySelectorAll('.polish-jangad-row').length <= 1) {
                alert('ઓછામાં ઓછો એક પોલિશ હીરો જરૂરી છે.');
                return;
            }
            if (tr._tomselect) tr._tomselect.destroy();
            tr.remove();
            recalcTotals();
        });

        if (defaultStockId > 0) {
            onStockPicked(defaultStockId);
        } else {
            recalcTotals();
        }
    }

    function recalcTotals() {
        let count = 0;
        let totPcs = 0;
        let totWt = 0;
        let totVal = 0;

        tbody.querySelectorAll('.polish-jangad-row').forEach(tr => {
            const selVal = tr.querySelector('.polish-stock-select')?.value;
            const pcs = parseInt(tr.querySelector('.issue-pcs-input')?.value || '0', 10) || 0;
            const wt = parseFloat(tr.querySelector('.issue-wt-input')?.value || '0') || 0;
            const rate = parseFloat(tr.querySelector('.asking-rate-input')?.value || '0') || 0;
            const lineVal = wt * rate;
            tr.querySelector('.row-value-cell').textContent = '₹ ' + lineVal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            if (selVal) count++;
            totPcs += pcs;
            totWt += wt;
            totVal += lineVal;
        });

        document.getElementById('sum_item_count').textContent = count;
        document.getElementById('sum_total_pcs').textContent = totPcs + ' Pcs';
        document.getElementById('sum_total_wt').textContent = totWt.toFixed(3) + ' ct';
        document.getElementById('sum_total_val').textContent = '₹ ' + totVal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    addBtn.addEventListener('click', () => addPolishRow(0));
    addPolishRow(preselectedStockId);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
