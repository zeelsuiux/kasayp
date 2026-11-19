<?php
/**
 * View Rough Sale Invoice & Diamond Lifecycle History
 * Trace complete origin: Purchase Lot -> Assort Part -> Sale & Profit
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'રફ વેચાણ વિગત & ઇતિહાસ (Rough Sale Details)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT rs.*, 
           cust.party_name as customer_name, cust.phone as customer_phone, cust.city as customer_city,
           b.party_name as broker_name,
           rp.id as purchase_id, rp.purchase_no, rp.purchase_date, rp.weight as purchase_original_weight,
           rp.price_per_carat_inr as purchase_cost_per_carat, rp.payment_amount as purchase_raw_amount,
           rp.safe as purchase_safe, rp.dabba as purchase_dabba,
           supp.party_name as supplier_name, supp.city as supplier_city,
           ap.id as assort_part_id, ap.part_name, ap.cost_per_carat as assort_cost_per_carat,
           at.id as assort_task_id, at.task_no as assort_task_no, at.assort_date
    FROM rough_sales rs
    JOIN parties cust ON rs.party_id = cust.id
    LEFT JOIN parties b ON rs.broker_id = b.id
    LEFT JOIN rough_purchases rp ON rs.rough_purchase_id = rp.id
    LEFT JOIN parties supp ON rp.party_id = supp.id
    LEFT JOIN assort_parts ap ON rs.assort_part_id = ap.id
    LEFT JOIN assort_tasks at ON ap.assort_task_id = at.id
    WHERE rs.id = ?
");
$stmt->execute([$id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    set_flash('error', 'વેચાણ રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=sell');
    exit;
}

// Check if this part was formed via merge
$mergeInfo = null;
if (!empty($sale['assort_part_id'])) {
    $mStmt = $pdo->prepare("SELECT * FROM assort_part_merges WHERE new_part_id = ?");
    $mStmt->execute([(int)$sale['assort_part_id']]);
    $mergeInfo = $mStmt->fetch(PDO::FETCH_ASSOC);
}

// Cost & Profit Margin Analysis
$costPerCarat = 0.0;
if ($sale['source_type'] === 'assort_part' && !empty($sale['assort_cost_per_carat'])) {
    $costPerCarat = (float)$sale['assort_cost_per_carat'];
} elseif (!empty($sale['purchase_cost_per_carat'])) {
    $costPerCarat = (float)$sale['purchase_cost_per_carat'];
}

$saleWeight = (float)$sale['weight'];
$totalCost = $costPerCarat * $saleWeight;
$netSaleAmount = (float)$sale['net_amount'];
$profitAmount = $netSaleAmount - $totalCost;
$profitMarginPct = $totalCost > 0 ? ($profitAmount / $totalCost) * 100 : 0.0;

$actionButtons = '
    <button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm me-2">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ
    </button>
    <a href="' . BASE_URL . '/modules/rough_sales/edit.php?id=' . $sale['id'] . '" class="btn btn-outline-primary px-3 shadow-sm me-2">
        ' . svg_icon('pen-to-square', 'me-1') . 'વેચાણ એડિટ
    </a>
    <a href="' . BASE_URL . '/modules/parties/view.php?id=' . $sale['party_id'] . '&tab=sales" class="btn btn-outline-success px-3 shadow-sm me-2">
        ' . svg_icon('receipt', 'me-1') . 'ગ્રાહક ખાતાવહી
    </a>
    <a href="' . BASE_URL . '/modules/rough/index.php?tab=sell" class="btn btn-outline-secondary px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'Rough Sell લિસ્ટ
    </a>
';
?>

<div class="d-print-none">
<?= render_page_header(
    'Rough વેચાણ: ' . e($sale['invoice_no']),
    'ગ્રાહક: ' . e($sale['customer_name']) . ' (' . e($sale['customer_city'] ?? 'Surat') . ') • વેચાણ તારીખ: ' . format_date($sale['sale_date']),
    'hand-holding-dollar',
    $actionButtons,
    'primary'
) ?>
</div>

<!-- 1. Top KPI Summary Strip -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'વેચેલ રફ વજન',
            format_carat($saleWeight),
            (int)$sale['pcs'] . ' Pcs',
            'Rough Sold Weight',
            'amber',
            null,
            '',
            'shapes'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'વેચાણ ભાવ / Ct',
            is_stand_dollar($sale) ? '$ ' . number_format((float)$sale['dollar_per_carat'], 2) : format_inr((float)$sale['price_per_carat_inr']),
            is_stand_dollar($sale) ? '🔴 Stand Dollar' : 'Regular INR',
            'Selling Rate per Carat',
            'blue',
            null,
            '',
            'tag'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'કુલ નેટ વેચાણ રકમ',
            format_inr($netSaleAmount),
            $sale['payment_status'] === 'paid' ? 'ચુકવણી પૂર્ણ (Paid)' : 'બાકી (Pending)',
            'Total Invoice Amount',
            'green',
            null,
            '',
            'wallet'
        ) ?>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <?= render_stat_card(
            'અંદાજિત નફો / ખોટ',
            ($profitAmount >= 0 ? '+ ' : '') . format_inr($profitAmount),
            ($profitAmount >= 0 ? '+' : '') . number_format($profitMarginPct, 1) . '% Margin',
            'પડતર: ' . format_inr($totalCost),
            $profitAmount >= 0 ? 'green' : 'rose',
            null,
            '',
            $profitAmount >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'
        ) ?>
    </div>
</div>

<div class="row g-3">
    <!-- LEFT 8 COLS: COMPLETE DIAMOND TRACEABILITY JOURNEY -->
    <div class="col-12 col-lg-8">
        
        <!-- Diamond Life-Cycle Journey Card -->
        <div class="finora-card mb-3">
            <div class="finora-card-header bg-light py-2.5 px-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('timeline', 'text-primary') ?>
                    <span>આ રફનો સંપૂર્ણ ઇતિહાસ (Diamond Traceability Lifecycle)</span>
                </h6>
                <span class="badge bg-primary-subtle text-primary border font-mono">End-to-End Audit</span>
            </div>
            <div class="p-3">
                <div class="row g-3 align-items-stretch">
                    
                    <!-- STEP 1: ORIGINAL PURCHASE -->
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3 border bg-light h-100 position-relative">
                            <div class="badge bg-primary text-white mb-2">૧. મૂળ રફ ખરીદી (Inward)</div>
                            <?php if (!empty($sale['purchase_no'])): ?>
                                <h6 class="fw-bold text-dark font-mono mb-1">
                                    <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $sale['purchase_id'] ?>" class="text-primary text-decoration-none">
                                        #<?= e($sale['purchase_no']) ?> &rarr;
                                    </a>
                                </h6>
                                <div class="small text-muted mb-2">
                                    <strong>સપ્લાયર:</strong> <?= e($sale['supplier_name'] ?? '—') ?>
                                    <div style="font-size: 0.76rem;"><?= e($sale['supplier_city'] ?? 'Surat') ?></div>
                                </div>
                                <div class="small font-mono border-top pt-2">
                                    <div><strong>ખરીદી તારીખ:</strong> <?= format_date($sale['purchase_date']) ?></div>
                                    <div><strong>મૂળ લોટ વજન:</strong> <?= format_carat((float)$sale['purchase_original_weight']) ?></div>
                                    <div><strong>ખરીદી ભાવ:</strong> <?= format_inr((float)$sale['purchase_cost_per_carat']) ?> / Ct</div>
                                    <?php if (!empty($sale['purchase_safe']) || !empty($sale['purchase_dabba'])): ?>
                                        <div class="mt-1 badge bg-white border text-secondary font-mono" style="font-size: 0.72rem;">
                                            Safe: <?= e($sale['purchase_safe'] ?: 'OFC') ?> • Dabba: <?= e($sale['purchase_dabba'] ?: '—') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">સીધું વેચાણ (મૂળ ખરીદી લિંક નથી)</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- STEP 2: PROCESSING / ASSORTING -->
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3 border bg-light h-100">
                            <div class="badge bg-warning text-dark mb-2">૨. પ્રોસેસ / એસોર્ટિંગ (Stage)</div>
                            <?php if ($sale['source_type'] === 'assort_part' && !empty($sale['part_name'])): ?>
                                <h6 class="fw-bold text-dark font-mono mb-1">
                                    <?php if (!empty($sale['assort_task_id'])): ?>
                                        <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $sale['assort_task_id'] ?>" class="text-warning-emphasis text-decoration-none">
                                            #<?= e($sale['assort_task_no']) ?> &rarr;
                                        </a>
                                    <?php else: ?>
                                        Assort Part
                                    <?php endif; ?>
                                </h6>
                                <div class="small text-muted mb-2">
                                    <strong>પાર્ટ કેટેગરી:</strong>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border font-mono"><?= e($sale['part_name']) ?></span>
                                </div>
                                <div class="small font-mono border-top pt-2">
                                    <div><strong>એસોર્ટિંગ તારીખ:</strong> <?= !empty($sale['assort_date']) ? format_date($sale['assort_date']) : '—' ?></div>
                                    <div><strong>એસોર્ટિંગ પડતર:</strong> <?= format_inr((float)$sale['assort_cost_per_carat']) ?> / Ct</div>
                                    <div class="text-muted" style="font-size: 0.74rem;">(આ ખરીદીના ભાગ પાડીને અલગ કરાયેલ માલ)</div>
                                    <?php if ($mergeInfo): ?>
                                        <div class="mt-2 p-2 bg-info-subtle border border-info rounded text-dark" style="font-size: 0.75rem;">
                                            <div class="fw-bold text-info-emphasis">🔗 મર્જ કરેલ પેકેટ (Merged Lot)</div>
                                            <div>મૂળ રફ લોટ્સ: <strong><?= e($mergeInfo['source_lot_numbers']) ?></strong></div>
                                            <div>મર્જ કોડ: <span class="font-mono"><?= e($mergeInfo['merge_code']) ?></span></div>
                                            <div>કુલ એકઠા કરેલ નંગ: <?= (int)$mergeInfo['total_pcs'] ?> Pcs</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <h6 class="fw-bold text-secondary mb-1">ડાયરેક્ટ રફ વેચાણ</h6>
                                <p class="small text-muted mb-0">આ રફ કોઈપણ એસોર્ટિંગ કે કારખાને આપ્યા વગર મૂળ ખરીદી લોટમાંથી સીધી વેચવામાં આવેલી છે.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- STEP 3: THIS ROUGH SALE -->
                    <div class="col-12 col-md-4">
                        <div class="p-3 rounded-3 border bg-success-subtle h-100">
                            <div class="badge bg-success text-white mb-2">૩. આ વેચાણ (Outward)</div>
                            <h6 class="fw-bold text-success font-mono mb-1">#<?= e($sale['invoice_no']) ?></h6>
                            <div class="small text-dark mb-2">
                                <strong>ગ્રાહક:</strong> <?= e($sale['customer_name']) ?>
                                <?php if (!empty($sale['broker_name'])): ?>
                                    <div class="text-muted" style="font-size: 0.76rem;">દલાલ: <?= e($sale['broker_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="small font-mono border-top pt-2 text-dark">
                                <div><strong>વેચાણ તારીખ:</strong> <?= format_date($sale['sale_date']) ?></div>
                                <div><strong>વેચેલ વજન:</strong> <?= format_carat($saleWeight) ?> (<?= (int)$sale['pcs'] ?> Pcs)</div>
                                <div><strong>વેચાણ ભાવ:</strong> <?= format_inr((float)$sale['price_per_carat_inr']) ?> / Ct</div>
                                <div class="fw-bold text-success mt-1">નેટ રકમ: <?= format_inr($netSaleAmount) ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Profit & Margin Detailed Calculation -->
        <div class="finora-card mb-3">
            <div class="finora-card-header bg-white py-2.5 px-3 border-bottom">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('calculator', 'text-primary') ?>
                    <span>વેચાણ નફો / નુકસાન & પડતર ગણતરી (P&L Breakdown)</span>
                </h6>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0 align-middle">
                        <thead class="table-light font-mono small">
                            <tr>
                                <th>વિગત (Item)</th>
                                <th class="text-end">વજન (Weight)</th>
                                <th class="text-end">ભાવ / Ct (Rate)</th>
                                <th class="text-end">કુલ રકમ (Amount)</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            <tr>
                                <td>
                                    <strong class="text-success">વેચાણ રકમ (Sale Invoice Amount)</strong>
                                    <small class="text-muted d-block">ગ્રાહક: <?= e($sale['customer_name']) ?></small>
                                </td>
                                <td class="text-end"><?= format_carat($saleWeight) ?></td>
                                <td class="text-end text-success fw-bold"><?= format_inr((float)$sale['price_per_carat_inr']) ?></td>
                                <td class="text-end text-success fw-bold"><?= format_inr($netSaleAmount) ?></td>
                            </tr>
                            <tr>
                                <td>
                                    <strong class="text-danger">મૂળ ખરીદી પડતર (Cost of Goods Sold)</strong>
                                    <small class="text-muted d-block">સોર્સ: <?= $sale['source_type'] === 'assort_part' ? 'એસોર્ટિંગ પાર્ટ (' . e($sale['part_name']) . ')' : 'મૂળ ખરીદી લોટ #' . e($sale['purchase_no']) ?></small>
                                </td>
                                <td class="text-end"><?= format_carat($saleWeight) ?></td>
                                <td class="text-end text-danger"><?= format_inr($costPerCarat) ?></td>
                                <td class="text-end text-danger">- <?= format_inr($totalCost) ?></td>
                            </tr>
                            <tr class="<?= $profitAmount >= 0 ? 'table-success' : 'table-danger' ?> fw-bold fs-6">
                                <td>
                                    <?= $profitAmount >= 0 ? 'ચોખ્ખો નફો (Gross Profit)' : 'ચોખ્ખી ખોટ (Gross Loss)' ?>
                                    <span class="badge <?= $profitAmount >= 0 ? 'bg-success' : 'bg-danger' ?> text-white ms-2">
                                        <?= ($profitAmount >= 0 ? '+' : '') . number_format($profitMarginPct, 2) ?>% Margin
                                    </span>
                                </td>
                                <td class="text-end">—</td>
                                <td class="text-end">—</td>
                                <td class="text-end <?= $profitAmount >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($profitAmount >= 0 ? '+ ' : '') . format_inr($profitAmount) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- RIGHT 4 COLS: CUSTOMER, BROKER & PAYMENT DETAILS -->
    <div class="col-12 col-lg-4">
        
        <!-- Customer & Party Profile Card -->
        <div class="finora-card mb-3">
            <div class="finora-card-header bg-white py-2.5 px-3 border-bottom">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('user', 'text-primary') ?>
                    <span>ગ્રાહક માહિતી (Customer)</span>
                </h6>
            </div>
            <div class="p-3">
                <h5 class="fw-bold text-dark mb-1">
                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $sale['party_id'] ?>" class="text-primary text-decoration-none">
                        <?= e($sale['customer_name']) ?>
                    </a>
                </h5>
                <div class="small text-muted mb-2">
                    <?= svg_icon('location-dot', 'text-secondary me-1', 12) ?><?= e($sale['customer_city'] ?? 'Surat') ?>
                    <?php if (!empty($sale['customer_phone'])): ?>
                        • <?= svg_icon('phone', 'text-secondary me-1', 12) ?><?= e($sale['customer_phone']) ?>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2 mt-3">
                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $sale['party_id'] ?>&tab=sales" class="btn btn-sm btn-outline-primary fw-semibold">
                        <?= svg_icon('receipt', 'me-1') ?>ગ્રાહક ખાતાવહી (Sales Ledger) &rarr;
                    </a>
                </div>
            </div>
        </div>

        <!-- Payment & Due Date Card -->
        <div class="finora-card mb-3">
            <div class="finora-card-header bg-white py-2.5 px-3 border-bottom">
                <h6 class="finora-card-title mb-0 d-flex align-items-center gap-2">
                    <?= svg_icon('calendar-check', 'text-success') ?>
                    <span>ચુકવણી & મુદત (Payment Schedule)</span>
                </h6>
            </div>
            <div class="p-3">
                <div class="mb-2.5">
                    <small class="text-muted d-block">ચુકવણી સ્થિતિ (Status)</small>
                    <?php if ($sale['payment_status'] === 'paid'): ?>
                        <span class="badge bg-success-subtle text-success fs-6 border px-2 py-1">ચુકવણી જમા થઈ ગયેલ (Paid)</span>
                    <?php elseif ($sale['payment_status'] === 'partial'): ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis fs-6 border px-2 py-1">અંશતઃ જમા (Partial)</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger fs-6 border px-2 py-1">બાકી રકમ (Pending)</span>
                    <?php endif; ?>
                </div>

                <div class="mb-2.5 font-mono">
                    <small class="text-muted d-block">મુદત (Due Days & Date)</small>
                    <div class="fw-bold fs-6 text-dark"><?= format_date($sale['due_date']) ?> <span class="badge bg-light text-dark border ms-1"><?= (int)$sale['due_days'] ?> Days</span></div>
                </div>

                <?php if ((float)$sale['received_amount'] > 0): ?>
                    <div class="mb-2.5 font-mono">
                        <small class="text-muted d-block">મળેલી રકમ (Received)</small>
                        <strong class="text-success"><?= format_inr((float)$sale['received_amount']) ?></strong>
                        <?php if (!empty($sale['received_date'])): ?>
                            <span class="small text-muted">(<?= format_date($sale['received_date']) ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($sale['broker_id']) && (float)$sale['brokerage_amount'] > 0): ?>
                    <hr class="my-2">
                    <div class="font-mono">
                        <small class="text-muted d-block">દલાલી કમિશન (Brokerage)</small>
                        <div class="d-flex align-items-center justify-content-between">
                            <strong><?= format_inr((float)$sale['brokerage_amount']) ?> <small class="text-muted">(<?= number_format((float)$sale['brokerage_percent'], 2) ?>%)</small></strong>
                            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $sale['broker_id'] ?>&tab=brokerage" class="small text-decoration-none text-primary">
                                <?= e($sale['broker_name']) ?> &rarr;
                            </a>
                        </div>
                        <div class="mt-1">
                            <span class="badge <?= $sale['brokerage_payment_status'] === 'paid' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-dark' ?> border">
                                <?= $sale['brokerage_payment_status'] === 'paid' ? 'દલાલી ચૂકવાઈ (Paid)' : 'દલાલી બાકી (Pending)' ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
