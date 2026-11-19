<?php
/**
 * Create New Rough Purchase Entry (સેક્શન ૬.૧)
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

$parties = get_parties_by_role('rough_purchase', 'party');
$brokers = get_parties_by_role('rough_purchase_broker', 'broker');
$bankAccounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

$activeDestinations = get_active_destinations();
$activeSafes = get_active_safes();
$activeDabbas = get_active_dabbas();
$activeTags = get_active_tags();
$activeProcessTags = get_active_process_tags();

$defaultPurchaseNo = generate_next_code('R-PUR', 'rough_purchases', 'purchase_no');
$defaultDate = date('Y-m-d');
$defaultDueDays = 50;
$defaultDueDate = date('Y-m-d', strtotime("+$defaultDueDays days"));
$defaultDollarRate = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();

    $purchaseNo   = trim($_POST['purchase_no'] ?? $defaultPurchaseNo);
    $purchaseDate = $_POST['purchase_date'] ?? $defaultDate;
    $partyId      = (int)($_POST['party_id'] ?? 0);
    $brokerId     = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $pcs          = (int)($_POST['pcs'] ?? 0);
    $weight       = (float)($_POST['weight'] ?? 0.000);

    $paymentCurrency = $_POST['payment_currency'] ?? 'INR';
    $dollarPerCarat  = null;
    $dollarRate      = null;
    $pricePerCarat   = 0.00;

    if ($paymentCurrency === 'USD') {
        $dollarPerCarat = !empty($_POST['dollar_per_carat']) ? (float)$_POST['dollar_per_carat'] : null;
        $dollarRate     = !empty($_POST['dollar_rate']) ? (float)$_POST['dollar_rate'] : null;
        if ($dollarPerCarat && $dollarRate && $dollarRate > 0) {
            $pricePerCarat = round($dollarPerCarat * $dollarRate, 2);
        } else {
            $pricePerCarat = 0.00; // Stand Dollar deal
        }
    } else {
        $paymentCurrency = 'INR';
        $pricePerCarat   = (float)($_POST['price_per_carat_inr'] ?? 0.00);
    }

    $paymentAmount   = round($weight * $pricePerCarat, 2);
    $acPercent       = (float)($_POST['ac_percent'] ?? 0.00);
    $acAmount        = round($paymentAmount * ($acPercent / 100), 2);
    $subTotal        = round($paymentAmount - $acAmount, 2);
    $brokeragePct    = (float)($_POST['brokerage_percent'] ?? 0.00);
    $brokerageAmount = round($subTotal * ($brokeragePct / 100), 2);
    $gstPercent      = (float)($_POST['gst_percent'] ?? 0.00);
    $gstAmount       = round($subTotal * ($gstPercent / 100), 2);
    if ($gstAmount == 0 && !empty($_POST['gst_amount'])) {
        $gstAmount = (float)$_POST['gst_amount'];
    }
    $partyBillAmount = round($subTotal + $gstAmount, 2);
    $netPayment      = round($subTotal + $brokerageAmount + $gstAmount, 2);
    $acGstAmount     = round($acAmount + $gstAmount, 2); // maintain legacy field compatibility

    $dueDays         = (int)($_POST['due_days'] ?? 50);
    $dueDate         = date('Y-m-d', strtotime($purchaseDate . " +$dueDays days"));
    $partyStatus     = $_POST['party_payment_status'] ?? 'pending';
    $chukvyaDate     = !empty($_POST['chukvya_date']) ? $_POST['chukvya_date'] : null;
    $paidAmount      = ($partyStatus === 'paid') ? $partyBillAmount : (float)($_POST['paid_amount'] ?? 0.00);

    if ($paidAmount >= $partyBillAmount && $partyBillAmount > 0) {
        $partyStatus = 'paid';
    } elseif ($paidAmount > 0) {
        $partyStatus = 'partial';
    }

    $brokerageStatus = $_POST['brokerage_payment_status'] ?? 'pending';
    $brokerageChukvyaDate = !empty($_POST['brokerage_chukvya_date']) ? $_POST['brokerage_chukvya_date'] : null;
    $bankAccountId   = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $description     = trim($_POST['product_description'] ?? '');
    $safe            = trim($_POST['safe'] ?? 'OFC');
    $dabba           = trim($_POST['dabba'] ?? '');
    $destination     = trim($_POST['destination'] ?? 'Surat');
    $tag             = trim($_POST['tag'] ?? 'Rough');
    $processTag      = trim($_POST['process_tag'] ?? 'Assort');

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને પાર્ટી અને વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCarat <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } elseif (is_party_blocked($partyId)) {
        set_flash('error', '🛑 આ પાર્ટી બ્લોક (Blocked) કરેલ છે! તેના નામ પર નવી રફ ખરીદી થઈ શકશે નહીં.');
    } elseif (!empty($brokerId) && is_party_blocked((int)$brokerId)) {
        set_flash('error', '🛑 આ દલાલ બ્લોક (Blocked) કરેલ છે! તેના દ્વારા સોદો નોંધાઈ શકશે નહીં.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO rough_purchases (
                purchase_no, purchase_date, payment_currency, party_id, broker_id, pcs, weight, available_weight, available_pcs,
                dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount, brokerage_percent,
                brokerage_amount, gst_percent, ac_percent, ac_amount, gst_amount, ac_gst_amount, net_payment, due_days, due_date, party_payment_status,
                paid_amount, chukvya_date, brokerage_payment_status, brokerage_chukvya_date, bank_account_id,
                product_description, safe, dabba, destination, tag, process_tag, status, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, 'in_stock', ?
            )");

            $stmt->execute([
                $purchaseNo, $purchaseDate, $paymentCurrency, $partyId, $brokerId, $pcs, $weight, $weight, $pcs,
                $dollarPerCarat, $dollarRate, $pricePerCarat, $paymentAmount, $brokeragePct,
                $brokerageAmount, $gstPercent, $acPercent, $acAmount, $gstAmount, $acGstAmount, $netPayment, $dueDays, $dueDate, $partyStatus,
                $paidAmount, $chukvyaDate, $brokerageStatus, $brokerageChukvyaDate, $bankAccountId,
                $description, $safe, $dabba, $destination, $tag, $processTag, $user['id']
            ]);

            $purchaseId = (int)$pdo->lastInsertId();

            // Record party bank payment if paid or partial
            if ($bankAccountId && $paidAmount > 0) {
                record_account_transaction(
                    $bankAccountId, 'debit', $paidAmount, 'rough_purchase', $purchaseId,
                    $purchaseNo, $chukvyaDate ?? $purchaseDate, "Rough purchase payment for $purchaseNo to Party #$partyId", $user['id']
                );
            }

            // Record brokerage bank payment if paid upfront
            if ($brokerageStatus === 'paid' && $bankAccountId && $brokerageAmount > 0) {
                record_account_transaction(
                    $bankAccountId, 'debit', $brokerageAmount, 'brokerage', $purchaseId,
                    $purchaseNo, $brokerageChukvyaDate ?? $purchaseDate, "Brokerage payment for $purchaseNo to Broker #$brokerId", $user['id']
                );
            }

            // Update party ledger outstanding balance
            $unpaidPartyBalance = round($partyBillAmount - $paidAmount, 2);
            if ($unpaidPartyBalance > 0) {
                update_party_balance($partyId, -$unpaidPartyBalance);
            }

            // Update broker balance if brokerage is pending
            if ($brokerId && $brokerageStatus !== 'paid' && $brokerageAmount > 0) {
                update_party_balance($brokerId, -$brokerageAmount);
            }

            $pdo->commit();
            log_activity('Rough Purchase Created', 'Rough', $purchaseId, "Created purchase $purchaseNo: $weight ct, Net: $netPayment");
            set_flash('success', "Rough ખરીદી એન્ટ્રી $purchaseNo સફળતાપૂર્વક નોંધાઈ ગઈ છે.");
            header('Location: ' . BASE_URL . '/modules/rough_purchase/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'ખરીદી નોંધવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવી Rough ખરીદી એન્ટ્રી (New Rough Purchase)';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon('cart-flatbed', 'text-primary me-2') ?>નવી Rough ખરીદી એન્ટ્રી (New Rough Purchase)
        </h4>
        <div class="text-muted small">ફોર્મ્યુલા મુજબ રફ હીરા ખરીદી અને સ્ટોક જમા એન્ટ્રી</div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>/modules/rough_purchase/index.php" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>ખરીદી લિસ્ટ પર પાછા જાઓ
        </a>
    </div>
</div>

<form action="<?= BASE_URL ?>/modules/rough_purchase/create.php" method="POST" id="roughForm">
    <?= csrf_field() ?>

    <!-- Hidden calculation carriers for database submission -->
    <input type="hidden" name="payment_amount" id="payment_amount" value="0.00">
    <input type="hidden" name="brokerage_amount" id="brokerage_amount" value="0.00">
    <input type="hidden" name="net_payment" id="net_payment" value="0.00">

    <div class="row g-3">
        <!-- ================= LEFT COLUMN: INPUTS & SPECIFICATIONS ================= -->
        <div class="col-12 col-lg-8">
            <!-- Card 1: Bill & Counterparty Details -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon"><?= svg_icon('file-invoice') ?></span>
                        ૧. ખરીદી અને વેપારી વિગત (Bill & Counterparty)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ખરીદી નંબર (Purchase No) *</label>
                            <input type="text" name="purchase_no" class="form-control fw-bold bg-light font-mono" value="<?= e($defaultPurchaseNo) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">ખરીદી તારીખ (Purchase Date) *</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control" value="<?= e($defaultDate) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">પાર્ટીનું નામ (Seller Party) *</label>
                            <select name="party_id" class="form-select" required>
                                <option value="">પાર્ટી શોધો અથવા પસંદ કરો...</option>
                                <?php foreach ($parties as $pt): ?>
                                    <option value="<?= $pt['id'] ?>"><?= e($pt['party_name']) ?> (<?= e($pt['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">દલાલ (Broker Name)</label>
                            <select name="broker_id" class="form-select">
                                <option value="">Direct (કોઈ દલાલ નથી)</option>
                                <?php foreach ($brokers as $br): ?>
                                    <option value="<?= $br['id'] ?>"><?= e($br['party_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">DESTINATION (ડેસ્ટિનેશન) *</label>
                            <input type="text" name="destination" list="destinations_list" class="form-control fw-semibold" placeholder="દા.ત. Surat, Mumbai" value="Surat" required>
                            <datalist id="destinations_list">
                                <?php foreach ($activeDestinations as $ad): ?>
                                    <option value="<?= e($ad['name']) ?>"><?= e($ad['description'] ?: $ad['name']) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted" style="font-size: 0.72rem;">માલનું મુખ્ય સ્થળ / બ્રાન્ચ</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">SAFE (સેફ / લોકેશન) *</label>
                            <input type="text" name="safe" list="safes_list" class="form-control font-mono fw-semibold" placeholder="દા.ત. OFC, M1, N1, Safe A" value="OFC" required>
                            <datalist id="safes_list">
                                <?php foreach ($activeSafes as $as): ?>
                                    <option value="<?= e($as['name']) ?>"><?= e($as['description'] ?: $as['name']) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted" style="font-size: 0.72rem;">મુખ્ય લોકેશન અથવા તિજોરી</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">DABBA (ડબ્બો / બોક્સ) *</label>
                            <input type="text" name="dabba" list="dabbas_list" class="form-control font-mono fw-semibold" placeholder="દા.ત. 1, 2, 3, 10, 12">
                            <datalist id="dabbas_list">
                                <?php foreach ($activeDabbas as $adb): ?>
                                    <option value="<?= e($adb['name']) ?>"><?= e($adb['description'] ?: ('ડબ્બો ' . $adb['name'])) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted" style="font-size: 0.72rem;">સેફ અંદરનો ડબ્બો / ખાના નં.</small>
                        </div>

                        <!-- Tag & Process Tag Selection -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1.5">
                                <span><?= svg_icon('tag', 'text-primary me-1') ?>TAG (માલ પ્રકાર ટેગ) *</span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" target="_blank" class="text-decoration-none small text-primary" style="font-size: 0.75rem;"><?= svg_icon('plus', 'me-0.5', 12) ?>સેટિંગ્સમાં નવો ટેગ</a>
                            </label>
                            <div class="d-flex flex-wrap gap-2" id="tag_radio_group">
                                <?php foreach ($activeTags as $tg): ?>
                                    <label class="tag-radio-card border rounded-3 p-2 px-3 cursor-pointer d-flex align-items-center gap-2 <?= $tg['name'] === 'Rough' ? 'border-primary bg-primary-subtle' : 'bg-light' ?>" style="cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" name="tag" value="<?= e($tg['name']) ?>" class="form-check-input mt-0" <?= $tg['name'] === 'Rough' ? 'checked' : '' ?> required>
                                        <div class="fw-bold small text-dark"><?= render_tag_badge($tg['name']) ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;">૧ ટેગ સિલેક્ટ કરો (દા.ત. Rough, Tops, Rejection)</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1.5">
                                <span><?= svg_icon('bolt', 'text-success me-1') ?>PROCESS TAG (પ્રોસેસ ટેગ) *</span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" target="_blank" class="text-decoration-none small text-success" style="font-size: 0.75rem;"><?= svg_icon('plus', 'me-0.5', 12) ?>સેટિંગ્સમાં નવો પ્રોસેસ ટેગ</a>
                            </label>
                            <div class="d-flex flex-wrap gap-2" id="ptag_radio_group">
                                <?php foreach ($activeProcessTags as $ptg): ?>
                                    <label class="ptag-radio-card border rounded-3 p-2 px-3 cursor-pointer d-flex align-items-center gap-2 <?= $ptg['name'] === 'Assort' ? 'border-success bg-success-subtle' : 'bg-light' ?>" style="cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" name="process_tag" value="<?= e($ptg['name']) ?>" class="form-check-input mt-0" <?= $ptg['name'] === 'Assort' ? 'checked' : '' ?> required>
                                        <div class="fw-bold small text-dark"><?= render_process_tag_badge($ptg['name']) ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;">૧ પ્રોસેસ ટેગ સિલેક્ટ કરો (Sell, Hold, Assort)</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">માલનું વર્ણન / ક્વોલિટી વિગત (DETAIL / Description)</label>
                            <input type="text" name="product_description" class="form-control" placeholder="દા.ત. Makeables, Botswana Lot, ORANGE-PINK, 8GRN YELLOW">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Weight, Rates & Pricing Formula -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('calculator') ?></span>
                        ૨. વજન, ભાવ અને ગણતરી (Weight, Rates & Commercial Engine)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <!-- Weight & Pcs -->
                    <div class="form-subhead">વજન અને નંગ (Units)</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">કુલ નંગ (Total Pcs)</label>
                            <div class="input-group">
                                <input type="number" name="pcs" id="pcs" class="form-control font-mono" min="0" value="1">
                                <span class="input-group-text">Pcs</span>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-primary">કુલ રફ વજન (Total Weight Carats) *</label>
                            <div class="input-group">
                                <input min="0" type="number" step="0.01" name="weight" id="weight" class="form-control form-control-lg fw-bold text-primary font-mono" placeholder="0.00" required oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text fw-bold text-primary">Ct</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">AVG SIZE (સરેરાશ)</label>
                            <div class="p-2 rounded-2 bg-light border text-center font-mono fw-bold text-secondary" id="display_avg_size" style="height: 48px; display: flex; align-items: center; justify-content: center; font-size: 0.92rem;">
                                0.000 Ct/Pc
                            </div>
                        </div>
                    </div>

                    <!-- Payment Currency Method (2 Types: Indian Currency or Dollar Currency) -->
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <div class="form-subhead mb-0">પેમેન્ટ પદ્ધતિ / કરન્સી પ્રકાર (Payment Method) *</div>
                        <span id="rate_mode_badge" class="badge bg-primary-subtle text-primary border px-2 py-1">
                            <?= svg_icon('tag', 'me-1') ?>Indian Currency (₹)
                        </span>
                    </div>

                    <!-- Payment Method Radio Toggle -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="row g-2">
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
                    </div>

                    <!-- 1. Indian Currency Inputs (Only Rupee is mandatory, Dollar hidden) -->
                    <div id="inr_currency_box" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-dark">પ્રતિ કેરેટ ભાવ (Price / Carat in INR ₹) *</label>
                                <div class="input-group">
                                    <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                                    <input type="number" step="0.01" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg fw-bold text-dark font-mono" placeholder="0.00" required>
                                </div>
                                <div class="form-text text-muted" style="font-size: 0.8125rem;">
                                    માત્ર ભારતીય રૂપિયામાં ભાવ દાખલ કરવો ફરજિયાત છે (ડોલર અને ડોલર રેટ લાગુ પડશે નહીં).
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Dollar Currency Inputs (Dollar mandatory, Dollar Rate optional = Stand Dollar) -->
                    <div id="usd_currency_box" class="mb-4" style="display: none;">
                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-success">Dollar / Ct ($) * <span class="badge bg-success-subtle text-success">Mandatory</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-success fw-bold">$</span>
                                    <input type="number" step="0.01" name="dollar_per_carat" id="dollar_per_carat" class="form-control form-control-lg font-mono fw-bold" placeholder="0.00">
                                </div>
                                <div class="form-text text-muted" style="font-size: 0.8125rem;">ડોલર ભાવ દાખલ કરવો ફરજિયાત છે.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Dollar Rate (₹/$) <span class="badge bg-light text-muted border">Optional - મરજિયાત</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted">₹</span>
                                    <input type="number" step="0.01" name="dollar_rate" id="dollar_rate" class="form-control form-control-lg font-mono" placeholder="ખાલી = Stand Dollar (દર બાકી)">
                                </div>
                                <div class="form-text text-muted" style="font-size: 0.8125rem;">જો ડોલર દર ખાલી રાખશો તો લૉટ <strong>Stand Dollar</strong> (લાલ બિંદી 🔴) તરીકે નોંધાશે.</div>
                            </div>
                        </div>
                        <div id="usd_conversion_preview" class="alert alert-soft-danger py-2 px-3 small mb-0 border border-danger-subtle">
                            <span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થશે.
                        </div>
                    </div>

                    <!-- Brokerage & Taxes -->
                    <div class="form-subhead">દલાલી, AC કપાત અને GST ખર્ચ (Brokerage, AC % & GST %)</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">AC % (A/C કપાત %)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="ac_percent" id="ac_percent" class="form-control font-mono" value="0.00" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <input type="hidden" name="ac_amount" id="ac_amount" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">દલાલી (Brokerage % - Sub Total)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="1.00" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">GST % (Sub Total પર)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="gst_percent" id="gst_percent" class="form-control font-mono" value="0.00" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <input type="hidden" name="gst_amount" id="gst_amount" value="0.00">
                            <input type="hidden" name="ac_gst_amount" id="ac_gst_amount" value="0.00">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= RIGHT COLUMN: LIVE TERMINAL & SETTLEMENT ================= -->
        <div class="col-12 col-lg-4">
            <div class="erp-summary-sticky">
                <!-- 1. Executive Commercial Terminal -->
                <div class="erp-terminal-card">
                    <div class="erp-terminal-header">
                        <h6><?= svg_icon('receipt', 'text-warning me-1') ?>હિસાબ ટર્મિનલ</h6>
                        
                    </div>
                    <div class="erp-terminal-body">
                        <div class="erp-terminal-row">
                            <span>કાચી રકમ (Raw Rough Value):</span>
                            <strong id="display_raw_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span>- AC % (<span id="display_ac_pct">0.00%</span>):</span>
                            <strong id="display_ac_amount" class="text-danger">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-divider my-2"></div>
                        <div class="erp-terminal-row">
                            <span class="fw-bold text-dark">Sub Total (Raw - AC):</span>
                            <strong id="display_sub_total" class="text-primary font-mono">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span>+ દલાલી રકમ (<span id="display_brokerage_pct">0.00%</span>):</span>
                            <strong id="display_brokerage_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-row">
                            <span>+ GST ખર્ચ (<span id="display_gst_pct">0.00%</span>):</span>
                            <strong id="display_gst_amount">₹ 0.00</strong>
                        </div>
                        <div class="erp-terminal-divider"></div>
                        <div class="erp-net-display">
                            <div class="net-label">કુલ ચૂકવવાની રકમ (NET PAYABLE)</div>
                            <div class="net-value" id="display_net_payment">₹ 0.00</div>
                            <div class="net-sub" id="display_effective_cost">₹ 0.00 / ct</div>
                        </div>
                        <div class="erp-terminal-row mt-2 pt-2 border-top">
                            <span class="text-muted small">પાર્ટી બિલ (Party Bill):</span>
                            <strong id="display_party_bill" class="font-mono text-primary">₹ 0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- 2. Payment Terms & Settlement -->
                <div class="erp-form-card">
                    <div class="erp-card-header">
                        <h6 class="header-title">
                            <span class="header-icon icon-gold"><?= svg_icon('calendar-check') ?></span>
                            મુદત અને ચુકવણી (Settlement)
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
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono" value="<?= $defaultDueDays ?>" min="0" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Due Date (ચુકવણી છેલ્લી તારીખ) *</label>
                            <input type="date" name="due_date" id="due_date" class="form-control bg-light font-mono" value="<?= $defaultDueDate ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">પાર્ટી ચુકવણી સ્થિતિ (Payment Status) *</label>
                            <select name="party_payment_status" id="payment_status_select" class="form-select no-search fw-semibold">
                                <option value="pending" selected>🕒 Pending (બાકી મુદત)</option>
                                <option value="partial">⏳ Partial (અંશતઃ ચૂકવેલ)</option>
                                <option value="paid">✅ Paid (પૂરેપૂરું ચૂકવાઈ ગયું)</option>
                            </select>
                        </div>

                        <!-- Pending Notice Box -->
                        <div id="pending_payment_notice" class="alert alert-soft-warning py-2 px-3 small mb-3 border">
                            <?= svg_icon('clock', 'me-1 text-warning') ?>
                            બિલ નિયત મુદતે બાકી રહેશે.
                        </div>

                        <!-- Conditional Payment Settlement Box -->
                        <div id="conditional_payment_box" style="display: none;" class="p-3 bg-light rounded-3 border mb-3">
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">ચૂકવેલ રકમ (Paid Amount INR) *</label>
                                <input type="number" step="0.01" name="paid_amount" id="paid_amount" class="form-control fw-bold font-mono" value="0.00">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold text-muted">ચૂકવ્યા તારીખ (Payment Date)</label>
                                <input type="date" name="chukvya_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="form-label small fw-bold text-muted">બેંક / કેશ ખાતું (Bank Account)</label>
                                <select name="bank_account_id" class="form-select">
                                    <option value="">ખાતું પસંદ કરો...</option>
                                    <?php foreach ($bankAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Brokerage Settlement Option -->
                        <div class="p-3 bg-light-subtle rounded-3 border mb-3">
                            <div class="form-subhead mb-2" style="font-size: 0.8125rem;">દલાલી સ્થિતિ (Brokerage Status)</div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select name="brokerage_payment_status" class="form-select form-select-sm no-search">
                                        <option value="pending" selected>દલાલી બાકી</option>
                                        <option value="paid">દલાલી ચૂકવાઈ</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="date" name="brokerage_chukvya_date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 pt-2">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                                <?= svg_icon('floppy-disk', 'me-2') ?>Rough ખરીદી સેવ કરો
                            </button>
                            <a href="<?= BASE_URL ?>/modules/rough_purchase/index.php" class="btn btn-outline-secondary">
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
            <strong id="sticky_net_display" class="font-mono text-primary fs-5">₹ 0.00</strong>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/rough/index.php" class="btn btn-outline-secondary px-3">રદ કરો</a>
            <button type="submit" class="btn btn-primary px-4 fw-bold scanner-btn-touch shadow-sm">
                <?= svg_icon('floppy-disk', 'me-1.5') ?>Rough ખરીદી સેવ કરો
            </button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function setupRadioGroup(groupSelector, activeClass, bgClass) {
        const group = document.querySelector(groupSelector);
        if (!group) return;
        const cards = group.querySelectorAll('label');
        group.addEventListener('change', function(e) {
            if (e.target && e.target.type === 'radio') {
                cards.forEach(c => {
                    c.classList.remove(activeClass, bgClass);
                    c.classList.add('bg-light');
                });
                const parent = e.target.closest('label');
                if (parent) {
                    parent.classList.remove('bg-light');
                    parent.classList.add(activeClass, bgClass);
                }
            }
        });
    }

    setupRadioGroup('#tag_radio_group', 'border-primary', 'bg-primary-subtle');
    setupRadioGroup('#ptag_radio_group', 'border-success', 'bg-success-subtle');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
