<?php
/**
 * Edit Rough Sales Entry
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
    set_flash('error', 'અમાન્ય વેચાણ ID.');
    header('Location: ' . BASE_URL . '/modules/rough_sales/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT rs.*, p.party_name, b.party_name as broker_name 
                       FROM rough_sales rs
                       JOIN parties p ON rs.party_id = p.id
                       LEFT JOIN parties b ON rs.broker_id = b.id
                       WHERE rs.id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    set_flash('error', 'Rough વેચાણ રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/rough_sales/index.php');
    exit;
}

$parties = get_parties_by_role('rough_sale', 'party');
$brokers = get_parties_by_role('rough_sale_broker', 'broker');
if (!empty($sale['party_id']) && !in_array($sale['party_id'], array_column($parties, 'id'))) {
    $curP = $pdo->query("SELECT id, party_name, city FROM parties WHERE id = " . (int)$sale['party_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curP) $parties[] = $curP;
}
if (!empty($sale['broker_id']) && !in_array($sale['broker_id'], array_column($brokers, 'id'))) {
    $curB = $pdo->query("SELECT id, party_name FROM parties WHERE id = " . (int)$sale['broker_id'])->fetch(PDO::FETCH_ASSOC);
    if ($curB) $brokers[] = $curB;
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
    $dueDate     = date('Y-m-d', strtotime($saleDate . " +$dueDays days"));
    $notes       = trim($_POST['notes'] ?? '');

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને ગ્રાહક અને વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCt <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } else {
        try {
            $pdo->beginTransaction();

            $oldNet = (float)$sale['net_amount'];
            $diff = $netAmt - $oldNet;

            $stmt = $pdo->prepare("UPDATE rough_sales SET 
                sale_date = ?, payment_currency = ?, party_id = ?, broker_id = ?, pcs = ?, weight = ?,
                dollar_per_carat = ?, dollar_rate = ?, price_per_carat_inr = ?, payment_amount = ?, brokerage_percent = ?,
                brokerage_amount = ?, ac_gst_amount = ?, net_amount = ?, due_days = ?,
                due_date = ?, notes = ?
                WHERE id = ?");

            $stmt->execute([
                $saleDate, $paymentCurrency, $partyId, $brokerId, $pcs, $weight,
                $dollarPerCarat, $dollarRate, $pricePerCt, $paymentAmt, $brokeragePct,
                $brokerageAmt, $acGstAmt, $netAmt, $dueDays,
                $dueDate, $notes, $id
            ]);

            // Adjust party receivable balance if not fully paid
            if ($partyId === (int)$sale['party_id'] && $sale['payment_status'] !== 'paid') {
                if ($diff != 0) {
                    update_party_balance($partyId, $diff);
                }
            }

            $pdo->commit();
            log_activity('Rough Sale Edited', 'Sales', $id, "Updated rough sale {$sale['invoice_no']}: $weight ct, Net: $netAmt");
            set_flash('success', "Rough વેચાણ ઇન્વોઇસ {$sale['invoice_no']} સફળતાપૂર્વક અપડેટ થઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/rough_sales/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'વેચાણ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Rough વેચાણ એડિટ: ' . $sale['invoice_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Rough વેચાણ એડિટ: ' . $sale['invoice_no'],
    'ગ્રાહક, વજન, કેરેટ ભાવ, રકમ અને મુદત દિવસો સુધારો',
    'pen-to-square',
    '<a href="' . BASE_URL . '/modules/rough_sales/index.php" class="btn btn-outline-secondary">' . svg_icon('arrow-left', 'me-1') . 'પાછા જાઓ</a>',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form action="<?= BASE_URL ?>/modules/rough_sales/edit.php?id=<?= $id ?>" method="POST" id="editRoughSaleForm">
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
                            <input min="0" type="number" step="0.01" name="weight" id="weight" class="form-control font-mono fw-bold text-primary" value="<?= number_format((float)$sale['weight'], 2, '.', '') ?>" required oninput="if(parseFloat(this.value)<0) this.value=0;">
                        </div>
                    </div>

                    <!-- 1. Indian Currency Box -->
                    <div id="inr_currency_box" class="mt-3" style="<?= $isUsd ? 'display:none;' : '' ?>">
                        <label class="form-label small fw-bold text-dark">ભાવ પ્રતિ Carat INR (₹) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                            <input type="number" step="0.01" name="price_per_carat_inr" id="price_per_carat_inr" class="form-control form-control-lg font-mono fw-bold" value="<?= (float)$sale['price_per_carat_inr'] ?>" <?= !$isUsd ? 'required' : '' ?>>
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
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">મુદત દિવસો (Due Days)</label>
                            <input type="number" name="due_days" id="due_days" class="form-control font-mono" value="<?= (int)$sale['due_days'] ?>" min="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-muted">ચુકવણી તારીખ (Due Date)</label>
                            <input type="date" id="disp_due_date" class="form-control font-mono bg-light" value="<?= e($sale['due_date']) ?>" readonly>
                        </div>
                    </div>

                    <!-- Live Calculation Pill Banner -->
                    <div class="p-3 mt-3 bg-light rounded-3 border">
                        <div class="row text-center g-2">
                            <div class="col-6 col-md-4">
                                <small class="text-muted d-block">રફ વેચાણ રકમ</small>
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

            <div class="card-erp mb-3">
                <div class="card-header bg-white py-2.5 px-3">
                    <span class="fw-bold text-dark"><?= svg_icon('note-sticky', 'me-2 text-primary') ?>નોટ્સ (Remarks)</span>
                </div>
                <div class="card-body p-3">
                    <input type="text" name="notes" class="form-control" value="<?= e($sale['notes'] ?? '') ?>" placeholder="વેચાણ અંગે કોઈ વિશેષ નોંધ...">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="<?= BASE_URL ?>/modules/rough_sales/index.php" class="btn btn-outline-secondary">રદ કરો</a>
                <button type="submit" class="btn btn-primary"><?= svg_icon('check', 'me-1') ?>ફેરફારો સાચવો (Update Rough Sale)</button>
            </div>
        </form>
    </div>
</div>

<script>
function recalcRoughSale() {
    const isUsd = document.getElementById('curr_usd')?.checked || false;
    const inrBox = document.getElementById('inr_currency_box');
    const usdBox = document.getElementById('usd_currency_box');
    const usdPreview = document.getElementById('usd_conversion_preview');
    const rateInrInput = document.getElementById('price_per_carat_inr');
    const dpcInput = document.getElementById('dollar_per_carat');
    const drateInput = document.getElementById('dollar_rate');

    let rateInr = 0;
    if (!isUsd) {
        if (inrBox) inrBox.style.display = '';
        if (usdBox) usdBox.style.display = 'none';
        rateInr = parseFloat(rateInrInput?.value) || 0;
    } else {
        if (inrBox) inrBox.style.display = 'none';
        if (usdBox) usdBox.style.display = '';
        const dpc = parseFloat(dpcInput?.value) || 0;
        const drate = parseFloat(drateInput?.value) || 0;
        if (dpc > 0 && drate > 0) {
            rateInr = Math.round((dpc * drate) * 100) / 100;
            if (rateInrInput) rateInrInput.value = rateInr.toFixed(2);
            if (usdPreview) {
                usdPreview.className = 'alert alert-soft-success py-2 px-3 small mt-2 mb-0 border';
                usdPreview.innerHTML = `<strong>કન્વર્ટ કરેલ ભાવ:</strong> ₹${rateInr.toFixed(2)} / Ct ($${dpc.toFixed(2)} × ₹${drate.toFixed(2)})`;
            }
        } else {
            rateInr = 0;
            if (rateInrInput) rateInrInput.value = '0.00';
            if (usdPreview) {
                usdPreview.className = 'alert alert-soft-danger py-2 px-3 small mt-2 mb-0 border border-danger-subtle';
                usdPreview.innerHTML = `<span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થયેલ છે.`;
            }
        }
    }

    const wt = parseFloat(document.getElementById('weight').value) || 0;
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
        document.getElementById('disp_due_date').value = d.toISOString().split('T')[0];
    }
}

['weight', 'price_per_carat_inr', 'dollar_per_carat', 'dollar_rate', 'brokerage_percent', 'ac_gst_amount', 'sale_date', 'due_days'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', recalcRoughSale);
        el.addEventListener('change', recalcRoughSale);
    }
});
document.querySelectorAll('input[name="payment_currency"]').forEach(r => {
    r.addEventListener('change', recalcRoughSale);
});
recalcRoughSale();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
