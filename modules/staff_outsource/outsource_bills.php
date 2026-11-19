<?php
/**
 * Outsource Vendor Bills & Payments (Galaxy, LS, Factory)
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

$vendors = $pdo->query("SELECT id, party_name, party_type, city FROM parties WHERE (status != 'blocked' OR status IS NULL) AND party_type IN ('outsource_galaxy', 'outsource_ls', 'outsource_factory') ORDER BY party_name ASC")->fetchAll();
$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

// Handle new bill creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bill') {
    validate_csrf();

    $partyId = (int)($_POST['party_id'] ?? 0);
    $serviceType = $_POST['service_type'] ?? 'galaxy';
    $billNo = trim($_POST['bill_no'] ?? '');
    $billDate = $_POST['bill_date'] ?? date('Y-m-d');
    $pcs = (int)($_POST['total_pcs'] ?? 0);
    $weight = (float)($_POST['total_weight'] ?? 0.000);
    $rate = (float)($_POST['rate_per_unit'] ?? 0.00);
    $totalAmt = (float)($_POST['total_amount'] ?? 0.00);
    $isPaid = isset($_POST['mark_paid']);
    $bankAccId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($partyId <= 0 || empty($billNo) || $totalAmt <= 0) {
        set_flash('error', 'કૃપા કરીને વેન્ડર, બિલ નંબર અને રકમ યોગ્ય રીતે દાખલ કરો.');
    } elseif (is_party_blocked($partyId)) {
        set_flash('error', '🛑 આ આઉટસોર્સ વેન્ડર બ્લોક (Blocked) કરેલ છે! તેના નામ પર નવું બિલ બની શકશે નહીં.');
    } else {
        try {
            $pdo->beginTransaction();

            $status = $isPaid ? 'paid' : 'pending';
            $paidAmt = $isPaid ? $totalAmt : 0.00;
            $payDate = $isPaid ? date('Y-m-d') : null;

            $stmt = $pdo->prepare("INSERT INTO outsource_bills (party_id, service_type, bill_no, bill_date, total_pcs, total_weight, rate_per_unit, total_amount, paid_amount, payment_status, bank_account_id, payment_date, notes, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$partyId, $serviceType, $billNo, $billDate, $pcs, $weight, $rate, $totalAmt, $paidAmt, $status, $bankAccId, $payDate, $notes, $user['id']]);
            $billId = (int)$pdo->lastInsertId();

            if ($isPaid && $bankAccId && $totalAmt > 0) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$totalAmt, $bankAccId]);
                $pdo->prepare("INSERT INTO account_transactions (bank_account_id, type, amount, related_type, related_id, reference_no, transaction_date, notes, created_by)
                               VALUES (?, 'debit', ?, 'outsource', ?, ?, ?, 'Outsource vendor payment', ?)")
                    ->execute([$bankAccId, $totalAmt, $billId, $billNo, $payDate, $user['id']]);
            }

            $pdo->commit();
            log_activity('Outsource Bill Added', 'Outsource', $billId, "Added bill $billNo for $totalAmt ($serviceType)");
            set_flash('success', "વેન્ડર બિલ $billNo સફળતાપૂર્વક નોંધાઈ ગયું છે.");
            header('Location: ' . BASE_URL . '/modules/staff_outsource/outsource_bills.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'બિલ સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$bills = $pdo->query("SELECT ob.*, p.party_name, p.city, ba.account_name
                      FROM outsource_bills ob
                      JOIN parties p ON ob.party_id = p.id
                      LEFT JOIN bank_accounts ba ON ob.bank_account_id = ba.id
                      ORDER BY ob.bill_date DESC, ob.id DESC")->fetchAll();

$totBillAmt = 0.00;
$totPaidAmt = 0.00;
foreach ($bills as $b) {
    $totBillAmt += (float)$b['total_amount'];
    $totPaidAmt += (float)$b['paid_amount'];
}
$totDueAmt = max(0.0, $totBillAmt - $totPaidAmt);

$pageTitle = 'Outsource બિલો અને ચુકવણી (Vendor Bills)';
require_once __DIR__ . '/../../includes/header.php';

$billCount = count($bills);
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Outsource અને કારખાના લેબર બિલો (Vendor & Labour Bills)',
    'Galaxy, LS અને કારખાનાના કામના બિલો, સુરત 0.30ct લેબર નિયમ, ખર્ચ હિસાબ અને પેમેન્ટ ટ્રેકિંગ',
    'receipt',
    '
    <button class="btn btn-primary px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#newBillModal">
        ' . svg_icon('plus', 'me-1') . 'નવું વેન્ડર બિલ ઉમેરો (Add Bill)
    </button>
    <a href="' . BASE_URL . '/modules/staff_outsource/index.php" class="btn btn-outline-secondary px-3">
        ' . svg_icon('users', 'me-1') . 'સ્ટાફ ડિરેક્ટરી
    </a>
    ',
    'emerald'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ વેન્ડર બિલિંગ (Total Billed)', format_inr($totBillAmt), count($bills) . ' કુલ બિલો', 'લેબર અને આઉટસોર્સ', 'hero', null, '', 'receipt') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ચૂકવેલ રકમ (Total Paid)', format_inr($totPaidAmt), 'ચૂકવણી પૂર્ણ', 'ક્લીયર હિસાબ', 'white', null, 'pill-mint', 'circle-check') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('બાકી ચુકવણી (Pending Dues)', format_inr($totDueAmt), 'બાકી રકમ', 'વેન્ડર ચૂકવણી બાકી', 'white', null, 'pill-coral', 'hourglass-half') ?>
    </div>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>બિલ નં / તારીખ</th>
                        <th>વેન્ડરનું નામ</th>
                        <th>સેવા પ્રકાર (Service)</th>
                        <th>કામ નંગ & Carats</th>
                        <th>દર / યુનિટ (Rate)</th>
                        <th>બિલ રકમ (Total INR)</th>
                        <th>ચૂકવેલ રકમ</th>
                        <th>સ્થિતિ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">કોઈ વેન્ડર બિલો મળ્યા નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bills as $b): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= e($b['bill_no']) ?></strong>
                                    <div class="small text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($b['bill_date']) ?></div>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $b['party_id'] ?>&tab=jangad" class="fw-semibold text-primary text-decoration-none" title="વેન્ડર ખાતાવહી & જાંગડ જુઓ">
                                        <?= e($b['party_name']) ?>
                                    </a>
                                    <small class="text-muted d-block"><?= e($b['city']) ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark border text-uppercase"><?= e($b['service_type']) ?></span></td>
                                <td>
                                    <div><?= format_carat($b['total_weight']) ?></div>
                                    <small class="text-muted"><?= (int)$b['total_pcs'] ?> Pcs</small>
                                </td>
                                <td><?= format_inr($b['rate_per_unit']) ?></td>
                                <td><strong class="text-danger fs-6"><?= format_inr($b['total_amount']) ?></strong></td>
                                <td><strong class="text-success"><?= format_inr($b['paid_amount']) ?></strong></td>
                                <td>
                                    <?php if ($b['payment_status'] === 'paid'): ?>
                                        <span class="badge bg-success-subtle text-success border">Paid (ચૂકવાઈ ગયું)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">Pending</span>
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

<!-- Modal: New Bill -->
<div class="modal fade" id="newBillModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/staff_outsource/outsource_bills.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_bill">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><?= svg_icon('file-invoice-dollar', 'me-2 text-primary') ?>નવું Outsource વેન્ડર બિલ ઉમેરો</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">વેન્ડર પસંદ કરો *</label>
                        <select name="party_id" class="form-select fw-semibold" required>
                            <option value="">વેન્ડર પસંદ કરો...</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= e($v['party_name']) ?> (<?= e($v['party_type']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">બિલ નંબર *</label>
                            <input type="text" name="bill_no" class="form-control" placeholder="દા.ત. GAL-9821" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">બિલ તારીખ *</label>
                            <input type="date" name="bill_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">સેવા પ્રકાર (Service) *</label>
                            <select name="service_type" id="bill_service_type" class="form-select" onchange="toggleLabourCalc(this.value)">
                                <option value="galaxy">Galaxy Scanning</option>
                                <option value="ls">Laser Sawing (LS)</option>
                                <option value="factory" selected>કારખાનું Labour (Polish)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">કુલ બિલ રકમ (Total INR) *</label>
                            <input type="number" step="0.01" name="total_amount" id="bill_total_amount" class="form-control fw-bold text-danger fs-6" placeholder="0.00" required>
                        </div>
                    </div>

                    <!-- Factory Labour Smart Calculator (0.30ct Rule & Quotin/Normal Rates) -->
                    <div id="factory_labour_box" class="p-3 bg-light rounded border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-dark small"><?= svg_icon('calculator', 'text-primary me-1') ?>કારખાનું ઘસાઈ મજૂરી ગણતરી (Labour Calculator)</h6>
                            <span id="labour_rule_badge" class="badge bg-primary-subtle text-primary border">ઓટો-ડિટેક્શન</span>
                        </div>

                        <!-- Polish Type: Normal vs Quotin -->
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted d-block mb-1">પોલિશ પ્રકાર (Polish Type):</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="polish_type" id="pt_normal" value="normal" checked onchange="recalcLabourBill()">
                                <label class="btn btn-sm btn-outline-primary" for="pt_normal"><?= svg_icon('shapes', 'me-1') ?>Normal Polish (નોર્મલ)</label>

                                <input type="radio" class="btn-check" name="polish_type" id="pt_quotin" value="quotin" onchange="recalcLabourBill()">
                                <label class="btn btn-sm btn-outline-danger" for="pt_quotin"><?= svg_icon('award', 'me-1') ?>Quotin Polish (કવોટિન)</label>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted">નંગ (Qty) *</label>
                                <input type="number" name="total_pcs" id="calc_pcs" class="form-control form-control-sm" value="1" min="1" oninput="recalcLabourBill()">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted">Carats (વજન) *</label>
                                <input type="number" step="0.001" name="total_weight" id="calc_weight" class="form-control form-control-sm" placeholder="0.000" oninput="recalcLabourBill()">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted" id="rate_label">દર (Rate) *</label>
                                <input type="number" step="0.01" name="rate_per_unit" id="calc_rate" class="form-control form-control-sm fw-bold" placeholder="0.00" oninput="recalcLabourBill()">
                            </div>
                        </div>

                        <!-- Quick Presets -->
                        <div class="small text-muted mb-2">
                            <span class="fw-bold me-1">રેટ પ્રીસેટ્સ:</span>
                            <button type="button" class="badge bg-light text-dark border p-1 cursor-pointer me-1" onclick="applyLabourPreset(150, 1800)">Normal (₹150/pc \| ₹1800/ct)</button>
                            <button type="button" class="badge bg-light text-danger border p-1 cursor-pointer" onclick="applyLabourPreset(280, 3200)">Quotin (₹280/pc \| ₹3200/ct)</button>
                        </div>

                        <!-- Live Calculation Formula Notice -->
                        <div id="labour_formula_display" class="small p-2 bg-white rounded border font-monospace text-primary">
                            વિગત: નંગ અને વજન દાખલ કરો. (0.30 ct નીચે: નંગ દીઠ | 0.30 ct ઉપર: કેરેટ દીઠ)
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="mark_paid" id="mark_paid">
                        <label class="form-check-label small fw-bold" for="mark_paid">આ બિલ ચૂકવાઈ ગયું છે (Mark as Paid)</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Bank ખાતું (જો ચૂકવાઈ ગયું હોય)</label>
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
                    <button type="submit" class="btn btn-primary fw-bold">બિલ સેવ કરો (Save Bill)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleLabourCalc(serviceType) {
    const box = document.getElementById('factory_labour_box');
    if (box) {
        box.style.display = (serviceType === 'factory') ? 'block' : 'none';
    }
}

function applyLabourPreset(belowRate, aboveRate) {
    const pcs = parseInt(document.getElementById('calc_pcs')?.value || '1') || 1;
    const wt = parseFloat(document.getElementById('calc_weight')?.value || '0') || 0;
    const avgWeight = pcs > 0 ? (wt / pcs) : wt;
    const rateInput = document.getElementById('calc_rate');
    
    if (rateInput) {
        if (avgWeight < 0.30 && wt > 0) {
            rateInput.value = belowRate.toFixed(2);
        } else {
            rateInput.value = aboveRate.toFixed(2);
        }
    }
    recalcLabourBill();
}

function recalcLabourBill() {
    const pcs = parseInt(document.getElementById('calc_pcs')?.value || '0') || 0;
    const wt = parseFloat(document.getElementById('calc_weight')?.value || '0') || 0;
    const rate = parseFloat(document.getElementById('calc_rate')?.value || '0') || 0;
    const isQuotin = document.getElementById('pt_quotin')?.checked || false;
    const polishName = isQuotin ? 'Quotin Polish' : 'Normal Polish';

    const avgWeight = pcs > 0 ? (wt / pcs) : wt;
    const badge = document.getElementById('labour_rule_badge');
    const rateLabel = document.getElementById('rate_label');
    const formulaDisplay = document.getElementById('labour_formula_display');
    const totalInput = document.getElementById('bill_total_amount');

    if (!badge || !formulaDisplay) return;

    let totalLabour = 0;
    let formulaText = '';

    if (wt <= 0 && pcs <= 0) {
        formulaDisplay.innerHTML = 'વિગત: નંગ અને વજન દાખલ કરો.';
        return;
    }

    if (avgWeight < 0.30) {
        // Rule 1.1: Below 0.30 ct -> QTY WISE LABOUR
        badge.className = 'badge bg-warning-subtle text-warning-emphasis border';
        badge.innerHTML = window.svgIcon('gem', 'me-1') + '0.30 ct નીચે: નંગ દીઠ (Qty Wise)';
        if (rateLabel) rateLabel.innerText = 'દર / નંગ (₹/pc) *';
        totalLabour = pcs * rate;
        formulaText = `નિયમ: ${polishName} (0.30ct નીચે) &rarr; ${pcs} નંગ × ₹ ${rate.toFixed(2)} / નંગ = <strong>₹ ${totalLabour.toLocaleString('en-IN', {minimumFractionDigits: 2})}</strong>`;
    } else {
        // Rule 1.2: 0.30 ct and above -> PER CARAT WISE LABOUR
        badge.className = 'badge bg-success-subtle text-success border';
        badge.innerHTML = window.svgIcon('diamond', 'me-1') + '0.30 ct ઉપર: કેરેટ દીઠ (Carat Wise)';
        if (rateLabel) rateLabel.innerText = 'દર / કેરેટ (₹/ct) *';
        totalLabour = wt * rate;
        formulaText = `નિયમ: ${polishName} (0.30ct ઉપર) &rarr; ${wt.toFixed(3)} Carat × ₹ ${rate.toFixed(2)} / ct = <strong>₹ ${totalLabour.toLocaleString('en-IN', {minimumFractionDigits: 2})}</strong>`;
    }

    formulaDisplay.innerHTML = formulaText;
    if (totalLabour > 0 && totalInput) {
        totalInput.value = totalLabour.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    recalcLabourBill();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
