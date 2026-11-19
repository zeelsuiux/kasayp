<?php
/**
 * Edit Polish Sales Entry
 * Kasyap Diamond ERP - Surat & Mumbai
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
    set_flash('error', 'અમાન્ય Polish વેચાણ ID.');
    header('Location: ' . BASE_URL . '/modules/polish_sales/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT ps.*, p.party_name, b.party_name as broker_name, stk.packet_no as stock_packet 
                       FROM polish_sales ps
                       JOIN parties p ON ps.party_id = p.id
                       LEFT JOIN parties b ON ps.broker_id = b.id
                       LEFT JOIN polish_stocks stk ON ps.polish_stock_id = stk.id
                       WHERE ps.id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    set_flash('error', 'Polish વેચાણ રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/polish_sales/index.php');
    exit;
}

$parties = get_parties_by_role('polish_sale', 'party');
$brokers = get_parties_by_role('polish_sale_broker', 'broker');
if (!empty($sale['party_id']) && !in_array($sale['party_id'], array_column($parties, 'id'))) {
    $curP = $pdo->query("SELECT id, party_name, city FROM parties WHERE id = " . (int)$sale['party_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curP) $parties[] = $curP;
}
if (!empty($sale['broker_id']) && !in_array($sale['broker_id'], array_column($brokers, 'id'))) {
    $curB = $pdo->query("SELECT id, party_name FROM parties WHERE id = " . (int)$sale['broker_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curB) $brokers[] = $curB;
}

$existingShapes = get_polish_parcel_shapes($pdo, 'sale', $id);
$stockRow = $pdo->query("SELECT * FROM polish_stocks WHERE id = " . (int)$sale['polish_stock_id'])->fetch(PDO::FETCH_ASSOC);
$isParcelSale = !empty($existingShapes) || ($stockRow && ($stockRow['stock_type'] === 'parcel' || (int)$sale['pcs'] > 1 || (int)$stockRow['total_pcs'] > 1));

if (empty($existingShapes) && $isParcelSale) {
    $existingShapes = [
        [
            'shape' => $stockRow['shape'] ?? 'Round',
            'pcs' => (int)$sale['pcs'],
            'weight_per_shape' => (int)$sale['pcs'] > 0 ? round((float)$sale['weight'] / (int)$sale['pcs'], 4) : (float)$sale['weight'],
            'total_weight' => (float)$sale['weight'],
            'price_per_carat' => (float)$sale['price_per_carat_inr']
        ]
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $saleDate    = $_POST['sale_date'] ?? $sale['sale_date'];
    $partyId     = (int)($_POST['party_id'] ?? $sale['party_id']);
    $brokerId    = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $pcs         = (int)($_POST['pcs'] ?? $sale['pcs']);
    $weight      = (float)($_POST['weight'] ?? $sale['weight']);

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

    $paymentAmt  = round($weight * $pricePerCt, 2);
    $brokeragePct = (float)($_POST['brokerage_percent'] ?? 0.00);
    $brokerageAmt = round($paymentAmt * ($brokeragePct / 100), 2);
    $acGstAmt    = (float)($_POST['ac_gst_amount'] ?? 0.00);
    $netAmt      = round($paymentAmt + $acGstAmt, 2);

    $dueDays     = (int)($_POST['due_days'] ?? $sale['due_days']);
    $dueDate     = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime($saleDate . " +$dueDays days"));
    $paymentStatus = $_POST['payment_status'] ?? ($sale['payment_status'] ?? 'pending');
    $receivedDate = !empty($_POST['received_date']) ? $_POST['received_date'] : null;
    $receivedAmt = ($paymentStatus === 'paid') ? $netAmt : (float)($_POST['received_amount'] ?? $sale['received_amount'] ?? 0.00);
    if ($paymentStatus === 'paid' && empty($receivedDate)) {
        $receivedDate = date('Y-m-d');
    }
    $notes       = trim($_POST['notes'] ?? '');

    // Validate Shape-wise breakdown for Parcel sales (Strict 1% & Quantity rules)
    $shapeItems = $_POST['shape_items'] ?? [];
    $shapeValResult = null;
    if ($isParcelSale) {
        $shapeValResult = validate_parcel_shape_breakdown($pcs, $weight, $shapeItems);
    }

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને ગ્રાહક અને વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($isParcelSale && !$shapeValResult['valid']) {
        set_flash('error', $shapeValResult['error']);
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCt <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $oldNet = (float)$sale['net_amount'];
            $diff = $netAmt - $oldNet;

            $stmt = $pdo->prepare("UPDATE polish_sales SET 
                sale_date = ?, payment_currency = ?, party_id = ?, broker_id = ?, pcs = ?, weight = ?,
                dollar_per_carat = ?, dollar_rate = ?, price_per_carat_inr = ?, payment_amount = ?, brokerage_percent = ?,
                brokerage_amount = ?, ac_gst_amount = ?, net_amount = ?, due_days = ?,
                due_date = ?, payment_status = ?, received_amount = ?, received_date = ?, notes = ?
                WHERE id = ?");

            $stmt->execute([
                $saleDate, $paymentCurrency, $partyId, $brokerId, $pcs, $weight,
                $dollarPerCarat, $dollarRate, $pricePerCt, $paymentAmt, $brokeragePct,
                $brokerageAmt, $acGstAmt, $netAmt, $dueDays,
                $dueDate, $paymentStatus, $receivedAmt, $receivedDate, $notes, $id
            ]);

            // Adjust party receivable balance
            if ($partyId === (int)$sale['party_id']) {
                $oldUnpaid = ($sale['payment_status'] === 'paid') ? 0.0 : max(0.0, (float)$sale['net_amount'] - (float)$sale['received_amount']);
                $newUnpaid = ($paymentStatus === 'paid') ? 0.0 : max(0.0, $netAmt - $receivedAmt);
                $diffUnpaid = round($newUnpaid - $oldUnpaid, 2);
                if ($diffUnpaid != 0) {
                    update_party_balance($partyId, $diffUnpaid);
                }
            }

            // Save Shape Breakdown to polish_parcel_shapes for parcel sale
            if ($isParcelSale && $shapeValResult && !empty($shapeValResult['clean_rows'])) {
                save_polish_parcel_shapes($pdo, 'sale', $id, (int)$sale['polish_stock_id'], $shapeValResult['clean_rows']);
            }

            $pdo->commit();
            log_activity('Polish Sale Edited', 'Sales', $id, "Updated polish sale {$sale['invoice_no']}: $weight ct, Net: $netAmt");
            set_flash('success', "Polish વેચાણ ઇન્વોઇસ {$sale['invoice_no']} સફળતાપૂર્વક અપડેટ થઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/polish_sales/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'વેચાણ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Polish વેચાણ એડિટ: ' . $sale['invoice_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Polish વેચાણ એડિટ: ' . $sale['invoice_no'],
    'ગ્રાહક, વજન, કેરેટ ભાવ, રકમ અને મુદત દિવસો સુધારો',
    'pen-to-square',
    '<a href="' . BASE_URL . '/modules/polish_sales/index.php" class="btn btn-outline-secondary">' . svg_icon('arrow-left', 'me-1') . 'પાછા જાઓ</a>',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form action="<?= BASE_URL ?>/modules/polish_sales/edit.php?id=<?= $id ?>" method="POST" id="editPolishSaleForm">
            <?= csrf_field() ?>

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark"><?= svg_icon('receipt', 'me-2 text-primary') ?>વેચાણ અને ગ્રાહક વિગતો</span>
                    <span class="badge bg-light text-dark font-mono"><?= e($sale['invoice_no']) ?></span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ઇન્વોઇસ નંબર</label>
                            <input type="text" class="form-control font-mono bg-light" value="<?= e($sale['invoice_no']) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">વેચાણ તારીખ *</label>
                            <input type="date" name="sale_date" id="sale_date" class="form-control" value="<?= e($sale['sale_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ગ્રાહક (Customer / Party) *</label>
                            <select name="party_id" id="party_id" class="form-select select-tom" required>
                                <?php foreach ($parties as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $sale['party_id'] ? 'selected' : '' ?>>
                                        <?= e($p['party_name']) ?> (<?= e($p['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">દલાલ (Broker)</label>
                            <select name="broker_id" id="broker_id" class="form-select select-tom">
                                <option value="">-- Direct --</option>
                                <?php foreach ($brokers as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $sale['broker_id'] ? 'selected' : '' ?>>
                                        <?= e($b['party_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3">
                    <span class="fw-bold text-dark"><?= svg_icon('scale-balanced', 'me-2 text-primary') ?>વજન અને રકમ ગણતરી (Weight & Amount)</span>
                </div>
                <div class="card-body p-3">
                    <?php 
                        $isUsd = ($sale['payment_currency'] ?? '') === 'USD' || ((float)($sale['dollar_per_carat'] ?? 0) > 0);
                    ?>
                    <!-- Payment Currency Method (2 Types: Indian Currency or Dollar Currency) -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark d-block">પેમેન્ટ પદ્ધતિ / કરન્સી પ્રકાર (Payment Method) *</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="payment_currency" id="curr_inr" value="INR" <?= !$isUsd ? 'checked' : '' ?> autocomplete="off">
                                <label class="btn btn-outline-primary w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_inr">
                                    <span>🇮🇳</span>
                                    <span>1. Indian Currency (₹)</span>
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="payment_currency" id="curr_usd" value="USD" <?= $isUsd ? 'checked' : '' ?> autocomplete="off">
                                <label class="btn btn-outline-success w-100 fw-bold py-2 d-flex align-items-center justify-content-center gap-2" for="curr_usd">
                                    <span>💵</span>
                                    <span>2. Dollar Currency ($)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">નંગ (Pcs)</label>
                            <input type="number" name="pcs" id="pcs" class="form-control font-mono" value="<?= (int)$sale['pcs'] ?>" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">વેચેલ વજન (Carat) *</label>
                            <input type="number" step="0.01" min="0" oninput="if(parseFloat(this.value)<0) this.value=0;" name="weight" id="weight" class="form-control font-mono fw-bold text-success" value="<?= (float)$sale['weight'] ?>" required>
                        </div>
                    </div>

                    <!-- 1. Indian Currency Box -->
                    <div id="inr_currency_box" class="mt-3" style="<?= $isUsd ? 'display:none;' : '' ?>">
                        <label class="form-label small fw-bold text-dark">વેચાણ ભાવ પ્રતિ Carat INR (₹/Ct) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                            <input type="number" step="0.01" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg font-mono fw-bold" value="<?= (float)$sale['price_per_carat_inr'] ?>" <?= !$isUsd ? 'required' : '' ?>>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.78rem;">માત્ર ભારતીય રૂપિયામાં ભાવ દાખલ કરવો ફરજિયાત છે.</div>
                    </div>

                    <!-- 2. Dollar Currency Box -->
                    <div id="usd_currency_box" class="mt-3" style="<?= !$isUsd ? 'display:none;' : '' ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-success">Dollar / Ct ($) * <span class="badge bg-success-subtle text-success">Mandatory</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-success fw-bold">$</span>
                                    <input type="number" step="0.01" name="dollar_per_carat" id="dollar_per_carat" class="form-control form-control-lg font-mono fw-bold" placeholder="0.00" value="<?= (float)($sale['dollar_per_carat'] ?? 0) > 0 ? (float)$sale['dollar_per_carat'] : '' ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Dollar Rate (₹/$) <span class="badge bg-light text-muted border">Optional</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted">₹</span>
                                    <input type="number" step="0.01" name="dollar_rate" id="dollar_rate" class="form-control form-control-lg font-mono" placeholder="ખાલી = Stand Dollar (દર બાકી)" value="<?= (float)($sale['dollar_rate'] ?? 0) > 0 ? (float)$sale['dollar_rate'] : '' ?>">
                                </div>
                            </div>
                        </div>
                        <div id="usd_conversion_preview" class="alert <?= ((float)($sale['dollar_rate'] ?? 0) > 0) ? 'alert-soft-success' : 'alert-soft-danger' ?> py-2 px-3 small mt-2 mb-0 border">
                            <?php if ((float)($sale['dollar_rate'] ?? 0) > 0): ?>
                                <strong>કન્વર્ટ કરેલ ભાવ:</strong> ₹<?= number_format((float)$sale['price_per_carat_inr'], 2) ?> / Ct ($<?= number_format((float)$sale['dollar_per_carat'], 2) ?> × ₹<?= number_format((float)$sale['dollar_rate'], 2) ?>)
                            <?php else: ?>
                                <span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થયેલ છે.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">દલાલી % (Brokerage %)</label>
                            <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="<?= (float)$sale['brokerage_percent'] ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">A/C GST ખર્ચ (INR)</label>
                            <input type="number" step="0.01" name="ac_gst_amount" id="ac_gst_amount" class="form-control font-mono" value="<?= (float)$sale['ac_gst_amount'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">મુદત દિવસો (Due Days)</label>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono" value="<?= (int)$sale['due_days'] ?>" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">મુદત તારીખ (Due Date) *</label>
                            <input type="date" name="due_date" id="due_date" class="form-control font-mono" value="<?= e($sale['due_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ચુકવણી સ્થિતિ (Payment Status)</label>
                            <select name="payment_status" id="payment_status_select" class="form-select no-search fw-semibold">
                                <option value="pending" <?= ($sale['payment_status'] === 'pending') ? 'selected' : '' ?>>🕒 Pending (બાકી)</option>
                                <option value="partial" <?= ($sale['payment_status'] === 'partial') ? 'selected' : '' ?>>⏳ Partial (અંશતઃ)</option>
                                <option value="paid" <?= ($sale['payment_status'] === 'paid') ? 'selected' : '' ?>>✅ Paid (ચુકતે)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ચુકવણી તારીખ (Paid Date)</label>
                            <input type="date" name="received_date" id="received_date" class="form-control font-mono" value="<?= e($sale['received_date'] ?? '') ?>" placeholder="YYYY-MM-DD">
                        </div>
                    </div>

                    <!-- Live Calculation Pill Banner -->
                    <div class="p-3 mt-3 bg-light rounded-3 border">
                        <div class="row text-center g-2">
                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block">પોલિશ વેચાણ રકમ</small>
                                <strong class="fs-6 font-mono text-dark" id="disp_payment_amt"><?= format_inr($sale['payment_amount']) ?></strong>
                            </div>
                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block">દલાલી રકમ</small>
                                <strong class="fs-6 font-mono text-warning-emphasis" id="disp_brokerage_amt"><?= format_inr($sale['brokerage_amount']) ?></strong>
                            </div>
                            <div class="col-12 col-md-4">
                                <small class="text-muted d-block">કુલ લેવાની રકમ (Net Receivable)</small>
                                <strong class="fs-6 font-mono text-success" id="disp_net_amount"><?= format_inr($sale['net_amount']) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: શેપ-વાઇઝ પાર્સલ વજન અને નંગ વિભાજન (Parcel Shape-wise Weight Management) -->
            <div class="card-erp border border-primary-subtle shadow-sm mb-3" id="card_shapes_section" style="<?= !$isParcelSale ? 'display:none;' : '' ?>">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2 py-2.5 px-3">
                    <span class="fw-bold text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('shapes', 'text-primary') ?>પાર્સલ શેપ-વાઇઝ વિગત (Shape-wise Breakdown Table)
                    </span>
                    <button type="button" class="btn btn-sm btn-primary" id="btn_add_shape_row">
                        <?= svg_icon('plus', 'me-1') ?>નવો શેપ ઉમેરો (Add Row)
                    </button>
                </div>
                <div class="card-body p-0">
                    <!-- Live Parcel Summary & 1% Validation Card -->
                    <div class="p-3 bg-light-subtle border-bottom" id="parcel_summary_box">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-8">
                                <div class="d-flex flex-wrap gap-2 align-items-center small">
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">પાર્સલ નંબર:</span>
                                        <strong class="font-mono text-dark" id="summary_parcel_no"><?= e($sale['stock_packet'] ?? 'PARCEL') ?></strong>
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">વેચાણ નંગ:</span>
                                        <strong class="font-mono text-primary" id="summary_parcel_pcs"><?= (int)$sale['pcs'] ?></strong> Pcs
                                    </div>
                                    <div class="p-2 bg-white rounded border">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">વેચાણ વજન:</span>
                                        <strong class="font-mono text-primary" id="summary_parcel_weight"><?= format_carat((float)$sale['weight']) ?></strong>
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
                                <!-- Populated via JS -->
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

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3">
                    <span class="fw-bold text-dark"><?= svg_icon('note-sticky', 'me-2 text-primary') ?>નોટ્સ (Remarks)</span>
                </div>
                <div class="card-body p-3">
                    <input type="text" name="notes" class="form-control" value="<?= e($sale['notes'] ?? '') ?>" placeholder="પોલિશ વેચાણ અંગે વિશેષ નોટ્સ...">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="<?= BASE_URL ?>/modules/polish_sales/index.php" class="btn btn-outline-secondary">રદ કરો</a>
                <button type="submit" class="btn btn-primary"><?= svg_icon('check', 'me-1') ?>ફેરફારો સાચવો (Update Polish Sale)</button>
            </div>
        </form>
    </div>
</div>

<script>
function recalcPolishSale() {
    const wt = parseFloat(document.getElementById('weight').value) || 0;
    const rateInr = parseFloat(document.getElementById('price_per_carat_inr').value) || 0;
    const payAmt = Math.round((wt * rateInr) * 100) / 100;
    const brokerPct = parseFloat(document.getElementById('brokerage_percent').value) || 0;
    const brokerAmt = Math.round((payAmt * (brokerPct / 100)) * 100) / 100;
    const gstAmt = parseFloat(document.getElementById('ac_gst_amount').value) || 0;
    const netAmt = Math.round((payAmt + gstAmt) * 100) / 100;

    document.getElementById('disp_payment_amt').innerText = '₹ ' + payAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('disp_brokerage_amt').innerText = '₹ ' + brokerAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('disp_net_amount').innerText = '₹ ' + netAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});

    const sDate = document.getElementById('sale_date').value;
    const days = parseInt(document.getElementById('due_days').value) || 0;
    if (sDate) {
        const d = new Date(sDate);
        d.setDate(d.getDate() + days);
        const ddEl = document.getElementById('due_date');
        if (ddEl && document.activeElement !== ddEl) {
            ddEl.value = d.toISOString().split('T')[0];
        }
    }
}

['weight', 'price_per_carat_inr', 'brokerage_percent', 'ac_gst_amount', 'sale_date', 'due_days'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', recalcPolishSale);
        el.addEventListener('change', recalcPolishSale);
    }
});

// Sync Due Date change back to Due Days
document.getElementById('due_date')?.addEventListener('change', function() {
    const sDate = document.getElementById('sale_date')?.value;
    if (sDate && this.value) {
        const d1 = new Date(sDate);
        const d2 = new Date(this.value);
        const diffDays = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
        if (diffDays >= 0) {
            document.getElementById('due_days').value = diffDays;
        }
    }
});

// Auto fill Received Date when marked Paid
document.getElementById('payment_status_select')?.addEventListener('change', function() {
    if (this.value === 'paid') {
        const recDate = document.getElementById('received_date');
        if (recDate && !recDate.value) {
            recDate.value = new Date().toISOString().split('T')[0];
        }
    }
});

// Shape-wise Breakdown Management for Sale Edit
const isParcel = <?= $isParcelSale ? 'true' : 'false' ?>;
const existingShapesData = <?= json_encode($existingShapes) ?>;
const shapeOptions = <?= json_encode(get_polish_shapes_list()) ?>;

const shapesTbody = document.getElementById('shapes_tbody');
const btnAddShape = document.getElementById('btn_add_shape_row');
const pcsInput = document.getElementById('pcs');
const weightInput = document.getElementById('weight');
const editSaleForm = document.querySelector('form');

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

    const allowedDiff = Math.round(parcelWeight * 0.01 * 1000) / 1000;
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

    editSaleForm?.addEventListener('submit', function (e) {
        if (isParcel) {
            recalculateShapeSummary();
            if (!isShapeValid) {
                e.preventDefault();
                alert('અમાન્ય વિગત: ' + shapeValidationError);
                return false;
            }
        }
    });

    if (existingShapesData && existingShapesData.length > 0) {
        shapesTbody.innerHTML = '';
        existingShapesData.forEach((item, idx) => {
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

</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
