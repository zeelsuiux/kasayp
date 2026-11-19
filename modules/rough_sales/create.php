<?php
/**
 * Create Rough Sale Entry
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
$partId = (int)($_POST['part_id'] ?? $_GET['part_id'] ?? 0);

$outSellPart = null;
if ($partId > 0) {
    $st = $pdo->prepare("SELECT ap.*, COALESCE(ap.rough_purchase_id, at.rough_purchase_id) as rough_purchase_id, COALESCE(rp.purchase_no, ap.rough_number) as purchase_no FROM assort_parts ap LEFT JOIN assort_tasks at ON ap.assort_task_id = at.id LEFT JOIN rough_purchases rp ON COALESCE(ap.rough_purchase_id, at.rough_purchase_id) = rp.id WHERE ap.id = ?");
    $st->execute([$partId]);
    $outSellPart = $st->fetch();
}

$saleableParts = $pdo->query("
    SELECT ap.id, ap.part_name, ap.assort_number, ap.weight, ap.pcs, COALESCE(at.task_no, 'MERGED') as task_no, COALESCE(rp.purchase_no, ap.rough_number) as purchase_no, COALESCE(ap.rough_purchase_id, at.rough_purchase_id) as rough_purchase_id
    FROM assort_parts ap
    LEFT JOIN assort_tasks at ON ap.assort_task_id = at.id
    LEFT JOIN rough_purchases rp ON COALESCE(ap.rough_purchase_id, at.rough_purchase_id) = rp.id
    WHERE ap.status = 'available' AND ap.part_type = 'out_sell'
    ORDER BY ap.id DESC
")->fetchAll();

$parties = get_parties_by_role('rough_sale', 'party');
$brokers = get_parties_by_role('rough_sale_broker', 'broker');
$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();
$roughLots = $pdo->query("SELECT id, purchase_no, available_pcs, available_weight FROM rough_purchases WHERE available_weight > 0 ORDER BY id DESC")->fetchAll();
$defaultInv = generate_next_code('R-INV', 'rough_sales', 'invoice_no');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $invoiceNo = trim($_POST['invoice_no'] ?? $defaultInv);
    $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
    $partyId = (int)($_POST['party_id'] ?? 0);
    $brokerId = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $roughPurchaseId = !empty($_POST['rough_purchase_id']) ? (int)$_POST['rough_purchase_id'] : ($outSellPart['rough_purchase_id'] ?? null);
    $pcs = (int)($_POST['pcs'] ?? 1);
    $weight = (float)($_POST['weight'] ?? 0.000);

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

    $paymentAmt = round($weight * $pricePerCt, 2);
    $brokeragePct = (float)($_POST['brokerage_percent'] ?? 0.00);
    $brokerageAmt = round($paymentAmt * ($brokeragePct / 100), 2);
    $acGstAmt = (float)($_POST['ac_gst_amount'] ?? 0.00);
    $netAmt = round($paymentAmt + $acGstAmt, 2);

    $dueDays = (int)($_POST['due_days'] ?? 50);
    $dueDate = date('Y-m-d', strtotime($saleDate . " +$dueDays days"));
    $status = $_POST['payment_status'] ?? 'pending';
    $receivedAmt = ($status === 'paid') ? $netAmt : (float)($_POST['received_amount'] ?? 0.00);
    $receivedDate = !empty($_POST['received_date']) ? $_POST['received_date'] : null;
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;

    if ($receivedAmt >= $netAmt && $netAmt > 0) {
        $status = 'paid';
    } elseif ($receivedAmt > 0) {
        $status = 'partial';
    }

    $notes = trim($_POST['notes'] ?? '');

    if ($partyId <= 0 || $weight <= 0) {
        set_flash('error', 'કૃપા કરીને પાર્ટી અને વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($paymentCurrency === 'USD' && ($dollarPerCarat === null || $dollarPerCarat <= 0)) {
        set_flash('error', 'કૃપા કરીને Dollar / Ct ($) દાખલ કરો (ડોલર કરન્સીમાં ડોલર ભાવ ફરજિયાત છે).');
    } elseif ($paymentCurrency === 'INR' && $pricePerCt <= 0) {
        set_flash('error', 'કૃપા કરીને પ્રતિ કેરેટ ભાવ (₹) દાખલ કરો.');
    } elseif (is_party_blocked($partyId)) {
        set_flash('error', '🛑 આ પાર્ટી બ્લોક (Blocked) કરેલ છે! તેને રફ વેચાણ થઈ શકશે નહીં.');
    } elseif (!empty($brokerId) && is_party_blocked((int)$brokerId)) {
        set_flash('error', '🛑 આ દલાલ બ્લોક (Blocked) કરેલ છે! તેના દ્વારા સોદો નોંધાઈ શકશે નહીં.');
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO rough_sales (
                invoice_no, sale_date, payment_currency, party_id, broker_id, source_type, rough_purchase_id, assort_part_id,
                pcs, weight, dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount, brokerage_percent,
                brokerage_amount, ac_gst_amount, net_amount, due_days, due_date,
                payment_status, received_amount, received_date, notes, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )");

            $sourceType = $partId > 0 ? 'assort_part' : 'direct';
            $stmt->execute([
                $invoiceNo, $saleDate, $paymentCurrency, $partyId, $brokerId, $sourceType, $roughPurchaseId, ($partId > 0 ? $partId : null),
                $pcs, $weight, $dollarPerCarat, $dollarRate, $pricePerCt, $paymentAmt, $brokeragePct,
                $brokerageAmt, $acGstAmt, $netAmt, $dueDays, $dueDate,
                $status, $receivedAmt, $receivedDate, $notes, $user['id']
            ]);
            $saleId = (int)$pdo->lastInsertId();

            // Record received bank deposit if paid or partial
            if ($bankAccId && $receivedAmt > 0) {
                record_account_transaction(
                    $bankAccId, 'credit', $receivedAmt, 'rough_sale', $saleId,
                    $invoiceNo, $receivedDate ?? $saleDate, "Rough diamond sale collection on $invoiceNo from Party #$partyId", $user['id']
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

            if ($partId > 0) {
                $pdo->prepare("UPDATE assort_parts SET status = 'sold' WHERE id = ?")->execute([$partId]);
            } elseif ($roughPurchaseId > 0) {
                // Deduct from direct rough purchase lot
                $stk = $pdo->prepare("SELECT available_weight, available_pcs FROM rough_purchases WHERE id = ?");
                $stk->execute([$roughPurchaseId]);
                $rLot = $stk->fetch();
                if ($rLot) {
                    $newWt = max(0, round((float)$rLot['available_weight'] - $weight, 3));
                    $newPcs = max(0, (int)$rLot['available_pcs'] - $pcs);
                    $newStatus = ($newWt <= 0) ? 'sold' : 'in_stock';
                    $pdo->prepare("UPDATE rough_purchases SET available_weight = ?, available_pcs = ?, status = ? WHERE id = ?")
                        ->execute([$newWt, $newPcs, $newStatus, $roughPurchaseId]);
                }
            }

            $pdo->commit();
            log_activity('Rough Sold', 'Rough', (int)$pdo->lastInsertId(), "Sold $weight ct for $netAmt on $invoiceNo");
            set_flash('success', "Rough વેચાણ $invoiceNo સફળતાપૂર્વક નોંધાઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/rough_sales/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'વેચાણ નોંધવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવું Rough વેચાણ (New Rough Sale)';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon('hand-holding-dollar', 'text-success me-2') ?>નવું Rough હીરા વેચાણ (New Rough Sale)
        </h4>
        <div class="text-muted small">ફોર્મેટ મુજબ રફ હીરા વેચાણ બિલ અને સ્ટોક ડિડક્શન</div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>/modules/rough_sales/index.php" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>વેચાણ લિસ્ટ પર પાછા જાઓ
        </a>
    </div>
</div>

<form action="<?= BASE_URL ?>/modules/rough_sales/create.php<?= $partId > 0 ? '?part_id=' . $partId : '' ?>" method="POST" id="roughSaleForm">
    <?= csrf_field() ?>

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
                    </div>
                </div>
            </div>

            <!-- Card 2: Rough Stock Lot or Assort Part Selection -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-purple"><?= svg_icon('boxes-stacked') ?></span>
                        ૨. રફ સ્ટોક લોટ પસંદગી (Select Rough Lot / Assort Part)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <?php if ($partId > 0 && $outSellPart): ?>
                        <input type="hidden" name="part_id" value="<?= $partId ?>">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <span class="text-muted small d-block">એસોર્ટ પાર્ટ નામ (Tag: Sale):</span>
                                    <strong class="text-dark"><?= e($outSellPart['part_name']) ?> <span class="badge bg-success-subtle text-success border">🏷️ Sale</span></strong>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-muted small d-block">Assort No / મૂળ લોટ:</span>
                                    <strong class="text-primary font-mono"><?= e($outSellPart['assort_number'] ?: ($outSellPart['purchase_no'] ?: ($outSellPart['lot_no'] ?? '-'))) ?></strong>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-muted small d-block">હાજર જથ્થો:</span>
                                    <strong class="text-success font-mono"><?= format_carat($outSellPart['weight']) ?> (<?= (int)$outSellPart['pcs'] ?> Pcs)</strong>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if (!empty($saleableParts)): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">
                                    <?= svg_icon('tag', 'text-success me-1') ?>એસોર્ટમાંથી વેચાણ કરવા પાત્ર ભાગ (Assort Sale Parts)
                                </label>
                                <select name="part_id" class="form-select fw-semibold" id="select_assort_part" onchange="onAssortPartSelect(this)">
                                    <option value="">-- અથવા એસોર્ટ પાર્ટ પસંદ કરો (જો એસોર્ટમાંથી વેચવું હોય) --</option>
                                    <?php foreach ($saleableParts as $sp): ?>
                                        <option value="<?= $sp['id'] ?>" data-weight="<?= (float)$sp['weight'] ?>" data-pcs="<?= (int)$sp['pcs'] ?>" data-rough-id="<?= (int)$sp['rough_purchase_id'] ?>">
                                            <?= e($sp['assort_number'] ?: $sp['part_name']) ?> — <?= e($sp['part_name']) ?> (હાજર: <?= format_carat($sp['weight']) ?> | <?= (int)$sp['pcs'] ?> Pcs)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">રફ ખરીદી લોટ (Rough Lot to Sell From)</label>
                            <select name="rough_purchase_id" class="form-select fw-semibold" id="select_rough_lot">
                                <option value="">લોટ પસંદ કરો...</option>
                                <?php foreach ($roughLots as $rl): ?>
                                    <option value="<?= $rl['id'] ?>">
                                        <?= e($rl['purchase_no']) ?> — હાજર: <?= format_carat($rl['available_weight']) ?> (<?= (int)$rl['available_pcs'] ?> Pcs)
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                            <label class="form-label small fw-bold text-muted">વેચાણ નંગ (Sale Pcs)</label>
                            <div class="input-group">
                                <input type="number" name="pcs" class="form-control font-mono" value="<?= (int)($outSellPart['pcs'] ?? 1) ?>" min="1">
                                <span class="input-group-text">Pcs</span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-primary">વેચાણ રફ વજન (Sale Weight Carats) *</label>
                            <div class="input-group">
                                <input min="0" type="number" step="0.01" name="weight" id="weight" class="form-control form-control-lg fw-bold text-primary font-mono" value="<?= number_format((float)($outSellPart['weight'] ?? 0.00), 2, '.', '') ?>" required oninput="if(parseFloat(this.value)<0) this.value=0;">
                                <span class="input-group-text fw-bold text-primary">Ct</span>
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

                    <!-- 1. Indian Currency Inputs -->
                    <div id="inr_currency_box" class="mb-4">
                        <label class="form-label small fw-bold text-dark">વેચાણ ભાવ / Carat (INR ₹) *</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold bg-white text-primary">₹</span>
                            <input type="number" step="0.01" name="price_per_carat_inr" id="calc_price_inr" class="form-control form-control-lg fw-bold text-dark font-mono" value="<?= !empty($outSellPart['cost_per_carat']) && (float)$outSellPart['cost_per_carat'] > 0 ? number_format((float)$outSellPart['cost_per_carat'], 2, '.', '') : '' ?>" placeholder="0.00" required>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.8125rem;">
                            માત્ર ભારતીય રૂપિયામાં ભાવ દાખલ કરવો ફરજિયાત છે.
                        </div>
                    </div>

                    <!-- 2. Dollar Currency Inputs -->
                    <div id="usd_currency_box" class="mb-4" style="display: none;">
                        <div class="row g-3 mb-2">
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
                        <div id="usd_conversion_preview" class="alert alert-soft-danger py-2 px-3 small mb-0 border border-danger-subtle">
                            <span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થશે.
                        </div>
                    </div>

                    <!-- Brokerage & Taxes -->
                    <div class="form-subhead">દલાલી અને ખર્ચ (Brokerage & Additional Taxes)</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">દલાલી (Brokerage %)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="brokerage_percent" id="brokerage_percent" class="form-control font-mono" value="1.00">
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
                            <span>કાચી રકમ (Raw Rough Value):</span>
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
                            <textarea name="notes" class="form-control" rows="2" placeholder="વેચાણ ઓર્ડર અથવા ડિલિવરી સંદર્ભ નોંધ..."></textarea>
                        </div>

                        <div class="d-grid gap-2 pt-2">
                            <button type="submit" class="btn btn-success btn-lg fw-bold shadow-sm">
                                <?= svg_icon('floppy-disk', 'me-2') ?>Rough વેચાણ સેવ કરો
                            </button>
                            <a href="<?= BASE_URL ?>/modules/rough_sales/index.php" class="btn btn-outline-secondary">
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
function onAssortPartSelect(el) {
    const opt = el.options[el.selectedIndex];
    if (el.value) {
        const wt = opt.dataset.weight;
        const pcs = opt.dataset.pcs;
        const roughId = opt.dataset.roughId;
        const wtInput = document.querySelector('input[name="weight"]');
        const pcsInput = document.querySelector('input[name="pcs"]');
        const roughSelect = document.getElementById('select_rough_lot');
        if (wtInput && wt) {
            wtInput.value = wt;
            wtInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (pcsInput && pcs) {
            pcsInput.value = pcs;
            pcsInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (roughSelect && roughId) {
            roughSelect.value = roughId;
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
