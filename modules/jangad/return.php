<?php
/**
 * Jangad Return Management - Diamond Splitting, Sub Numbers & Automatic Tops
 * Diamond ERP (Section 6.4 & Advanced Return Workflow)
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

$jangadId = (int)($_GET['id'] ?? $_POST['jangad_id'] ?? 0);

// If no Jangad ID provided, show the active Jangads list for return selection
if ($jangadId <= 0) {
    $activeVouchers = $pdo->query("
        SELECT jv.*, p.party_name, p.city 
        FROM jangad_vouchers jv 
        JOIN parties p ON jv.party_id = p.id 
        WHERE jv.status IN ('issued', 'partially_returned') 
        ORDER BY jv.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pageTitle = 'Jangad Return - વાઉચર પસંદ કરો (Select Jangad)';
    require_once __DIR__ . '/../../includes/header.php';
    ?>

    <?= render_page_header(
        'Sales Jangad Return & વેચાણ સેટલમેન્ટ',
        'પસંદ ન આવેલ માલ Polish Stock માં પાછો જમા કરવા અથવા ગ્રાહક વેચાણ બિલ બનાવવા સક્રિય Jangad પસંદ કરો',
        'rotate-left',
        '<a href="' . BASE_URL . '/modules/jangad/index.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'યાદી પર પાછા</a>',
        'primary'
    ) ?>

    <div class="card-erp">
        <div class="card-header bg-white py-3 px-3">
            <h6 class="fw-bold mb-0 text-dark">
                <?= svg_icon('hourglass-half', 'text-warning me-2') ?>પરત જમા બાકી હોય તેવા સક્રિય Jangad વાઉચર્સ (Active Returnable Jangads)
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-diamond mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Jangad નંબર</th>
                            <th>પાર્ટી / વેન્ડર</th>
                            <th>કેટેગરી</th>
                            <th>ઇસ્યુ તારીખ</th>
                            <th class="text-center">ઇસ્યુ નંગ</th>
                            <th class="text-end">ઇસ્યુ વજન (Ct)</th>
                            <th class="text-end">વેચાણ (Sold)</th>
                            <th class="text-end">પાછો જમા (Ret)</th>
                            <th class="text-end">પેન્ડિંગ વજન (Ct)</th>
                            <th>સ્થિતિ</th>
                            <th class="text-end">એક્શન</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeVouchers)): ?>
                            <tr><td colspan="11" class="text-center py-5 text-muted">કોઈ બાકી Jangad વાઉચર મળ્યું નથી. તમામ માલ જમા થઈ ગયેલ છે.</td></tr>
                        <?php else: ?>
                            <?php foreach ($activeVouchers as $av): 
                                $avSoldWt = (float)($av['sold_weight'] ?? 0);
                                $avSoldPcs = (int)($av['sold_pcs'] ?? 0);
                                $pend = max(0.0, (float)$av['total_weight'] - $avSoldWt - (float)$av['returned_weight'] - (float)($av['total_tops_weight'] ?? 0));
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $av['id'] ?>" class="fw-bold font-mono text-primary text-decoration-none fs-6">
                                            <?= e($av['jangad_no']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong><?= e($av['party_name']) ?></strong>
                                        <small class="text-muted d-block"><?= e($av['city']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-mono small"><?= e($av['category']) ?></span>
                                    </td>
                                    <td class="font-mono"><?= format_date($av['issue_date']) ?></td>
                                    <td class="text-center font-mono"><?= (int)$av['total_pcs'] ?> Pcs</td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_carat($av['total_weight']) ?></td>
                                    <td class="text-end font-mono text-primary fw-semibold">
                                        <?= format_carat($avSoldWt) ?>
                                        <?php if ($avSoldPcs > 0): ?><small class="d-block text-muted"><?= $avSoldPcs ?> Pcs</small><?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono text-success">
                                        <?= format_carat($av['returned_weight']) ?>
                                        <?php if ((int)$av['returned_pcs'] > 0): ?><small class="d-block text-muted"><?= (int)$av['returned_pcs'] ?> Pcs</small><?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold text-danger"><?= format_carat($pend) ?></td>
                                    <td>
                                        <?php if ($av['status'] === 'partially_returned'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ પરત / વેચાણ</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary-subtle text-primary border">બાકી (Pending)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $av['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-xs px-3">
                                            <?= svg_icon('sliders', 'me-1') ?>વેચાણ / સ્ટોક પરત
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
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Fetch Jangad Voucher Master
$stmt = $pdo->prepare("
    SELECT jv.*, p.party_name, p.city as party_city, b.party_name as broker_name 
    FROM jangad_vouchers jv 
    JOIN parties p ON jv.party_id = p.id 
    LEFT JOIN parties b ON jv.broker_id = b.id 
    WHERE jv.id = ?
");
$stmt->execute([$jangadId]);
$jangad = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$jangad) {
    set_flash('error', 'Jangad વાઉચર મળ્યું નથી.');
    header('Location: ' . BASE_URL . '/modules/jangad/return.php');
    exit;
}

// ================= POST ACTIONS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $subAction = trim($_POST['sub_action'] ?? '');

    // -------------------------------------------------------------
    // ACTION 1: Record Split Sub Diamond Return (Hierarchical Sub-Diamonds e.g. 01.1.1.1)
    // -------------------------------------------------------------
    if ($subAction === 'record_sub_return') {
        $assignmentId   = (int)($_POST['assignment_id'] ?? 0);
        $receivedWeight = (float)($_POST['received_weight'] ?? 0.000);
        $receivedDate   = !empty($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d');
        $notes          = trim($_POST['notes'] ?? '');

        try {
            $res = record_diamond_split_return(
                $assignmentId,
                $jangadId,
                $receivedWeight,
                $receivedDate,
                $notes,
                (int)($user['id'] ?? 0)
            );

            log_activity('Jangad Diamond Returned', 'Jangad', $jangadId, "Returned Sub Diamond {$res['sub_number']} ({$receivedWeight} ct) for Assign ID #{$assignmentId}");
            set_flash('success', "સબ ડાયમંડ '{$res['sub_number']}' ({$receivedWeight} Ct) સફળતાપૂર્વક જમા થઈ ગયેલ છે અને નવો ઇન્વેન્ટરી રેકોર્ડ બની ગયો છે.");
            header('Location: ' . BASE_URL . '/modules/jangad/return.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'રિટર્ન નોંધવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }

    // -------------------------------------------------------------
    // ACTION 2: Complete Assignment & Classify Difference (Loss / Tops / Other)
    // -------------------------------------------------------------
    elseif ($subAction === 'complete_assignment_tops') {
        $assignmentId        = (int)($_POST['assignment_id'] ?? 0);
        $diffClassification  = trim($_POST['diff_classification'] ?? 'loss');

        try {
            $res = finalize_diamond_assignment_tops($assignmentId, $jangadId, (int)($user['id'] ?? 0), $diffClassification);

            $diffLabel = ($diffClassification === 'loss') ? 'પ્રોસેસિંગ ઘટ (Loss)' : (($diffClassification === 'tops') ? 'Tops (રદિયો)' : 'અન્ય તફાવત');
            log_activity('Jangad Assignment Finalized', 'Jangad', $jangadId, "Finalized Assignment #{$assignmentId} classified as {$diffClassification}");
            set_flash('success', "ડાયમંડ ફાળવણી સફળતાપૂર્વક પૂર્ણ થઈ. બાકી રહેલ તફાવત વજન {$diffLabel} તરીકે રેકોર્ડ થયું અને મૂળ ડાયમંડ આગળના સ્ટેજ માટે તૈયાર છે.");
            header('Location: ' . BASE_URL . '/modules/jangad/return.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'Finalize કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }

    // -------------------------------------------------------------
    // ACTION 3: Return Unsold Polish Item from Sales Jangad back to its Kapan & Polish Stock
    // -------------------------------------------------------------
    elseif ($subAction === 'return_polish_item') {
        $itemId    = (int)($_POST['item_id'] ?? 0);
        $retPcs    = (int)($_POST['return_pcs'] ?? 0);
        $retWt     = round((float)($_POST['return_weight'] ?? 0.000), 3);
        $retNotes  = trim((string)($_POST['notes'] ?? ''));

        try {
            $pdo->beginTransaction();
            $retRes = apply_jangad_item_return_to_kapan($itemId, $jangadId, $retPcs, $retWt, $retNotes);
            recalculate_jangad_voucher_totals($jangadId);
            $pdo->commit();

            $kapanTag = !empty($retRes['kapan_code']) ? "કાપન {$retRes['kapan_code']} સ્ટોક" : "Polish Stock";
            log_activity('Sales Jangad Returned', 'Jangad', $jangadId, "Returned $retPcs Pcs ($retWt ct) to {$kapanTag} on {$jangad['jangad_no']}");
            set_flash('success', "{$retPcs} Pcs ({$retWt} Ct) સફળતાપૂર્વક {$kapanTag} માં પાછા જમા થઈ ગયા છે!");
            header('Location: ' . BASE_URL . '/modules/jangad/return.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'સ્ટોક પાછો જમા કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }

    // -------------------------------------------------------------
    // ACTION 4: Batch Settle Whole Sales Jangad (Sold + Return-to-Kapan-Stock together)
    // -------------------------------------------------------------
    elseif ($subAction === 'settle_sales_jangad') {
        $soldPcsMap  = $_POST['sold_pcs'] ?? [];
        $soldWtMap   = $_POST['sold_weight'] ?? [];
        $soldRateMap = $_POST['sold_rate'] ?? [];
        $retPcsMap   = $_POST['return_pcs'] ?? [];
        $retWtMap    = $_POST['return_weight'] ?? [];

        $saleDate      = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
        if ($saleDate === '') {
            $saleDate = date('Y-m-d');
        }
        $baseInvoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
        if ($baseInvoiceNo === '') {
            $baseInvoiceNo = generate_next_code('P-INV', 'polish_sales', 'invoice_no');
        }
        $brokerId       = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : (!empty($jangad['broker_id']) ? (int)$jangad['broker_id'] : null);
        $brokeragePct   = max(0.0, (float)($_POST['brokerage_percent'] ?? 0.00));
        $batchAcGstAmt  = max(0.0, round((float)($_POST['ac_gst_amount'] ?? 0.00), 2));
        $dueDays        = max(0, (int)($_POST['due_days'] ?? 50));
        $dueDate        = date('Y-m-d', strtotime($saleDate . " +{$dueDays} days"));
        $payStatusInput = trim((string)($_POST['payment_status'] ?? 'pending'));
        $rawReceivedAmt = max(0.0, round((float)($_POST['received_amount'] ?? 0.00), 2));
        $receivedDate   = !empty($_POST['received_date']) ? $_POST['received_date'] : ($payStatusInput !== 'pending' ? $saleDate : null);
        $bankAccId      = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $batchNotes     = trim((string)($_POST['notes'] ?? ''));

        $candidateIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_keys((array)$soldPcsMap),
            array_keys((array)$soldWtMap),
            array_keys((array)$retPcsMap),
            array_keys((array)$retWtMap)
        )), fn($idVal) => $idVal > 0)));
        sort($candidateIds);

        try {
            $pdo->beginTransaction();

            $actionsToRun = [];
            $soldRowsCount = 0;
            $totalBaseSaleAmt = 0.00;

            $lockItemStmt = $pdo->prepare("SELECT * FROM jangad_items WHERE id = ? AND jangad_id = ? FOR UPDATE");
            foreach ($candidateIds as $itId) {
                $sPcs  = max(0, (int)($soldPcsMap[$itId] ?? 0));
                $sWt   = max(0.0, round((float)($soldWtMap[$itId] ?? 0), 3));
                $sRate = max(0.0, round((float)($soldRateMap[$itId] ?? 0), 2));
                $rPcs  = max(0, (int)($retPcsMap[$itId] ?? 0));
                $rWt   = max(0.0, round((float)($retWtMap[$itId] ?? 0), 3));

                if ($sPcs === 0 && $sWt <= 0.0005 && $rPcs === 0 && $rWt <= 0.0005) {
                    continue;
                }
                if (($sPcs > 0) !== ($sWt > 0.0005)) {
                    throw new \RuntimeException("આઇટમ #{$itId}: વેચાણ માટે નંગ અને વજન બંને ૦ કરતા વધુ દાખલ કરો.");
                }
                if (($rPcs > 0) !== ($rWt > 0.0005)) {
                    throw new \RuntimeException("આઇટમ #{$itId}: કાપન સ્ટોક પરત માટે નંગ અને વજન બંને ૦ કરતા વધુ દાખલ કરો.");
                }

                $lockItemStmt->execute([$itId, $jangadId]);
                $item = $lockItemStmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    throw new \RuntimeException("જાંગડ આઇટમ #{$itId} મળ્યું નથી.");
                }

                $curSoldPcs = (int)($item['sold_pcs'] ?? 0);
                $curSoldWt  = round((float)($item['sold_weight'] ?? 0), 3);
                $pendPcs    = max(0, (int)$item['issued_pcs'] - $curSoldPcs - (int)$item['returned_pcs']);
                $pendWt     = max(0.0, round((float)$item['issued_weight'] - $curSoldWt - (float)$item['returned_weight'], 3));

                if (($sPcs + $rPcs) > $pendPcs || ($sWt + $rWt) > ($pendWt + 0.001)) {
                    throw new \RuntimeException("આઇટમ '{$item['description']}': વેચાણ + પરત જથ્થો બાકી જથ્થા ({$pendPcs} Pcs / {$pendWt} Ct) કરતાં વધુ છે.");
                }
                if (($sWt + $rWt) > $pendWt) {
                    $excess = round(($sWt + $rWt) - $pendWt, 3);
                    if ($rWt >= $excess) {
                        $rWt = round($rWt - $excess, 3);
                    } else {
                        $sWt = round(max(0.001, $sWt - $excess), 3);
                    }
                }

                $rowPayAmt = 0.00;
                if ($sPcs > 0) {
                    if (empty($item['polish_stock_id'])) {
                        throw new \RuntimeException("આઇટમ '{$item['description']}' માટે Polish Stock લિંક નથી, તેથી વેચાણ બિલ બનાવી શકાય નહીં.");
                    }
                    if ($sRate <= 0) {
                        throw new \RuntimeException("વેચાયેલ આઇટમ '{$item['description']}' નો ભાવ (₹/Ct) દાખલ કરો.");
                    }
                    $soldRowsCount++;
                    $rowPayAmt = round($sWt * $sRate, 2);
                    $totalBaseSaleAmt = round($totalBaseSaleAmt + $rowPayAmt, 2);
                }

                $actionsToRun[] = [
                    'item'      => $item,
                    'item_id'   => $itId,
                    'sold_pcs'  => $sPcs,
                    'sold_wt'   => $sWt,
                    'sold_rate' => $sRate,
                    'pay_amt'   => $rowPayAmt,
                    'ret_pcs'   => $rPcs,
                    'ret_wt'    => $rWt,
                ];
            }

            if (empty($actionsToRun)) {
                throw new \RuntimeException('કૃપા કરીને ઓછામાં ઓછા એક આઇટમમાં વેચાણ અથવા કાપન સ્ટોકમાં પરત નંગ/વજન દાખલ કરો.');
            }

            $totalBatchNetAmt    = round($totalBaseSaleAmt + $batchAcGstAmt, 2);
            $targetTotalReceived = ($payStatusInput === 'paid') ? $totalBatchNetAmt : min($rawReceivedAmt, $totalBatchNetAmt);

            $allocGst = 0.00;
            $allocRec = 0.00;
            $soldIdx  = 0;

            $totSoldPcsBatch = 0;
            $totSoldWtBatch  = 0.000;
            $totRetPcsBatch  = 0;
            $totRetWtBatch   = 0.000;
            $createdInvoices = [];
            $retKapanSummary = [];

            $dupInvStmt = $pdo->prepare("SELECT id FROM polish_sales WHERE invoice_no = ? LIMIT 1");
            $insSaleStmt = $pdo->prepare("INSERT INTO polish_sales (
                invoice_no, sale_date, party_id, broker_id, polish_stock_id, jangad_id, jangad_item_id, pcs, weight,
                price_per_carat_inr, payment_amount, brokerage_percent, brokerage_amount,
                ac_gst_amount, net_amount, due_days, due_date, payment_status,
                received_amount, received_date, notes, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )");

            foreach ($actionsToRun as $act) {
                $item = $act['item'];
                $itId = (int)$act['item_id'];

                // Step A: Record Sold Portion -> polish_sales & jangad_items
                if ($act['sold_pcs'] > 0) {
                    $soldIdx++;
                    $rowInvoiceNo = ($soldRowsCount === 1) ? $baseInvoiceNo : ($baseInvoiceNo . '-' . $soldIdx);
                    $dupInvStmt->execute([$rowInvoiceNo]);
                    if ($dupInvStmt->fetchColumn()) {
                        throw new \RuntimeException("વેચાણ ઇન્વોઇસ નંબર '{$rowInvoiceNo}' પહેલાથી હાજર છે. કૃપા કરીને નવો નંબર આપો.");
                    }

                    $rowPayAmt = $act['pay_amt'];
                    if ($soldIdx < $soldRowsCount && $totalBaseSaleAmt > 0) {
                        $rowGst = round($batchAcGstAmt * ($rowPayAmt / $totalBaseSaleAmt), 2);
                    } else {
                        $rowGst = round(max(0.0, $batchAcGstAmt - $allocGst), 2);
                    }
                    $allocGst = round($allocGst + $rowGst, 2);

                    $rowNetAmt    = round($rowPayAmt + $rowGst, 2);
                    $rowBrokerage = round($rowPayAmt * ($brokeragePct / 100), 2);

                    if ($payStatusInput === 'paid') {
                        $rowRec = $rowNetAmt;
                    } elseif ($soldIdx < $soldRowsCount && $totalBatchNetAmt > 0) {
                        $rowRec = round($targetTotalReceived * ($rowNetAmt / $totalBatchNetAmt), 2);
                    } else {
                        $rowRec = round($targetTotalReceived - $allocRec, 2);
                    }
                    $rowRec = max(0.0, min($rowNetAmt, $rowRec));
                    $allocRec = round($allocRec + $rowRec, 2);

                    $rowPayStatus = ($rowRec >= $rowNetAmt && $rowNetAmt > 0) ? 'paid' : (($rowRec > 0) ? 'partial' : 'pending');
                    $saleNote     = $batchNotes !== '' ? $batchNotes : "Sales Jangad {$jangad['jangad_no']} સેટલમેન્ટ વેચાણ";

                    $insSaleStmt->execute([
                        $rowInvoiceNo, $saleDate, (int)$jangad['party_id'], $brokerId, (int)$item['polish_stock_id'], $jangadId, $itId,
                        $act['sold_pcs'], $act['sold_wt'], $act['sold_rate'], $rowPayAmt, $brokeragePct, $rowBrokerage,
                        $rowGst, $rowNetAmt, $dueDays, $dueDate, $rowPayStatus,
                        $rowRec, $receivedDate, $saleNote, (int)$user['id']
                    ]);
                    $saleId = (int)$pdo->lastInsertId();

                    if ($bankAccId && $rowRec > 0) {
                        record_account_transaction(
                            $bankAccId, 'credit', $rowRec, 'polish_sale', $saleId,
                            $rowInvoiceNo, $receivedDate ?? $saleDate,
                            "Polish diamond sale collection on {$rowInvoiceNo} (Jangad {$jangad['jangad_no']})", (int)$user['id']
                        );
                    }

                    $unpaidReceivable = round($rowNetAmt - $rowRec, 2);
                    if ($unpaidReceivable > 0) {
                        update_party_balance((int)$jangad['party_id'], +$unpaidReceivable);
                    }
                    if ($brokerId && $rowBrokerage > 0) {
                        update_party_balance($brokerId, -$rowBrokerage);
                    }

                    $newSoldPcs  = (int)($item['sold_pcs'] ?? 0) + $act['sold_pcs'];
                    $newSoldWt   = round((float)($item['sold_weight'] ?? 0) + $act['sold_wt'], 3);
                    $settledWt   = round($newSoldWt + (float)$item['returned_weight'], 3);
                    $settledPcs  = $newSoldPcs + (int)$item['returned_pcs'];
                    $remAfterSaleWt = max(0.0, round((float)$item['issued_weight'] - $settledWt, 3));
                    $interStatus = ($remAfterSaleWt <= 0.0005 || $settledPcs >= (int)$item['issued_pcs']) ? 'returned' : 'partially_returned';

                    $pdo->prepare("
                        UPDATE jangad_items SET
                            sold_pcs = ?,
                            sold_weight = ?,
                            polish_sale_id = ?,
                            status = ?,
                            description = CONCAT(description, ' [Sold: ', ?, ']')
                        WHERE id = ?
                    ")->execute([$newSoldPcs, $newSoldWt, $saleId, $interStatus, $rowInvoiceNo, $itId]);

                    reconcile_polish_stock_status((int)$item['polish_stock_id']);

                    // Only mark single-diamond packet sold if entire packet is sold (no return piece)
                    if (!empty($item['packet_id']) && $interStatus === 'returned' && $act['ret_pcs'] === 0 && (int)$item['returned_pcs'] === 0) {
                        $pktId = (int)$item['packet_id'];
                        $pdo->prepare("
                            UPDATE packets SET status = 'sold', diamond_status = 'completed', jangad_id = NULL, assigned_party_id = NULL, current_location = 'Sold'
                            WHERE id = ?
                        ")->execute([$pktId]);
                        $pdo->prepare("
                            UPDATE diamond_stage_history SET
                                received_weight = ?,
                                pending_weight = 0.000,
                                return_date = CURDATE(),
                                receiving_location = 'Sold',
                                status = 'completed',
                                notes = CONCAT(IFNULL(notes, ''), ?)
                            WHERE packet_id = ? AND jangad_id = ? AND stage_code = 'sales_jangad' AND status = 'in_process'
                        ")->execute([$act['sold_wt'], " | વેચાણ બિલ: {$rowInvoiceNo}", $pktId, $jangadId]);
                    }

                    $totSoldPcsBatch += $act['sold_pcs'];
                    $totSoldWtBatch   = round($totSoldWtBatch + $act['sold_wt'], 3);
                    $createdInvoices[] = $rowInvoiceNo;
                }

                // Step B: Return Unsold Portion straight back to its respective Kapan & Polish Stock
                if ($act['ret_pcs'] > 0) {
                    $retRes = apply_jangad_item_return_to_kapan($itId, $jangadId, $act['ret_pcs'], $act['ret_wt'], $batchNotes);
                    $totRetPcsBatch += $act['ret_pcs'];
                    $totRetWtBatch   = round($totRetWtBatch + $act['ret_wt'], 3);
                    $kCode = !empty($retRes['kapan_code']) ? $retRes['kapan_code'] : 'Polish Stock';
                    $retKapanSummary[$kCode] = ($retKapanSummary[$kCode] ?? 0) + $act['ret_wt'];
                }
            }

            recalculate_jangad_voucher_totals($jangadId);
            $pdo->commit();

            $partsMsg = [];
            if ($totSoldPcsBatch > 0) {
                $invTxt = implode(', ', $createdInvoices);
                $partsMsg[] = "વેચાણ: {$totSoldPcsBatch} Pcs ({$totSoldWtBatch} Ct) [બિલ: {$invTxt}]";
            }
            if ($totRetPcsBatch > 0) {
                $kList = [];
                foreach ($retKapanSummary as $kc => $kw) {
                    $kList[] = "{$kc}: {$kw} Ct";
                }
                $partsMsg[] = "કાપન સ્ટોકમાં પરત: {$totRetPcsBatch} Pcs ({$totRetWtBatch} Ct) (" . implode(', ', $kList) . ")";
            }
            $summaryLine = implode(' • ', $partsMsg);

            log_activity('Sales Jangad Settled', 'Jangad', $jangadId, "Settled {$jangad['jangad_no']}: {$summaryLine}");
            set_flash('success', "જાંગડ સેટલમેન્ટ પૂર્ણ! {$summaryLine}");
            header('Location: ' . BASE_URL . '/modules/jangad/return.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'સેટલમેન્ટ સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Fetch Polish Sales Jangad Items with resolved source Kapan & linked Sale Invoice
$jiStmt = $pdo->prepare("
    SELECT ji.*, ps.packet_no, ps.shape, ps.color, ps.clarity, ps.gia_number,
           ps.kapan_id as stock_kapan_id,
           COALESCE(ji.kapan_id, ps.kapan_id) as resolved_kapan_id,
           k.kapan_code, k.kapan_name,
           psale.invoice_no as linked_invoice_no, psale.net_amount as linked_sale_amount
    FROM jangad_items ji
    LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
    LEFT JOIN kapans k ON COALESCE(ji.kapan_id, ps.kapan_id) = k.id
    LEFT JOIN polish_sales psale ON ji.polish_sale_id = psale.id
    WHERE ji.jangad_id = ?
    ORDER BY ji.id ASC
");
$jiStmt->execute([$jangadId]);
$polishJangadItems = $jiStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch structured Kapan-wise assignments and returns (legacy support)
$kapanAssignments = get_jangad_kapan_assignments($jangadId);

// Compute overall summary totals
$totalKapansCount     = count($kapanAssignments);
$totalAssignedPcs     = 0;
$totalAssignedWeight  = 0.0;
$totalSoldPcs         = 0;
$totalSoldWeight      = 0.0;
$totalReceivedPcs     = 0;
$totalReceivedWeight  = 0.0;
$totalPendingWeight   = 0.0;
$totalFinalizedTops   = 0.0;
$hasPendingSalesItems = false;
$uniqueKapanIds       = [];

if (!empty($kapanAssignments)) {
    foreach ($kapanAssignments as $ka) {
        foreach ($ka['assignments'] as $asgn) {
            $totalAssignedPcs    += 1;
            $totalAssignedWeight += (float)$asgn['assigned_weight'];
            $totalReceivedPcs    += (int)$asgn['returned_pcs'];
            $totalReceivedWeight += (float)$asgn['returned_weight'];
            $totalFinalizedTops  += (float)$asgn['tops_weight'];

            if ($asgn['status'] !== 'completed') {
                $totalPendingWeight += max(0.0, (float)$asgn['assigned_weight'] - (float)$asgn['returned_weight']);
            }
        }
    }
} else {
    foreach ($polishJangadItems as $it) {
        if (!empty($it['resolved_kapan_id'])) {
            $uniqueKapanIds[(int)$it['resolved_kapan_id']] = true;
        }
        $itSoldPcs = (int)($it['sold_pcs'] ?? 0);
        $itSoldWt  = (float)($it['sold_weight'] ?? 0);
        $itRetPcs  = (int)($it['returned_pcs'] ?? 0);
        $itRetWt   = (float)($it['returned_weight'] ?? 0);
        $itPendPcs = max(0, (int)$it['issued_pcs'] - $itSoldPcs - $itRetPcs);
        $itPendWt  = max(0.0, round((float)$it['issued_weight'] - $itSoldWt - $itRetWt, 3));

        $totalAssignedPcs    += (int)$it['issued_pcs'];
        $totalAssignedWeight += (float)$it['issued_weight'];
        $totalSoldPcs        += $itSoldPcs;
        $totalSoldWeight     += $itSoldWt;
        $totalReceivedPcs    += $itRetPcs;
        $totalReceivedWeight += $itRetWt;

        if ($it['status'] !== 'returned' && $itPendWt > 0.0005 && $itPendPcs > 0) {
            $totalPendingWeight += $itPendWt;
            $hasPendingSalesItems = true;
        }
    }
    $totalKapansCount = count($uniqueKapanIds) > 0 ? count($uniqueKapanIds) : count($polishJangadItems);
}

$brokers = [];
$bankAccounts = [];
$defaultSaleInv = '';
if (empty($kapanAssignments) && $hasPendingSalesItems) {
    $brokers = get_parties_by_role('polish_sale_broker', 'broker');
    $bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    $defaultSaleInv = generate_next_code('P-INV', 'polish_sales', 'invoice_no');
}

$pageTitle = 'Jangad Return & Splitting: ' . e($jangad['jangad_no']);
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    empty($kapanAssignments) ? 'Sales Jangad જમા & વેચાણ સેટલમેન્ટ (Return / Sold)' : 'Jangad માલ જમા & ડાયમંડ વિભાજન (Return & Splitting)',
    'વાઉચર: ' . $jangad['jangad_no'] . ' • પાર્ટી: ' . $jangad['party_name'],
    'rotate-left',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/jangad/view.php?id=' . $jangad['id'] . '" class="btn btn-outline-primary btn-sm px-3">' . svg_icon('eye', 'me-1') . 'વાઉચર જુઓ</a>
        <a href="' . BASE_URL . '/modules/jangad/return.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'બીજો Jangad પસંદ કરો</a>
    </div>',
    'primary'
) ?>

<!-- Top Voucher Identity Pill Bar -->
<div class="card-erp p-3 mb-3 bg-white border">
    <div class="row g-2 align-items-center">
        <div class="col-6 col-md-3">
            <span class="small text-muted d-block">Jangad નંબર</span>
            <span class="fw-bold font-mono fs-6 text-dark"><?= e($jangad['jangad_no']) ?></span>
        </div>
        <div class="col-6 col-md-3">
            <span class="small text-muted d-block">વેન્ડર / પાર્ટી</span>
            <strong class="text-dark"><?= e($jangad['party_name']) ?></strong>
        </div>
        <div class="col-6 col-md-3">
            <span class="small text-muted d-block">કેટેગરી / સેવા</span>
            <span class="badge bg-light text-dark border font-mono"><?= e($jangad['category']) ?></span>
        </div>
        <div class="col-6 col-md-3 text-md-end">
            <span class="small text-muted d-block">વાઉચર સ્થિતિ</span>
            <?php if ($jangad['status'] === 'completed'): ?>
                <span class="badge bg-success-subtle text-success border fw-bold"><?= svg_icon('check', 'me-1') ?>સંપૂર્ણ જમા (Completed)</span>
            <?php elseif ($jangad['status'] === 'partially_returned'): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border fw-bold"><?= svg_icon('hourglass-half', 'me-1') ?>અંશતઃ પરત (Partially Returned)</span>
            <?php else: ?>
                <span class="badge bg-primary-subtle text-primary border fw-bold">ઇસ્યુ કરેલ (Issued / Pending)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (empty($kapanAssignments) && !empty($polishJangadItems)): ?>
<!-- ================= SECTION: POLISH SALES JANGAD SETTLEMENT (SOLD VS RETURN-TO-KAPAN) ================= -->
<div class="erp-form-card mb-4">
    <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="header-title mb-0">
                <span class="header-icon icon-green"><?= svg_icon('gem') ?></span>
                એક જાંગડમાંથી વેચાણ (Sold) અને તે જ કાપન સ્ટોકમાં પરત (Return to Kapan Stock)
            </h6>
            <div class="small text-muted mt-1">
                <?= svg_icon('info-circle', 'text-primary me-1') ?>
                જે હીરા વેચાયા હોય તે <strong>વેચાણ (Sold)</strong> માં અને બાકી રહેલા હીરા <strong>પરત (Return)</strong> માં દાખલ કરો — પરત માલ સીધો જે-તે <strong>કાપનના પોલિશ સ્ટોક</strong> માં પાછો જમા થશે.
            </div>
        </div>
        <?php if ($hasPendingSalesItems): ?>
            <div class="d-flex flex-wrap gap-1.5">
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" onclick="applyAllSalesJangad('sold')">
                    <?= svg_icon('cart-shopping', 'me-1') ?>બધા વેચાણ (All Sold)
                </button>
                <button type="button" class="btn btn-sm btn-outline-success fw-semibold" onclick="applyAllSalesJangad('return')">
                    <?= svg_icon('rotate-left', 'me-1') ?>બધા કાપન પરત (All Return)
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyAllSalesJangad('clear')">
                    <?= svg_icon('eraser', 'me-1') ?>Reset
                </button>
            </div>
        <?php endif; ?>
    </div>
    <div class="erp-card-body p-0">
        <form method="POST" action="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $jangadId ?>" id="settleJangadForm">
            <?= csrf_field() ?>
            <input type="hidden" name="jangad_id" value="<?= $jangadId ?>">
            <input type="hidden" name="sub_action" value="settle_sales_jangad">

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>પોલિશ પેકેટ & મૂળ કાપન</th>
                            <th class="text-center">ઇસ્યુ (Pcs / Ct)</th>
                            <th class="text-center">વેચાણ થયેલ (Sold)</th>
                            <th class="text-center">કાપન પરત (Ret)</th>
                            <th class="text-center">બાકી (Pending)</th>
                            <th>સ્થિતિ</th>
                            <th class="pe-3" style="min-width: 410px;">આજનું સેટલમેન્ટ (વેચાણ &amp; કાપન સ્ટોક પરત)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($polishJangadItems as $idx => $it):
                            $itId      = (int)$it['id'];
                            $issPcs    = (int)$it['issued_pcs'];
                            $issWt     = (float)$it['issued_weight'];
                            $soldPcs   = (int)($it['sold_pcs'] ?? 0);
                            $soldWt    = (float)($it['sold_weight'] ?? 0);
                            $retPcs    = (int)($it['returned_pcs'] ?? 0);
                            $retWt     = (float)($it['returned_weight'] ?? 0);
                            $pendPcs   = max(0, $issPcs - $soldPcs - $retPcs);
                            $pendWt    = max(0.0, round($issWt - $soldWt - $retWt, 3));
                            $askRate   = (float)($it['charges'] ?? 0);
                            $isSettled = ($it['status'] === 'returned' || $pendWt <= 0.0005 || $pendPcs <= 0);
                            $kapanCode = trim((string)($it['kapan_code'] ?? ''));
                            $kapanId   = (int)($it['resolved_kapan_id'] ?? 0);
                        ?>
                            <tr class="<?= $isSettled ? 'bg-light' : '' ?>"
                                data-item-row="<?= $itId ?>"
                                data-max-pcs="<?= $pendPcs ?>"
                                data-max-wt="<?= number_format($pendWt, 3, '.', '') ?>"
                                data-default-rate="<?= $askRate > 0 ? number_format($askRate, 2, '.', '') : '0' ?>"
                                data-kapan-label="<?= e($kapanCode !== '' ? $kapanCode : 'Polish Stock') ?>">
                                <td class="ps-3 font-mono text-muted"><?= $idx + 1 ?></td>
                                <td>
                                    <strong class="text-dark d-block"><?= e($it['description']) ?></strong>
                                    <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                        <?php if ($kapanId > 0 && $kapanCode !== ''): ?>
                                            <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= $kapanId ?>" class="badge bg-secondary-subtle text-dark border text-decoration-none font-mono small" title="આ કાપન સ્ટોક જુઓ">
                                                <?= svg_icon('boxes-stacked', 'text-primary me-1') ?>કાપન: <?= e($kapanCode) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border font-mono small">Direct Polish Stock</span>
                                        <?php endif; ?>
                                        <?php if (!empty($it['gia_number']) && strpos((string)$it['description'], (string)$it['gia_number']) === false): ?>
                                            <span class="badge bg-info-subtle text-info border font-mono small">GIA: <?= e($it['gia_number']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center font-mono">
                                    <span class="fw-bold"><?= $issPcs ?> Pcs</span> / <span class="text-dark fw-bold"><?= format_carat($issWt) ?></span>
                                    <?php if ($askRate > 0): ?>
                                        <small class="text-muted d-block">માંગ ભાવ: <?= format_inr($askRate) ?>/Ct</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono">
                                    <?php if ($soldPcs > 0 || $soldWt > 0): ?>
                                        <span class="fw-bold text-primary"><?= $soldPcs ?> Pcs / <?= format_carat($soldWt) ?></span>
                                        <?php if (!empty($it['linked_invoice_no'])): ?>
                                            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=sell&search=<?= urlencode((string)$it['linked_invoice_no']) ?>" class="badge bg-primary-subtle text-primary border text-decoration-none d-inline-block mt-1">
                                                <?= svg_icon('file-invoice', 'me-1') ?><?= e($it['linked_invoice_no']) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono">
                                    <?php if ($retPcs > 0 || $retWt > 0): ?>
                                        <span class="fw-bold text-success"><?= $retPcs ?> Pcs / <?= format_carat($retWt) ?></span>
                                        <small class="text-success d-block"><?= e($kapanCode !== '' ? $kapanCode . ' સ્ટોકમાં' : 'સ્ટોકમાં જમા') ?></small>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono">
                                    <strong class="<?= $pendWt > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $pendPcs ?> Pcs / <?= format_carat($pendWt) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($isSettled): ?>
                                        <span class="badge bg-success-subtle text-success border">પૂર્ણ (Settled)</span>
                                    <?php elseif ($it['status'] === 'partially_returned'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">અંશતઃ</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">બાકી</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3">
                                    <?php if (!$isSettled): ?>
                                        <div class="d-flex flex-column gap-1.5 py-1">
                                            <!-- Row 1: Sold Inputs -->
                                            <div class="d-flex flex-wrap align-items-center gap-1 p-1.5 rounded-2 border" style="background: #f0f7ff; border-color: #bfdbfe !important;">
                                                <span class="badge bg-primary text-white" style="min-width: 58px;">વેચાણ</span>
                                                <input type="number" name="sold_pcs[<?= $itId ?>]" value="0" min="0" max="<?= $pendPcs ?>"
                                                       class="form-control form-control-sm font-mono text-center sj-input sj-sold-pcs"
                                                       data-item-id="<?= $itId ?>" style="width: 58px;" title="વેચાયેલા નંગ (Sold Pcs)">
                                                <input type="number" step="0.01" name="sold_weight[<?= $itId ?>]" value="0.000" min="0" max="<?= number_format($pendWt, 3, '.', '') ?>"
                                                       class="form-control form-control-sm font-mono text-end sj-input sj-sold-wt"
                                                       data-item-id="<?= $itId ?>" style="width: 82px;" title="વેચાયેલું વજન (Sold Ct)">
                                                <input type="number" step="0.01" name="sold_rate[<?= $itId ?>]" value="<?= $askRate > 0 ? number_format($askRate, 2, '.', '') : '' ?>" min="0"
                                                       placeholder="₹/Ct"
                                                       class="form-control form-control-sm font-mono text-end sj-input sj-sold-rate"
                                                       data-item-id="<?= $itId ?>" style="width: 92px;" title="વેચાણ ભાવ (₹/Ct)">
                                                <button type="button" class="btn btn-xs btn-outline-primary fw-semibold ms-auto" onclick="fillRowMode(<?= $itId ?>, 'sold')" title="બાકી બધો માલ વેચાણમાં ભરો">
                                                    બધું Sale
                                                </button>
                                            </div>
                                            <!-- Row 2: Return-to-Kapan Inputs -->
                                            <div class="d-flex flex-wrap align-items-center gap-1 p-1.5 rounded-2 border" style="background: #f0fdf4; border-color: #bbf7d0 !important;">
                                                <span class="badge bg-success text-white" style="min-width: 58px;">પરત</span>
                                                <input type="number" name="return_pcs[<?= $itId ?>]" value="0" min="0" max="<?= $pendPcs ?>"
                                                       class="form-control form-control-sm font-mono text-center sj-input sj-ret-pcs"
                                                       data-item-id="<?= $itId ?>" style="width: 58px;" title="પરત નંગ (Return Pcs)">
                                                <input type="number" step="0.01" name="return_weight[<?= $itId ?>]" value="0.000" min="0" max="<?= number_format($pendWt, 3, '.', '') ?>"
                                                       class="form-control form-control-sm font-mono text-end sj-input sj-ret-wt"
                                                       data-item-id="<?= $itId ?>" style="width: 82px;" title="પરત વજન (Return Ct)">
                                                <span class="small text-success fw-semibold text-truncate" style="max-width: 95px;" title="પરત થનાર કાપન સ્ટોક">
                                                    → <?= e($kapanCode !== '' ? $kapanCode : 'Stock') ?>
                                                </span>
                                                <div class="ms-auto d-flex gap-1">
                                                    <button type="button" class="btn btn-xs btn-outline-success fw-semibold" onclick="fillRowMode(<?= $itId ?>, 'return')" title="બાકી બધો માલ કાપન સ્ટોક પરતમાં ભરો">
                                                        બધું પરત
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-success" onclick="submitQuickReturn(<?= $itId ?>, <?= $pendPcs ?>, <?= number_format($pendWt, 3, '.', '') ?>)" title="આ લાઇન સીધી જ <?= e($kapanCode ?: 'Polish Stock') ?> માં જમા કરો">
                                                        <?= svg_icon('rotate-left') ?>
                                                    </button>
                                                    <?php if (!empty($it['polish_stock_id'])): ?>
                                                        <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?jangad_id=<?= $jangadId ?>&jangad_item_id=<?= $itId ?>" class="btn btn-xs btn-outline-dark" title="સિંગલ વિગતવાર વેચાણ બિલ પેજ ખોલો">
                                                            <?= svg_icon('arrow-up-right-from-square') ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-success small fw-semibold"><?= svg_icon('check-double', 'me-1') ?>હિસાબ પૂર્ણ</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($hasPendingSalesItems): ?>
                <!-- Live Batch Preview + Sale Invoice Details Box -->
                <div class="p-3 border-top bg-light">
                    <div class="row g-2 align-items-center mb-3 p-2.5 rounded-3 bg-white border">
                        <div class="col-12 col-md-4">
                            <span class="small text-muted d-block">આજનું કુલ વેચાણ (Selected Sold)</span>
                            <strong class="font-mono fs-6 text-primary" id="sjTotSoldDisp">0 Pcs / 0.000 Ct • ₹0.00</strong>
                        </div>
                        <div class="col-12 col-md-5">
                            <span class="small text-muted d-block">જે-તે કાપન સ્ટોકમાં પાછો જમા (Return to Kapan)</span>
                            <strong class="font-mono fs-6 text-success" id="sjTotRetDisp">0 Pcs / 0.000 Ct</strong>
                            <small class="text-muted d-block font-mono" id="sjRetKapanBreakdown">—</small>
                        </div>
                        <div class="col-12 col-md-3 text-md-end">
                            <button type="submit" class="btn btn-primary fw-bold px-3 w-100 w-md-auto shadow-sm">
                                <?= svg_icon('check-double', 'me-1') ?>સેટલમેન્ટ સેવ કરો
                            </button>
                        </div>
                    </div>

                    <!-- Sale Invoice Details (Only needed when Sold qty > 0) -->
                    <div id="sjInvoiceBox" class="p-3 rounded-3 bg-white border" style="display: none; border-color: #93c5fd !important;">
                        <h6 class="fw-bold text-primary mb-2.5">
                            <?= svg_icon('file-invoice-dollar', 'me-1') ?>વેચાણ બિલ &amp; પેમેન્ટ વિગતો (Auto Polish Sale Invoice)
                        </h6>
                        <div class="row g-2.5">
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">ઇન્વોઇસ નંબર</label>
                                <input type="text" name="invoice_no" class="form-control form-control-sm font-mono fw-bold" value="<?= e($defaultSaleInv) ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">વેચાણ તારીખ</label>
                                <input type="date" name="sale_date" class="form-control form-control-sm font-mono" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">દલાલ (Broker)</label>
                                <select name="broker_id" class="form-select form-select-sm">
                                    <option value="">સીધું વેચાણ (No Broker)</option>
                                    <?php foreach ($brokers as $br): ?>
                                        <option value="<?= (int)$br['id'] ?>" <?= ((int)$jangad['broker_id'] === (int)$br['id']) ? 'selected' : '' ?>>
                                            <?= e($br['party_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">દલાલી (%)</label>
                                <input type="number" step="0.01" name="brokerage_percent" class="form-control form-control-sm font-mono text-end" value="0.00" min="0">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">A/C GST (₹)</label>
                                <input type="number" step="0.01" name="ac_gst_amount" id="sjAcGst" class="form-control form-control-sm font-mono text-end" value="0.00" min="0">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">મુદત (Due Days)</label>
                                <input type="number" name="due_days" class="form-control form-control-sm font-mono text-center" value="50" min="0">
                            </div>

                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold mb-1">પેમેન્ટ સ્થિતિ</label>
                                <select name="payment_status" id="sjPayStatus" class="form-select form-select-sm" onchange="toggleBatchReceivedInput()">
                                    <option value="pending">બાકી (Pending Receivable)</option>
                                    <option value="partial">અંશતઃ મળેલ (Partial Received)</option>
                                    <option value="paid">પૂર્ણ જમા (Full Paid)</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold mb-1">મળેલ રકમ (₹)</label>
                                <input type="number" step="0.01" name="received_amount" id="sjRecAmt" class="form-control form-control-sm font-mono text-end" value="0.00" min="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold mb-1">બેંક / રોકડ ખાતું</label>
                                <select name="bank_account_id" class="form-select form-select-sm">
                                    <option value="">ખાતું પસંદ કરો...</option>
                                    <?php foreach ($bankAccounts as $ba): ?>
                                        <option value="<?= (int)$ba['id'] ?>"><?= e($ba['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold mb-1">નોંધ (Remarks)</label>
                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="વેચાણ / પરત વિગત...">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </form>

        <!-- Separate Form for Single-Row Instant Return (Avoids Nested Forms) -->
        <form method="POST" action="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $jangadId ?>" id="quickReturnForm" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="jangad_id" value="<?= $jangadId ?>">
            <input type="hidden" name="sub_action" value="return_polish_item">
            <input type="hidden" name="item_id" id="qr_item_id" value="">
            <input type="hidden" name="return_pcs" id="qr_ret_pcs" value="">
            <input type="hidden" name="return_weight" id="qr_ret_wt" value="">
            <input type="hidden" name="notes" value="તાત્કાલિક કાપન સ્ટોકમાં જમા">
        </form>
    </div>
</div>

<?php else: ?>
<!-- ================= SECTION: KAPAN-WISE RETURN ACCORDIONS (LEGACY) ================= -->
<div class="erp-form-card mb-4">
    <div class="erp-card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="header-title mb-0">
            <span class="header-icon"><?= svg_icon('table-list') ?></span>
            Kapan વાઇઝ ડાયમંડ રિટર્ન અને વિભાજન (Return & Splitting Management)
        </h6>
        <div class="small text-muted">
            <?= svg_icon('info-circle', 'text-primary me-1') ?>
            એક અસાઈન નંબર સામે મલ્ટિપલ સબ નંબર્સ (દા.ત. 1.1, 1.2) જમા કરી શકાય છે.
        </div>
    </div>
    <div class="erp-card-body p-3">
        <?php if (empty($kapanAssignments)): ?>
            <div class="alert alert-warning mb-0 py-3 text-center">
                <?= svg_icon('triangle-exclamation', 'me-1') ?>
                આ Jangad વાઉચરમાં કોઈ આઇટમ ઉપલબ્ધ નથી.
            </div>
        <?php else: ?>
            <div class="accordion" id="kapanReturnAccordions">
                <?php foreach ($kapanAssignments as $idx => $kItem): 
                    $accId = "ret_acc_" . $idx;
                    $collapseId = "ret_col_" . $idx;

                    // Kapan sub-metrics
                    $kAssignedWt = 0.0;
                    $kReceivedWt = 0.0;
                    $kPendingWt  = 0.0;
                    $kTopsWt     = 0.0;
                    foreach ($kItem['assignments'] as $asgn) {
                        $kAssignedWt += (float)$asgn['assigned_weight'];
                        $kReceivedWt += (float)$asgn['returned_weight'];
                        $kTopsWt     += (float)$asgn['tops_weight'];
                        if ($asgn['status'] !== 'completed') {
                            $kPendingWt += max(0.0, (float)$asgn['assigned_weight'] - (float)$asgn['returned_weight']);
                        }
                    }
                ?>
                    <div class="accordion-item border rounded-3 mb-3 shadow-xs">
                        <h2 class="accordion-header p-2.5 bg-light rounded-top d-flex align-items-center justify-content-between">
                            <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?> fw-bold py-1 px-2 bg-transparent shadow-none text-dark d-flex align-items-center gap-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                                <?= svg_icon('boxes-stacked', 'text-primary') ?>
                                <span><?= e($kItem['kapan_code']) ?> — <?= e($kItem['kapan_name']) ?></span>
                                <span class="badge bg-primary-subtle text-primary border font-mono ms-2">
                                    ફાળવેલ: <?= format_carat($kAssignedWt) ?>
                                </span>
                                <span class="badge bg-success-subtle text-success border font-mono ms-1">
                                    જમા: <?= format_carat($kReceivedWt) ?>
                                </span>
                                <?php if ($kPendingWt > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger border font-mono ms-1">
                                        બાકી: <?= format_carat($kPendingWt) ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                        </h2>
                        <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>">
                            <div class="accordion-body p-3">
                                <div class="table-responsive mb-2" style="overflow-x: auto !important;">
                                    <table class="table table-bordered table-sm align-middle mb-0" style="table-layout: fixed; width: 100%; min-width: 950px;">
                                        <colgroup>
                                            <col style="width: 12%;">
                                            <col style="width: 14%;">
                                            <col style="width: 26%;">
                                            <col style="width: 14%;">
                                            <col style="width: 14%;">
                                            <col style="width: 10%;">
                                            <col style="width: 10%;">
                                        </colgroup>
                                        <thead class="table-light small">
                                            <tr>
                                                <th>Assign No.</th>
                                                <th class="text-end">Assigned Wt</th>
                                                <th>Sub Number(s) & જમા વિગતો</th>
                                                <th class="text-end">Total Received</th>
                                                <th class="text-end">Pending Wt</th>
                                                <th class="text-end">Tops (Ct)</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($kItem['assignments'] as $asgn): 
                                                $assignedWeight = (float)$asgn['assigned_weight'];
                                                $returnedWeight = (float)$asgn['returned_weight'];
                                                $topsWeight     = (float)$asgn['tops_weight'];
                                                $isCompleted    = ($asgn['status'] === 'completed');
                                                $pendingWeight  = $isCompleted ? 0.000 : max(0.0, round($assignedWeight - $returnedWeight, 3));
                                                $subReturns     = $asgn['returns'] ?? [];
                                                $nextSubNumber  = generate_next_sub_number((int)$asgn['id'], (string)$asgn['assign_number']);
                                            ?>
                                                <tr class="<?= $isCompleted ? 'bg-light' : '' ?>">
                                                    <td>
                                                        <span class="badge bg-primary text-white font-mono px-2 py-1">
                                                            Assign #<?= e($asgn['assign_number']) ?>
                                                        </span>
                                                        <small class="text-muted d-block font-mono mt-0.5" style="font-size: 0.72rem;">
                                                            <?= e($asgn['packet_no']) ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-end font-mono fw-bold text-dark">
                                                        <?= format_carat($assignedWeight) ?>
                                                    </td>
                                                    <td>
                                                        <?php if (empty($subReturns)): ?>
                                                            <span class="text-muted small fst-italic">કોઈ રિટર્ન નથી મળેલ</span>
                                                        <?php else: ?>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <?php foreach ($subReturns as $sr): ?>
                                                                    <span class="badge bg-white text-dark border font-mono small py-1 px-1.5 shadow-xs" title="જમા તારીખ: <?= format_date($sr['received_at']) ?><?= !empty($sr['notes']) ? ' • ' . e($sr['notes']) : '' ?>">
                                                                        <?= svg_icon('gem', 'text-success me-1') ?>Sub <strong><?= e($sr['sub_number']) ?></strong>: <?= format_carat($sr['received_weight']) ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end font-mono fw-bold text-success">
                                                        <?= format_carat($returnedWeight) ?>
                                                    </td>
                                                    <td class="text-end font-mono fw-bold <?= $pendingWeight > 0 ? 'text-danger' : 'text-muted' ?>">
                                                        <?= format_carat($pendingWeight) ?>
                                                    </td>
                                                    <td class="text-end font-mono fw-bold <?= $topsWeight > 0 ? 'text-secondary' : 'text-muted' ?>">
                                                        <?= format_carat($topsWeight) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($isCompleted): ?>
                                                            <div class="d-flex justify-content-center align-items-center gap-1">
                                                                <span class="badge bg-success text-white small px-2 py-1">
                                                                    <?= svg_icon('check', 'me-1') ?>Closed
                                                                </span>
                                                                <a href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= $asgn['original_diamond_id'] ?>" class="btn btn-outline-primary btn-sm py-0.5 px-1.5" title="મુસાફરી હિસાબ (Diamond Journey)">
                                                                    <?= svg_icon('timeline') ?>
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="dropdown">
                                                                <button class="btn btn-sm btn-primary dropdown-toggle py-0.5 px-2" type="button" data-bs-toggle="dropdown">
                                                                    જમા / એક્શન
                                                                </button>
                                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm small">
                                                                    <li>
                                                                        <button type="button" class="dropdown-item py-1.5" onclick="openSubReturnModal(<?= $asgn['id'] ?>, '<?= addslashes(e($asgn['assign_number'])) ?>', '<?= addslashes(e($asgn['packet_no'])) ?>', <?= $assignedWeight ?>, <?= $returnedWeight ?>, <?= $pendingWeight ?>, '<?= $nextSubNumber ?>')">
                                                                            <?= svg_icon('plus', 'text-success me-2') ?>Sub Diamond જમા કરો
                                                                        </button>
                                                                    </li>
                                                                    <li>
                                                                        <a class="dropdown-item py-1.5 text-primary" href="<?= BASE_URL ?>/modules/process/diamond_journey.php?id=<?= $asgn['original_diamond_id'] ?>" target="_blank">
                                                                            <?= svg_icon('timeline', 'me-2') ?>Diamond Journey (મુસાફરી)
                                                                        </a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider my-1"></li>
                                                                    <li>
                                                                        <button type="button" class="dropdown-item py-1.5 text-danger fw-bold" onclick="openFinalizeTopsModal(<?= $asgn['id'] ?>, '<?= addslashes(e($asgn['assign_number'])) ?>', <?= $assignedWeight ?>, <?= $returnedWeight ?>, <?= $pendingWeight ?>)">
                                                                            <?= svg_icon('check-double', 'text-danger me-2') ?>Complete & Classify Diff (ઘટ/Tops)
                                                                        </button>
                                                                    </li>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light fw-bold" style="border-top: 2px solid #cbd5e1;">
                                            <tr>
                                                <td>Kapan સબટોટલ:</td>
                                                <td class="text-end font-mono text-dark"><?= format_carat($kAssignedWt) ?></td>
                                                <td class="small text-muted">કુલ રિટર્ન હિસાબ</td>
                                                <td class="text-end font-mono text-success"><?= format_carat($kReceivedWt) ?></td>
                                                <td class="text-end font-mono text-danger"><?= format_carat($kPendingWt) ?></td>
                                                <td class="text-end font-mono text-secondary"><?= format_carat($kTopsWt) ?></td>
                                                <td class="text-center small text-muted">-</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ================= SECTION: OVERALL JANGAD SUMMARY DASHBOARD ================= -->
<div class="card-erp mb-4 p-3.5" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 18px; box-shadow: 0 4px 16px -4px rgba(6, 52, 54, 0.05);">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
        <div>
            <h6 class="fw-bold text-dark mb-0 fs-6">
                <?= svg_icon('chart-pie', 'text-primary me-2') ?>Jangad આખરી સમરી ડેશબોર્ડ (Voucher Live Summary)
            </h6>
            <small class="text-muted">રીયલ-ટાઇમ ગણતરી: ફાળવેલ હીરા, પરત જમા, બાકી પેન્ડિંગ વજન અને Tops</small>
        </div>
        <span class="badge bg-primary-subtle text-primary border font-mono px-2.5 py-1 rounded-pill">
            <?= svg_icon('bolt', 'me-1') ?>Live Real-Time Totals
        </span>
    </div>

    <!-- All 7 Summary Metric Cards in 1 Single Line -->
    <div class="d-flex gap-2 mb-3 pb-1 flex-nowrap overflow-x-auto align-items-stretch" style="scrollbar-width: thin; -webkit-overflow-scrolling: touch;">
        <!-- Card 1: ૧. કુલ Kapans -->
        <div style="flex: 1 1 0; min-width: 120px;">
            <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૧. કુલ Kapans</span>
                <div class="font-mono fs-5 fw-bold text-dark"><?= $totalKapansCount ?></div>
            </div>
        </div>

        <!-- Card 2: ૨. કુલ ઇશ્યુ નંગ (Issued Pcs) -->
        <div style="flex: 1 1 0; min-width: 130px;">
            <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૨. કુલ ઇશ્યુ નંગ</span>
                <div class="font-mono fs-5 fw-bold text-dark"><?= $totalAssignedPcs ?> Pcs</div>
            </div>
        </div>

        <!-- Card 3: ૩. કુલ ઇશ્યુ વજન (Issued Wt) -->
        <div style="flex: 1 1 0; min-width: 135px;">
            <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૩. કુલ ઇશ્યુ વજન</span>
                <div class="font-mono fs-5 fw-bold" style="color: #064e3b;"><?= format_carat($totalAssignedWeight) ?></div>
            </div>
        </div>

        <!-- Card 4: ૪. પરત આવેલ ડાયમંડ્સ (PCs) -->
        <div style="flex: 1 1 0; min-width: 135px;">
            <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">
                    <?= empty($kapanAssignments) ? '૪. કાપનમાં પરત' : '૪. પરત હીરા' ?>
                </span>
                <div class="font-mono fs-5 fw-bold text-success"><?= $totalReceivedPcs ?> Pcs</div>
            </div>
        </div>

        <!-- Card 5: ૫. પરત મળેલ વજન (Received Wt) -->
        <div style="flex: 1 1 0; min-width: 135px;">
            <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">
                    <?= empty($kapanAssignments) ? '૫. પરત વજન' : '૫. પરત વજન' ?>
                </span>
                <div class="font-mono fs-5 fw-bold text-success"><?= format_carat($totalReceivedWeight) ?></div>
            </div>
        </div>

        <!-- Card 6: ૬. પેન્ડિંગ વજન (Pending Weight) - Highlighted Card -->
        <div style="flex: 1 1 0; min-width: 140px;">
            <div class="p-2.5 rounded-3 border h-100 shadow-xs" style="background: #fffbeb; border-color: #fde68a !important;">
                <span class="small d-block mb-1 fw-bold text-truncate" style="font-size: 0.78rem; color: #92400e;">૬. પેન્ડિંગ વજન</span>
                <div class="font-mono fs-5 fw-bold" style="color: #dc2626;"><?= format_carat($totalPendingWeight) ?></div>
            </div>
        </div>

        <!-- Card 7: ૭. વેચાણ થયેલ (Sold) OR Tops (Legacy) -->
        <div style="flex: 1 1 0; min-width: 150px;">
            <?php if (empty($kapanAssignments)): ?>
                <div class="p-2.5 rounded-3 border h-100 shadow-xs" style="background: #eff6ff; border-color: #bfdbfe !important;">
                    <span class="small d-block mb-1 fw-bold text-truncate" style="font-size: 0.78rem; color: #1e40af;">૭. વેચાણ માલ</span>
                    <div class="font-mono fs-5 fw-bold text-primary"><?= format_carat($totalSoldWeight) ?> <small class="fs-6 fw-normal">(<?= $totalSoldPcs ?> Pcs)</small></div>
                </div>
            <?php else: ?>
                <div class="p-2.5 rounded-3 border bg-white h-100 shadow-xs" style="border-color: #e2e8f0 !important;">
                    <span class="text-muted small d-block mb-1 text-truncate" style="font-size: 0.78rem; font-weight: 500;">૭. માન્ય Tops</span>
                    <div class="font-mono fs-5 fw-bold text-secondary"><?= format_carat($totalFinalizedTops) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= MODAL: RECORD SUB DIAMOND RETURN ================= -->
<div class="modal fade" id="subReturnModal" tabindex="-1" aria-labelledby="subReturnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-dark" id="subReturnModalLabel">
                    <?= svg_icon('plus-circle', 'text-primary me-2') ?>Sub Diamond પરત જમા કરો
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= BASE_URL ?>/modules/jangad/return.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="jangad_id" value="<?= $jangadId ?>">
                <input type="hidden" name="sub_action" value="record_sub_return">
                <input type="hidden" name="assignment_id" id="modal_sub_assignment_id" value="">

                <div class="modal-body">
                    <!-- Diamond Info Pill -->
                    <div class="p-2.5 rounded-3 border bg-light mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-primary fs-6 px-2.5 py-1" id="modal_sub_assign_badge">Assign #1</span>
                                <small class="text-muted ms-1 font-mono" id="modal_sub_packet_text">KPNA-001</small>
                            </div>
                            <div class="text-end">
                                <span class="small text-muted d-block">મૂળ વજન: <strong class="text-dark font-mono" id="modal_sub_assigned_wt">0.000 Ct</strong></span>
                                <span class="small text-muted d-block">હાલ બાકી: <strong class="text-danger font-mono" id="modal_sub_pending_wt">0.000 Ct</strong></span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Sub Number (સિસ્ટમ જનરેટેડ) *</label>
                        <input type="text" id="modal_sub_number_disp" class="form-control font-mono fw-bold bg-light" readonly>
                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">હાયરાર્કીકલ સબ-નંબર આપમેળે અસાઇન થાય છે.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-primary">જમા વજન (Received Weight Carats) *</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0.001" name="received_weight" id="modal_received_weight_input" class="form-control font-mono fw-bold fs-5 text-primary" placeholder="0.00" required>
                            <span class="input-group-text font-mono fw-bold text-primary">Ct</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">જમા તારીખ (Return Date) *</label>
                        <input type="date" name="received_date" class="form-control font-mono" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold text-muted">નોંધ / રિમાર્ક્સ (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="દા.ત. Part A received, 4P Polish...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <?= svg_icon('check', 'me-1') ?>માલ જમા કરો
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: FINALIZE ASSIGNMENT & CALCULATE TOPS ================= -->
<div class="modal fade" id="finalizeTopsModal" tabindex="-1" aria-labelledby="finalizeTopsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="finalizeTopsModalLabel">
                    <?= svg_icon('calculator', 'me-2') ?>Complete Assignment & Calculate Tops
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= BASE_URL ?>/modules/jangad/return.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="jangad_id" value="<?= $jangadId ?>">
                <input type="hidden" name="sub_action" value="complete_assignment_tops">
                <input type="hidden" name="assignment_id" id="modal_finalize_assignment_id" value="">

                <div class="modal-body py-3">
                    <p class="mb-2">
                        શું તમે ખરેખર <strong class="text-primary font-mono" id="modal_finalize_assign_text">Assign #1</strong> ને પૂર્ણ (Completed / Closed) કરવા માંગો છો?
                    </p>

                    <div class="p-3 rounded-3 border bg-light mb-3">
                        <div class="row g-2 small">
                            <div class="col-6">
                                <span class="text-muted d-block">અસાઇન વજન:</span>
                                <strong class="font-mono text-dark" id="modal_finalize_assigned_wt">0.000 Ct</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block">પરત મળેલ વજન:</span>
                                <strong class="font-mono text-success" id="modal_finalize_returned_wt">0.000 Ct</strong>
                            </div>
                            <div class="col-12 pt-2 border-top">
                                <span class="text-dark fw-bold d-block">તફાવત વજન (Difference Weight):</span>
                                <span class="fs-4 fw-bold font-mono text-danger" id="modal_finalize_tops_wt">0.000 Ct</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">તફાવત વજનનું વર્ગીકરણ (Classification) *</label>
                        <select name="diff_classification" class="form-select font-mono" required>
                            <option value="loss">ઘટ / Process Loss (સામાન્ય ઘસારો - સ્ટોકમાં જમા નહીં થાય)</option>
                            <option value="tops">Tops / રદિયો (Scrap તરીકે Tops ઇન્વેન્ટરીમાં જમા કરો)</option>
                            <option value="other">અન્ય તફાવત / Other Difference</option>
                        </select>
                        <small class="text-muted" style="font-size: 0.78rem;">
                            નોંધ: પોલિશિંગ કે લેસર કટિંગના સામાન્ય વજન ઘટાડા માટે 'ઘટ (Process Loss)' પસંદ કરવું.
                        </small>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <?= svg_icon('shield-halved', 'me-1') ?>
                        આ એક્શન કરવાથી ડાયમંડ વર્તમાન પ્રોસેસમાંથી ક્લોઝ થઈને વાસ્તવિક વજન સાથે <strong>Surat Stock</strong> માં ઉપલબ્ધ બનશે અને આગળના સ્ટેજ માટે તૈયાર થશે.
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <?= svg_icon('check-double', 'me-1') ?>હા, ક્લોઝ કરો & વર્ગીકરણ સેવ કરો
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ==================== SALES JANGAD BATCH SETTLEMENT (SOLD + RETURN-TO-KAPAN) ====================
function getSalesRowRefs(itemId) {
    const tr = document.querySelector(`tr[data-item-row="${itemId}"]`);
    if (!tr) return null;
    return {
        tr,
        maxPcs: parseInt(tr.dataset.maxPcs || '0', 10),
        maxWt: parseFloat(tr.dataset.maxWt || '0'),
        defaultRate: parseFloat(tr.dataset.defaultRate || '0'),
        kapanLabel: tr.dataset.kapanLabel || 'Polish Stock',
        sPcsEl: tr.querySelector('.sj-sold-pcs'),
        sWtEl: tr.querySelector('.sj-sold-wt'),
        sRateEl: tr.querySelector('.sj-sold-rate'),
        rPcsEl: tr.querySelector('.sj-ret-pcs'),
        rWtEl: tr.querySelector('.sj-ret-wt')
    };
}

function fillRowMode(itemId, mode) {
    const r = getSalesRowRefs(itemId);
    if (!r || !r.sPcsEl || !r.rPcsEl) return;

    if (mode === 'sold') {
        r.sPcsEl.value = r.maxPcs;
        r.sWtEl.value = r.maxWt.toFixed(3);
        if ((!r.sRateEl.value || parseFloat(r.sRateEl.value) <= 0) && r.defaultRate > 0) {
            r.sRateEl.value = r.defaultRate.toFixed(2);
        }
        r.rPcsEl.value = 0;
        r.rWtEl.value = '0.000';
    } else if (mode === 'return') {
        r.sPcsEl.value = 0;
        r.sWtEl.value = '0.000';
        r.rPcsEl.value = r.maxPcs;
        r.rWtEl.value = r.maxWt.toFixed(3);
    } else {
        r.sPcsEl.value = 0;
        r.sWtEl.value = '0.000';
        r.rPcsEl.value = 0;
        r.rWtEl.value = '0.000';
    }
    updateSalesJangadPreview();
}

function applyAllSalesJangad(mode) {
    document.querySelectorAll('tr[data-item-row]').forEach(tr => {
        const id = tr.dataset.itemRow;
        if (tr.querySelector('.sj-sold-pcs')) {
            fillRowMode(id, mode);
        }
    });
}

function submitQuickReturn(itemId, defaultPcs, defaultWt) {
    const r = getSalesRowRefs(itemId);
    let pcs = defaultPcs;
    let wt = defaultWt;
    let kapanName = r ? r.kapanLabel : 'Polish Stock';

    if (r && r.rPcsEl && r.rWtEl) {
        const curPcs = parseInt(r.rPcsEl.value || '0', 10);
        const curWt = parseFloat(r.rWtEl.value || '0');
        if (curPcs > 0 && curWt > 0) {
            pcs = curPcs;
            wt = curWt;
        }
    }
    if (!confirm(`${pcs} Pcs (${wt.toFixed(3)} Ct) સીધા જ '${kapanName}' સ્ટોકમાં પાછા જમા કરવા છે?`)) {
        return;
    }
    document.getElementById('qr_item_id').value = itemId;
    document.getElementById('qr_ret_pcs').value = pcs;
    document.getElementById('qr_ret_wt').value = wt.toFixed(3);
    document.getElementById('quickReturnForm').submit();
}

let lastComputedBatchNet = 0;
function toggleBatchReceivedInput() {
    const st = document.getElementById('sjPayStatus');
    const recEl = document.getElementById('sjRecAmt');
    if (!st || !recEl) return;
    if (st.value === 'paid') {
        recEl.value = lastComputedBatchNet.toFixed(2);
        recEl.readOnly = true;
    } else if (st.value === 'pending') {
        recEl.value = '0.00';
        recEl.readOnly = false;
    } else {
        recEl.readOnly = false;
    }
}

function updateSalesJangadPreview() {
    let totSoldPcs = 0, totSoldWt = 0, totSaleAmt = 0;
    let totRetPcs = 0, totRetWt = 0;
    const kapanMap = {};

    document.querySelectorAll('tr[data-item-row]').forEach(tr => {
        const sPcsEl = tr.querySelector('.sj-sold-pcs');
        if (!sPcsEl) return;
        const sWtEl = tr.querySelector('.sj-sold-wt');
        const sRateEl = tr.querySelector('.sj-sold-rate');
        const rPcsEl = tr.querySelector('.sj-ret-pcs');
        const rWtEl = tr.querySelector('.sj-ret-wt');

        const sPcs = parseInt(sPcsEl.value || '0', 10);
        const sWt = parseFloat(sWtEl.value || '0');
        const sRate = parseFloat(sRateEl.value || '0');
        const rPcs = parseInt(rPcsEl.value || '0', 10);
        const rWt = parseFloat(rWtEl.value || '0');

        totSoldPcs += isNaN(sPcs) ? 0 : sPcs;
        totSoldWt += isNaN(sWt) ? 0 : sWt;
        totSaleAmt += (isNaN(sWt) ? 0 : sWt) * (isNaN(sRate) ? 0 : sRate);

        totRetPcs += isNaN(rPcs) ? 0 : rPcs;
        totRetWt += isNaN(rWt) ? 0 : rWt;
        if (!isNaN(rWt) && rWt > 0) {
            const kLbl = tr.dataset.kapanLabel || 'Polish Stock';
            kapanMap[kLbl] = (kapanMap[kLbl] || 0) + rWt;
        }
    });

    const acGstEl = document.getElementById('sjAcGst');
    const acGst = acGstEl ? (parseFloat(acGstEl.value || '0') || 0) : 0;
    lastComputedBatchNet = totSaleAmt + acGst;

    const soldDisp = document.getElementById('sjTotSoldDisp');
    const retDisp = document.getElementById('sjTotRetDisp');
    const retKapanEl = document.getElementById('sjRetKapanBreakdown');
    const invBox = document.getElementById('sjInvoiceBox');

    if (soldDisp) {
        soldDisp.innerText = `${totSoldPcs} Pcs / ${totSoldWt.toFixed(3)} Ct • ₹${totSaleAmt.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
    if (retDisp) {
        retDisp.innerText = `${totRetPcs} Pcs / ${totRetWt.toFixed(3)} Ct`;
    }
    if (retKapanEl) {
        const entries = Object.entries(kapanMap).map(([k, v]) => `${k}: ${v.toFixed(3)} Ct`);
        retKapanEl.innerText = entries.length > 0 ? ('જમા થનાર કાપન: ' + entries.join(' • ')) : '—';
    }
    if (invBox) {
        invBox.style.display = (totSoldPcs > 0 || totSoldWt > 0) ? 'block' : 'none';
    }
    const paySt = document.getElementById('sjPayStatus');
    if (paySt && paySt.value === 'paid') {
        const recEl = document.getElementById('sjRecAmt');
        if (recEl) recEl.value = lastComputedBatchNet.toFixed(2);
    }
}

document.addEventListener('input', function(e) {
    const el = e.target;
    if (el.classList.contains('sj-sold-pcs') || el.classList.contains('sj-ret-pcs')) {
        const r = getSalesRowRefs(el.dataset.itemId);
        if (r) {
            let sPcs = Math.max(0, Math.min(r.maxPcs, parseInt(r.sPcsEl.value || '0', 10) || 0));
            let rPcs = Math.max(0, Math.min(r.maxPcs, parseInt(r.rPcsEl.value || '0', 10) || 0));

            if (el.classList.contains('sj-sold-pcs')) {
                r.sPcsEl.value = sPcs;
                if (sPcs + rPcs > r.maxPcs) {
                    rPcs = Math.max(0, r.maxPcs - sPcs);
                    r.rPcsEl.value = rPcs;
                    if (rPcs === 0) r.rWtEl.value = '0.000';
                }
                const curRetWt = parseFloat(r.rWtEl.value || '0') || 0;
                const availWt = Math.max(0, r.maxWt - curRetWt);
                if (sPcs === 0) {
                    r.sWtEl.value = '0.000';
                } else if (sPcs === (r.maxPcs - rPcs)) {
                    r.sWtEl.value = availWt.toFixed(3);
                } else if (r.maxPcs > 0) {
                    r.sWtEl.value = Math.min(availWt, r.maxWt * (sPcs / r.maxPcs)).toFixed(3);
                }
            } else {
                r.rPcsEl.value = rPcs;
                if (sPcs + rPcs > r.maxPcs) {
                    sPcs = Math.max(0, r.maxPcs - rPcs);
                    r.sPcsEl.value = sPcs;
                    if (sPcs === 0) r.sWtEl.value = '0.000';
                }
                const curSoldWt = parseFloat(r.sWtEl.value || '0') || 0;
                const availWt = Math.max(0, r.maxWt - curSoldWt);
                if (rPcs === 0) {
                    r.rWtEl.value = '0.000';
                } else if (rPcs === (r.maxPcs - sPcs)) {
                    r.rWtEl.value = availWt.toFixed(3);
                } else if (r.maxPcs > 0) {
                    r.rWtEl.value = Math.min(availWt, r.maxWt * (rPcs / r.maxPcs)).toFixed(3);
                }
            }
        }
        updateSalesJangadPreview();
    } else if (el.classList.contains('sj-sold-wt') || el.classList.contains('sj-ret-wt') || el.classList.contains('sj-sold-rate') || el.id === 'sjAcGst') {
        updateSalesJangadPreview();
    }
});

// Open Sub Return Modal
function openSubReturnModal(assignmentId, assignNo, packetNo, assignedWt, returnedWt, pendingWt, nextSubNo) {
    document.getElementById('modal_sub_assignment_id').value = assignmentId;
    document.getElementById('modal_sub_assign_badge').innerText = `Assign #${assignNo}`;
    document.getElementById('modal_sub_packet_text').innerText = packetNo;
    document.getElementById('modal_sub_assigned_wt').innerText = `${assignedWt.toFixed(3)} Ct`;
    document.getElementById('modal_sub_pending_wt').innerText = `${pendingWt.toFixed(3)} Ct`;
    document.getElementById('modal_sub_number_disp').value = nextSubNo;
    document.getElementById('modal_received_weight_input').value = pendingWt > 0 ? pendingWt.toFixed(3) : '0.000';
    document.getElementById('modal_received_weight_input').max = pendingWt.toFixed(3);

    const modal = new bootstrap.Modal(document.getElementById('subReturnModal'));
    modal.show();
}

// Open Finalize Tops Modal
function openFinalizeTopsModal(assignmentId, assignNo, assignedWt, returnedWt, pendingWt) {
    document.getElementById('modal_finalize_assignment_id').value = assignmentId;
    document.getElementById('modal_finalize_assign_text').innerText = `Assign #${assignNo}`;
    document.getElementById('modal_finalize_assigned_wt').innerText = `${assignedWt.toFixed(3)} Ct`;
    document.getElementById('modal_finalize_returned_wt').innerText = `${returnedWt.toFixed(3)} Ct`;
    document.getElementById('modal_finalize_tops_wt').innerText = `${pendingWt.toFixed(3)} Ct`;

    const modal = new bootstrap.Modal(document.getElementById('finalizeTopsModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
