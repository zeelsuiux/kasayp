<?php
/**
 * Edit Polish Purchase Entry (GIA & Parcel)
 * Kasyap Diamond ERP - Surat & Mumbai
 * Exact index sequence [1-31] & Commercial Engine matching create.php
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'અમાન્ય Polish ખરીદી ID.');
    header('Location: ' . BASE_URL . '/modules/polish_purchase/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT pp.*, p.party_name, b.party_name as broker_name 
                       FROM polish_purchases pp
                       JOIN parties p ON pp.party_id = p.id
                       LEFT JOIN parties b ON pp.broker_id = b.id
                       WHERE pp.id = ?");
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    set_flash('error', 'Polish ખરીદી રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/polish_purchase/index.php');
    exit;
}

$parties = get_parties_by_role('polish_purchase', 'party');
$brokers = get_parties_by_role('polish_purchase_broker', 'broker');

$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

$masterShapes       = get_gia_master_options('shape');
$masterColors       = get_gia_master_options('color');
$masterClarities    = get_gia_master_options('clarity');
$masterPolish       = get_gia_master_options('polish');
$masterSymmetry     = get_gia_master_options('symmetry');
$masterFluorescence = get_gia_master_options('fluorescence');
$masterKeySymbols   = get_gia_master_options('key_symbols');

$masterGiaColorTags    = get_all_color_tags_with_descriptions('gia', true);
$masterPolishColorTags = get_all_color_tags_with_descriptions('polish', true);
$masterParcelClarities = get_master_parcel_clarities(true);
$activeDestinations    = get_active_destinations();
$activeSafes           = get_active_safes();
$activeDabbas          = get_active_dabbas();

if (!empty($purchase['party_id']) && !in_array($purchase['party_id'], array_column($parties, 'id'))) {
    $curP = $pdo->query("SELECT id, party_name, city FROM parties WHERE id = " . (int)$purchase['party_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curP) $parties[] = $curP;
}
if (!empty($purchase['broker_id']) && !in_array($purchase['broker_id'], array_column($brokers, 'id'))) {
    $curB = $pdo->query("SELECT id, party_name FROM parties WHERE id = " . (int)$purchase['broker_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curB) $brokers[] = $curB;
}

$isParcel = ($purchase['stock_type'] === 'parcel');

$existingShapes = get_polish_parcel_shapes($pdo, 'purchase', $id);
if (empty($existingShapes) && $isParcel) {
    $existingShapes = [
        [
            'shape' => $purchase['shape'] ?? 'Round',
            'pcs' => (int)$purchase['pcs'],
            'weight_per_shape' => (int)$purchase['pcs'] > 0 ? round((float)$purchase['weight'] / (int)$purchase['pcs'], 4) : (float)$purchase['weight'],
            'total_weight' => (float)$purchase['weight'],
            'price_per_carat' => (float)$purchase['price_per_carat_inr']
        ]
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $purchaseDate = $_POST['purchase_date'] ?? $purchase['purchase_date'];
    $partyId      = (int)($_POST['party_id'] ?? $purchase['party_id']);
    $brokerId     = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $rawStockType = $_POST['stock_type'] ?? $purchase['stock_type'];
    $stockType    = ($rawStockType === 'gia_single') ? 'gia_single' : 'parcel';
    $giaNo        = !empty($_POST['gia_number']) ? trim($_POST['gia_number']) : null;
    $shape        = $_POST['shape'] ?? ($purchase['shape'] ?? 'Round');
    $color        = $_POST['color'] ?? ($purchase['color'] ?? 'G');
    $clarity      = $_POST['clarity'] ?? ($purchase['clarity'] ?? 'VS1');
    $colorTag     = !empty($_POST['color_tag']) ? trim($_POST['color_tag']) : ($purchase['color_tag'] ?? null);
    $clarityTag   = !empty($_POST['clarity_tag']) ? trim($_POST['clarity_tag']) : ($purchase['clarity_tag'] ?? null);

    if ($stockType === 'parcel') {
        $shape   = !empty($_POST['shape']) ? $_POST['shape'] : ($purchase['shape'] ?? 'Mix');
        $color   = !empty($_POST['color']) ? $_POST['color'] : (!empty($_POST['color_tag']) ? $_POST['color_tag'] : ($purchase['color'] ?? 'White'));
        $clarity = !empty($_POST['clarity']) ? $_POST['clarity'] : (!empty($_POST['clarity_tag']) ? $_POST['clarity_tag'] : ($purchase['clarity'] ?? 'Clean'));
    }
    $pcs          = max(1, (int)($_POST['pcs'] ?? $purchase['pcs']));
    $weight       = max(0.0, round((float)($_POST['weight'] ?? $purchase['weight']), 3));

    $paymentCurrency = $_POST['payment_currency'] ?? ($purchase['payment_currency'] ?? 'INR');
    $dollarPerCt  = null;
    $dollarRate   = null;
    $pricePerCt   = 0.00;

    if ($paymentCurrency === 'USD') {
        $dollarPerCt = (isset($_POST['dollar_per_carat']) && $_POST['dollar_per_carat'] !== '') ? max(0.0, (float)$_POST['dollar_per_carat']) : null;
        $dollarRate  = (isset($_POST['dollar_rate']) && $_POST['dollar_rate'] !== '') ? max(0.0, (float)$_POST['dollar_rate']) : null;
        if ($dollarPerCt && $dollarRate && $dollarRate > 0) {
            $pricePerCt = round($dollarPerCt * $dollarRate, 2);
        } else {
            $pricePerCt = 0.00; // Stand Dollar deal
        }
    } else {
        $paymentCurrency = 'INR';
        $pricePerCt      = max(0.0, (float)($_POST['price_per_carat_inr'] ?? 0.00));
    }

    $paymentAmt   = max(0.0, round($weight * $pricePerCt, 2));
    $acPct        = max(0.0, (float)($_POST['ac_percent'] ?? 0.00));
    $acAmt        = max(0.0, round($paymentAmt * ($acPct / 100), 2));
    $subTotal     = max(0.0, round($paymentAmt - $acAmt, 2));
    $brokeragePct = max(0.0, (float)($_POST['brokerage_percent'] ?? 0.00));
    $brokerageAmt = max(0.0, round($subTotal * ($brokeragePct / 100), 2));
    $gstPct       = max(0.0, (float)($_POST['gst_percent'] ?? 0.00));
    $gstAmt       = max(0.0, round($subTotal * ($gstPct / 100), 2));
    if ($gstAmt == 0 && !empty($_POST['gst_amount'])) {
        $gstAmt = max(0.0, (float)$_POST['gst_amount']);
    }
    $otherExpense = max(0.0, (float)($_POST['other_expense'] ?? 0.00));

    $partyBillAmt = max(0.0, round($subTotal + $gstAmt + $otherExpense, 2));
    $netPayment   = max(0.0, round($subTotal + $brokerageAmt + $gstAmt + $otherExpense, 2));
    $acGstAmt     = max(0.0, round($acAmt + $gstAmt, 2)); // backward compat

    $dueDays      = max(0, (int)($_POST['due_days'] ?? $purchase['due_days']));
    $dueDate      = date('Y-m-d', strtotime($purchaseDate . " +$dueDays days"));

    $visionaryPrice = (isset($_POST['visionary_price']) && $_POST['visionary_price'] !== '') ? max(0.0, (float)$_POST['visionary_price']) : null;
    $totalVisionaryPrice = ($visionaryPrice !== null && $weight > 0) ? max(0.0, round($visionaryPrice * $weight, 2)) : null;

    $status       = $_POST['payment_status'] ?? ($purchase['payment_status'] ?? 'pending');
    $chukvyaDate  = !empty($_POST['chukvya_date']) ? $_POST['chukvya_date'] : null;
    $paidAmount   = max(0.0, ($status === 'paid') ? $partyBillAmt : (float)($_POST['paid_amount'] ?? ($purchase['paid_amount'] ?? 0.00)));
    if ($paidAmount >= $partyBillAmt && $partyBillAmt > 0) {
        $status = 'paid';
    } elseif ($paidAmount > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    $bankAccId    = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $notes        = trim($_POST['notes'] ?? '');

    // GIA Certified Diamond Specifications & Measurements
    $stockIdCustom = !empty($_POST['stock_id_custom']) ? trim($_POST['stock_id_custom']) : ($purchase['stock_id_custom'] ?? null);
    $cut           = null;
    $polish        = !empty($_POST['polish']) ? trim($_POST['polish']) : ($purchase['polish'] ?? null);
    $symmetry      = !empty($_POST['symmetry']) ? trim($_POST['symmetry']) : ($purchase['symmetry'] ?? null);
    $fluorescence  = !empty($_POST['fluorescence']) ? trim($_POST['fluorescence']) : ($purchase['fluorescence'] ?? null);

    $rawKeySymbols = $_POST['key_symbols'] ?? [];
    if (is_array($rawKeySymbols)) {
        $cleanSymbols = array_filter(array_map('trim', $rawKeySymbols));
        $keySymbols   = !empty($cleanSymbols) ? implode(', ', $cleanSymbols) : null;
    } else {
        $keySymbols   = trim((string)$rawKeySymbols);
        if ($keySymbols === '') $keySymbols = ($purchase['key_symbols'] ?? null);
    }

    $lengthMm      = (isset($_POST['length_mm']) && $_POST['length_mm'] !== '') ? max(0.0, (float)$_POST['length_mm']) : null;
    $widthMm       = (isset($_POST['width_mm']) && $_POST['width_mm'] !== '') ? max(0.0, (float)$_POST['width_mm']) : null;
    $depthMm       = (isset($_POST['depth_mm']) && $_POST['depth_mm'] !== '') ? max(0.0, (float)$_POST['depth_mm']) : null;
    $depthPct      = ($depthMm !== null && $widthMm !== null && $widthMm > 0) ? max(0.0, round(($depthMm / $widthMm) * 100, 2)) : null;
    $ratio         = ($lengthMm !== null && $widthMm !== null && $widthMm > 0) ? max(0.0, round($lengthMm / $widthMm, 2)) : null;

    // Parcel Trade Specifications
    $jangadNo      = !empty($_POST['jangad_no']) ? trim($_POST['jangad_no']) : null;
    $detail        = !empty($_POST['detail']) ? trim($_POST['detail']) : null;
    $place         = !empty($_POST['place']) ? trim($_POST['place']) : ($purchase['place'] ?? 'Surat');
    $destination   = !empty($_POST['destination']) ? trim($_POST['destination']) : ($purchase['destination'] ?? 'Surat');
    $safe          = !empty($_POST['safe']) ? trim($_POST['safe']) : ($purchase['safe'] ?? 'OFC');
    $dabba         = !empty($_POST['dabba']) ? trim($_POST['dabba']) : ($purchase['dabba'] ?? null);

    // Validate Shape-wise breakdown for Parcel purchases
    $shapeItems = $_POST['shape_items'] ?? [];
    $shapeValResult = null;
    if ($stockType === 'parcel') {
        $shapeValResult = validate_parcel_shape_breakdown($pcs, $weight, $shapeItems);
    }

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને પાર્ટી અને વજન યોગ્ય રીતે દાખલ કરો (વજન ક્યારેય ૦ કે માઈનસ ન હોવું જોઈએ).');
    } elseif ($stockType === 'parcel' && !$shapeValResult['valid']) {
        set_flash('error', $shapeValResult['error']);
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCt === null || $dollarPerCt <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડૉલર કરન્સીમાં ડૉલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCt <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $oldPartyBill = round((float)$purchase['payment_amount'] - (float)($purchase['ac_amount'] ?? 0) + (float)($purchase['gst_amount'] ?? $purchase['ac_gst_amount']) + (float)($purchase['other_expense'] ?? 0), 2);
            $billDiff = $partyBillAmt - $oldPartyBill;

            $stmt = $pdo->prepare("UPDATE polish_purchases SET 
                purchase_date = ?, payment_currency = ?, party_id = ?, broker_id = ?, stock_type = ?, gia_number = ?,
                shape = ?, color = ?, color_tag = ?, clarity = ?, cut = ?, polish = ?, symmetry = ?, fluorescence = ?, key_symbols = ?,
                length_mm = ?, width_mm = ?, depth_mm = ?, depth_pct = ?, ratio = ?, stock_id_custom = ?,
                jangad_no = ?, clarity_tag = ?, detail = ?, place = ?,
                destination = ?, safe = ?, dabba = ?,
                pcs = ?, weight = ?, dollar_per_carat = ?,
                dollar_rate = ?, price_per_carat_inr = ?, payment_amount = ?, brokerage_percent = ?,
                brokerage_amount = ?, gst_percent = ?, ac_percent = ?, ac_amount = ?, gst_amount = ?, ac_gst_amount = ?, other_expense = ?, net_payment = ?, due_days = ?,
                due_date = ?, payment_status = ?, paid_amount = ?, chukvya_date = ?, bank_account_id = ?, notes = ?, visionary_price = ?, total_visionary_price = ?
                WHERE id = ?");

            $stmt->execute([
                $purchaseDate, $paymentCurrency, $partyId, $brokerId, $stockType, $giaNo,
                $shape, $color, $colorTag, $clarity, $cut, $polish, $symmetry, $fluorescence, $keySymbols,
                $lengthMm, $widthMm, $depthMm, $depthPct, $ratio, $stockIdCustom,
                $jangadNo, $clarityTag, $detail, $place,
                $destination, $safe, $dabba,
                $pcs, $weight, $dollarPerCt,
                $dollarRate, $pricePerCt, $paymentAmt, $brokeragePct,
                $brokerageAmt, $gstPct, $acPct, $acAmt, $gstAmt, $acGstAmt, $otherExpense, $netPayment, $dueDays,
                $dueDate, $status, $paidAmount, $chukvyaDate, $bankAccId, $notes, $visionaryPrice, $totalVisionaryPrice, $id
            ]);

            // Adjust party ledger balance if payment pending
            if ($partyId === (int)$purchase['party_id'] && $purchase['payment_status'] !== 'paid') {
                if ($billDiff != 0) {
                    update_party_balance($partyId, -$billDiff);
                }
            }

            $costPerCt = $weight > 0 ? round($netPayment / $weight, 2) : 0.00;

            // Update Single Parcel Stock and Shape breakdown
            if ($stockType === 'parcel') {
                $stkRow = $pdo->prepare("SELECT id, packet_no FROM polish_stocks WHERE polish_purchase_id = ? AND stock_type = 'parcel' LIMIT 1");
                $stkRow->execute([$id]);
                $curParcelStk = $stkRow->fetch(PDO::FETCH_ASSOC);
                $stkId = (int)($curParcelStk['id'] ?? 0);
                if ($stkId > 0) {
                    $updPktNo = !empty($stockIdCustom) ? $stockIdCustom : ($curParcelStk['packet_no'] ?? ('PARCEL-' . $purchase['purchase_no']));
                    $primaryShape = !empty($shapeValResult['clean_rows'][0]['shape']) ? $shapeValResult['clean_rows'][0]['shape'] : $shape;
                    $pdo->prepare("UPDATE polish_stocks SET 
                        packet_no = ?, shape = ?, clarity = ?, color = ?, color_tag = ?, clarity_tag = ?, detail = ?,
                        destination = ?, safe = ?, dabba = ?, current_location = ?,
                        total_pcs = ?, total_weight = ?, available_pcs = ?, available_weight = ?, cost_per_carat = ?, visionary_price = ?, notes = ? 
                        WHERE id = ?")
                        ->execute([$updPktNo, $primaryShape, $clarity, $color, $colorTag, $clarityTag, $detail, $destination, $safe, $dabba, $destination, $pcs, $weight, $pcs, $weight, $costPerCt, $visionaryPrice, $notes, $stkId]);
                }
                save_polish_parcel_shapes($pdo, 'purchase', $id, $stkId > 0 ? $stkId : null, $shapeValResult['clean_rows']);
            } else {
                // Update GIA Single Stock in polish_stocks
                $stkRow = $pdo->prepare("SELECT id, packet_no FROM polish_stocks WHERE polish_purchase_id = ? AND stock_type = 'gia_single' LIMIT 1");
                $stkRow->execute([$id]);
                $curGiaStk = $stkRow->fetch(PDO::FETCH_ASSOC);
                if ($curGiaStk) {
                    $updPktNo = !empty($stockIdCustom) ? $stockIdCustom : $curGiaStk['packet_no'];
                    $pdo->prepare("UPDATE polish_stocks SET 
                        packet_no = ?, gia_number = ?, shape = ?, clarity = ?, color = ?, color_tag = ?,
                        cut = ?, polish = ?, symmetry = ?, fluorescence = ?, key_symbols = ?,
                        length_mm = ?, width_mm = ?, depth_mm = ?, depth_pct = ?, ratio = ?,
                        notes = ?, total_weight = ?, available_weight = ?, cost_per_carat = ?, visionary_price = ?,
                        destination = ?, safe = ?, dabba = ?, current_location = ?
                        WHERE id = ?")
                        ->execute([
                            $updPktNo, $giaNo, $shape, $clarity, $color, $colorTag,
                            $cut, $polish, $symmetry, $fluorescence, $keySymbols,
                            $lengthMm, $widthMm, $depthMm, $depthPct, $ratio,
                            $notes, $weight, $weight, $costPerCt, $visionaryPrice,
                            $destination, $safe, $dabba, $destination, (int)$curGiaStk['id']
                        ]);
                }
            }

            $pdo->commit();
            log_activity('Polish Purchase Edited', 'Polish', $id, "Updated purchase {$purchase['purchase_no']}: $weight ct, Net: $netPayment");
            set_flash('success', "Polish ખરીદી એન્ટ્રી {$purchase['purchase_no']} સફળતાપૂર્વક અપડેટ થઈ ગઈ છે.");
            $targetTab = ($stockType === 'parcel') ? 'parcel_purchase' : 'gia_purchase';
            $targetSec = ($stockType === 'parcel') ? 'parcel' : 'gia';
            header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $targetTab . '&section=' . $targetSec);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'Polish ખરીદી અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$linkedStock = $pdo->prepare("SELECT * FROM polish_stocks WHERE polish_purchase_id = ? LIMIT 1");
$linkedStock->execute([$id]);
$curStock = $linkedStock->fetch(PDO::FETCH_ASSOC);

$stockIdVal  = !empty($purchase['stock_id_custom']) ? $purchase['stock_id_custom'] : ($curStock['packet_no'] ?? '');
$giaNoVal    = !empty($purchase['gia_number']) ? $purchase['gia_number'] : ($curStock['gia_number'] ?? '');
$shapeVal    = !empty($purchase['shape']) ? $purchase['shape'] : ($curStock['shape'] ?? 'Round');
$colorVal    = !empty($purchase['color']) ? $purchase['color'] : ($curStock['color'] ?? 'G');
$clarityVal  = !empty($purchase['clarity']) ? $purchase['clarity'] : ($curStock['clarity'] ?? 'VS1');
$polishVal   = !empty($purchase['polish']) ? $purchase['polish'] : ($curStock['polish'] ?? 'EX');
$symVal      = !empty($purchase['symmetry']) ? $purchase['symmetry'] : ($curStock['symmetry'] ?? 'EX');
$floroVal    = !empty($purchase['fluorescence']) ? $purchase['fluorescence'] : ($curStock['fluorescence'] ?? 'None');
$keySymVal   = !empty($purchase['key_symbols']) ? $purchase['key_symbols'] : ($curStock['key_symbols'] ?? '');
$lengthVal   = (isset($purchase['length_mm']) && $purchase['length_mm'] !== null) ? (float)$purchase['length_mm'] : ((isset($curStock['length_mm']) && $curStock['length_mm'] !== null) ? (float)$curStock['length_mm'] : '');
$widthVal    = (isset($purchase['width_mm']) && $purchase['width_mm'] !== null) ? (float)$purchase['width_mm'] : ((isset($curStock['width_mm']) && $curStock['width_mm'] !== null) ? (float)$curStock['width_mm'] : '');
$depthVal    = (isset($purchase['depth_mm']) && $purchase['depth_mm'] !== null) ? (float)$purchase['depth_mm'] : ((isset($curStock['depth_mm']) && $curStock['depth_mm'] !== null) ? (float)$curStock['depth_mm'] : '');
$depthPctVal = (isset($purchase['depth_pct']) && $purchase['depth_pct'] !== null) ? (float)$purchase['depth_pct'] : ((isset($curStock['depth_pct']) && $curStock['depth_pct'] !== null) ? (float)$curStock['depth_pct'] : '');
$ratioVal    = (isset($purchase['ratio']) && $purchase['ratio'] !== null) ? (float)$purchase['ratio'] : ((isset($curStock['ratio']) && $curStock['ratio'] !== null) ? (float)$curStock['ratio'] : '');
$jangadVal   = !empty($purchase['jangad_no']) ? $purchase['jangad_no'] : ($curStock['jangad_no'] ?? '');
$colorTagVal = !empty($purchase['color_tag']) ? $purchase['color_tag'] : ($curStock['color_tag'] ?? '');
$clarityTagVal = !empty($purchase['clarity_tag']) ? $purchase['clarity_tag'] : ($curStock['clarity_tag'] ?? '');
$detailVal   = !empty($purchase['detail']) ? $purchase['detail'] : ($curStock['detail'] ?? '');
$placeVal    = !empty($purchase['place']) ? $purchase['place'] : ($curStock['current_location'] ?? 'Surat');
$destinationVal = !empty($purchase['destination']) ? $purchase['destination'] : ($curStock['destination'] ?? 'Surat');
$safeVal        = !empty($purchase['safe']) ? $purchase['safe'] : ($curStock['safe'] ?? 'M1');
$dabbaVal       = !empty($purchase['dabba']) ? $purchase['dabba'] : ($curStock['dabba'] ?? '');

// Parse selected key symbols for multi-select
$selectedKeySymbols = [];
if (!empty($keySymVal)) {
    $selectedKeySymbols = array_map('trim', explode(',', (string)$keySymVal));
}

// Find initial descriptions for selected GIA Color Tag
$initialGiaDescriptions = [];
if (!empty($colorTagVal)) {
    foreach ($masterGiaColorTags as $gt) {
        if (strcasecmp($gt['tag_name'], $colorTagVal) === 0 && !empty($gt['descriptions'])) {
            $initialGiaDescriptions = $gt['descriptions'];
            break;
        }
    }
}

$pageTitle = ($isParcel ? 'PARCEL Polish ખરીદી એડિટ: ' : 'GIA Polish ખરીદી એડિટ: ') . $purchase['purchase_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon($isParcel ? 'boxes-stacked' : 'gem', 'text-primary me-2') ?><?= $isParcel ? 'PARCEL Polish ખરીદી એડિટ: ' : 'GIA Polish ખરીદી એડિટ: ' ?><span class="font-mono text-primary"><?= e($purchase['purchase_no']) ?></span>
        </h4>
        <div class="text-muted small"><?= $isParcel ? 'પાર્સલ લૂઝ હીરાની ખરીદી, કિંમત અને Shape Breakdown ફેરફાર' : 'GIA સર્ટિફાઇડ સિંગલ સ્ટોનની ખરીદી, સ્પેસિફિકેશન [૧ - ૩૧] અને હિસાબ ફેરફાર' ?></div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=<?= $isParcel ? 'parcel_purchase' : 'gia_purchase' ?>&section=<?= $isParcel ? 'parcel' : 'gia' ?>" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>ખરીદી લિસ્ટ પર પાછા જાઓ
        </a>
    </div>
</div>

<form action="<?= BASE_URL ?>/modules/polish_purchase/edit.php?id=<?= $id ?>" method="POST" id="polishPurchaseForm">
    <?= csrf_field() ?>
    <input type="hidden" name="stock_type" id="stock_type_hidden" value="<?= $isParcel ? 'parcel' : 'gia_single' ?>">

    <!-- Hidden calculation carriers for database storage -->
    <input type="hidden" name="payment_amount" id="payment_amount" value="<?= (float)$purchase['payment_amount'] ?>">
    <input type="hidden" name="brokerage_amount" id="brokerage_amount" value="<?= (float)$purchase['brokerage_amount'] ?>">
    <input type="hidden" name="net_payment" id="net_payment" value="<?= (float)$purchase['net_payment'] ?>">
    <input type="hidden" name="ac_amount" id="ac_amount" value="<?= (float)($purchase['ac_amount'] ?? 0.00) ?>">
    <input type="hidden" name="gst_amount" id="gst_amount" value="<?= (float)($purchase['gst_amount'] ?? 0.00) ?>">
    <input type="hidden" name="ac_gst_amount" id="ac_gst_amount" value="<?= (float)($purchase['ac_gst_amount'] ?? $purchase['gst_amount'] ?? 0.00) ?>">
    <input type="hidden" name="total_visionary_price" id="total_visionary_price" value="<?= (float)($purchase['total_visionary_price'] ?? 0.00) ?>">
    <input type="hidden" name="ratio" id="gia_ratio" value="<?= e((string)$ratioVal) ?>">
    <input type="hidden" name="depth_pct" id="gia_depth_pct" value="<?= e((string)$depthPctVal) ?>">

    <div class="row g-3">
        <!-- ================= LEFT COLUMN: SPECIFICATIONS & INPUTS ================= -->
        <div class="col-12 col-lg-8">

        <?php if (!$isParcel): ?>
            <!-- ================= GIA PURCHASE FORM (31 INPUTS EXACT INDEX SEQUENCE) ================= -->

            <!-- Group 1: ખરીદી અને સ્ટોક પ્રાથમિક વિગત (Basic Info & Quantity) - Inputs 1 to 5 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header d-flex justify-content-between align-items-center">
                    <h6 class="header-title mb-0">
                        <span class="header-icon icon-purple"><?= svg_icon('file-invoice') ?></span>
                        ૧. ખરીદી અને સ્ટોક પ્રાથમિક વિગત [૧ - ૫]
                    </h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono px-2 py-1">
                        💎 GIA Certified Single
                    </span>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 1. Purchase No* (Auto - Disable) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૧. ખરીદી નંબર (Purchase No) * <span class="badge bg-light text-muted border font-mono">Auto - Disabled</span>
                            </label>
                            <input type="text" name="purchase_no" id="purchase_no" class="form-control fw-bold bg-light font-mono" value="<?= e($purchase['purchase_no']) ?>" readonly required style="cursor: not-allowed; background-color: #f8fafc;">
                            <div class="form-text text-muted" style="font-size: 0.72rem;">સિસ્ટમ દ્વારા ઓટો જનરેટ થયેલ ખરીદી નંબર.</div>
                        </div>

                        <!-- 2. Purchase Date* (By Default Today & editable) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૨. ખરીદી તારીખ (Purchase Date) * <span class="badge bg-light text-primary border font-mono">Editable</span>
                            </label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control font-mono" value="<?= e($purchase['purchase_date']) ?>" required>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">ખરીદીની તારીખ (જરૂર મુજબ બદલી શકાય છે).</div>
                        </div>

                        <!-- 3. Packet Number (Input only) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૩. પેકેટ નંબર (Packet Number) <span class="badge bg-light text-muted border font-mono">Input Only</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><?= svg_icon('gem', 'text-primary') ?></span>
                                <input type="text" name="stock_id_custom" id="stock_id_custom" class="form-control font-mono fw-bold text-primary" value="<?= e($stockIdVal) ?>" placeholder="દા.ત. POL-GIA-...">
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">સ્ટોકમાં નોંધવા માટે પેકેટ નંબર.</div>
                        </div>

                        <!-- 4. Pcs* (By Default 1 But it should be editable) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">
                                ૪. નંગ (Pcs) * <span class="badge bg-light text-success border font-mono">Editable</span>
                            </label>
                            <div class="input-group">
                                <input type="number" name="pcs" id="pcs" class="form-control fw-bold font-mono text-center" value="<?= (int)($purchase['pcs'] ?? 1) ?>" min="1" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseInt(this.value)<1||isNaN(parseInt(this.value))) this.value=1;">
                                <span class="input-group-text">Pc</span>
                            </div>
                        </div>

                        <!-- 5. Weight (Ct)* -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-primary">
                                ૫. વજન (Weight Ct) *
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.001" min="0" name="weight" id="weight" class="form-control fw-bold font-mono text-primary text-end fs-6" value="<?= (float)$purchase['weight'] ?>" required placeholder="0.000" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text fw-bold text-primary">Ct</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group 2: GIA હીરા સ્પેસિફિકેશન & 4Cs (Diamond Specification) - Inputs 6 to 13 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-cyan"><?= svg_icon('award') ?></span>
                        ૨. GIA હીરા સ્પેસિફિકેશન & 4Cs [૬ - ૧૩]
                    </h6>
                </div>
                <div class="erp-card-body">
                    <!-- 6. Shape, 7. Clarity, 8. Color tag, 9. Color Description -->
                    <div class="row g-3 mb-3">
                        <!-- 6. Shape (Get Dropdown from Setting) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">
                                ૬. શેપ (Shape) *
                            </label>
                            <select name="shape" id="shape_select" class="form-select select-search" required>
                                <option value="">શેપ પસંદ કરો...</option>
                                <?php foreach ($masterShapes as $sh): ?>
                                    <option value="<?= e($sh['name']) ?>" <?= strcasecmp($sh['name'], $shapeVal) === 0 ? 'selected' : '' ?>><?= e($sh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 7. Clarity (Get Dropdown from Setting) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">
                                ૭. ક્લેરિટી (Clarity) *
                            </label>
                            <select name="clarity" id="clarity_select" class="form-select select-search" required>
                                <option value="">ક્લેરિટી પસંદ કરો...</option>
                                <?php foreach ($masterClarities as $cl): ?>
                                    <option value="<?= e($cl['code']) ?>" <?= strcasecmp($cl['code'], $clarityVal) === 0 ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 8. Color tag (Get Dropdown from Setting - settings.php?tab=gia_colors) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark d-flex justify-content-between align-items-center">
                                <span>૮. કલર ટેગ (Color Tag) *</span>
                                <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=gia_colors" target="_blank" class="text-primary text-decoration-none" style="font-size: 0.72rem;" title="કલર માસ્ટર સેટિંગ"><?= svg_icon('gear', 'me-0.5', 12) ?>સેટિંગ</a>
                            </label>
                            <select name="color_tag" id="gia_color_tag_select" class="form-select select-search" required>
                                <option value="">-- કલર ટેગ પસંદ કરો --</option>
                                <?php foreach ($masterGiaColorTags as $gt): ?>
                                    <option value="<?= e($gt['tag_name']) ?>" data-tag-id="<?= $gt['id'] ?>" <?= strcasecmp($gt['tag_name'], $colorTagVal) === 0 ? 'selected' : '' ?>><?= e($gt['tag_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 9. Color Description (Get Dropdown from Setting - dynamic, no pills) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">
                                ૯. કલર ડિસ્ક્રિપ્શન (Color Desc) *
                            </label>
                            <select name="color" id="gia_color_select" class="form-select select-search" required>
                                <option value="">કલર ડિસ્ક્રિપ્શન પસંદ કરો...</option>
                                <?php if (!empty($initialGiaDescriptions)): ?>
                                    <?php foreach ($initialGiaDescriptions as $d): ?>
                                        <option value="<?= e($d['description_name']) ?>" <?= strcasecmp($d['description_name'], $colorVal) === 0 ? 'selected' : '' ?>><?= e($d['description_name']) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($masterColors as $c): ?>
                                        <option value="<?= e($c['code']) ?>" <?= (strcasecmp($c['code'], $colorVal) === 0 || strcasecmp($c['name'], $colorVal) === 0) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- 10. Polish, 11. Symmetry, 12. Floro -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="row g-3">
                            <!-- 10. Polish (Get Dropdown from Setting) -->
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark">
                                    ૧૦. પોલિશ (Polish)
                                </label>
                                <select name="polish" id="polish_select" class="form-select select-search">
                                    <option value="">પોલિશ પસંદ કરો...</option>
                                    <?php foreach ($masterPolish as $pol): ?>
                                        <option value="<?= e($pol['code']) ?>" <?= strcasecmp($pol['code'], $polishVal) === 0 ? 'selected' : '' ?>><?= e($pol['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 11. Symmetry (Get Dropdown from Setting) -->
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark">
                                    ૧૧. સિમિટ્રી (Symmetry)
                                </label>
                                <select name="symmetry" id="symmetry_select" class="form-select select-search">
                                    <option value="">સિમિટ્રી પસંદ કરો...</option>
                                    <?php foreach ($masterSymmetry as $sym): ?>
                                        <option value="<?= e($sym['code']) ?>" <?= strcasecmp($sym['code'], $symVal) === 0 ? 'selected' : '' ?>><?= e($sym['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 12. Floro (Get Dropdown from Setting) -->
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark">
                                    ૧૨. ફ્લોરો (Fluorescence)
                                </label>
                                <select name="fluorescence" id="fluorescence_select" class="form-select select-search">
                                    <option value="">ફ્લોરો પસંદ કરો...</option>
                                    <?php foreach ($masterFluorescence as $fl): ?>
                                        <option value="<?= e($fl['code']) ?>" <?= strcasecmp($fl['code'], $floroVal) === 0 ? 'selected' : '' ?>><?= e($fl['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 13. Key to Symbol (Get Dropdown from Setting - Multiple Selection, no badges below) -->
                    <div>
                        <label class="form-label small fw-bold text-dark d-flex justify-content-between align-items-center mb-1">
                            <span>૧૩. કી સિમ્બોલ (Key to Symbol) <span class="badge bg-primary-subtle text-primary border font-mono">Multiple Selection</span></span>
                            <span class="text-muted" style="font-size: 0.72rem;">ડ્રોપડાઉન લિસ્ટમાંથી સર્ચ કરી ૧ કે વધુ સિમ્બોલ સિલેક્ટ કરો</span>
                        </label>
                        <div>
                            <select name="key_symbols[]" id="key_symbols_select" multiple class="form-select select-search" placeholder="કી સિમ્બોલ શોધો અથવા પસંદ કરો... (Select Multiple)">
                                <?php foreach ($masterKeySymbols as $sym): ?>
                                    <option value="<?= e($sym['name']) ?>" <?= in_array($sym['name'], $selectedKeySymbols) ? 'selected' : '' ?>><?= e($sym['name']) ?> (<?= e($sym['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group 3: માપ સાઈઝ, રેશિયો & GIA રિપોર્ટ (Measurements & Report) - Inputs 14 to 19 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('ruler-combined') ?></span>
                        ૩. માપ સાઈઝ, રેશિયો & GIA રિપોર્ટ [૧૪ - ૧૯]
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 14. Length -->
                        <div class="col-6 col-md-4">
                            <label class="form-label small fw-bold text-dark">
                                ૧૪. લંબાઈ (Length mm)
                            </label>
                            <input type="number" step="0.01" min="0" name="length_mm" id="gia_length_mm" class="form-control font-mono fw-bold" placeholder="6.45" value="<?= $lengthVal !== '' ? (float)$lengthVal : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                        </div>

                        <!-- 15. width -->
                        <div class="col-6 col-md-4">
                            <label class="form-label small fw-bold text-dark">
                                ૧૫. પહોળાઈ (Width mm)
                            </label>
                            <input type="number" step="0.01" min="0" name="width_mm" id="gia_width_mm" class="form-control font-mono fw-bold" placeholder="6.42" value="<?= $widthVal !== '' ? (float)$widthVal : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                        </div>

                        <!-- 16. Depth -->
                        <div class="col-6 col-md-4">
                            <label class="form-label small fw-bold text-dark">
                                ૧૬. ઊંડાઈ (Depth mm)
                            </label>
                            <input type="number" step="0.01" min="0" name="depth_mm" id="gia_depth_mm" class="form-control font-mono fw-bold" placeholder="3.98" value="<?= $depthVal !== '' ? (float)$depthVal : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                        </div>

                        <!-- 17. Ratio (Length / Width) -->
                        <div class="col-6 col-md-4">
                            <label class="form-label small fw-bold text-success">
                                ૧૭. Ratio (Length / Width) <span class="badge bg-success-subtle text-success border">Auto</span>
                            </label>
                            <input type="text" id="disp_ratio_input" class="form-control bg-light font-mono fw-bold text-success" readonly value="<?= $ratioVal !== '' ? number_format((float)$ratioVal, 2) : '0.00' ?>" style="cursor: not-allowed;">
                        </div>

                        <!-- 18. Depth% ((Depth/width)*100) -->
                        <div class="col-6 col-md-4">
                            <label class="form-label small fw-bold text-success">
                                ૧૮. Depth% ((Depth/Width)*100) <span class="badge bg-success-subtle text-success border">Auto</span>
                            </label>
                            <input type="text" id="disp_depth_pct_input" class="form-control bg-light font-mono fw-bold text-success" readonly value="<?= $depthPctVal !== '' ? number_format((float)$depthPctVal, 2) . ' %' : '0.00 %' ?>" style="cursor: not-allowed;">
                        </div>

                        <!-- 19. GIA No (Input) -->
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">
                                ૧૯. GIA નંબર (GIA Report No) *
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><?= svg_icon('award', 'text-warning') ?></span>
                                <input type="text" name="gia_number" id="gia_number" class="form-control font-mono fw-bold text-dark" value="<?= e($giaNoVal) ?>" placeholder="દા.ત. 2489012345" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group 4: ભાવ, કપાત, ખર્ચ & નેટ પેમેન્ટ (Commercial Engine) - Inputs 20 to 25 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-gold"><?= svg_icon('calculator') ?></span>
                        ૪. ભાવ, કપાત, ખર્ચ & નેટ પેમેન્ટ [૨૦ - ૨૫]
                    </h6>
                </div>
                <div class="erp-card-body">
                    <!-- 20. Price per ct* (Atyare che e rite j INR And Dollar) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <label class="form-label small fw-bold text-dark mb-0">
                                ૨૦. પ્રતિ કેરેટ ભાવ (Price per ct) *
                            </label>
                            <div class="d-flex gap-2">
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="radio" name="payment_currency" id="curr_inr" value="INR" <?= ($purchase['payment_currency'] ?? 'INR') === 'INR' ? 'checked' : '' ?>>
                                    <label class="form-check-label small fw-bold" for="curr_inr">🇮🇳 Indian (₹)</label>
                                </div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="radio" name="payment_currency" id="curr_usd" value="USD" <?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? 'checked' : '' ?>>
                                    <label class="form-check-label small fw-bold" for="curr_usd">💵 Dollar ($)</label>
                                </div>
                            </div>
                        </div>

                        <div id="inr_currency_box" style="<?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? 'display: none;' : '' ?>">
                            <div class="input-group">
                                <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                                <input type="number" step="0.01" min="0" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg fw-bold text-dark font-mono" placeholder="0.00" value="<?= (float)($purchase['price_per_carat_inr'] ?? 0.00) > 0 ? (float)$purchase['price_per_carat_inr'] : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                            </div>
                        </div>

                        <div id="usd_currency_box" style="<?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? '' : 'display: none;' ?>">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-success fw-bold">$</span>
                                        <input type="number" step="0.01" min="0" name="dollar_per_carat" id="dollar_per_carat" class="form-control form-control-lg font-mono fw-bold" placeholder="Dollar / Ct" value="<?= (float)($purchase['dollar_per_carat'] ?? 0.00) > 0 ? (float)$purchase['dollar_per_carat'] : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-muted">₹</span>
                                        <input type="number" step="0.01" min="0" name="dollar_rate" id="dollar_rate" class="form-control form-control-lg font-mono" placeholder="Dollar Rate (ખાલી = Stand)" value="<?= (float)($purchase['dollar_rate'] ?? 0.00) > 0 ? (float)$purchase['dollar_rate'] : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 21, 22, 23, 24, 25 Row -->
                    <div class="row g-3 mb-2">
                        <!-- 21. Less (AC %) -->
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-bold text-muted">
                                ૨૧. Less (AC %)
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="ac_percent" id="ac_percent" class="form-control font-mono" value="<?= (float)($purchase['ac_percent'] ?? 0.00) ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 22. Brokrage % -->
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-bold text-muted">
                                ૨૨. Brokrage %
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="<?= (float)($purchase['brokerage_percent'] ?? 0.50) ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 23. GST % -->
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-bold text-muted">
                                ૨૩. GST %
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="gst_percent" id="gst_percent" class="form-control font-mono" value="<?= (float)($purchase['gst_percent'] ?? 0.00) ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 24. Other Expense in ₹ -->
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-bold text-dark">
                                ૨૪. Other Expense (₹)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white">₹</span>
                                <input type="number" step="0.01" min="0" name="other_expense" id="other_expense" class="form-control font-mono fw-bold" value="<?= (float)($purchase['other_expense'] ?? 0.00) ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                            </div>
                        </div>

                        <!-- 25. Net payment (Auto calculated readonly) -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-primary d-flex justify-content-between align-items-center">
                                <span>૨૫. Net Payment (કુલ ચૂકવવાની રકમ) *</span>
                                <span class="badge bg-primary text-white font-mono">Formula Calculated</span>
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-primary-subtle text-primary fw-bold">₹</span>
                                <input type="text" id="disp_net_payment_input" class="form-control font-mono fw-bold text-primary fs-5 bg-light" readonly value="₹ <?= number_format((float)($purchase['net_payment'] ?? 0.00), 2) ?>" style="cursor: not-allowed;">
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">
                                Subtotal = (Raw Value = Weight × Price/ct) - Less AC% + Brokerage + GST + Other Expense
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group 5: વેપારી, દલાલ & મુદત તારીખ (Counterparty & Terms) - Inputs 26 to 29 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('users') ?></span>
                        ૫. વેપારી, દલાલ & મુદત તારીખ [૨૬ - ૨૯]
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 26. Party Name (First) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૨૬. પાર્ટીનું નામ (Party Name) *
                            </label>
                            <select name="party_id" id="party_id" class="form-select select-search" required>
                                <option value="">પાર્ટી શોધો અથવા પસંદ કરો...</option>
                                <?php foreach ($parties as $pt): ?>
                                    <option value="<?= $pt['id'] ?>" <?= $pt['id'] == $purchase['party_id'] ? 'selected' : '' ?>><?= e($pt['party_name']) ?> (<?= e($pt['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 27. Broker Name (Second) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૨૭. દલાલનું નામ (Broker Name)
                            </label>
                            <select name="broker_id" id="broker_id" class="form-select select-search">
                                <option value="">Direct (કોઈ દલાલ નથી)</option>
                                <?php foreach ($brokers as $br): ?>
                                    <option value="<?= $br['id'] ?>" <?= $br['id'] == $purchase['broker_id'] ? 'selected' : '' ?>><?= e($br['party_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 28. Due Days (editable, clean input only, no presets) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                ૨૮. મુદત દિવસ (Due Days) *
                            </label>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono fw-bold" value="<?= (int)($purchase['due_days'] ?? 50) ?>" min="0" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseInt(this.value)<0) this.value=0;">
                        </div>

                        <!-- 29. Due date (Auto from Due Days - Disabled) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                ૨૯. ચૂકવણી તારીખ (Due Date) * <span class="badge bg-light text-muted border font-mono">Auto - Disabled</span>
                            </label>
                            <input type="date" name="due_date" id="due_date" class="form-control bg-light font-mono fw-bold" value="<?= e($purchase['due_date'] ?? date('Y-m-d')) ?>" readonly required style="cursor: not-allowed; background-color: #f8fafc;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group 6: સ્ટોક સંગ્રહ લોકેશન & વિઝનરી પ્રાઈસ (Stock Location & Visionary Price) -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('vault') ?></span>
                        ૬. સ્ટોક સંગ્રહ લોકેશન & વિઝનરી પ્રાઈસ (Stock Location & Visionary Price)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <!-- Destination, Safe, Dabba -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1 mb-2">
                            <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#21</span>
                            Destination, Safe, Dabba (સ્ટોક સંગ્રહ લોકેશન)
                        </label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Destination (ડેસ્ટિનેશન)</label>
                                <select name="destination" id="destination" class="form-select font-mono">
                                    <?php foreach ($activeDestinations as $ad): ?>
                                        <option value="<?= e($ad['name']) ?>" <?= strcasecmp($ad['name'], $destinationVal) === 0 ? 'selected' : '' ?>><?= e($ad['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Safe (સેફ / તિજોરી)</label>
                                <select name="safe" id="safe" class="form-select font-mono">
                                    <?php foreach ($activeSafes as $as): ?>
                                        <option value="<?= e($as['name']) ?>" <?= strcasecmp($as['name'], $safeVal) === 0 ? 'selected' : '' ?>><?= e($as['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Dabba (ડબ્બો / બોક્સ)</label>
                                <select name="dabba" id="dabba" class="form-select font-mono">
                                    <option value="">— કોઈ ડબ્બો નથી —</option>
                                    <?php foreach ($activeDabbas as $adb): ?>
                                        <option value="<?= e($adb['name']) ?>" <?= (!empty($dabbaVal) && strcasecmp($adb['name'], $dabbaVal) === 0) ? 'selected' : '' ?>><?= e($adb['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- 30. Visionary Price (Input in $) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">
                                ૩૦. વિઝનરી ભાવ (Visionary Price $ / Ct)
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-purple-subtle text-purple fw-bold">$</span>
                                <input type="number" step="0.01" min="0" name="visionary_price" id="visionary_price" class="form-control font-mono fw-bold text-dark" placeholder="0.00" value="<?= (float)($purchase['visionary_price'] ?? 0.00) > 0 ? (float)$purchase['visionary_price'] : '' ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">અંદાજિત આંતરરાષ્ટ્રીય વિઝનરી ભાવ (Always in Dollar $).</div>
                        </div>

                        <!-- 31. Total Visionary Price (Visionary Price * weight in $) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-purple d-flex justify-content-between align-items-center">
                                <span>૩૧. કુલ વિઝનરી રકમ (Total Visionary Price $)</span>
                                <span class="badge bg-purple text-white font-mono">Visionary × Wt</span>
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-purple-subtle text-purple fw-bold">$</span>
                                <input type="text" id="disp_total_visionary_price" class="form-control bg-light font-mono fw-bold text-purple fs-5" readonly value="<?= (float)($purchase['total_visionary_price'] ?? 0.00) > 0 ? ('$ ' . number_format((float)$purchase['total_visionary_price'], 2)) : '$ 0.00' ?>" style="cursor: not-allowed;">
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">હમેશા ડૉલર ($) માં જ દર્શાવાશે.</div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ================= PARCEL POLISH PURCHASE EDIT FORM (EXACT 23 INPUTS INDEX SEQUENCE) ================= -->

            <!-- CARD 1: ખરીદી અને સ્ટોક પ્રાથમિક વિગત (Basic Info & Quantity) - Inputs 1 to 6 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header d-flex justify-content-between align-items-center">
                    <h6 class="header-title mb-0">
                        <span class="header-icon icon-emerald"><?= svg_icon('boxes-stacked') ?></span>
                        ૧. ખરીદી અને જથ્થો (Basic Info & Quantity)
                    </h6>
                    <span class="badge bg-success-subtle text-success border border-success-subtle font-mono px-2 py-1">
                        📦 PARCEL POLISH
                    </span>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 1. Purchase No* (Auto - Disable) -->
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#1</span>
                                Purchase No (ખરીદી નંબર) *
                            </label>
                            <input type="text" name="purchase_no" class="form-control fw-bold bg-light font-mono" value="<?= e($purchase['purchase_no']) ?>" readonly required style="cursor: not-allowed;">
                        </div>

                        <!-- 2. Purchase Date* (By Default Today & editable) -->
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#2</span>
                                Purchase Date (ખરીદી તારીખ) *
                            </label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control font-mono" value="<?= e($purchase['purchase_date']) ?>" required>
                        </div>

                        <!-- 3. Packet Number (Input only) -->
                        <div class="col-md-12 col-lg-4">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#3</span>
                                Packet Number (પેકેટ નંબર)
                            </label>
                            <input type="text" name="stock_id_custom" id="stock_id_custom" class="form-control font-mono fw-bold text-success" placeholder="દા.ત. PKT-001" value="<?= e($purchase['stock_id_custom'] ?? '') ?>">
                        </div>

                        <!-- 4. Pcs* (By Default blank But it should be editable) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#4</span>
                                Pcs (કુલ નંગ) *
                            </label>
                            <div class="input-group">
                                <input type="number" name="pcs" id="pcs" class="form-control fw-bold font-mono" value="<?= (int)$purchase['pcs'] ?>" placeholder="દા.ત. 100" min="1" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">Pcs</span>
                            </div>
                        </div>

                        <!-- 5. Weight (Ct)* -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-primary d-flex align-items-center gap-1">
                                <span class="badge bg-primary-subtle text-primary font-mono" style="font-size:0.7rem;">#5</span>
                                Weight (Ct) (કુલ વજન કેરેટ) *
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.001" min="0.001" name="weight" id="weight" class="form-control fw-bold text-primary font-mono" placeholder="0.000" value="<?= (float)$purchase['weight'] ?>" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text fw-bold text-primary">Ct</span>
                            </div>
                        </div>

                        <!-- 6. Average Size (Weight/Pcs) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#6</span>
                                Average Size (એવરેજ સાઇઝ: Weight/Pcs)
                            </label>
                            <div class="input-group">
                                <?php $avgSzInit = ((int)$purchase['pcs'] > 0 && (float)$purchase['weight'] > 0) ? round((float)$purchase['weight'] / (int)$purchase['pcs'], 4) : 0.0000; ?>
                                <input type="text" id="disp_avg_size" class="form-control font-mono bg-light text-primary fw-bold" readonly value="<?= number_format($avgSzInit, 4) ?> Ct/pc" style="cursor: not-allowed;">
                                <span class="input-group-text text-muted">Ct/pc</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 2: Shape-wise Breakdown Table - Input 7 -->
            <div class="erp-form-card mb-3 border border-primary-subtle shadow-sm" id="card_shapes_section">
                <div class="erp-card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2 py-2.5 px-3">
                    <h6 class="header-title mb-0 d-flex align-items-center gap-2">
                        <span class="badge bg-primary-subtle text-primary font-mono" style="font-size:0.7rem;">#7</span>
                        <span class="header-icon bg-primary text-white" style="width: 26px; height: 26px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;">
                            <?= svg_icon('shapes', '', 14) ?>
                        </span>
                        <span class="fw-bold">Shape-wise Breakdown Table (શેપ અને નંગ વિભાજન)</span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-primary" id="btn_add_shape_row">
                        <?= svg_icon('plus', 'me-1') ?>Add Row (નવો શેપ ઉમેરો)
                    </button>
                </div>
                <div class="erp-card-body p-0">
                    <div class="p-2.5 bg-light-subtle border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3 small">
                            <span>પાર્સલ નંગ: <strong id="summary_parcel_pcs" class="font-mono text-dark"><?= (int)$purchase['pcs'] ?></strong> Pcs</span>
                            <span>શેપ કુલ નંગ: <strong id="summary_shape_pcs" class="font-mono text-primary"><?= (int)$purchase['pcs'] ?></strong> Pcs</span>
                        </div>
                        <div id="shape_validation_badge">
                            <span class="badge bg-success py-1.5 px-2.5 fs-7 shadow-sm">✓ VALID</span>
                        </div>
                    </div>
                    <div id="shape_validation_msg" class="p-2" style="display: none;"></div>

                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-bordered table-hover align-middle mb-0" id="shapes_table">
                            <thead class="table-light text-muted small text-uppercase" style="position: sticky; top: 0; z-index: 2;">
                                <tr>
                                    <th style="width: 50px;" class="text-center">#</th>
                                    <th>Shape (શેપ) *</th>
                                    <th style="width: 220px;">Pcs (નંગ) *</th>
                                    <th style="width: 70px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="shapes_tbody">
                                <?php if (!empty($existingShapes)): ?>
                                    <?php $sIdx = 1; foreach ($existingShapes as $es): ?>
                                        <tr class="shape-item-row" data-row-index="<?= $sIdx ?>">
                                            <td class="text-center font-mono text-muted small fw-bold row-num"><?= $sIdx ?></td>
                                            <td>
                                                <select name="shape_items[<?= $sIdx ?>][shape]" class="form-select form-select-sm shape-select no-search">
                                                    <?php foreach (get_polish_shapes_list() as $sKey => $sVal): ?>
                                                        <option value="<?= $sKey ?>" <?= $sKey === ($es['shape'] ?? 'Round') ? 'selected' : '' ?>><?= $sVal ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" min="1" step="1" name="shape_items[<?= $sIdx ?>][pcs]" class="form-control form-control-sm font-mono fw-bold shape-qty-input" placeholder="નંગ" value="<?= (int)($es['pcs'] ?? '') ?>" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                                    <span class="input-group-text">Pcs</span>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-xs btn-outline-danger btn-delete-shape-row" title="શેપ રદ કરો">
                                                    &times;
                                                </button>
                                            </td>
                                        </tr>
                                    <?php $sIdx++; endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end">Total Shape Pcs:</td>
                                    <td><span id="footer_shape_qty" class="font-mono text-primary fs-6"><?= (int)$purchase['pcs'] ?></span> Pcs</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CARD 3: ક્લેરિટી અને કલર માસ્ટર્સ (Clarity & Colors) - Inputs 8 to 10 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('gem') ?></span>
                        ૩. ક્લેરિટી અને કલર પસંદગી (Clarity & Colors)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 8. Clarity (Get Dropdown from Setting) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="badge bg-secondary-subtle text-dark font-mono me-1" style="font-size:0.7rem;">#8</span>
                                    Clarity (ક્લેરિટી) *
                                </span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=parcel_clarities" target="_blank" class="small text-primary text-decoration-none" title="Manage Clarities in Settings">+ સેટિંગ્સ</a>
                            </label>
                            <select name="clarity" id="parcel_clarity" class="form-select font-mono fw-bold" required>
                                <option value="">-- ક્લેરિટી પસંદ કરો --</option>
                                <?php foreach ($masterParcelClarities as $mpc): ?>
                                    <option value="<?= e($mpc['name']) ?>" <?= strcasecmp($mpc['name'], $purchase['clarity'] ?? '') === 0 ? 'selected' : '' ?>>
                                        <?= e($mpc['name']) ?> <?= $mpc['code'] ? '('.e($mpc['code']).')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 9. Color tag (Get Dropdown from Setting - settings.php?tab=polish_colors) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="badge bg-secondary-subtle text-dark font-mono me-1" style="font-size:0.7rem;">#9</span>
                                    Color tag (કલર ટેગ) *
                                </span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_colors" target="_blank" class="small text-primary text-decoration-none" title="Manage Color Tags in Settings">+ સેટિંગ્સ</a>
                            </label>
                            <select name="color_tag" id="parcel_color_tag_select" class="form-select font-mono fw-bold">
                                <option value="">-- કલર ટેગ પસંદ કરો --</option>
                                <?php foreach ($masterPolishColorTags as $pct): ?>
                                    <option value="<?= e($pct['tag_name']) ?>" <?= strcasecmp($pct['tag_name'], $purchase['color_tag'] ?? '') === 0 ? 'selected' : '' ?>>
                                        <?= e($pct['tag_name']) ?> <?= $pct['tag_code'] ? '('.e($pct['tag_code']).')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 10. Color Description (Get Dropdown from Setting) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="badge bg-secondary-subtle text-dark font-mono me-1" style="font-size:0.7rem;">#10</span>
                                    Color Description (કલર વિગત)
                                </span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_colors" target="_blank" class="small text-primary text-decoration-none" title="Manage Color Descriptions in Settings">+ સેટિંગ્સ</a>
                            </label>
                            <select name="detail" id="parcel_color_desc_select" class="form-select" data-init-val="<?= e($purchase['detail'] ?? '') ?>">
                                <option value="">-- કલર વિગત પસંદ કરો --</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 4: ભાવ અને હિસાબ ગણતરી (Pricing & Commercials) - Inputs 11 to 16 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('calculator') ?></span>
                        ૪. ભાવ, ડિસ્કાઉન્ટ, દલાલી અને નેટ પેમેન્ટ (Pricing & Commercials)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <!-- 11. Price per ct* (INR / Dollar) -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1 mb-2">
                            <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#11</span>
                            Price per ct (પ્રતિ કેરેટ ભાવ) *
                        </label>
                        <div class="p-2.5 bg-light rounded-3 border mb-2">
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_currency" id="curr_inr" value="INR" <?= ($purchase['payment_currency'] ?? 'INR') === 'INR' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn btn-outline-primary w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_inr">
                                        <span>🇮🇳</span>
                                        <span>1. Indian Currency (₹)</span>
                                    </label>
                                </div>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_currency" id="curr_usd" value="USD" <?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? 'checked' : '' ?> autocomplete="off">
                                    <label class="btn btn-outline-success w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_usd">
                                        <span>💵</span>
                                        <span>2. Dollar Currency ($)</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="inr_currency_box" style="<?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? 'display: none;' : '' ?>">
                            <div class="input-group">
                                <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                                <input type="number" step="0.01" min="0" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg fw-bold text-dark font-mono" placeholder="0.00" value="<?= (float)$purchase['price_per_carat_inr'] ?>" <?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? '' : 'required' ?> onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">/ Ct</span>
                            </div>
                        </div>

                        <div id="usd_currency_box" style="<?= ($purchase['payment_currency'] ?? 'INR') === 'USD' ? '' : 'display: none;' ?>">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-success">Dollar / Ct ($) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-success fw-bold">$</span>
                                        <input type="number" step="0.01" min="0" name="dollar_per_carat" id="dollar_per_carat" class="form-control font-mono fw-bold" placeholder="0.00" value="<?= (float)($purchase['dollar_per_carat'] ?? '') ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted">Dollar Rate (₹/$)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-muted">₹</span>
                                        <input type="number" step="0.01" min="0" name="dollar_rate" id="dollar_rate" class="form-control font-mono" placeholder="ખાલી = Stand" value="<?= (float)($purchase['dollar_rate'] ?? '') ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- 12. Less (AC %) -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#12</span>
                                Less (AC %)
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="ac_percent" id="ac_percent" class="form-control font-mono" value="<?= (float)$purchase['ac_percent'] ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 13. Brokrage % -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#13</span>
                                Brokrage % (દલાલી %)
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="<?= (float)$purchase['brokerage_percent'] ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 14. GST % -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#14</span>
                                GST %
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="gst_percent" id="gst_percent" class="form-control font-mono" value="<?= (float)$purchase['gst_percent'] ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- 15. Other Expense in ₹ -->
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#15</span>
                                Other Expense in ₹ (અન્ય ખર્ચ)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="other_expense" id="other_expense" class="form-control font-mono" value="<?= (float)$purchase['other_expense'] ?>" min="0" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                            </div>
                        </div>
                    </div>

                    <!-- 16. Net payment (with live formula calculation) -->
                    <div class="p-3 bg-light rounded-3 border">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1 mb-1">
                            <span class="badge bg-primary-subtle text-primary font-mono" style="font-size:0.7rem;">#16</span>
                            Net Payment (કુલ ચૂકવવાની રકમ)
                        </label>
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white fw-bold text-primary">₹</span>
                            <input type="text" id="disp_form_net_payment" class="form-control form-control-lg font-mono fw-bold text-primary bg-white" readonly value="₹ <?= number_format((float)$purchase['net_payment'], 2) ?>" style="cursor: not-allowed;">
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.74rem;">
                            <strong>Formula:</strong> ((Subtotal = (Raw Value = Weight ct × Price Per ct) - Less AC% (Raw Value × Less%)) + Brokerage (Subtotal × Brokerage%) + GST (Subtotal × GST%) + Other Expense)
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 5: વેપારી, દલાલ અને મુદત (Broker, Party & Terms) - Inputs 17 to 20 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('handshake') ?></span>
                        ૫. વેપારી, દલાલ અને મુદત (Broker, Party & Terms)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <!-- 17. Broker Name -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#17</span>
                                Broker Name (દલાલનું નામ)
                            </label>
                            <select name="broker_id" id="broker_id" class="form-select select-search">
                                <option value="">Direct (કોઈ દલાલ નથી)</option>
                                <?php foreach ($brokers as $br): ?>
                                    <option value="<?= $br['id'] ?>" <?= (int)$purchase['broker_id'] === (int)$br['id'] ? 'selected' : '' ?>><?= e($br['party_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 18. Party Name -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#18</span>
                                Party Name (પાર્ટીનું નામ) *
                            </label>
                            <select name="party_id" id="party_id" class="form-select select-search" required>
                                <option value="">પાર્ટી શોધો અથવા પસંદ કરો...</option>
                                <?php foreach ($parties as $pt): ?>
                                    <option value="<?= $pt['id'] ?>" <?= (int)$purchase['party_id'] === (int)$pt['id'] ? 'selected' : '' ?>><?= e($pt['party_name']) ?> (<?= e($pt['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 19. Due Days (editable) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#19</span>
                                Due Days (મુદત દિવસ) *
                            </label>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono fw-bold" value="<?= (int)($purchase['due_days'] ?? 50) ?>" min="0" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                        </div>

                        <!-- 20. Due date (Auto from Due Days - Disabled) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#20</span>
                                Due Date (ચુકવણી તારીખ - Auto) *
                            </label>
                            <input type="date" id="disp_due_date" class="form-control bg-light font-mono fw-bold" readonly value="<?= e($purchase['due_date']) ?>" style="cursor: not-allowed;">
                            <input type="hidden" name="due_date" id="due_date" value="<?= e($purchase['due_date']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 6: સ્ટોક લોકેશન & વિઝનરી (Location & Visionary Price) - Inputs 21 to 23 -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('vault') ?></span>
                        ૬. સ્ટોક લોકેશન & વિઝનરી ભાવ (Location & Visionary Price)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <!-- 21. Destination, Safe, dabba -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1 mb-2">
                            <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#21</span>
                            Destination, Safe, Dabba (સ્ટોક સંગ્રહ લોકેશન)
                        </label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Destination (ડેસ્ટિનેશન)</label>
                                <select name="destination" id="destination" class="form-select font-mono">
                                    <?php foreach ($activeDestinations as $ad): ?>
                                        <option value="<?= e($ad['name']) ?>" <?= strcasecmp($ad['name'], $purchase['destination'] ?? 'Surat') === 0 ? 'selected' : '' ?>><?= e($ad['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Safe (સેફ / તિજોરી)</label>
                                <select name="safe" id="safe" class="form-select font-mono">
                                    <?php foreach ($activeSafes as $as): ?>
                                        <option value="<?= e($as['name']) ?>" <?= strcasecmp($as['name'], $purchase['safe'] ?? 'OFC') === 0 ? 'selected' : '' ?>><?= e($as['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Dabba (ડબ્બો / બોક્સ)</label>
                                <select name="dabba" id="dabba" class="form-select font-mono">
                                    <option value="">— કોઈ ડબ્બો નથી —</option>
                                    <?php foreach ($activeDabbas as $adb): ?>
                                        <option value="<?= e($adb['name']) ?>" <?= strcasecmp($adb['name'], $purchase['dabba'] ?? '') === 0 ? 'selected' : '' ?>><?= e($adb['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- 22. Visionary Price (Input) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#22</span>
                                Visionary Price (વિઝનરી ભાવ ₹ / Ct)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white fw-bold text-primary">₹</span>
                                <input type="number" step="0.01" min="0" name="visionary_price" id="visionary_price" class="form-control font-mono fw-bold" placeholder="0.00" value="<?= (float)($purchase['visionary_price'] ?? '') ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                                <span class="input-group-text">/ Ct</span>
                            </div>
                        </div>

                        <!-- 23. Total Visionary Price (Visionary Price * weight) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted d-flex align-items-center gap-1">
                                <span class="badge bg-secondary-subtle text-dark font-mono" style="font-size:0.7rem;">#23</span>
                                Total Visionary Price (કુલ વિઝનરી રકમ ₹)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success">₹</span>
                                <?php 
                                    $totVisVal = (float)($purchase['total_visionary_price'] ?? 0);
                                    $totVisFormatted = $isParcel
                                        ? ('₹ ' . number_format($totVisVal, 2))
                                        : (($purchase['payment_currency'] ?? 'INR') === 'USD' ? ('$ ' . number_format($totVisVal, 2)) : ('₹ ' . number_format($totVisVal, 2)));
                                ?>
                                <input type="text" id="disp_total_visionary_price" class="form-control bg-light font-mono fw-bold text-success" readonly value="<?= $totVisVal > 0 ? $totVisFormatted : '₹ 0.00' ?>" style="cursor: not-allowed;">
                                <input type="hidden" name="total_visionary_price" id="total_visionary_price" value="<?= $totVisVal ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </div>

        <!-- ================= RIGHT COLUMN: COMMERCIAL TERMINAL & SETTLEMENT ================= -->
        <div class="col-12 col-lg-4">
            <div class="erp-summary-sticky">
                <!-- 1. Live Executive Commercial Terminal (Side Dark Blue Box) -->
                <div class="erp-terminal-card mb-3">
                    <div class="erp-terminal-header">
                        <h6 class="mb-0 text-white"><?= svg_icon('receipt', 'text-warning me-1.5') ?>હિસાબ ટર્મિનલ</h6>
                        <span class="badge bg-warning-subtle text-dark border border-warning-subtle font-mono small">Live Calculation</span>
                    </div>
                    <div class="erp-terminal-body">
                        <!-- Weight and Price / Ct in Terminal Box as requested -->
                        <div class="erp-terminal-row">
                            <span class="text-white-50">કુલ વજન (Weight):</span>
                            <strong id="display_weight_val" class="text-white font-mono"><?= number_format((float)$purchase['weight'], 3) ?> Ct</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">પ્રતિ કેરેટ ભાવ (Price/Ct):</span>
                            <strong id="display_price_val" class="text-white font-mono">₹ <?= number_format((float)$purchase['price_per_carat_inr'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-divider my-2"></div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">કાચી રકમ (Raw Value):</span>
                            <strong id="display_raw_amount" class="text-white font-mono">₹ <?= number_format((float)$purchase['payment_amount'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">- AC % (<span id="display_ac_pct"><?= number_format((float)$purchase['ac_percent'], 2) ?>%</span>):</span>
                            <strong id="display_ac_amount" class="text-danger font-mono">- ₹ <?= number_format((float)$purchase['ac_amount'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">સબ ટોટલ (Sub Total):</span>
                            <strong id="display_sub_total" class="text-white font-mono">₹ <?= number_format((float)$purchase['payment_amount'] - (float)$purchase['ac_amount'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">+ દલાલી (<span id="display_brokerage_pct"><?= number_format((float)$purchase['brokerage_percent'], 2) ?>%</span>):</span>
                            <strong id="display_brokerage_amount" class="text-white font-mono">+ ₹ <?= number_format((float)$purchase['brokerage_amount'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">+ GST (<span id="display_gst_pct"><?= number_format((float)$purchase['gst_percent'], 2) ?>%</span>):</span>
                            <strong id="display_gst_amount" class="text-white font-mono">+ ₹ <?= number_format((float)$purchase['gst_amount'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span class="text-white-50">+ Other Expense (અન્ય ખર્ચ):</span>
                            <strong id="display_other_expense" class="text-white font-mono">+ ₹ <?= number_format((float)$purchase['other_expense'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-divider my-2"></div>
                        <div class="erp-terminal-row fs-6">
                            <span class="text-warning fw-bold">કુલ ચૂકવવાની રકમ (Net):</span>
                            <strong id="display_net_payment" class="text-warning font-mono fs-5">₹ <?= number_format((float)$purchase['net_payment'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row small mt-1">
                            <span class="text-white-50">પાર્ટી બિલ (Party Bill):</span>
                            <strong id="display_party_bill" class="text-info font-mono">₹ <?= number_format((float)$purchase['payment_amount'] - (float)$purchase['ac_amount'] + (float)$purchase['gst_amount'] + (float)$purchase['other_expense'], 0) ?></strong>
                        </div>
                        <div class="erp-terminal-row small">
                            <span class="text-white-50">નેટ પડતર / Ct (Cost/Ct):</span>
                            <strong id="display_effective_cost" class="text-white-50 font-mono"><?= (float)$purchase['weight'] > 0 ? ('₹ ' . number_format((float)$purchase['net_payment'] / (float)$purchase['weight'], 0) . ' / ct') : '₹ 0 / ct' ?></strong>
                        </div>
                        <div class="erp-terminal-row small mt-2 pt-2 border-top border-secondary">
                            <span class="text-white-50"><?= $isParcel ? 'કુલ વિઝનરી (Visionary ₹):' : 'કુલ વિઝનરી (Visionary $):' ?></span>
                            <?php
                                $termVisVal = (float)($purchase['total_visionary_price'] ?? 0);
                                $termVisFormatted = $isParcel
                                    ? ('₹ ' . number_format($termVisVal, 2))
                                    : (($purchase['payment_currency'] ?? 'INR') === 'USD' ? ('$ ' . number_format($termVisVal, 2)) : ('₹ ' . number_format($termVisVal, 2)));
                            ?>
                            <strong id="display_terminal_visionary" class="text-warning font-mono"><?= $termVisVal > 0 ? $termVisFormatted : ($isParcel ? '₹ 0.00' : '$ 0.00') ?></strong>
                        </div>
                    </div>
                </div>

                <!-- 2. Payment Terms & Settlement -->
                <div class="erp-form-card mb-3">
                    <div class="erp-card-header">
                        <h6 class="header-title">
                            <span class="header-icon icon-gold"><?= svg_icon('calendar-check') ?></span>
                            <?= $isParcel ? 'મુદત અને ચુકવણી (Settlement)' : 'ચુકવણી સ્થિતિ & વિગત (Payment Status)' ?>
                        </h6>
                    </div>
                    <div class="erp-card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark mb-1">ચૂકવણી સ્થિતિ (Payment Status) *</label>
                            <select name="payment_status" id="payment_status_select" class="form-select no-search fw-semibold">
                                <option value="pending" <?= ($purchase['payment_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>🕒 Pending (બાકી મુદત)</option>
                                <option value="partial" <?= ($purchase['payment_status'] ?? 'pending') === 'partial' ? 'selected' : '' ?>>⏳ Partial (અંશતઃ ચૂકવેલ)</option>
                                <option value="paid" <?= ($purchase['payment_status'] ?? 'pending') === 'paid' ? 'selected' : '' ?>>✅ Paid (પૂરેપૂરું ચૂકવાઈ ગયું)</option>
                            </select>
                        </div>

                        <!-- Conditional Payment Settlement Box -->
                        <div id="conditional_payment_box" style="<?= in_array($purchase['payment_status'] ?? 'pending', ['paid', 'partial']) ? 'display: block;' : 'display: none;' ?>" class="p-3 bg-light rounded-3 border mb-3">
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">ચૂકવેલ રકમ (Paid Amount INR) *</label>
                                <input type="number" step="0.01" min="0" name="paid_amount" id="paid_amount" class="form-control fw-bold font-mono" value="<?= (float)($purchase['paid_amount'] ?? 0.00) ?>" onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();" oninput="if(parseFloat(this.value)<0) this.value=0;">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">ચુકવણી તારીખ (Payment Date)</label>
                                <input type="date" name="chukvya_date" class="form-control" value="<?= e($purchase['chukvya_date'] ?? date('Y-m-d')) ?>">
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-muted">બેંક / કેશ ખાતું (Bank Account)</label>
                                <select name="bank_account_id" class="form-select">
                                    <option value="">ખાતું પસંદ કરો...</option>
                                    <?php foreach ($bankAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" <?= $acc['id'] == ($purchase['bank_account_id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="pending_payment_notice" class="alert alert-info py-2 px-3 small mb-3 border-0" style="<?= in_array($purchase['payment_status'] ?? 'pending', ['paid', 'partial']) ? 'display: none;' : '' ?>">
                            <?= svg_icon('info', 'me-1') ?>રકમ મુદત મુજબ ભવિષ્યમાં ચૂકવવામાં આવશે (લેજર બેલેન્સમાં નોંધાશે).
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">શેરો / વિગત (Notes)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="<?= $isParcel ? 'પાર્સલ ક્વોલિટી અથવા બિલ સંબંધિત નોંધ...' : 'હીરા ક્વોલિટી અથવા ખરીદી સંબંધિત નોંધ...' ?>"><?= e($purchase['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="d-grid gap-2 pt-2">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                                <?= svg_icon('floppy-disk', 'me-2') ?>ફેરફારો સાચવો (Update Polish Purchase)
                            </button>
                            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=<?= $isParcel ? 'parcel_purchase' : 'gia_purchase' ?>&section=<?= $isParcel ? 'parcel' : 'gia' ?>" class="btn btn-outline-secondary">
                                રદ કરો
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky Save Bar for Ergonomic Bottom Access -->
    <div class="sticky-save-bar mt-4">
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">કુલ ચૂકવવાપાત્ર રકમ (Net):</span>
            <strong id="sticky_net_display" class="font-mono text-primary fs-5">₹ <?= number_format((float)$purchase['net_payment'], 0) ?></strong>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=<?= $isParcel ? 'parcel_purchase' : 'gia_purchase' ?>&section=<?= $isParcel ? 'parcel' : 'gia' ?>" class="btn btn-outline-secondary px-3">રદ કરો</a>
            <button type="submit" class="btn btn-primary px-4 fw-bold scanner-btn-touch shadow-sm">
                <?= svg_icon('floppy-disk', 'me-1.5') ?>ફેરફારો સાચવો (Update Polish Purchase)
            </button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isParcelMode = <?= $isParcel ? 'true' : 'false' ?>;
    const purNoInput = document.querySelector('input[name="purchase_no"]');
    const pcsInput = document.getElementById('pcs');
    const weightInput = document.getElementById('weight');
    const purchaseForm = document.getElementById('polishPurchaseForm');
    const purchaseDateInput = document.getElementById('purchase_date');
    const dueDaysInput = document.getElementById('due_days');
    const dueDateInput = document.getElementById('due_date');

    // Auto Due Date Calculation from Purchase Date + Due Days
    function syncDueDateFromDays() {
        const purDateVal = purchaseDateInput ? purchaseDateInput.value : '';
        const daysVal = parseInt(dueDaysInput ? dueDaysInput.value : 0) || 0;
        if (!purDateVal) return;
        const dt = new Date(purDateVal);
        dt.setDate(dt.getDate() + daysVal);
        const yyyy = dt.getFullYear();
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const dd = String(dt.getDate()).padStart(2, '0');
        if (dueDateInput) {
            dueDateInput.value = `${yyyy}-${mm}-${dd}`;
        }
    }

    dueDaysInput?.addEventListener('input', syncDueDateFromDays);
    purchaseDateInput?.addEventListener('change', syncDueDateFromDays);

    // Payment Status Select Toggle
    const paymentStatusSelect = document.getElementById('payment_status_select');
    const conditionalPaymentBox = document.getElementById('conditional_payment_box');
    const pendingNotice = document.getElementById('pending_payment_notice');
    paymentStatusSelect?.addEventListener('change', function () {
        if (this.value === 'paid' || this.value === 'partial') {
            if (conditionalPaymentBox) conditionalPaymentBox.style.display = 'block';
            if (pendingNotice) pendingNotice.style.display = 'none';
        } else {
            if (conditionalPaymentBox) conditionalPaymentBox.style.display = 'none';
            if (pendingNotice) pendingNotice.style.display = 'block';
        }
    });

    if (!isParcelMode) {
        // ================= GIA SPECIFIC CLIENT LOGIC =================
        const giaLenInput = document.getElementById('gia_length_mm');
        const giaWidInput = document.getElementById('gia_width_mm');
        const giaDepInput = document.getElementById('gia_depth_mm');
        const dispDepthPct = document.getElementById('disp_depth_pct_input');
        const hiddenDepthPct = document.getElementById('gia_depth_pct');
        const dispRatio = document.getElementById('disp_ratio_input');
        const hiddenRatio = document.getElementById('gia_ratio');

        function recalcGiaFormulas() {
            const l = parseFloat(giaLenInput?.value) || 0;
            const w = parseFloat(giaWidInput?.value) || 0;
            const d = parseFloat(giaDepInput?.value) || 0;

            // Depth % = (Depth / Width) * 100
            let depthPct = 0;
            if (w > 0 && d > 0) {
                depthPct = Math.round((d / w) * 10000) / 100;
            }
            if (dispDepthPct) dispDepthPct.value = depthPct > 0 ? depthPct.toFixed(2) + ' %' : '0.00 %';
            if (hiddenDepthPct) hiddenDepthPct.value = depthPct > 0 ? depthPct.toFixed(2) : '';

            // Ratio = Length / Width
            let ratio = 0;
            if (w > 0 && l > 0) {
                ratio = Math.round((l / w) * 100) / 100;
            }
            if (dispRatio) dispRatio.value = ratio > 0 ? ratio.toFixed(2) : '0.00';
            if (hiddenRatio) hiddenRatio.value = ratio > 0 ? ratio.toFixed(2) : '';
        }

        [giaLenInput, giaWidInput, giaDepInput].forEach(inp => {
            inp?.addEventListener('input', recalcGiaFormulas);
            inp?.addEventListener('change', recalcGiaFormulas);
        });
        recalcGiaFormulas();

        // Color Masters Hierarchy Integration for GIA (Dropdown list only - no external pills)
        const giaColorMaster = <?= json_encode($masterGiaColorTags, JSON_UNESCAPED_UNICODE) ?>;
        const masterColors = <?= json_encode($masterColors, JSON_UNESCAPED_UNICODE) ?>;
        const giaColorTagSelect = document.getElementById('gia_color_tag_select');
        const giaColorSelect = document.getElementById('gia_color_select');

        function updateGiaColorOptions(tagName) {
            if (!giaColorSelect) return;
            const tagObj = giaColorMaster.find(t => t.tag_name === tagName);
            const ts = giaColorSelect.tomselect;

            if (ts) {
                ts.clear();
                ts.clearOptions();
            }
            giaColorSelect.innerHTML = '';

            if (tagObj && tagObj.descriptions && tagObj.descriptions.length > 0) {
                tagObj.descriptions.forEach((d, idx) => {
                    const opt = document.createElement('option');
                    opt.value = d.description_name;
                    opt.textContent = d.description_name;
                    if (idx === 0) opt.selected = true;
                    giaColorSelect.appendChild(opt);

                    if (ts) {
                        ts.addOption({ value: d.description_name, text: d.description_name });
                    }
                });
                if (ts && tagObj.descriptions.length > 0) {
                    ts.setValue(tagObj.descriptions[0].description_name);
                }
            } else {
                masterColors.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.code;
                    opt.textContent = c.name;
                    if (c.code === 'G') opt.selected = true;
                    giaColorSelect.appendChild(opt);

                    if (ts) {
                        ts.addOption({ value: c.code, text: c.name });
                    }
                });
                if (ts) {
                    ts.setValue('G');
                }
            }
        }

        if (giaColorTagSelect) {
            giaColorTagSelect.addEventListener('change', function() {
                updateGiaColorOptions(this.value);
            });
        }
    } else {
        // ================= PARCEL MODE SPECIFIC CLIENT LOGIC =================
        const shapesTbody = document.getElementById('shapes_tbody');
        const btnAddShape = document.getElementById('btn_add_shape_row');

        // Summary Elements
        const summaryParcelPcs = document.getElementById('summary_parcel_pcs');
        const summaryShapePcs = document.getElementById('summary_shape_pcs');
        const shapeValidationBadge = document.getElementById('shape_validation_badge');
        const shapeValidationMsg = document.getElementById('shape_validation_msg');
        const footerShapeQty = document.getElementById('footer_shape_qty');

        const shapeOptions = <?= json_encode(get_polish_shapes_list()) ?>;

        let isShapeValid = true;
        let shapeValidationError = '';

        // Master Color Tag & Description Dropdown sync
        const polishColorMaster = <?= json_encode($masterPolishColorTags, JSON_UNESCAPED_UNICODE) ?>;
        const parcelColorTagSelect = document.getElementById('parcel_color_tag_select');
        const parcelColorDescSelect = document.getElementById('parcel_color_desc_select');

        function populateColorDescriptions(selectedTagName, preserveVal = '') {
            if (!parcelColorDescSelect) return;
            const initVal = preserveVal || parcelColorDescSelect.dataset.initVal || '';
            parcelColorDescSelect.innerHTML = '<option value="">-- કલર વિગત પસંદ કરો --</option>';

            const matchedTag = polishColorMaster.find(t => t.tag_name === selectedTagName);
            if (matchedTag && matchedTag.descriptions && matchedTag.descriptions.length > 0) {
                matchedTag.descriptions.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.description_name;
                    opt.textContent = d.description_name + (d.description_code ? ' (' + d.description_code + ')' : '');
                    if (initVal && initVal === d.description_name) {
                        opt.selected = true;
                    }
                    parcelColorDescSelect.appendChild(opt);
                });
            } else {
                polishColorMaster.forEach(t => {
                    if (t.descriptions && t.descriptions.length > 0) {
                        const grp = document.createElement('optgroup');
                        grp.label = t.tag_name;
                        t.descriptions.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.description_name;
                            opt.textContent = d.description_name;
                            if (initVal && initVal === d.description_name) {
                                opt.selected = true;
                            }
                            grp.appendChild(opt);
                        });
                        parcelColorDescSelect.appendChild(grp);
                    }
                });
            }
        }

        parcelColorTagSelect?.addEventListener('change', function() {
            populateColorDescriptions(this.value);
        });
        if (parcelColorTagSelect && parcelColorTagSelect.value) {
            populateColorDescriptions(parcelColorTagSelect.value);
        }

        // Build simplified shape row (Only Shape & Pcs)
        function buildShapeRowHtml(index, data = {}) {
            const selShape = data.shape || 'Round';
            const defaultQty = data.pcs !== undefined ? data.pcs : '';

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
                        <div class="input-group input-group-sm">
                            <input type="number" min="1" step="1" name="shape_items[${index}][pcs]" class="form-control form-control-sm font-mono fw-bold shape-qty-input" placeholder="નંગ" value="${defaultQty}" required onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E') event.preventDefault();">
                            <span class="input-group-text">Pcs</span>
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
            const parcelPcs = parseInt(pcsInput?.value) || 0;
            let totalShapePcs = 0;

            const rows = shapesTbody ? shapesTbody.querySelectorAll('.shape-item-row') : [];
            rows.forEach(r => {
                const qtyInput = r.querySelector('.shape-qty-input');
                const qty = parseInt(qtyInput?.value) || 0;
                totalShapePcs += qty;
            });

            if (summaryParcelPcs) summaryParcelPcs.innerText = parcelPcs;
            if (summaryShapePcs) summaryShapePcs.innerText = totalShapePcs;
            if (footerShapeQty) footerShapeQty.innerText = totalShapePcs;

            isShapeValid = true;
            shapeValidationError = '';

            if (rows.length === 0) {
                isShapeValid = false;
                shapeValidationError = 'કૃપા કરીને ઓછામાં ઓછી એક શેપ એન્ટ્રી (Shape Entry) ઉમેરો.';
            } else if (parcelPcs > 0 && totalShapePcs !== parcelPcs) {
                isShapeValid = false;
                shapeValidationError = `શેપ કુલ નંગ (${totalShapePcs} Pcs) અને પાર્સલ નંગ (${parcelPcs} Pcs) મેળ ખાતા નથી.`;
            }

            if (shapeValidationBadge) {
                if (isShapeValid && parcelPcs > 0 && totalShapePcs === parcelPcs) {
                    shapeValidationBadge.innerHTML = `<span class="badge bg-success py-1.5 px-2.5 fs-7 shadow-sm">✓ VALID (${totalShapePcs} Pcs Match)</span>`;
                } else if (rows.length > 0 && parcelPcs > 0 && totalShapePcs !== parcelPcs) {
                    shapeValidationBadge.innerHTML = `<span class="badge bg-warning text-dark py-1.5 px-2.5 fs-7 shadow-sm">⚠️ નંગ અલગ છે (${totalShapePcs} / ${parcelPcs})</span>`;
                } else {
                    shapeValidationBadge.innerHTML = `<span class="badge bg-secondary py-1.5 px-2.5 fs-7">નંગ દાખલ કરો</span>`;
                }
            }

            if (shapeValidationMsg) {
                if (!isShapeValid && shapeValidationError) {
                    shapeValidationMsg.style.display = 'block';
                    shapeValidationMsg.className = 'alert alert-warning py-1 px-3 small mb-0 border-0 rounded-2';
                    shapeValidationMsg.innerHTML = `<i class="me-1">⚠️</i> ${shapeValidationError}`;
                } else {
                    shapeValidationMsg.style.display = 'none';
                    shapeValidationMsg.innerHTML = '';
                }
            }
        }

        btnAddShape?.addEventListener('click', function () {
            const nextIndex = shapesTbody.querySelectorAll('.shape-item-row').length + 1;
            shapesTbody.insertAdjacentHTML('beforeend', buildShapeRowHtml(nextIndex));
            recalculateShapeSummary();
        });

        shapesTbody?.addEventListener('click', function (e) {
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
            });

            recalculateShapeSummary();
        });

        shapesTbody?.addEventListener('input', function (e) {
            if (e.target.classList.contains('shape-qty-input')) {
                recalculateShapeSummary();
            }
        });

        pcsInput?.addEventListener('input', function() {
            recalculateShapeSummary();
        });

        recalculateShapeSummary();
    }

    // ================= UNIVERSAL COMMERCIALS RECALCULATION =================
    const calcPriceInr = document.getElementById('calc_price_inr');
    const dollarPerCtInput = document.getElementById('dollar_per_carat');
    const dollarRateInput = document.getElementById('dollar_rate');
    const currInrRadio = document.getElementById('curr_inr');
    const currUsdRadio = document.getElementById('curr_usd');
    const acPercentInput = document.getElementById('ac_percent');
    const brokeragePercentInput = document.getElementById('brokerage_percent');
    const gstPercentInput = document.getElementById('gst_percent');
    const otherExpenseInput = document.getElementById('other_expense');
    const visionaryPriceInput = document.getElementById('visionary_price');

    // Currency Box Toggle
    function toggleCurrencyBoxes() {
        const inrBox = document.getElementById('inr_currency_box');
        const usdBox = document.getElementById('usd_currency_box');
        if (currUsdRadio && currUsdRadio.checked) {
            if (inrBox) inrBox.style.display = 'none';
            if (usdBox) usdBox.style.display = 'block';
            if (calcPriceInr) calcPriceInr.required = false;
            if (dollarPerCtInput) dollarPerCtInput.required = true;
        } else {
            if (inrBox) inrBox.style.display = 'block';
            if (usdBox) usdBox.style.display = 'none';
            if (calcPriceInr) calcPriceInr.required = true;
            if (dollarPerCtInput) dollarPerCtInput.required = false;
        }
        recalculateCommercials();
    }

    currInrRadio?.addEventListener('change', toggleCurrencyBoxes);
    currUsdRadio?.addEventListener('change', toggleCurrencyBoxes);

    function recalculateCommercials() {
        const wt = parseFloat(weightInput?.value) || 0;
        const pcs = parseInt(pcsInput?.value) || 0;

        // 6. Average Size = Weight / Pcs
        const avgSize = (pcs > 0 && wt > 0) ? (wt / pcs) : 0;
        const dispAvgSize = document.getElementById('disp_avg_size');
        if (dispAvgSize) {
            dispAvgSize.value = avgSize > 0 ? avgSize.toFixed(4) + ' Ct/pc' : '0.0000 Ct/pc';
        }

        // 11. Rate / Price per ct (INR or USD * Rate)
        let ratePerCt = 0;
        const isUsd = currUsdRadio ? currUsdRadio.checked : false;
        const dPerCt = parseFloat(dollarPerCtInput?.value) || 0;
        const dRate = parseFloat(dollarRateInput?.value) || 0;
        const inrPerCt = parseFloat(calcPriceInr?.value) || 0;

        if (isUsd) {
            if (dPerCt > 0 && dRate > 0) {
                ratePerCt = dPerCt * dRate;
            } else {
                ratePerCt = 0; // Stand Dollar deal
            }
        } else {
            ratePerCt = inrPerCt;
        }

        // 16. Net Payment Formula:
        // Raw Value = Weight * Price per ct
        const rawValue = Math.round(wt * ratePerCt * 100) / 100;

        // 12. Less AC%
        const acPct = parseFloat(acPercentInput?.value) || 0;
        const acAmount = Math.round(rawValue * (acPct / 100) * 100) / 100;

        // Subtotal = Raw Value - Less AC%
        const subtotal = Math.round((rawValue - acAmount) * 100) / 100;

        // 13. Brokerage %
        const brkPct = parseFloat(brokeragePercentInput?.value) || 0;
        const brkAmount = Math.round(subtotal * (brkPct / 100) * 100) / 100;

        // 14. GST %
        const gstPct = parseFloat(gstPercentInput?.value) || 0;
        const gstAmount = Math.round(subtotal * (gstPct / 100) * 100) / 100;

        // 15. Other Expense
        const otherExp = parseFloat(otherExpenseInput?.value) || 0;

        // Net Payment = Subtotal + Brokerage + GST + Other Expense
        const netPayment = Math.round((subtotal + brkAmount + gstAmount + otherExp) * 100) / 100;
        const partyBill = Math.round((subtotal + gstAmount + otherExp) * 100) / 100;
        const effectiveCost = wt > 0 ? (netPayment / wt) : 0;

        // 22 & 23. Visionary Price & Total Visionary Price = Visionary Price * weight
        const visPrice = parseFloat(visionaryPriceInput?.value) || 0;
        const totalVisPrice = Math.round(visPrice * wt * 100) / 100;

        const dispTotalVis = document.getElementById('disp_total_visionary_price');
        if (dispTotalVis) {
            dispTotalVis.value = isParcelMode
                ? ('₹ ' + totalVisPrice.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}))
                : (isUsd ? ('$ ' + totalVisPrice.toFixed(2)) : ('₹ ' + totalVisPrice.toFixed(2)));
        }
        const hiddenTotalVis = document.getElementById('total_visionary_price');
        if (hiddenTotalVis) {
            hiddenTotalVis.value = totalVisPrice.toFixed(2);
        }

        // Update Form Net Payment
        const dispFormNet = document.getElementById('disp_form_net_payment');
        if (dispFormNet) {
            dispFormNet.value = '₹ ' + netPayment.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        const stickyNet = document.getElementById('sticky_net_display');
        if (stickyNet) {
            stickyNet.innerText = '₹ ' + Math.round(netPayment).toLocaleString('en-IN');
        }

        // Update Side Dark Blue Terminal Box
        const dispWeightVal = document.getElementById('display_weight_val');
        if (dispWeightVal) dispWeightVal.innerText = wt.toFixed(3) + ' Ct';

        const dispPriceVal = document.getElementById('display_price_val');
        if (dispPriceVal) {
            if (isUsd) {
                dispPriceVal.innerText = '$ ' + dPerCt.toFixed(2) + (dRate > 0 ? ` (₹ ${ratePerCt.toFixed(2)})` : ' (Stand)');
            } else {
                dispPriceVal.innerText = '₹ ' + ratePerCt.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        }

        const dispRaw = document.getElementById('display_raw_amount');
        if (dispRaw) dispRaw.innerText = '₹ ' + Math.round(rawValue).toLocaleString('en-IN');

        const dispAcPct = document.getElementById('display_ac_pct');
        if (dispAcPct) dispAcPct.innerText = acPct.toFixed(2) + '%';
        const dispAcAmt = document.getElementById('display_ac_amount');
        if (dispAcAmt) dispAcAmt.innerText = '- ₹ ' + Math.round(acAmount).toLocaleString('en-IN');

        const dispSub = document.getElementById('display_sub_total');
        if (dispSub) dispSub.innerText = '₹ ' + Math.round(subtotal).toLocaleString('en-IN');

        const dispBrkPct = document.getElementById('display_brokerage_pct');
        if (dispBrkPct) dispBrkPct.innerText = brkPct.toFixed(2) + '%';
        const dispBrkAmt = document.getElementById('display_brokerage_amount');
        if (dispBrkAmt) dispBrkAmt.innerText = '+ ₹ ' + Math.round(brkAmount).toLocaleString('en-IN');

        const dispGstPct = document.getElementById('display_gst_pct');
        if (dispGstPct) dispGstPct.innerText = gstPct.toFixed(2) + '%';
        const dispGstAmt = document.getElementById('display_gst_amount');
        if (dispGstAmt) dispGstAmt.innerText = '+ ₹ ' + Math.round(gstAmount).toLocaleString('en-IN');

        const dispOther = document.getElementById('display_other_expense');
        if (dispOther) dispOther.innerText = '+ ₹ ' + Math.round(otherExp).toLocaleString('en-IN');

        const dispNet = document.getElementById('display_net_payment');
        if (dispNet) dispNet.innerText = '₹ ' + Math.round(netPayment).toLocaleString('en-IN');

        const dispEff = document.getElementById('display_effective_cost');
        if (dispEff) dispEff.innerText = '₹ ' + effectiveCost.toFixed(2) + ' / ct';

        const dispBill = document.getElementById('display_party_bill');
        if (dispBill) dispBill.innerText = '₹ ' + Math.round(partyBill).toLocaleString('en-IN');

        const dispTermVis = document.getElementById('display_terminal_visionary');
        if (dispTermVis) {
            dispTermVis.innerText = isParcelMode
                ? ('₹ ' + totalVisPrice.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}))
                : (isUsd ? ('$ ' + totalVisPrice.toFixed(2)) : ('₹ ' + totalVisPrice.toFixed(2)));
        }
    }

    [
        pcsInput, weightInput, calcPriceInr, dollarPerCtInput, dollarRateInput,
        acPercentInput, brokeragePercentInput, gstPercentInput, otherExpenseInput,
        visionaryPriceInput
    ].forEach(inp => {
        inp?.addEventListener('input', recalculateCommercials);
        inp?.addEventListener('change', recalculateCommercials);
    });

    // Initial calculations
    recalculateCommercials();

    // Auto Due Date Calculation from Purchase Date + Due Days
    function syncDueDateFromDays() {
        const purDateVal = purchaseDateInput ? purchaseDateInput.value : '';
        const daysVal = parseInt(dueDaysInput ? dueDaysInput.value : 0) || 0;
        if (!purDateVal) return;
        const dt = new Date(purDateVal);
        dt.setDate(dt.getDate() + daysVal);
        const yyyy = dt.getFullYear();
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const dd = String(dt.getDate()).padStart(2, '0');
        const iso = `${yyyy}-${mm}-${dd}`;
        const dispDueDate = document.getElementById('disp_due_date');
        if (dispDueDate) dispDueDate.value = iso;
        if (dueDateInput) dueDateInput.value = iso;
    }
    dueDaysInput?.addEventListener('input', syncDueDateFromDays);
    purchaseDateInput?.addEventListener('change', syncDueDateFromDays);

    // Submit Guard
    purchaseForm?.addEventListener('submit', function (e) {
        // Strict Zero & Negative Value Guard
        const numInputs = purchaseForm.querySelectorAll('input[type="number"]');
        for (let inp of numInputs) {
            const val = parseFloat(inp.value);
            if (!isNaN(val) && val < 0) {
                e.preventDefault();
                alert('કોઈપણ કિંમત માઇનસ (ઋણ) હોઈ શકે નહીં. કૃપા કરીને સાચી કિંમત દાખલ કરો.');
                inp.focus();
                return false;
            }
        }

        const wtVal = parseFloat(weightInput?.value) || 0;
        if (wtVal <= 0) {
            e.preventDefault();
            alert('કુલ વજન ૦ થી વધુ હોવું ફરજિયાત છે (Weight must be greater than 0).');
            weightInput.focus();
            return false;
        }

        if (isParcelMode) {
            const shapesTbodyEl = document.getElementById('shapes_tbody');
            if (shapesTbodyEl && typeof recalculateShapeSummary === 'function') {
                recalculateShapeSummary();
                if (!isShapeValid) {
                    e.preventDefault();
                    alert('અમાન્ય વિગત: ' + shapeValidationError);
                    return false;
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
