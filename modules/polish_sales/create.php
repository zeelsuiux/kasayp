<?php
/**
 * Create Polish Diamond Sale Entry
 * Diamond ERP
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
sync_all_completed_packets_to_polish_stock();

$stockId = (int)($_GET['stock_id'] ?? 0);
$jangadId = (int)($_GET['jangad_id'] ?? $_POST['jangad_id'] ?? 0);
$jangadItemId = (int)($_GET['jangad_item_id'] ?? $_POST['jangad_item_id'] ?? 0);

$jangadContext = null;
$prePartyId = 0;
$preBrokerId = 0;

if ($jangadItemId > 0) {
    $jcStmt = $pdo->prepare("
        SELECT ji.*, jv.jangad_no, jv.party_id, jv.broker_id, p.party_name
        FROM jangad_items ji
        JOIN jangad_vouchers jv ON ji.jangad_id = jv.id
        JOIN parties p ON jv.party_id = p.id
        WHERE ji.id = ?
    ");
    $jcStmt->execute([$jangadItemId]);
    $jangadContext = $jcStmt->fetch(PDO::FETCH_ASSOC);
    if ($jangadContext) {
        $jangadId = (int)$jangadContext['jangad_id'];
        $stockId = (int)($jangadContext['polish_stock_id'] ?? 0);
        $prePartyId = (int)$jangadContext['party_id'];
        $preBrokerId = (int)($jangadContext['broker_id'] ?? 0);
    }
}

$reqType = trim((string)($_GET['type'] ?? $_GET['stock_type'] ?? ''));
$isGiaSale = ($reqType === 'gia');
$isParcelSale = ($reqType === 'parcel');

$stockWhere = "available_weight > 0";
if ($isGiaSale) {
    $stockWhere .= " AND (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))";
} elseif ($isParcelSale) {
    $stockWhere .= " AND (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = ''))";
}
if ($stockId > 0) {
    $stockWhere = "($stockWhere) OR id = " . (int)$stockId;
}

$stockList = $pdo->query("
    SELECT * FROM polish_stocks
    WHERE $stockWhere
    ORDER BY id DESC
")->fetchAll();

$selectedStock = null;
if ($stockId > 0) {
    $st = $pdo->prepare("SELECT * FROM polish_stocks WHERE id = ?");
    $st->execute([$stockId]);
    $selectedStock = $st->fetch();
} elseif (!empty($stockList)) {
    $selectedStock = $stockList[0];
    $stockId = (int)$selectedStock['id'];
}

$parties = get_parties_by_role('polish_sale', 'party');
// Ensure preselected party from Sales Jangad is present in dropdown
if ($prePartyId > 0) {
    $hasPre = false;
    foreach ($parties as $pt) {
        if ((int)$pt['id'] === $prePartyId) {
            $hasPre = true;
            break;
        }
    }
    if (!$hasPre && $jangadContext) {
        $pRow = $pdo->query("SELECT id, party_name, city FROM parties WHERE id = " . (int)$prePartyId)->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            array_unshift($parties, $pRow);
        }
    }
}

$brokers = get_parties_by_role('polish_sale_broker', 'broker');
$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();
$defaultInv = generate_next_code('P-INV', 'polish_sales', 'invoice_no');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $invoiceNo  = trim($_POST['invoice_no'] ?? $defaultInv);
    $saleDate   = $_POST['sale_date'] ?? date('Y-m-d');
    $partyId    = (int)($_POST['party_id'] ?? 0);
    $brokerId   = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $targetStockId = (int)($_POST['polish_stock_id'] ?? 0);
    $postJangadId = (int)($_POST['jangad_id'] ?? 0);
    $postJangadItemId = (int)($_POST['jangad_item_id'] ?? 0);
    $pcs        = (int)($_POST['pcs'] ?? 1);
    $weight     = round((float)($_POST['weight'] ?? 0.000), 3);

    $paymentCurrency = $_POST['payment_currency'] ?? 'INR';
    $dollarPerCarat  = null;
    $dollarRate      = null;
    $pricePerCt      = 0.00;

    if ($paymentCurrency === 'USD') {
        $dollarPerCarat = !empty($_POST['dollar_per_carat']) ? (float)$_POST['dollar_per_carat'] : null;
        $dollarRate     = !empty($_POST['dollar_rate']) ? (float)$_POST['dollar_rate'] : null;
        if ($dollarPerCarat && $dollarRate && $dollarRate > 0) {
            $pricePerCt = round($dollarPerCarat * $dollarRate, 2);
        } else {
            $pricePerCt = 0.00; // Stand Dollar deal
        }
    } else {
        $paymentCurrency = 'INR';
        $pricePerCt      = (float)($_POST['price_per_carat_inr'] ?? 0.00);
    }

    $paymentAmt   = round($weight * $pricePerCt, 2);
    $brokeragePct = (float)($_POST['brokerage_percent'] ?? 0.00);
    $brokerageAmt = round($paymentAmt * ($brokeragePct / 100), 2);
    $acGstAmt     = (float)($_POST['ac_gst_amount'] ?? 0.00);
    $netAmt       = round($paymentAmt + $acGstAmt, 2);

    $dueDays      = (int)($_POST['due_days'] ?? 50);
    $dueDate      = date('Y-m-d', strtotime($saleDate . " +$dueDays days"));
    $status       = $_POST['payment_status'] ?? 'pending';
    $receivedAmt  = ($status === 'paid') ? $netAmt : (float)($_POST['received_amount'] ?? 0.00);
    $receivedDate = !empty($_POST['received_date']) ? $_POST['received_date'] : null;
    $bankAccId    = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;

    if ($receivedAmt >= $netAmt && $netAmt > 0) {
        $status = 'paid';
    } elseif ($receivedAmt > 0) {
        $status = 'partial';
    }

    $notes = trim($_POST['notes'] ?? '');

    // Check stock availability (either direct Polish Stock OR pending on Sales Jangad)
    $stCheck = $pdo->prepare("SELECT available_pcs, available_weight, packet_no, status FROM polish_stocks WHERE id = ?");
    $stCheck->execute([$targetStockId]);
    $curStk = $stCheck->fetch();

    $jItemRow = null;
    $maxAvailPcs = $curStk ? (int)$curStk['available_pcs'] : 0;
    $maxAvailWt  = $curStk ? round((float)$curStk['available_weight'], 3) : 0.0;

    if ($postJangadItemId > 0) {
        $jiChk = $pdo->prepare("SELECT * FROM jangad_items WHERE id = ?");
        $jiChk->execute([$postJangadItemId]);
        $jItemRow = $jiChk->fetch(PDO::FETCH_ASSOC);
        if ($jItemRow && (int)$jItemRow['polish_stock_id'] === $targetStockId) {
            $maxAvailPcs = max(0, (int)$jItemRow['issued_pcs'] - (int)($jItemRow['sold_pcs'] ?? 0) - (int)$jItemRow['returned_pcs']);
            $maxAvailWt  = max(0.0, round((float)$jItemRow['issued_weight'] - (float)($jItemRow['sold_weight'] ?? 0) - (float)$jItemRow['returned_weight'], 3));
        }
    }

    $isParcelStock = ($curStk && ($curStk['stock_type'] === 'parcel' || (int)$curStk['total_pcs'] > 1 || (int)$pcs > 1));
    $shapeItems = $_POST['shape_items'] ?? [];
    $shapeValResult = null;
    if ($isParcelStock) {
        $shapeValResult = validate_parcel_shape_breakdown($pcs, $weight, $shapeItems);
    }

    if ($partyId <= 0 || $weight <= 0 || !$curStk) {
        set_flash('error', 'કૃપા કરીને પાર્ટી, વજન અને સ્ટોક યોગ્ય રીતે દાખલ કરો.');
    } elseif ($isParcelStock && !$shapeValResult['valid']) {
        set_flash('error', $shapeValResult['error']);
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCt <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } elseif (is_party_blocked($partyId)) {
        set_flash('error', '🛑 આ પાર્ટી બ્લોક (Blocked) કરેલ છે! તેને પોલિશ વેચાણ થઈ શકશે નહીં.');
    } elseif (!empty($brokerId) && is_party_blocked((int)$brokerId)) {
        set_flash('error', '🛑 આ દલાલ બ્લોક (Blocked) કરેલ છે! તેના દ્વારા સોદો નોંધાઈ શકશે નહીં.');
    } elseif ($weight > ($maxAvailWt + 0.001) || $pcs > $maxAvailPcs) {
        set_flash('error', "વેચાણ વજન અથવા નંગ ઉપલબ્ધ જથ્થા કરતાં વધુ છે (મહત્તમ: {$maxAvailWt} ct / {$maxAvailPcs} Pcs).");
    } else {
        try {
            $pdo->beginTransaction();

            if ($jItemRow && $weight > $maxAvailWt) {
                $weight = $maxAvailWt;
            }

            if ($jItemRow && empty($notes)) {
                $notes = "Converted from Sales Jangad Item #{$postJangadItemId}";
            }

            $stmt = $pdo->prepare("INSERT INTO polish_sales (
                invoice_no, sale_date, payment_currency, party_id, broker_id, polish_stock_id, jangad_id, jangad_item_id, pcs, weight,
                dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount, brokerage_percent, brokerage_amount,
                ac_gst_amount, net_amount, due_days, due_date, payment_status,
                received_amount, received_date, notes, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )");

            $stmt->execute([
                $invoiceNo, $saleDate, $paymentCurrency, $partyId, $brokerId, $targetStockId, ($postJangadId > 0 ? $postJangadId : null), ($postJangadItemId > 0 ? $postJangadItemId : null), $pcs, $weight,
                $dollarPerCarat, $dollarRate, $pricePerCt, $paymentAmt, $brokeragePct, $brokerageAmt,
                $acGstAmt, $netAmt, $dueDays, $dueDate, $status,
                $receivedAmt, $receivedDate, $notes, $user['id']
            ]);
            $saleId = (int)$pdo->lastInsertId();

            // Record received payment deposit into bank account
            if ($bankAccId && $receivedAmt > 0) {
                record_account_transaction(
                    $bankAccId, 'credit', $receivedAmt, 'polish_sale', $saleId,
                    $invoiceNo, $receivedDate ?? $saleDate, "Polish diamond sale collection on $invoiceNo from Party #$partyId", $user['id']
                );
            }

            // Update party balance (receivable from customer)
            $unpaidReceivable = round($netAmt - $receivedAmt, 2);
            if ($unpaidReceivable > 0) {
                update_party_balance($partyId, +$unpaidReceivable);
            }

            // Record brokerage payable liability
            if ($brokerId && $brokerageAmt > 0) {
                update_party_balance($brokerId, -$brokerageAmt);
            }

            if ($jItemRow) {
                // Record exact Sold qty separately from Returned qty
                $newSoldPcs = (int)($jItemRow['sold_pcs'] ?? 0) + $pcs;
                $newSoldWt  = round((float)($jItemRow['sold_weight'] ?? 0) + $weight, 3);
                $settledWt  = round($newSoldWt + (float)$jItemRow['returned_weight'], 3);
                $settledPcs = $newSoldPcs + (int)$jItemRow['returned_pcs'];
                $remItemWt  = max(0.0, round((float)$jItemRow['issued_weight'] - $settledWt, 3));
                $newItemStatus = ($remItemWt <= 0.0005 || $settledPcs >= (int)$jItemRow['issued_pcs']) ? 'returned' : 'partially_returned';

                $pdo->prepare("
                    UPDATE jangad_items SET
                        sold_pcs = ?,
                        sold_weight = ?,
                        polish_sale_id = ?,
                        status = ?,
                        description = CONCAT(description, ' [Sold: ', ?, ']')
                    WHERE id = ?
                ")->execute([$newSoldPcs, $newSoldWt, $saleId, $newItemStatus, $invoiceNo, $postJangadItemId]);

                recalculate_jangad_voucher_totals((int)$jItemRow['jangad_id']);
                reconcile_polish_stock_status($targetStockId);

                // Update linked packet if settled via sale
                if (!empty($jItemRow['packet_id']) && $newItemStatus === 'returned') {
                    $pktId = (int)$jItemRow['packet_id'];
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
                    ")->execute([$weight, " | વેચાણ બિલ: {$invoiceNo}", $pktId, (int)$jItemRow['jangad_id']]);
                }
            } else {
                // Direct Sale: Deduct directly from Polish Stock
                $remPcs = max(0, (int)$curStk['available_pcs'] - $pcs);
                $remWt = max(0.0, round((float)$curStk['available_weight'] - $weight, 3));
                $pdo->prepare("UPDATE polish_stocks SET available_pcs = ?, available_weight = ? WHERE id = ?")->execute([$remPcs, $remWt, $targetStockId]);
                reconcile_polish_stock_status($targetStockId);

                if ($remWt <= 0) {
                    $pdo->prepare("UPDATE packets SET status = 'sold', diamond_status = 'completed', current_location = 'Sold' WHERE polish_stock_id = ?")->execute([$targetStockId]);
                }
            }

            // Save Shape Breakdown to polish_parcel_shapes for parcel sale
            if ($isParcelStock && $shapeValResult && !empty($shapeValResult['clean_rows'])) {
                save_polish_parcel_shapes($pdo, 'sale', $saleId, $targetStockId, $shapeValResult['clean_rows']);
            }

            $pdo->commit();
            log_activity('Polish Diamond Sold', 'Polish', $saleId, "Sold $weight ct on invoice $invoiceNo for $netAmt");
            set_flash('success', "Polish વેચાણ $invoiceNo સફળતાપૂર્વક નોંધાઈ ગયું અને સ્ટોક અપડેટ થયો.");
            $redirTab = $isParcelStock ? 'parcel_sell' : ($isGiaSale ? 'gia_sell' : 'parcel_sell');
            $redirSec = ($redirTab === 'parcel_sell') ? 'parcel' : 'gia';
            header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $redirTab . '&section=' . $redirSec);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'વેચાણ નોંધવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Compute UI defaults cleanly (respecting Sales Jangad conversion if present)
$uiDefaultPcs = $selectedStock ? (int)$selectedStock['available_pcs'] : 1;
$uiDefaultWt  = $selectedStock ? (float)$selectedStock['available_weight'] : 0.000;
$uiDefaultPrice = $selectedStock ? (float)$selectedStock['sale_price_per_carat'] : 0.00;
if ($jangadContext) {
    $uiDefaultPcs = max(1, (int)$jangadContext['issued_pcs'] - (int)($jangadContext['sold_pcs'] ?? 0) - (int)$jangadContext['returned_pcs']);
    $uiDefaultWt  = max(0.001, round((float)$jangadContext['issued_weight'] - (float)($jangadContext['sold_weight'] ?? 0) - (float)$jangadContext['returned_weight'], 3));
    if ((float)($jangadContext['charges'] ?? 0) > 0) {
        $uiDefaultPrice = (float)$jangadContext['charges'];
    }
}

$isParcelStock = ($selectedStock && ($selectedStock['stock_type'] === 'parcel' || (int)$selectedStock['total_pcs'] > 1 || (int)$uiDefaultPcs > 1));
$originShapes = [];
if ($selectedStock && !empty($selectedStock['polish_purchase_id'])) {
    $originShapes = get_polish_parcel_shapes($pdo, 'purchase', (int)$selectedStock['polish_purchase_id']);
}
if (empty($originShapes) && $selectedStock) {
    $originShapes = [
        [
            'shape' => $selectedStock['shape'] ?? 'Round',
            'pcs' => (int)$uiDefaultPcs,
            'weight_per_shape' => (int)$uiDefaultPcs > 0 ? round((float)$uiDefaultWt / (int)$uiDefaultPcs, 4) : (float)$uiDefaultWt,
            'total_weight' => (float)$uiDefaultWt,
            'price_per_carat' => (float)$uiDefaultPrice
        ]
    ];
}

$pageTitle = $isGiaSale ? 'નવું 💎 GIA Polish વેચાણ (New GIA Sale)' : ($isParcelSale ? 'નવું 📦 PARCEL Polish વેચાણ (New Parcel Sale)' : 'નવું Polish વેચાણ (New Polish Sale)');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon('hand-holding-dollar', 'text-primary me-2') ?><?= $isGiaSale ? 'નવું 💎 GIA Polish વેચાણ (New GIA Diamond Sale)' : ($isParcelSale ? 'નવું 📦 PARCEL Polish વેચાણ (New Parcel Diamond Sale)' : 'નવું Polish હીરા વેચાણ (New Polish Diamond Sale)') ?>
        </h4>
        <div class="text-muted small"><?= $isGiaSale ? 'GIA સર્ટિફાઇડ સિંગલ સ્ટોકનું ગ્રાહકને વેચાણ' : ($isParcelSale ? 'પાર્સલ લૂઝ હીરા અને Shape Breakdown સાથે વેચાણ' : 'ફોર્મેટ મુજબ તૈયાર હીરા વેચાણ બિલ અને સ્ટોક ઓટો-ડિડક્શન') ?></div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0 d-flex justify-content-md-end gap-1.5 align-items-center">
        <a href="<?= BASE_URL ?>/modules/polish_sales/import.php" class="btn btn-outline-success btn-sm px-3 fw-bold">
            <?= svg_icon('file-arrow-up', 'me-1') ?>Bulk વેચાણ Upload
        </a>
        <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=<?= $isGiaSale ? 'gia_sell' : ($isParcelSale ? 'parcel_sell' : 'gia_sell') ?>&section=<?= $isGiaSale ? 'gia' : 'parcel' ?>" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>વેચાણ લિસ્ટ
        </a>
    </div>
</div>

<?php if ($jangadContext): ?>
<div class="alert alert-success border d-flex align-items-center justify-content-between gap-3 rounded-3 mb-3 py-2.5 px-3" style="background: #f0fdf4; border-color: #bbf7d0 !important;">
    <div class="small text-dark">
        <?= svg_icon('file-invoice', 'text-success me-1.5') ?>
        <strong>Sales Jangad માંથી વેચાણ કન્વર્ઝન:</strong> વાઉચર <strong class="font-mono"><?= e($jangadContext['jangad_no']) ?></strong> (પાર્ટી: <strong><?= e($jangadContext['party_name']) ?></strong>) પરના હીરાનું પાકું વેચાણ બિલ બની રહ્યું છે.
    </div>
    <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= $jangadId ?>" class="btn btn-xs btn-outline-success text-nowrap">વાઉચર જુઓ</a>
</div>
<?php endif; ?>

<form action="<?= BASE_URL ?>/modules/polish_sales/create.php" method="POST" id="polishSaleForm">
    <?= csrf_field() ?>
    <input type="hidden" name="jangad_id" value="<?= $jangadId ?>">
    <input type="hidden" name="jangad_item_id" value="<?= $jangadItemId ?>">

    <!-- Hidden calculation carriers for database submission -->
    <input type="hidden" name="payment_amount" id="payment_amount" value="0.00">
    <input type="hidden" name="brokerage_amount" id="brokerage_amount" value="0.00">
    <input type="hidden" name="net_amount" id="net_payment" value="0.00">

    <div class="row g-3">
        <!-- ================= LEFT COLUMN: INPUTS & SPECIFICATIONS ================= -->
        <div class="col-12 col-lg-8">
            <!-- Card 1: Sale Invoice & Customer -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon"><?= svg_icon('file-invoice') ?></span>
                        ૧. વેચાણ બિલ અને ગ્રાહક વિગત (Sale Invoice & Customer)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ઇન્વોઇસ નંબર (Invoice No) *</label>
                            <input type="text" name="invoice_no" class="form-control fw-bold bg-light font-mono" value="<?= e($defaultInv) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">વેચાણ તારીખ (Sale Date) *</label>
                            <input type="date" name="sale_date" id="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ગ્રાહક પાર્ટી (Customer Name) *</label>
                            <select name="party_id" class="form-select" required>
                                <option value="">ગ્રાહક શોધો અથવા પસંદ કરો...</option>
                                <?php foreach ($parties as $pt): ?>
                                    <option value="<?= $pt['id'] ?>" <?= $prePartyId === (int)$pt['id'] ? 'selected' : '' ?>><?= e($pt['party_name']) ?> (<?= e($pt['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">દલાલ (Broker Name)</label>
                            <select name="broker_id" class="form-select">
                                <option value="">Direct (કોઈ દલાલ નથી)</option>
                                <?php foreach ($brokers as $br): ?>
                                    <option value="<?= $br['id'] ?>" <?= $preBrokerId === (int)$br['id'] ? 'selected' : '' ?>><?= e($br['party_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Polish Stock Packet Selection -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-purple"><?= svg_icon('boxes-stacked') ?></span>
                        ૨. તૈયાર હીરા સ્ટોક પસંદગી (Select Polish Stock Packet)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">સ્ટોક પેકેટ (Select Available Polish Stock) *</label>
                        <?php if ($jangadContext): ?>
                            <input type="hidden" name="polish_stock_id" value="<?= $stockId ?>">
                            <select class="form-select fw-semibold bg-light" disabled>
                                <?php foreach ($stockList as $sl): ?>
                                    <?php if ((int)$sl['id'] === $stockId): ?>
                                        <option selected><?= e($sl['packet_no']) ?> (<?= e($sl['shape']) ?> <?= e($sl['color'] ?? '') ?>/<?= e($sl['clarity'] ?? '') ?>) — જાંગડ પર: <?= format_carat($uiDefaultWt) ?> (<?= $uiDefaultPcs ?> Pcs)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select name="polish_stock_id" class="form-select fw-semibold" onchange="window.location.href='?stock_id=' + this.value + '<?= !empty($reqType) ? '&type=' . urlencode($reqType) : '' ?>'" required>
                                <?php foreach ($stockList as $sl): ?>
                                    <option value="<?= $sl['id'] ?>" <?= $stockId == $sl['id'] ? 'selected' : '' ?>>
                                        <?= e($sl['packet_no']) ?> (<?= e($sl['shape']) ?> <?= e($sl['color'] ?? '') ?>/<?= e($sl['clarity'] ?? '') ?>) — હાજર: <?= format_carat($sl['available_weight']) ?> (<?= (int)$sl['available_pcs'] ?> Pcs) • કિંમત: <?= format_inr($sl['cost_per_carat']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <?php if ($selectedStock): ?>
                        <div class="p-3 bg-light rounded-3 border d-flex flex-wrap gap-4 align-items-center">
                            <div>
                                <span class="text-muted small d-block">પસંદ કરેલ પેકેટ:</span>
                                <strong class="text-dark font-mono"><?= e($selectedStock['packet_no']) ?></strong>
                            </div>
                            <div>
                                <span class="text-muted small d-block"><?= $jangadContext ? 'જાંગડ પર બાકી જથ્થો:' : 'હાજર જથ્થો:' ?></span>
                                <strong class="text-primary font-mono"><?= format_carat($uiDefaultWt) ?> (<?= $uiDefaultPcs ?> Pcs)</strong>
                            </div>
                            <div>
                                <span class="text-muted small d-block">પડતર ભાવ (Cost/Ct):</span>
                                <strong class="text-muted font-mono"><?= format_inr($selectedStock['cost_per_carat']) ?></strong>
                            </div>
                            <div>
                                <span class="text-muted small d-block">સૂચિત વેચાણ ભાવ:</span>
                                <strong class="text-success font-mono"><?= format_inr($uiDefaultPrice) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 3: Sale Units, Rate & Commercial Engine -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('calculator') ?></span>
                        ૩. વેચાણ વજન, ભાવ અને ગણતરી (Sales Units & Pricing)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <!-- Weight & Pcs -->
                    <div class="form-subhead">વેચાણ નંગ અને વજન (Sale Quantity)</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">વેચાણ નંગ (Sale Pcs) *</label>
                            <div class="input-group">
                                <input type="number" name="pcs" class="form-control fw-bold font-mono" value="<?= $uiDefaultPcs ?>" min="1" max="<?= max(1, $uiDefaultPcs) ?>" required>
                                <span class="input-group-text">Pcs</span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-primary">વેચાણ વજન (Sale Weight Carats) *</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" oninput="if(parseFloat(this.value)<0) this.value=0;" name="weight" id="weight" class="form-control form-control-lg fw-bold text-primary font-mono" value="<?= number_format($uiDefaultWt, 3, '.', '') ?>" max="<?= number_format($uiDefaultWt > 0 ? $uiDefaultWt : 999999, 3, '.', '') ?>" required>
                                <span class="input-group-text fw-bold text-primary">Ct</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Currency Method (2 Types: Indian Currency or Dollar Currency) -->
                    <div class="form-subhead">પેમેન્ટ પદ્ધતિ / કરન્સી પ્રકાર (Payment Method) *</div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <input type="radio" class="btn-check" name="payment_currency" id="curr_inr" value="INR" checked autocomplete="off">
                            <label class="btn btn-outline-primary w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_inr">
                                <span>🇮🇳</span>
                                <span>1. Indian Currency (₹)</span>
                            </label>
                        </div>
                        <div class="col-6">
                            <input type="radio" class="btn-check" name="payment_currency" id="curr_usd" value="USD" autocomplete="off">
                            <label class="btn btn-outline-success w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_usd">
                                <span>💵</span>
                                <span>2. Dollar Currency ($)</span>
                            </label>
                        </div>
                    </div>

                    <!-- 1. Indian Currency Box -->
                    <div id="inr_currency_box" class="mb-4">
                        <label class="form-label small fw-bold text-dark">વેચાણ ભાવ / Carat INR (₹) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                            <input type="number" step="0.01" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg font-mono fw-bold" value="<?= number_format($uiDefaultPrice, 2, '.', '') ?>" required>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.78rem;">માત્ર ભારતીય રૂપિયામાં ભાવ દાખલ કરવો ફરજિયાત છે.</div>
                    </div>

                    <!-- 2. Dollar Currency Box -->
                    <div id="usd_currency_box" class="mb-4" style="display:none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-success">Dollar / Ct ($) * <span class="badge bg-success-subtle text-success">Mandatory</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-success fw-bold">$</span>
                                    <input type="number" step="0.01" name="dollar_per_carat" id="dollar_per_carat" class="form-control form-control-lg font-mono fw-bold" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Dollar Rate (₹/$) <span class="badge bg-light text-muted border">Optional</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted">₹</span>
                                    <input type="number" step="0.01" name="dollar_rate" id="dollar_rate" class="form-control form-control-lg font-mono" placeholder="ખાલી = Stand Dollar (દર બાકી)">
                                </div>
                            </div>
                        </div>
                        <div id="usd_conversion_preview" class="alert alert-soft-info py-2 px-3 small mt-2 mb-0 border" style="display:none;"></div>
                    </div>

                    <!-- Brokerage & Taxes -->
                    <div class="form-subhead">દલાલી અને ખર્ચ (Brokerage & Additional Taxes)</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">દલાલી (Brokerage %)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="0.50">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">AC / GST ખર્ચ (INR)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="ac_gst_amount" id="ac_gst_amount" class="form-control font-mono" value="0.00">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 4: શેપ-વાઇઝ પાર્સલ વજન અને નંગ વિભાજન (Parcel Shape-wise Weight Management) -->
            <div class="erp-form-card border border-primary-subtle shadow-sm mb-3" id="card_shapes_section" style="<?= !$isParcelStock ? 'display:none;' : '' ?>">
                <div class="erp-card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2 py-2.5 px-3">
                    <h6 class="header-title mb-0 d-flex align-items-center gap-2">
                        <span class="header-icon bg-primary text-white" style="width: 28px; height: 28px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;">
                            <?= svg_icon('shapes', '', 14) ?>
                        </span>
                        <span class="fw-bold">પાર્સલ શેપ-વાઇઝ વિગત (Shape-wise Breakdown Table)</span>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-primary" id="btn_add_shape_row">
                            <?= svg_icon('plus', 'me-1') ?>નવો શેપ ઉમેરો (Add Row)
                        </button>
                    </div>
                </div>
                <div class="erp-card-body p-0">
                    <!-- Live Parcel Summary & 1% Validation Card -->
                    <div class="p-3 bg-light-subtle border-bottom" id="parcel_summary_box">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-8">
                                <div class="d-flex flex-wrap gap-2 align-items-center small">
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">પાર્સલ નંબર:</span>
                                        <strong class="font-mono text-dark" id="summary_parcel_no"><?= e($selectedStock['packet_no'] ?? 'PARCEL') ?></strong>
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">વેચાણ નંગ:</span>
                                        <strong class="font-mono text-primary" id="summary_parcel_pcs"><?= $uiDefaultPcs ?></strong> Pcs
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">વેચાણ વજન:</span>
                                        <strong class="font-mono text-primary" id="summary_parcel_weight"><?= format_carat($uiDefaultWt) ?></strong>
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">શેપ કુલ નંગ:</span>
                                        <strong class="font-mono" id="summary_shape_pcs">0</strong> Pcs
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">શેપ કુલ વજન:</span>
                                        <strong class="font-mono" id="summary_shape_weight">0.000</strong> Ct
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">મંજૂર ૧% તફાવત:</span>
                                        <strong class="font-mono text-secondary" id="summary_allowed_diff">0.000</strong> Ct
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">વાસ્તવિક તફાવત:</span>
                                        <strong class="font-mono" id="summary_actual_diff">0.000</strong> Ct
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-4 text-md-end">
                                <div id="shape_validation_badge">
                                    <span class="badge bg-secondary py-2 px-3 fs-7">તપાસ ચાલુ છે...</span>
                                </div>
                            </div>
                        </div>
                        <div id="shape_validation_msg" class="mt-2" style="display: none;"></div>
                    </div>

                    <!-- Shape-wise Table -->
                    <div class="table-responsive" style="max-height: 440px; overflow-y: auto;">
                        <table class="table table-bordered table-hover align-middle mb-0" id="shapes_table">
                            <thead class="table-light text-muted small text-uppercase" style="position: sticky; top: 0; z-index: 2;">
                                <tr>
                                    <th style="width: 45px;" class="text-center">#</th>
                                    <th style="min-width: 160px;">શેપ (Shape) *</th>
                                    <th style="min-width: 110px;">નંગ (Qty) *</th>
                                    <th style="min-width: 140px;">કુલ વજન (Total Weight Ct) *</th>
                                    <th style="min-width: 150px;">વજન / નંગ (Weight Per Shape Ct)</th>
                                    <th style="min-width: 140px;">અંદાજિત ભાવ/Ct (Approx Price/Ct)</th>
                                    <th style="width: 50px;" class="text-center">રદ</th>
                                </tr>
                            </thead>
                            <tbody id="shapes_tbody">
                                <!-- Populated dynamically via JavaScript -->
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end">કુલ સરવાળો (Total):</td>
                                    <td><span id="footer_shape_qty" class="font-mono text-primary fs-6">0</span> Pcs</td>
                                    <td><span id="footer_shape_weight" class="font-mono text-primary fs-6">0.000</span> Ct</td>
                                    <td></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= RIGHT COLUMN: LIVE TERMINAL & SETTLEMENT ================= -->
        <div class="col-12 col-lg-4">
            <div class="erp-summary-sticky">
                <!-- 1. Executive Sales Terminal -->
                <div class="erp-terminal-card">
                    <div class="erp-terminal-header">
                        <h6><?= svg_icon('receipt', 'text-cyan me-1') ?>વેચાણ હિસાબ ટર્મિનલ</h6>
                        <span class="badge bg-info-subtle text-cyan">Sales Recalc</span>
                    </div>
                    <div class="erp-terminal-body">
                        <div class="erp-terminal-row">
                            <span>કાચી રકમ (Raw Diamond Value):</span>
                            <strong id="display_raw_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span>+ દલાલી રકમ (Brokerage):</span>
                            <strong id="display_brokerage_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span>+ AC / GST ખર્ચ:</span>
                            <strong id="display_gst_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-divider"></div>
                        <div class="erp-net-display sales-tone">
                            <div class="net-label">કુલ લેવાની રકમ (NET RECEIVABLE)</div>
                            <div class="net-value" id="display_net_payment">₹ 0.00</div>
                            <div class="net-sub" id="display_effective_cost">₹ 0.00 / ct</div>
                        </div>
                    </div>
                </div>

                <!-- 2. Payment Terms & Settlement -->
                <div class="erp-form-card">
                    <div class="erp-card-header">
                        <h6 class="header-title">
                            <span class="header-icon icon-gold"><?= svg_icon('calendar-check') ?></span>
                            મુદત અને વસૂલાત (Collection)
                        </h6>
                    </div>
                    <div class="erp-card-body">
                        <div class="mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-1 gap-1">
                                <label class="form-label small fw-bold text-muted mb-0">Due Days (મુદત દિવસ) *</label>
                                <div class="due-presets-wrap">
                                    <button type="button" class="due-preset-btn" data-days="30">30</button>
                                    <button type="button" class="due-preset-btn" data-days="45">45</button>
                                    <button type="button" class="due-preset-btn active" data-days="50">50</button>
                                    <button type="button" class="due-preset-btn" data-days="60">60</button>
                                    <button type="button" class="due-preset-btn" data-days="90">90</button>
                                </div>
                            </div>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono" value="50" min="0" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Due Date (ચુકવણી છેલ્લી તારીખ) *</label>
                            <input type="date" name="due_date" id="due_date" class="form-control bg-light font-mono" value="<?= date('Y-m-d', strtotime('+50 days')) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">ચુકવણી સ્થિતિ (Payment Status) *</label>
                            <select name="payment_status" id="payment_status_select" class="form-select no-search fw-semibold">
                                <option value="pending" selected>🕒 Pending (લેવાના બાકી)</option>
                                <option value="partial">⏳ Partial (અંશતઃ મળ્યા)</option>
                                <option value="paid">✅ Paid (પૂરેપૂરા જમા)</option>
                            </select>
                        </div>

                        <!-- Pending Notice Box -->
                        <div id="pending_payment_notice" class="alert alert-soft-warning py-2 px-3 small mb-3 border">
                            <?= svg_icon('clock', 'me-1 text-warning') ?>
                            બિલ નિયત મુદતે ગ્રાહક ખાતામાં લેણું બોલશે.
                        </div>

                        <!-- Conditional Payment Settlement Box -->
                        <div id="conditional_payment_box" style="display: none;" class="p-3 bg-light rounded-3 border mb-3">
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">મળેલ રકમ (Received Amount INR) *</label>
                                <input type="number" step="0.01" name="received_amount" id="paid_amount" class="form-control fw-bold font-mono" value="0.00">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">મળ્યા તારીખ (Collection Date)</label>
                                <input type="date" name="received_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-muted">જમા બેંક / કેશ ખાતું (Bank Account)</label>
                                <select name="bank_account_id" class="form-select">
                                    <option value="">ખાતું પસંદ કરો...</option>
                                    <?php foreach ($bankAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">નોંધ / શેરો (Notes)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="ગ્રાહક ઓર્ડર અથવા ડિલિવરી સંદર્ભ નોંધ..."></textarea>
                        </div>

                        <div class="d-grid gap-2 pt-2">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                                <?= svg_icon('floppy-disk', 'me-2') ?>વેચાણ સેવ કરો & Stock ઘટાડો
                            </button>
                            <a href="<?= BASE_URL ?>/modules/polish_sales/index.php" class="btn btn-outline-secondary">
                                રદ કરો
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isParcel = <?= $isParcelStock ? 'true' : 'false' ?>;
    const originShapesData = <?= json_encode($originShapes) ?>;
    const shapeOptions = <?= json_encode(get_polish_shapes_list()) ?>;

    const shapesTbody = document.getElementById('shapes_tbody');
    const btnAddShape = document.getElementById('btn_add_shape_row');
    const pcsInput = document.querySelector('input[name="pcs"]');
    const weightInput = document.getElementById('weight');
    const saleForm = document.querySelector('form');

    const summaryParcelNo = document.getElementById('summary_parcel_no');
    const summaryParcelPcs = document.getElementById('summary_parcel_pcs');
    const summaryParcelWeight = document.getElementById('summary_parcel_weight');
    const summaryShapePcs = document.getElementById('summary_shape_pcs');
    const summaryShapeWeight = document.getElementById('summary_shape_weight');
    const summaryAllowedDiff = document.getElementById('summary_allowed_diff');
    const summaryActualDiff = document.getElementById('summary_actual_diff');
    const shapeValidationBadge = document.getElementById('shape_validation_badge');
    const shapeValidationMsg = document.getElementById('shape_validation_msg');
    const footerShapeQty = document.getElementById('footer_shape_qty');
    const footerShapeWeight = document.getElementById('footer_shape_weight');

    let isShapeValid = true;
    let shapeValidationError = '';

    function buildShapeRowHtml(index, data = {}) {
        const selShape = data.shape || 'Round';
        const defaultQty = data.pcs !== undefined ? data.pcs : '';
        const defaultWeightPerShape = data.weight_per_shape !== undefined ? data.weight_per_shape : '';
        const defaultTotalWeight = data.total_weight !== undefined ? data.total_weight : '';
        const defaultPrice = data.price_per_carat !== undefined ? data.price_per_carat : '';

        let shapeOpts = '';
        for (const [key, label] of Object.entries(shapeOptions)) {
            shapeOpts += `<option value="${key}" ${key === selShape ? 'selected' : ''}>${label}</option>`;
        }

        return `
            <tr class="shape-item-row" data-row-index="${index}">
                <td class="text-center font-mono text-muted small fw-bold row-num">${index}</td>
                <td>
                    <select name="shape_items[${index}][shape]" class="form-select form-select-sm shape-select no-search">
                        ${shapeOpts}
                    </select>
                </td>
                <td>
                    <input type="number" min="1" step="1" name="shape_items[${index}][pcs]" class="form-control form-control-sm font-mono fw-bold shape-qty-input" placeholder="Pcs" value="${defaultQty}">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" min="0.001" oninput="if(parseFloat(this.value)<0) this.value=0;" name="shape_items[${index}][total_weight]" class="form-control form-control-sm font-mono fw-bold text-primary shape-totwt-input" placeholder="0.00" value="${defaultTotalWeight}">
                        <span class="input-group-text px-1 text-muted">Ct</span>
                    </div>
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" min="0" name="shape_items[${index}][weight_per_shape]" class="form-control form-control-sm font-mono fw-semibold shape-wps-input bg-light" placeholder="0.00" value="${defaultWeightPerShape}" readonly>
                        <span class="input-group-text px-1 text-muted">Ct/pc</span>
                    </div>
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text px-1 text-muted">₹</span>
                        <input type="number" step="0.01" name="shape_items[${index}][price_per_carat]" class="form-control form-control-sm font-mono shape-price-input" placeholder="0.00" value="${defaultPrice}">
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-danger btn-delete-shape-row" title="શેપ રદ કરો">
                        &times;
                    </button>
                </td>
            </tr>
        `;
    }

    function recalculateShapeSummary() {
        if (!isParcel) return;

        const parcelPcs = parseInt(pcsInput?.value) || 0;
        const rawParcelWeight = parseFloat(weightInput?.value) || 0;
        const parcelWeight = Math.round(rawParcelWeight * 1000) / 1000;

        let totalShapePcs = 0;
        let totalShapeWeight = 0.000;

        const rows = shapesTbody?.querySelectorAll('.shape-item-row') || [];
        rows.forEach(r => {
            const qtyInput = r.querySelector('.shape-qty-input');
            const totwtInput = r.querySelector('.shape-totwt-input');
            const wpsInput = r.querySelector('.shape-wps-input');

            const qty = parseInt(qtyInput?.value) || 0;
            let totWt = parseFloat(totwtInput?.value) || 0.000;
            if (totWt < 0) {
                totWt = 0;
                if (totwtInput) totwtInput.value = '0';
            }
            
            const rowWps = (qty > 0 && totWt > 0) ? Math.round((totWt / qty) * 10000) / 10000 : 0.0000;
            if (wpsInput) {
                wpsInput.value = rowWps > 0 ? rowWps.toFixed(4) : '';
            }

            totalShapePcs += qty;
            totalShapeWeight = Math.round((totalShapeWeight + totWt) * 1000) / 1000;
        });

        // 1% Allowed Difference: Allowed Difference = Total Parcel Weight * 1%
        const allowedDiff = Math.round(parcelWeight * 0.01 * 1000) / 1000;
        // Actual Difference = ABS(Total Parcel Weight - Total Shapes Weight)
        const actualDiff = Math.round(Math.abs(parcelWeight - totalShapeWeight) * 1000) / 1000;

        if (summaryParcelPcs) summaryParcelPcs.innerText = parcelPcs;
        if (summaryParcelWeight) summaryParcelWeight.innerText = parcelWeight.toFixed(3);
        if (summaryShapePcs) summaryShapePcs.innerText = totalShapePcs;
        if (summaryShapeWeight) summaryShapeWeight.innerText = totalShapeWeight.toFixed(3);
        if (summaryAllowedDiff) summaryAllowedDiff.innerText = `± ${allowedDiff.toFixed(3)}`;
        if (summaryActualDiff) summaryActualDiff.innerText = `${actualDiff.toFixed(3)}`;

        if (footerShapeQty) footerShapeQty.innerText = totalShapePcs;
        if (footerShapeWeight) footerShapeWeight.innerText = totalShapeWeight.toFixed(3);

        isShapeValid = true;
        shapeValidationError = '';

        if (rows.length === 0) {
            isShapeValid = false;
            shapeValidationError = 'કૃપા કરીને ઓછામાં ઓછી એક શેપ એન્ટ્રી (Shape Entry) ઉમેરો.';
        } else if (parcelPcs <= 0 || parcelWeight <= 0) {
            isShapeValid = false;
            shapeValidationError = 'કૃપા કરીને પહેલા વેચાણ નંગ (Pcs) અને કુલ વજન (Weight Ct > 0) દાખલ કરો.';
        } else if (totalShapePcs !== parcelPcs) {
            isShapeValid = false;
            shapeValidationError = `Total Shape Quantity (${totalShapePcs} Pcs) must match the selected Parcel Quantity (${parcelPcs} Pcs).`;
        } else if (actualDiff > (allowedDiff + 0.0001)) {
            isShapeValid = false;
            shapeValidationError = `Shape-wise Total Weight (${totalShapeWeight.toFixed(3)} Ct) exceeds the allowed 1% parcel weight difference. Please correct the shape-wise weight entries. (Allowed: ${allowedDiff.toFixed(3)} Ct, Actual Difference: ${actualDiff.toFixed(3)} Ct).`;
        }

        if (shapeValidationBadge) {
            if (isShapeValid) {
                shapeValidationBadge.innerHTML = `<span class="badge bg-success py-2 px-3 fs-7 shadow-sm">✓ VALID (૧% નિયમ અને નંગ સંપૂર્ણ માન્ય)</span>`;
            } else {
                shapeValidationBadge.innerHTML = `<span class="badge bg-danger py-2 px-3 fs-7 shadow-sm">✗ BLOCKED (અમાન્ય વિગત)</span>`;
            }
        }

        if (shapeValidationMsg) {
            if (!isShapeValid && shapeValidationError) {
                shapeValidationMsg.style.display = 'block';
                shapeValidationMsg.className = 'alert alert-danger py-1.5 px-3 small mb-0 border-0 rounded-3 d-flex align-items-center';
                shapeValidationMsg.innerHTML = `<i class="me-1.5">⚠️</i> ${shapeValidationError}`;
            } else {
                shapeValidationMsg.style.display = 'none';
                shapeValidationMsg.innerHTML = '';
            }
        }
    }

    if (isParcel && shapesTbody) {
        btnAddShape?.addEventListener('click', function () {
            const nextIndex = shapesTbody.querySelectorAll('.shape-item-row').length + 1;
            shapesTbody.insertAdjacentHTML('beforeend', buildShapeRowHtml(nextIndex));
            recalculateShapeSummary();
        });

        shapesTbody.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-delete-shape-row');
            if (!btn) return;
            const row = btn.closest('.shape-item-row');
            const rows = shapesTbody.querySelectorAll('.shape-item-row');
            if (rows.length <= 1) {
                alert('પાર્સલ માટે ઓછામાં ઓછી ૧ શેપ એન્ટ્રી હોવી જરૂરી છે.');
                return;
            }
            row.remove();

            shapesTbody.querySelectorAll('.shape-item-row').forEach((r, idx) => {
                const newIdx = idx + 1;
                r.dataset.rowIndex = newIdx;
                r.querySelector('.row-num').innerText = newIdx;
                r.querySelector('.shape-select').name = `shape_items[${newIdx}][shape]`;
                r.querySelector('.shape-qty-input').name = `shape_items[${newIdx}][pcs]`;
                r.querySelector('.shape-wps-input').name = `shape_items[${newIdx}][weight_per_shape]`;
                r.querySelector('.shape-totwt-input').name = `shape_items[${newIdx}][total_weight]`;
                r.querySelector('.shape-price-input').name = `shape_items[${newIdx}][price_per_carat]`;
            });

            recalculateShapeSummary();
        });

        pcsInput?.addEventListener('input', recalculateShapeSummary);
        weightInput?.addEventListener('input', recalculateShapeSummary);

        shapesTbody.addEventListener('input', function (e) {
            if (e.target.classList.contains('shape-qty-input') || e.target.classList.contains('shape-totwt-input')) {
                recalculateShapeSummary();
            }
        });

        saleForm?.addEventListener('submit', function (e) {
            if (isParcel) {
                recalculateShapeSummary();
                if (!isShapeValid) {
                    e.preventDefault();
                    alert('અમાન્ય વિગત: ' + shapeValidationError);
                    return false;
                }
            }
        });

        // Initialize shape rows
        if (originShapesData && originShapesData.length > 0) {
            shapesTbody.innerHTML = '';
            originShapesData.forEach((item, idx) => {
                shapesTbody.insertAdjacentHTML('beforeend', buildShapeRowHtml(idx + 1, item));
            });
        } else {
            const defQty = parseInt(pcsInput?.value) || 1;
            const defTotWt = parseFloat(weightInput?.value) || 0;
            shapesTbody.insertAdjacentHTML('beforeend', buildShapeRowHtml(1, {
                shape: 'Round',
                pcs: defQty,
                total_weight: defTotWt > 0 ? defTotWt.toFixed(3) : '',
                weight_per_shape: (defQty > 0 && defTotWt > 0) ? (defTotWt / defQty).toFixed(4) : ''
            }));
        }

        recalculateShapeSummary();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
