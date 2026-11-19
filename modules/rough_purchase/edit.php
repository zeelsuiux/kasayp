<?php
/**
 * Edit Rough Purchase Entry
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
    set_flash('error', 'અમાન્ય ખરીદી ID.');
    header('Location: ' . BASE_URL . '/modules/rough_purchase/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT rp.*, p.party_name, b.party_name as broker_name 
                       FROM rough_purchases rp
                       JOIN parties p ON rp.party_id = p.id
                       LEFT JOIN parties b ON rp.broker_id = b.id
                       WHERE rp.id = ?");
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    set_flash('error', 'ખરીદી રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/rough_purchase/index.php');
    exit;
}

$parties = get_parties_by_role('rough_purchase', 'party');
$brokers = get_parties_by_role('rough_purchase_broker', 'broker');

$activeDestinations = get_active_destinations();
$activeSafes = get_active_safes();
$activeDabbas = get_active_dabbas();
$activeTags = get_active_tags();
$activeProcessTags = get_active_process_tags();
if (!empty($purchase['party_id']) && !in_array($purchase['party_id'], array_column($parties, 'id'))) {
    $curP = $pdo->query("SELECT id, party_name, city FROM parties WHERE id = " . (int)$purchase['party_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curP) $parties[] = $curP;
}
if (!empty($purchase['broker_id']) && !in_array($purchase['broker_id'], array_column($brokers, 'id'))) {
    $curB = $pdo->query("SELECT id, party_name FROM parties WHERE id = " . (int)$purchase['broker_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curB) $brokers[] = $curB;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();

    $purchaseDate   = $_POST['purchase_date'] ?? $purchase['purchase_date'];
    $partyId        = (int)($_POST['party_id'] ?? $purchase['party_id']);
    $brokerId       = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $pcs            = (int)($_POST['pcs'] ?? $purchase['pcs']);
    $weight         = (float)($_POST['weight'] ?? $purchase['weight']);

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
    $acPercent       = (float)($_POST['ac_percent'] ?? ($purchase['ac_percent'] ?? 0.00));
    $acAmount        = round($paymentAmount * ($acPercent / 100), 2);
    $subTotal        = round($paymentAmount - $acAmount, 2);
    $brokeragePct    = (float)($_POST['brokerage_percent'] ?? 0.00);
    $brokerageAmount = round($subTotal * ($brokeragePct / 100), 2);
    $gstPercent      = (float)($_POST['gst_percent'] ?? ($purchase['gst_percent'] ?? 0.00));
    $gstAmount       = round($subTotal * ($gstPercent / 100), 2);
    if ($gstAmount == 0 && !empty($_POST['gst_amount'])) {
        $gstAmount = (float)$_POST['gst_amount'];
    }
    $partyBillAmount = round($subTotal + $gstAmount, 2);
    $netPayment      = round($subTotal + $brokerageAmount + $gstAmount, 2);
    $acGstAmount     = round($acAmount + $gstAmount, 2); // maintain legacy column

    $dueDays         = (int)($_POST['due_days'] ?? $purchase['due_days']);
    $dueDate         = date('Y-m-d', strtotime($purchaseDate . " +$dueDays days"));
    $description     = trim($_POST['product_description'] ?? '');
    $safe            = trim($_POST['safe'] ?? ($purchase['safe'] ?? 'OFC'));
    $dabba           = trim($_POST['dabba'] ?? ($purchase['dabba'] ?? ''));
    $destination     = trim($_POST['destination'] ?? ($purchase['destination'] ?? 'Surat'));
    $tag             = trim($_POST['tag'] ?? ($purchase['tag'] ?? 'Rough'));
    $processTag      = trim($_POST['process_tag'] ?? ($purchase['process_tag'] ?? 'Assort'));

    // Check available weight adjustment
    $weightDiff = $weight - (float)$purchase['weight'];
    $pcsDiff = $pcs - (int)$purchase['pcs'];
    $newAvailWeight = max(0.000, round((float)$purchase['available_weight'] + $weightDiff, 3));
    $newAvailPcs = max(0, (int)$purchase['available_pcs'] + $pcsDiff);

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને પાર્ટી અને વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCarat <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate party ledger difference
            $oldPartyBill = round((float)$purchase['payment_amount'] - (float)($purchase['ac_amount'] ?? 0) + (float)($purchase['gst_amount'] ?? $purchase['ac_gst_amount']), 2);
            $billDiff = $partyBillAmount - $oldPartyBill;

            $stmt = $pdo->prepare("UPDATE rough_purchases SET 
                purchase_date = ?, payment_currency = ?, party_id = ?, broker_id = ?, pcs = ?, weight = ?,
                available_weight = ?, available_pcs = ?, dollar_per_carat = ?, dollar_rate = ?,
                price_per_carat_inr = ?, payment_amount = ?, brokerage_percent = ?,
                brokerage_amount = ?, gst_percent = ?, ac_percent = ?, ac_amount = ?, gst_amount = ?, ac_gst_amount = ?, net_payment = ?, due_days = ?,
                due_date = ?, product_description = ?, safe = ?, dabba = ?, destination = ?,
                tag = ?, process_tag = ?
                WHERE id = ?");

            $stmt->execute([
                $purchaseDate, $paymentCurrency, $partyId, $brokerId, $pcs, $weight,
                $newAvailWeight, $newAvailPcs, $dollarPerCarat, $dollarRate,
                $pricePerCarat, $paymentAmount, $brokeragePct,
                $brokerageAmount, $gstPercent, $acPercent, $acAmount, $gstAmount, $acGstAmount, $netPayment, $dueDays,
                $dueDate, $description, $safe, $dabba, $destination,
                $tag, $processTag, $id
            ]);

            // Adjust party ledger balance if party is unchanged and payment pending
            if ($partyId === (int)$purchase['party_id'] && $purchase['party_payment_status'] !== 'paid') {
                if ($billDiff != 0) {
                    update_party_balance($partyId, -$billDiff);
                }
            } elseif ($partyId !== (int)$purchase['party_id']) {
                // Party changed: reverse old and apply new
                if ($purchase['party_payment_status'] !== 'paid') {
                    update_party_balance((int)$purchase['party_id'], $oldPartyBill);
                    update_party_balance($partyId, -$partyBillAmount);
                }
            }

            $pdo->commit();
            log_activity('Rough Purchase Edited', 'Rough', $id, "Updated purchase {$purchase['purchase_no']}: $weight ct, Net: $netPayment");
            set_flash('success', "Rough ખરીદી એન્ટ્રી {$purchase['purchase_no']} સફળતાપૂર્વક અપડેટ થઈ ગઈ છે.");
            header('Location: ' . BASE_URL . '/modules/rough_purchase/view.php?id=' . $id);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'ખરીદી અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Rough ખરીદી એડિટ કરો: ' . $purchase['purchase_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Rough ખરીદી એડિટ કરો: ' . $purchase['purchase_no'],
    'ખરીદી વજન, ભાવ, દલાલી અને મુદતમાં ફેરફાર કરો',
    'pen-to-square',
    '<a href="' . BASE_URL . '/modules/rough_purchase/view.php?id=' . $id . '" class="btn btn-outline-secondary">' . svg_icon('arrow-left', 'me-1') . 'પાછા જાઓ</a>',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form action="<?= BASE_URL ?>/modules/rough_purchase/edit.php?id=<?= $id ?>" method="POST" id="editRoughForm">
            <?= csrf_field() ?>

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark"><?= svg_icon('file-invoice', 'me-2 text-primary') ?>ખરીદી મૂળભૂત વિગતો</span>
                    <span class="badge bg-light text-dark font-mono"><?= e($purchase['purchase_no']) ?></span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ખરીદી નંબર (Purchase No)</label>
                            <input type="text" class="form-control font-mono bg-light" value="<?= e($purchase['purchase_no']) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ખરીદી તારીખ *</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="form-control" value="<?= e($purchase['purchase_date']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">પાર્ટી (Party / Vendor) *</label>
                            <select name="party_id" id="party_id" class="form-select select-tom" required>
                                <option value="">-- પાર્ટી પસંદ કરો --</option>
                                <?php foreach ($parties as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $purchase['party_id'] ? 'selected' : '' ?>>
                                        <?= e($p['party_name']) ?> (<?= e($p['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">દલાલ (Broker)</label>
                            <select name="broker_id" id="broker_id" class="form-select select-tom">
                                <option value="">-- Direct (દલાલ વગર) --</option>
                                <?php foreach ($brokers as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $purchase['broker_id'] ? 'selected' : '' ?>>
                                        <?= e($b['party_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">DESTINATION (ડેસ્ટિનેશન) *</label>
                            <input type="text" name="destination" list="destinations_list" class="form-control fw-semibold" value="<?= e($purchase['destination'] ?? 'Surat') ?>" placeholder="દા.ત. Surat, Mumbai" required>
                            <datalist id="destinations_list">
                                <?php foreach ($activeDestinations as $ad): ?>
                                    <option value="<?= e($ad['name']) ?>"><?= e($ad['description'] ?: $ad['name']) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">SAFE (સેફ / લોકેશન)</label>
                            <input type="text" name="safe" list="safes_list" class="form-control font-mono fw-semibold" value="<?= e($purchase['safe'] ?? 'OFC') ?>" placeholder="દા.ત. OFC, M1, N1, Safe A">
                            <datalist id="safes_list">
                                <?php foreach ($activeSafes as $as): ?>
                                    <option value="<?= e($as['name']) ?>"><?= e($as['description'] ?: $as['name']) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-dark">DABBA (ડબ્બો / બોક્સ)</label>
                            <input type="text" name="dabba" list="dabbas_list" class="form-control font-mono fw-semibold" value="<?= e($purchase['dabba'] ?? '') ?>" placeholder="દા.ત. 1, 2, 3, 10, 12">
                            <datalist id="dabbas_list">
                                <?php foreach ($activeDabbas as $adb): ?>
                                    <option value="<?= e($adb['name']) ?>"><?= e($adb['description'] ?: ('ડબ્બો ' . $adb['name'])) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <!-- Tag & Process Tag Selection -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1.5">
                                <span><?= svg_icon('tag', 'text-primary me-1') ?>TAG (માલ પ્રકાર ટેગ) *</span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" target="_blank" class="text-decoration-none small text-primary" style="font-size: 0.75rem;"><?= svg_icon('plus', 'me-0.5', 12) ?>સેટિંગ્સમાં નવો ટેગ</a>
                            </label>
                            <div class="d-flex flex-wrap gap-2" id="tag_radio_group">
                                <?php foreach ($activeTags as $tg): ?>
                                    <label class="tag-radio-card border rounded-3 p-2 px-3 cursor-pointer d-flex align-items-center gap-2 <?= ($purchase['tag'] ?? 'Rough') === $tg['name'] ? 'border-primary bg-primary-subtle' : 'bg-light' ?>" style="cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" name="tag" value="<?= e($tg['name']) ?>" class="form-check-input mt-0" <?= ($purchase['tag'] ?? 'Rough') === $tg['name'] ? 'checked' : '' ?> required>
                                        <div class="fw-bold small text-dark"><?= render_tag_badge($tg['name']) ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-1.5">
                                <span><?= svg_icon('bolt', 'text-success me-1') ?>PROCESS TAG (પ્રોસેસ ટેગ) *</span>
                                <a href="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" target="_blank" class="text-decoration-none small text-success" style="font-size: 0.75rem;"><?= svg_icon('plus', 'me-0.5', 12) ?>સેટિંગ્સમાં નવો પ્રોસેસ ટેગ</a>
                            </label>
                            <div class="d-flex flex-wrap gap-2" id="ptag_radio_group">
                                <?php foreach ($activeProcessTags as $ptg): ?>
                                    <label class="ptag-radio-card border rounded-3 p-2 px-3 cursor-pointer d-flex align-items-center gap-2 <?= ($purchase['process_tag'] ?? 'Assort') === $ptg['name'] ? 'border-success bg-success-subtle' : 'bg-light' ?>" style="cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" name="process_tag" value="<?= e($ptg['name']) ?>" class="form-check-input mt-0" <?= ($purchase['process_tag'] ?? 'Assort') === $ptg['name'] ? 'checked' : '' ?> required>
                                        <div class="fw-bold small text-dark"><?= render_process_tag_badge($ptg['name']) ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3">
                    <span class="fw-bold text-dark"><?= svg_icon('scale-balanced', 'me-2 text-primary') ?>વજન, ભાવ અને ગણતરી (Weight & Pricing)</span>
                </div>
                <div class="card-body p-3">
                    <?php 
                        $isUsd = ($purchase['payment_currency'] ?? '') === 'USD' || ((float)$purchase['dollar_per_carat'] > 0);
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
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">નંગ (Pcs)</label>
                            <input type="number" name="pcs" id="pcs" class="form-control font-mono" value="<?= (int)$purchase['pcs'] ?>" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">કુલ વજન (Weight Carat) *</label>
                            <input min="0" type="number" step="0.01" name="weight" id="weight" class="form-control font-mono fw-bold text-primary" value="<?= number_format((float)$purchase['weight'], 2, '.', '') ?>" required oninput="if(parseFloat(this.value)<0) this.value=0;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">AVG SIZE</label>
                            <div class="p-2 rounded-2 bg-light border text-center font-mono fw-bold text-secondary" id="display_avg_size" style="height: 38px; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                                <?= ((int)$purchase['pcs'] > 0 && (float)$purchase['weight'] > 0) ? number_format((float)$purchase['weight'] / (int)$purchase['pcs'], 2) . ' Ct/Pc' : '0.00 Ct/Pc' ?>
                            </div>
                        </div>
                    </div>

                    <!-- 1. Indian Currency Box -->
                    <div id="inr_currency_box" class="mt-3" style="<?= $isUsd ? 'display:none;' : '' ?>">
                        <label class="form-label small fw-bold text-dark">પ્રતિ Carat ભાવ INR (₹/Ct) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                            <input type="number" step="0.01" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg font-mono fw-bold" value="<?= (float)$purchase['price_per_carat_inr'] ?>" <?= !$isUsd ? 'required' : '' ?>>
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
                                    <input type="number" step="0.01" name="dollar_per_carat" id="dollar_per_carat" class="form-control form-control-lg font-mono fw-bold" placeholder="0.00" value="<?= (float)$purchase['dollar_per_carat'] > 0 ? (float)$purchase['dollar_per_carat'] : '' ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Dollar Rate (₹/$) <span class="badge bg-light text-muted border">Optional</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted">₹</span>
                                    <input type="number" step="0.01" name="dollar_rate" id="dollar_rate" class="form-control form-control-lg font-mono" placeholder="ખાલી = Stand Dollar (દર બાકી)" value="<?= (float)$purchase['dollar_rate'] > 0 ? (float)$purchase['dollar_rate'] : '' ?>">
                                </div>
                            </div>
                        </div>
                        <div id="usd_conversion_preview" class="alert <?= ((float)$purchase['dollar_rate'] > 0) ? 'alert-soft-success' : 'alert-soft-danger' ?> py-2 px-3 small mt-2 mb-0 border">
                            <?php if ((float)$purchase['dollar_rate'] > 0): ?>
                                <strong>કન્વર્ટ કરેલ ભાવ:</strong> ₹<?= number_format((float)$purchase['price_per_carat_inr'], 2) ?> / Ct ($<?= number_format((float)$purchase['dollar_per_carat'], 2) ?> × ₹<?= number_format((float)$purchase['dollar_rate'], 2) ?>)
                            <?php else: ?>
                                <span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થયેલ છે.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">AC % (A/C કપાત %)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="ac_percent" id="ac_percent" class="form-control font-mono" value="<?= (float)($purchase['ac_percent'] ?? 0.00) ?>" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <input type="hidden" name="ac_amount" id="ac_amount" value="<?= (float)($purchase['ac_amount'] ?? 0.00) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">દલાલી % (Brokerage % - Sub Total)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="<?= (float)$purchase['brokerage_percent'] ?>" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">GST % (Sub Total પર)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="gst_percent" id="gst_percent" class="form-control font-mono" value="<?= (float)($purchase['gst_percent'] ?? 0.00) ?>" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <input type="hidden" name="gst_amount" id="gst_amount" value="<?= (float)($purchase['gst_amount'] ?? 0.00) ?>">
                            <input type="hidden" name="ac_gst_amount" id="ac_gst_amount" value="<?= (float)($purchase['gst_amount'] ?? $purchase['ac_gst_amount']) ?>">
                        </div>
                    </div>

                    <!-- Live Calculation Summary Pill Banner -->
                    <div class="p-3 mt-3 bg-light rounded-3 border">
                        <div class="row text-center g-2">
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">રફ મૂલ્ય (Gross)</small>
                                <strong class="fs-6 font-mono text-dark" id="disp_payment_amt"><?= format_inr($purchase['payment_amount']) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">- AC % (<span id="disp_ac_pct_lbl"><?= number_format((float)($purchase['ac_percent'] ?? 0.00), 2) ?>%</span>)</small>
                                <strong class="fs-6 font-mono text-danger" id="disp_ac_amt">- <?= format_inr((float)($purchase['ac_amount'] ?? 0.00)) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">Sub Total (Raw-AC)</small>
                                <strong class="fs-6 font-mono text-primary" id="disp_sub_total"><?= format_inr((float)$purchase['payment_amount'] - (float)($purchase['ac_amount'] ?? 0.00)) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">+ દલાલી રકમ (<span id="disp_brokerage_pct_lbl"><?= number_format((float)$purchase['brokerage_percent'], 2) ?>%</span>)</small>
                                <strong class="fs-6 font-mono text-warning-emphasis" id="disp_brokerage_amt"><?= format_inr($purchase['brokerage_amount']) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">+ GST ખર્ચ (<span id="disp_gst_pct_lbl"><?= number_format((float)($purchase['gst_percent'] ?? 0.00), 2) ?>%</span>)</small>
                                <strong class="fs-6 font-mono text-primary" id="disp_gst_amt"><?= format_inr((float)($purchase['gst_amount'] ?? $purchase['ac_gst_amount'])) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">પાર્ટી બિલ</small>
                                <strong class="fs-6 font-mono text-primary" id="disp_party_bill"><?= format_inr((float)$purchase['payment_amount'] - (float)($purchase['ac_amount'] ?? 0) + (float)($purchase['gst_amount'] ?? $purchase['ac_gst_amount'])) ?></strong>
                            </div>
                            <div class="col-6 col-md-3 col-lg">
                                <small class="text-muted d-block">કુલ ખરીદી ખર્ચ (Net)</small>
                                <strong class="fs-6 font-mono text-success" id="disp_net_payment"><?= format_inr($purchase['net_payment']) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3">
                    <span class="fw-bold text-dark"><?= svg_icon('calendar-check', 'me-2 text-primary') ?>મુદત અને શરતો (Payment Terms)</span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">મુદત દિવસો (Due Days)</label>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono" value="<?= (int)$purchase['due_days'] ?>" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">ચુકવણી તારીખ (Due Date)</label>
                            <input type="date" id="disp_due_date" class="form-control font-mono bg-light" value="<?= e($purchase['due_date']) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">માલ વર્ણન / નોટ્સ</label>
                            <input type="text" name="product_description" class="form-control" value="<?= e($purchase['product_description']) ?>" placeholder="દા.ત. Kapan-A, 4-8 sz...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">રદ કરો</a>
                <button type="submit" class="btn btn-primary"><?= svg_icon('check', 'me-1') ?>ફેરફારો સાચવો (Update Purchase)</button>
            </div>
        </form>
    </div>
</div>

<script>
function recalcRough() {
    const wt = parseFloat(document.getElementById('weight').value) || 0;
    const dpc = parseFloat(document.getElementById('dollar_per_carat').value) || 0;
    const dr = parseFloat(document.getElementById('dollar_rate').value) || 0;
    let rateInr = parseFloat(document.getElementById('price_per_carat_inr').value) || 0;

    if (dpc > 0 && dr > 0) {
        rateInr = Math.round((dpc * dr) * 100) / 100;
        document.getElementById('price_per_carat_inr').value = rateInr.toFixed(2);
        const lbl = document.getElementById('price_inr_label');
        if (lbl) lbl.innerText = 'પ્રતિ Carat ભાવ INR (કન્વર્ટેડ) *';
        const hlp = document.getElementById('price_inr_help');
        if (hlp) hlp.innerText = `ડોલર ગણતરી: $${dpc.toFixed(2)} × ₹${dr.toFixed(2)}`;
    } else {
        const lbl = document.getElementById('price_inr_label');
        if (lbl) lbl.innerText = 'પ્રતિ Carat ભાવ INR (Stand Rate) *';
        const hlp = document.getElementById('price_inr_help');
        if (hlp) hlp.innerText = 'સીધો સ્ટેન્ડ રેટ (Stand Rate ₹)';
    }

    const payAmt = Math.round((wt * rateInr) * 100) / 100;
    const acPct = parseFloat(document.getElementById('ac_percent')?.value) || 0;
    const acAmt = Math.round((payAmt * (acPct / 100)) * 100) / 100;
    const subTotal = Math.round((payAmt - acAmt) * 100) / 100;
    const brokerPct = parseFloat(document.getElementById('brokerage_percent')?.value) || 0;
    const brokerAmt = Math.round((subTotal * (brokerPct / 100)) * 100) / 100;
    const gstPct = parseFloat(document.getElementById('gst_percent')?.value) || 0;
    let gstAmt = 0;
    if (document.getElementById('gst_percent')) {
        gstAmt = Math.round((subTotal * (gstPct / 100)) * 100) / 100;
        if (document.getElementById('gst_amount')) document.getElementById('gst_amount').value = gstAmt.toFixed(2);
    } else {
        gstAmt = parseFloat(document.getElementById('gst_amount')?.value || document.getElementById('ac_gst_amount')?.value) || 0;
    }

    const partyBill = Math.round((subTotal + gstAmt) * 100) / 100;
    const netPayment = Math.round((subTotal + brokerAmt + gstAmt) * 100) / 100;

    document.getElementById('disp_payment_amt').innerText = '₹ ' + payAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    if (document.getElementById('disp_ac_pct_lbl')) document.getElementById('disp_ac_pct_lbl').innerText = acPct.toFixed(2) + '%';
    if (document.getElementById('disp_ac_amt')) document.getElementById('disp_ac_amt').innerText = (acAmt > 0 ? '- ' : '') + '₹ ' + acAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    if (document.getElementById('disp_sub_total')) document.getElementById('disp_sub_total').innerText = '₹ ' + subTotal.toLocaleString('en-IN', {minimumFractionDigits: 2});
    if (document.getElementById('disp_brokerage_pct_lbl')) document.getElementById('disp_brokerage_pct_lbl').innerText = brokerPct.toFixed(2) + '%';
    document.getElementById('disp_brokerage_amt').innerText = (brokerAmt > 0 ? '+ ' : '') + '₹ ' + brokerAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    if (document.getElementById('disp_gst_pct_lbl')) document.getElementById('disp_gst_pct_lbl').innerText = gstPct.toFixed(2) + '%';
    if (document.getElementById('disp_gst_amt')) document.getElementById('disp_gst_amt').innerText = (gstAmt > 0 ? '+ ' : '') + '₹ ' + gstAmt.toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('disp_party_bill').innerText = '₹ ' + partyBill.toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('disp_net_payment').innerText = '₹ ' + netPayment.toLocaleString('en-IN', {minimumFractionDigits: 2});

    const pDate = document.getElementById('purchase_date').value;
    const days = parseInt(document.getElementById('due_days').value) || 0;
    if (pDate) {
        const d = new Date(pDate);
        d.setDate(d.getDate() + days);
        document.getElementById('disp_due_date').value = d.toISOString().split('T')[0];
    }
}

['weight', 'dollar_per_carat', 'dollar_rate', 'price_per_carat_inr', 'ac_percent', 'brokerage_percent', 'gst_percent', 'gst_amount', 'ac_gst_amount', 'purchase_date', 'due_days'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', recalcRough);
        el.addEventListener('change', recalcRough);
    }
});

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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
