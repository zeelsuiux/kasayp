<?php
/**
 * Party Ledger & Profile (પાર્ટી ખાતાવહી અને પ્રોફાઇલ)
 * Diamond ERP - kasyap
 */

declare(strict_types=1);

$pageTitle = 'પાર્ટી ખાતાવહી (Party Ledger & Statement)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$partyId = (int)($_GET['id'] ?? 0);

if ($partyId <= 0) {
    echo "<div class='alert alert-danger m-4'>માન્ય પાર્ટી ID મળ્યો નથી. (Invalid Party ID)</div>";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Fetch Party Details
$stmt = $pdo->prepare("SELECT * FROM parties WHERE id = ?");
$stmt->execute([$partyId]);
$party = $stmt->fetch();

if (!$party) {
    echo "<div class='alert alert-danger m-4'>પાર્ટી રેકોર્ડ સિસ્ટમમાં ઉપલબ્ધ નથી.</div>";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$bJoinRp  = "";
$bJoinPp  = "";
$bJoinFin = "";
$pJoinRp  = "";
$pJoinPp  = "";
$pJoinRs  = "";
$pJoinPs  = "";

$rpFilter  = "";
$ppFilter  = "";
$rsFilter  = "";
$psFilter  = "";
$jgFilter  = "";
$finFilter = "";

// Fetch Rough Purchases from this party
$rpStmt = $pdo->prepare("
    SELECT rp.*, b.account_name as paid_account_name
    FROM rough_purchases rp
    LEFT JOIN bank_accounts b ON (rp.bank_account_id = b.id {$bJoinRp})
    WHERE rp.party_id = ? {$rpFilter}
    ORDER BY rp.purchase_date DESC, rp.id DESC
");
$rpStmt->execute([$partyId]);
$roughPurchases = $rpStmt->fetchAll();

// Fetch Polish Purchases from this party
$ppStmt = $pdo->prepare("
    SELECT pp.*, b.account_name as paid_account_name
    FROM polish_purchases pp
    LEFT JOIN bank_accounts b ON (pp.bank_account_id = b.id {$bJoinPp})
    WHERE pp.party_id = ? {$ppFilter}
    ORDER BY pp.purchase_date DESC, pp.id DESC
");
$ppStmt->execute([$partyId]);
$polishPurchases = $ppStmt->fetchAll();

// Fetch Rough Sales to this party
$rsStmt = $pdo->prepare("
    SELECT rs.*
    FROM rough_sales rs
    WHERE rs.party_id = ? {$rsFilter}
    ORDER BY rs.sale_date DESC, rs.id DESC
");
$rsStmt->execute([$partyId]);
$roughSales = $rsStmt->fetchAll();

// Fetch Polish Sales to this party
$psStmt = $pdo->prepare("
    SELECT ps.*
    FROM polish_sales ps
    WHERE ps.party_id = ? {$psFilter}
    ORDER BY ps.sale_date DESC, ps.id DESC
");
$psStmt->execute([$partyId]);
$polishSales = $psStmt->fetchAll();

// Fetch Brokerage Earnings if this party is a broker
$brokerageRough = [];
$brokeragePolish = [];
$brokerageRoughSale = [];
$brokeragePolishSale = [];
if (in_array($party['party_type'], ['broker', 'both'])) {
    $brStmt = $pdo->prepare("
        SELECT rp.id, rp.purchase_no, rp.purchase_date, rp.payment_amount, rp.brokerage_percent, rp.brokerage_amount,
               COALESCE(rp.brokerage_payment_status, 'pending') as status, rp.brokerage_chukvya_date, p.party_name
        FROM rough_purchases rp
        JOIN parties p ON (rp.party_id = p.id {$pJoinRp})
        WHERE rp.broker_id = ? {$rpFilter}
        ORDER BY rp.purchase_date DESC
    ");
    $brStmt->execute([$partyId]);
    $brokerageRough = $brStmt->fetchAll();

    $bpStmt = $pdo->prepare("
        SELECT pp.id, pp.purchase_no, pp.purchase_date, pp.net_payment as payment_amount, pp.brokerage_percent, pp.brokerage_amount,
               COALESCE(pp.brokerage_payment_status, 'pending') as status, pp.brokerage_chukvya_date, p.party_name
        FROM polish_purchases pp
        JOIN parties p ON (pp.party_id = p.id {$pJoinPp})
        WHERE pp.broker_id = ? {$ppFilter}
        ORDER BY pp.purchase_date DESC
    ");
    $bpStmt->execute([$partyId]);
    $brokeragePolish = $bpStmt->fetchAll();

    $brsStmt = $pdo->prepare("
        SELECT rs.id, rs.invoice_no as purchase_no, rs.sale_date as purchase_date, rs.payment_amount, rs.brokerage_percent, rs.brokerage_amount,
               COALESCE(rs.brokerage_payment_status, 'pending') as status, rs.brokerage_chukvya_date, p.party_name
        FROM rough_sales rs
        JOIN parties p ON (rs.party_id = p.id {$pJoinRs})
        WHERE rs.broker_id = ? {$rsFilter}
        ORDER BY rs.sale_date DESC
    ");
    $brsStmt->execute([$partyId]);
    $brokerageRoughSale = $brsStmt->fetchAll();

    $bpsStmt = $pdo->prepare("
        SELECT ps.id, ps.invoice_no as purchase_no, ps.sale_date as purchase_date, ps.net_amount as payment_amount, ps.brokerage_percent, ps.brokerage_amount,
               COALESCE(ps.brokerage_payment_status, 'pending') as status, ps.brokerage_chukvya_date, p.party_name
        FROM polish_sales ps
        JOIN parties p ON (ps.party_id = p.id {$pJoinPs})
        WHERE ps.broker_id = ? {$psFilter}
        ORDER BY ps.sale_date DESC
    ");
    $bpsStmt->execute([$partyId]);
    $brokeragePolishSale = $bpsStmt->fetchAll();
}

$bankAccounts = $pdo->query("SELECT id, account_name, current_balance FROM bank_accounts WHERE status = 'active'")->fetchAll();

// Fetch Jangad vouchers
$jgStmt = $pdo->prepare("
    SELECT j.*, 
           (SELECT COUNT(*) FROM jangad_items WHERE jangad_id = j.id) as item_count,
           (SELECT COALESCE(SUM(issued_weight), 0) FROM jangad_items WHERE jangad_id = j.id) as total_issued_weight
    FROM jangad_vouchers j
    WHERE j.party_id = ? {$jgFilter}
    ORDER BY j.issue_date DESC
");
$jgStmt->execute([$partyId]);
$jangads = $jgStmt->fetchAll();

// Fetch Finance Transactions for this party
$finStmt = $pdo->prepare("
    SELECT t.*, b.account_name, b.account_type, u.full_name as user_name
    FROM account_transactions t
    JOIN bank_accounts b ON (t.bank_account_id = b.id {$bJoinFin})
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.party_id = ? {$finFilter}
    ORDER BY t.transaction_date DESC, t.id DESC
");
$finStmt->execute([$partyId]);
$partyTransactions = $finStmt->fetchAll();

// Determine Active Tab from URL parameter
$activeTab = trim($_GET['tab'] ?? '');
if (empty($activeTab)) {
    if (!empty($roughPurchases)) {
        $activeTab = 'rough';
    } elseif (!empty($polishPurchases)) {
        $activeTab = 'polish';
    } elseif (!empty($roughSales) || !empty($polishSales)) {
        $activeTab = 'sales';
    } elseif (!empty($partyTransactions)) {
        $activeTab = 'finance';
    } elseif (!empty($jangads)) {
        $activeTab = 'jangad';
    } elseif (!empty($brokerageRough) || !empty($brokeragePolish)) {
        $activeTab = 'brokerage';
    } else {
        $activeTab = 'rough';
    }
}

// Calculate Summary Totals
$totalRoughBuy = array_sum(array_column($roughPurchases, 'net_payment'));
$totalRoughBuyPaid = array_sum(array_column($roughPurchases, 'paid_amount'));
$totalRoughBuyPending = $totalRoughBuy - $totalRoughBuyPaid;

$totalPolishBuy = array_sum(array_column($polishPurchases, 'net_payment'));
$totalPolishBuyPaid = array_sum(array_column($polishPurchases, 'paid_amount'));
$totalPolishBuyPending = $totalPolishBuy - $totalPolishBuyPaid;

$totalRoughSold = array_sum(array_column($roughSales, 'net_amount'));
$totalPolishSold = array_sum(array_column($polishSales, 'net_amount'));

$totalBrokerageEarned = array_sum(array_column($brokerageRough, 'brokerage_amount'))
    + array_sum(array_column($brokeragePolish, 'brokerage_amount'))
    + array_sum(array_column($brokerageRoughSale, 'brokerage_amount'))
    + array_sum(array_column($brokeragePolishSale, 'brokerage_amount'));

// Format phone for WhatsApp
$cleanPhone = preg_replace('/[^0-9]/', '', (string)$party['phone']);
if (strlen($cleanPhone) === 10) {
    $whatsappNumber = '91' . $cleanPhone;
} else {
    $whatsappNumber = $cleanPhone;
}

$balance = (float)$party['current_balance'];
$partyActions = '
    <a href="' . BASE_URL . '/modules/finance/schedule.php" class="btn btn-outline-success px-3 me-2">
        ' . svg_icon('money-bill-transfer', 'me-1') . 'ચુકવણી શેડ્યૂલ
    </a>
';
if (!empty($whatsappNumber)) {
    $partyActions .= '
        <a href="https://wa.me/' . $whatsappNumber . '?text=' . urlencode('નમસ્તે ' . $party['party_name'] . ', કાશ્યપ જેમ્ઝ તરફથી તમારું ખાતાવહી સ્ટેટમેન્ટ અને બેલેન્સ અપડેટ.') . '" target="_blank" class="btn btn-success px-3 me-2">
            ' . svg_icon('whatsapp', 'me-1') . 'WhatsApp
        </a>
    ';
}
$isBlocked = (($party['status'] ?? 'active') === 'blocked');

if ($isBlocked) {
    $partyActions .= '
        <form method="POST" action="' . BASE_URL . '/modules/parties/toggle_block.php" class="d-inline-block mb-0 me-2">
            ' . csrf_field() . '
            <input type="hidden" name="party_id" value="' . $partyId . '">
            <input type="hidden" name="action" value="unblock">
            <input type="hidden" name="redirect" value="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">
            <button type="submit" class="btn btn-success px-3" onclick="return confirm(\'શું તમે આ પાર્ટીને અન-બ્લોક કરવા માંગો છો?\')">
                ' . svg_icon('check', 'me-1') . 'અન-બ્લોક કરો
            </button>
        </form>
    ';
} else {
    $partyActions .= '
        <button type="button" class="btn btn-outline-danger px-3 me-2" data-bs-toggle="modal" data-bs-target="#viewBlockModal">
            ' . svg_icon('ban', 'me-1') . 'બ્લોક કરો
        </button>
    ';
}

$partyActions .= '
    <a href="' . BASE_URL . '/modules/parties/edit.php?id=' . $partyId . '" class="btn btn-outline-primary px-3 me-2">
        ' . svg_icon('pen-to-square', 'me-1') . 'પાર્ટી એડિટ
    </a>
    <button onclick="exportTableToExcel(\'.tab-pane.active table, table.table-diamond\', \'' . addslashes(e($party['party_name'])) . '_Ledger.csv\')" class="btn btn-outline-success px-3 me-2">
        ' . svg_icon('file-excel', 'me-1') . 'Excel લેજર
    </button>
    <button onclick="window.print()" class="btn btn-outline-dark px-3 me-2">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ
    </button>
    <a href="' . BASE_URL . '/modules/parties/index.php" class="btn btn-outline-secondary px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'પાર્ટી લિસ્ટ
    </a>
';
?>

<div class="d-print-none">
<?= render_page_header(
    $party['party_name'] . ' (' . strtoupper($party['party_type']) . ')' . ($isBlocked ? ' <span class="badge bg-danger text-white fs-6 ms-2">🛑 BLOCKED</span>' : ''),
    'પાર્ટી ખાતાવહી સ્ટેટમેન્ટ, વ્યવહાર ઇતિહાસ અને Live લેણાં/દેવાં બેલેન્સ',
    $isBlocked ? 'ban' : 'address-book',
    $partyActions,
    $isBlocked ? 'danger' : 'primary'
) ?>
</div>

<?php if ($isBlocked): ?>
    <div class="alert alert-danger d-flex align-items-center gap-3 p-3 mb-4 shadow-sm border-danger border-2 rounded-3 d-print-none">
        <div class="fs-1 text-danger"><?= svg_icon('ban', '', 36) ?></div>
        <div class="flex-grow-1">
            <h5 class="alert-heading fw-bold mb-1">🛑 આ એકમ હાલમાં બ્લોક (Blocked) કરેલ છે!</h5>
            <p class="mb-0 small text-danger-emphasis">
                <strong>કારણ:</strong> <?= e($party['block_reason'] ?: 'વહીવટી નિર્ણય / સ્થગિત વ્યવહાર') ?>
                <?php if (!empty($party['blocked_at'])): ?>
                    &bull; <strong>બ્લોક તારીખ:</strong> <?= format_date($party['blocked_at']) ?>
                <?php endif; ?>
                <br>આ પાર્ટી/દલાલના નામ પર કોઈપણ નવી ખરીદી, વેચાણ કે જાંગડ બની શકશે નહીં.
            </p>
        </div>
        <form method="POST" action="<?= BASE_URL ?>/modules/parties/toggle_block.php" class="mb-0">
            <?= csrf_field() ?>
            <input type="hidden" name="party_id" value="<?= $partyId ?>">
            <input type="hidden" name="action" value="unblock">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <button type="submit" class="btn btn-success px-3 fw-bold" onclick="return confirm('શું તમે આ પાર્ટીને અન-બ્લોક કરવા માંગો છો?')">
                <?= svg_icon('check', 'me-1') ?>અન-બ્લોક કરો
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Party Header Card -->
<div class="erp-form-card mb-4">
    <div class="erp-card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-4 border-end-md">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary-subtle text-primary p-3 fs-3 d-flex align-items-center justify-content-center" style="width: 55px; height: 55px;">
                        <?= svg_icon('user') ?>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><?= e($party['party_name']) ?></h5>
                        <p class="text-muted small mb-0"><?= svg_icon('user-tag', 'me-1') ?><?= e($party['contact_person'] ?: 'સંપર્ક વ્યક્તિ નથી') ?></p>
                        <p class="text-muted small mb-0"><?= svg_icon('city', 'me-1') ?><?= e($party['city']) ?></p>
                        <div class="mt-1.5">
                            <?= render_party_role_badges($party['roles'] ?? null) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 border-end-md">
                <div class="small">
                    <div class="mb-1"><strong class="text-muted">ફોન:</strong> <?= e($party['phone'] ?: '-') ?></div>
                    <div class="mb-1"><strong class="text-muted">PAN / GST:</strong> <span class="font-monospace fw-bold"><?= e($party['pan_gst'] ?: '-') ?></span></div>
                    <div><strong class="text-muted">સરનામું:</strong> <?= e($party['address'] ?: '-') ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <div class="small text-muted fw-bold">ખાતાવહી બાકી બેલેન્સ (Outstanding Balance)</div>
                <div class="fs-4 fw-bold font-monospace <?= $balance > 0 ? 'text-danger' : ($balance < 0 ? 'text-success' : 'text-dark') ?>">
                    <?= format_inr(abs($balance)) ?>
                </div>
                <div class="small text-muted">
                    <?php if ($balance > 0): ?>
                        <span class="badge bg-danger-subtle text-danger"><?= svg_icon('arrow-down', 'me-1') ?>આપણે ચૂકવવાના બાકી (Payable)</span>
                    <?php elseif ($balance < 0): ?>
                        <span class="badge bg-success-subtle text-success"><?= svg_icon('arrow-up', 'me-1') ?>પાર્ટી પાસેથી લેવાના (Receivable)</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary">ખાતું સરભર છે (Nil)</span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mt-1">શરૂઆતનું બેલેન્સ: <?= format_inr((float)$party['opening_balance']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Donezo Financial KPI Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-3">
        <?= render_stat_card('કુલ રફ ખરીદી (Rough Buy)', format_inr($totalRoughBuy), svg_icon('gem') . ' બાકી ' . format_inr($totalRoughBuyPending), 'રફ ખરીદી', 'hero', null) ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('પોલિશ ખરીદી (Pol. Buy)', format_inr($totalPolishBuy), svg_icon('award') . ' બાકી ' . format_inr($totalPolishBuyPending), 'પોલિશ વ્યવહાર', 'white', null, 'pill-mint') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('કુલ વેચાણ (Sales)', format_inr($totalRoughSold + $totalPolishSold), svg_icon('handshake') . ' પોલિશ: ' . format_inr($totalPolishSold), 'કુલ વેચાણ ટર્નઓવર', 'white', null, 'pill-mint') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <?= render_stat_card('દલાલી / જાંગડ વાઉચર', format_inr($totalBrokerageEarned), count($jangads) . ' જાંગડ વાઉચર્સ', 'દલાલી & જાંગડ', 'white', null, 'pill-coral', 'receipt') ?>
    </div>
</div>

<!-- Tabs for Statement Details (Modern Segmented Pills) -->
<ul class="nav nav-tabs erp-nav-tabs mb-3 d-print-none" id="partyTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'rough' ? 'active' : '' ?>" id="rough-tab" data-bs-toggle="tab" data-bs-target="#tab-rough" type="button" role="tab">
            <?= svg_icon('gem', 'me-1.5') ?>
            <span>રફ ખરીદી</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'polish' ? 'active' : '' ?>" id="polish-tab" data-bs-toggle="tab" data-bs-target="#tab-polish" type="button" role="tab">
            <?= svg_icon('shapes', 'me-1.5') ?>
            <span>પોલિશ ખરીદી</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'sales' ? 'active' : '' ?>" id="sales-tab" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button" role="tab">
            <?= svg_icon('handshake', 'me-1.5') ?>
            <span>વેચાણ</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'finance' ? 'active' : '' ?>" id="finance-tab" data-bs-toggle="tab" data-bs-target="#tab-finance" type="button" role="tab">
            <?= svg_icon('money-bill-transfer', 'me-1.5') ?>
            <span>નાણાકીય લેવડ-દેવડ</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'jangad' ? 'active' : '' ?>" id="jangad-tab" data-bs-toggle="tab" data-bs-target="#tab-jangad" type="button" role="tab">
            <?= svg_icon('file-invoice', 'me-1.5') ?>
            <span>જાંગડ વાઉચર</span>
        </button>
    </li>
    <?php if (in_array($party['party_type'], ['broker', 'both'])): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'brokerage' ? 'active' : '' ?>" id="brokerage-tab" data-bs-toggle="tab" data-bs-target="#tab-brokerage" type="button" role="tab">
            <?= svg_icon('percent', 'me-1.5') ?>
            <span>દલાલી હિસાબ</span>
        </button>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content">
    <!-- Rough Purchases Tab -->
    <div class="tab-pane fade <?= $activeTab === 'rough' ? 'show active' : '' ?>" id="tab-rough" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('gem', 'text-primary me-2') ?>રફ ખરીદી વ્યવહારો (Rough Purchases)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ખરીદી નં</th>
                                <th>તારીખ</th>
                                <th class="text-end">નંગ (Pcs)</th>
                                <th class="text-end">વજન (Cts)</th>
                                <th class="text-end">ભાવ / કેરેટ</th>
                                <th class="text-end">ચુકવવા પાત્ર</th>
                                <th class="text-end">ચૂકવેલ</th>
                                <th class="text-end">બાકી રકમ</th>
                                <th>મુદ્દત તારીખ</th>
                                <th>સ્થિતિ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roughPurchases)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">આ પાર્ટી પાસેથી રફ ખરીદી થયેલ નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($roughPurchases as $rp): 
                                    $due = (float)$rp['net_payment'] - (float)$rp['paid_amount'];
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>/modules/rough_purchase/index.php" class="fw-bold font-monospace text-primary text-decoration-none">
                                                <?= e($rp['purchase_no']) ?>
                                            </a>
                                        </td>
                                        <td><?= e($rp['purchase_date']) ?></td>
                                        <td class="text-end font-monospace"><?= (int)($rp['pcs'] ?? 1) ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)$rp['weight'], 2) ?></td>
                                        <td class="text-end font-monospace">
                                            <div><?= format_inr((float)$rp['price_per_carat_inr']) ?></div>
                                            <?php if ((float)($rp['dollar_per_carat'] ?? 0) > 0 && (float)($rp['dollar_rate'] ?? 0) > 0): ?>
                                                <small class="text-muted" style="font-size: 0.72rem;">$<?= number_format((float)$rp['dollar_per_carat'], 2) ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border" style="font-size: 0.65rem; padding: 1px 4px;">Stand</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-monospace fw-bold"><?= format_inr((float)$rp['net_payment']) ?></td>
                                        <td class="text-end font-monospace text-success"><?= format_inr((float)$rp['paid_amount']) ?></td>
                                        <td class="text-end font-monospace fw-bold <?= $due > 0 ? 'text-danger' : 'text-muted' ?>"><?= format_inr($due) ?></td>
                                        <td><?= e($rp['due_date']) ?></td>
                                        <td>
                                            <?php if ($rp['party_payment_status'] === 'paid'): ?>
                                                <span class="badge bg-success-subtle text-success">ચુકતે</span>
                                            <?php elseif ($rp['party_payment_status'] === 'partial'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis">અંશત: ચૂકવેલ</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger">બાકી</span>
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
    </div>

    <!-- Polish Purchases Tab -->
    <div class="tab-pane fade <?= $activeTab === 'polish' ? 'show active' : '' ?>" id="tab-polish" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('shapes', 'text-info me-2') ?>પોલિશ ખરીદી વ્યવહારો (Polish Purchases)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ખરીદી નં</th>
                                <th>તારીખ</th>
                                <th>પ્રકાર</th>
                                <th class="text-end">નંગ (Pcs)</th>
                                <th class="text-end">વજન (Cts)</th>
                                <th class="text-end">ભાવ / કેરેટ</th>
                                <th class="text-end">કુલ રકમ</th>
                                <th class="text-end">ચૂકવેલ</th>
                                <th class="text-end">બાકી રકમ</th>
                                <th>મુદ્દત તારીખ</th>
                                <th>સ્થિતિ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($polishPurchases)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">આ પાર્ટી પાસેથી પોલિશ હીરા ખરીદેલ નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($polishPurchases as $pp): 
                                    $due = (float)$pp['net_payment'] - (float)$pp['paid_amount'];
                                ?>
                                    <tr>
                                        <td><strong class="font-monospace text-dark"><?= e($pp['purchase_no']) ?></strong></td>
                                        <td><?= e($pp['purchase_date']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= e(strtoupper($pp['stock_type'])) ?></span></td>
                                        <td class="text-end font-monospace"><?= (int)($pp['pcs'] ?? 1) ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)$pp['weight'], 2) ?></td>
                                        <td class="text-end font-monospace">
                                            <div><?= format_inr((float)$pp['price_per_carat_inr']) ?></div>
                                            <?php if ((float)($pp['dollar_per_carat'] ?? 0) > 0 && (float)($pp['dollar_rate'] ?? 0) > 0): ?>
                                                <small class="text-muted" style="font-size: 0.72rem;">$<?= number_format((float)$pp['dollar_per_carat'], 2) ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border" style="font-size: 0.65rem; padding: 1px 4px;">Stand</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-monospace fw-bold"><?= format_inr((float)$pp['net_payment']) ?></td>
                                        <td class="text-end font-monospace text-success"><?= format_inr((float)$pp['paid_amount']) ?></td>
                                        <td class="text-end font-monospace fw-bold <?= $due > 0 ? 'text-danger' : 'text-muted' ?>"><?= format_inr($due) ?></td>
                                        <td><?= e($pp['due_date']) ?></td>
                                        <td>
                                            <?php if ($pp['payment_status'] === 'paid'): ?>
                                                <span class="badge bg-success-subtle text-success">ચુકતે</span>
                                            <?php elseif ($pp['payment_status'] === 'partial'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis">અંશત: ચૂકવેલ</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger">બાકી</span>
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
    </div>

    <!-- Sales Tab -->
    <div class="tab-pane fade <?= $activeTab === 'sales' ? 'show active' : '' ?>" id="tab-sales" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('handshake', 'text-success me-2') ?>વેચાણ વ્યવહારો (Rough & Polish Sales)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ઇન્વોઇસ નં</th>
                                <th>પ્રકાર</th>
                                <th>તારીખ</th>
                                <th class="text-end">નંગ (Pcs)</th>
                                <th class="text-end">વજન (Cts)</th>
                                <th class="text-end">ભાવ / કેરેટ</th>
                                <th class="text-end">કુલ બિલ</th>
                                <th>મુદ્દત તારીખ</th>
                                <th>ચુકવણી સ્થિતિ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $allSales = array_merge(
                                array_map(fn($r) => array_merge($r, ['_kind' => 'Rough Sale']), $roughSales),
                                array_map(fn($p) => array_merge($p, ['_kind' => 'Polish Sale']), $polishSales)
                            );
                            usort($allSales, fn($a, $b) => strcmp($b['sale_date'], $a['sale_date']));
                            ?>
                            <?php if (empty($allSales)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">આ પાર્ટીને કોઈ વેચાણ કરવામાં આવ્યું નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allSales as $s): ?>
                                    <tr>
                                        <td><strong class="font-monospace text-dark"><?= e($s['invoice_no']) ?></strong></td>
                                        <td>
                                            <span class="badge <?= $s['_kind'] === 'Polish Sale' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' ?>">
                                                <?= $s['_kind'] ?>
                                            </span>
                                        </td>
                                        <td><?= e($s['sale_date']) ?></td>
                                        <td class="text-end font-monospace"><?= (int)($s['pcs'] ?? 1) ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)($s['weight'] ?? 0), 2) ?></td>
                                        <td class="text-end font-monospace"><?= format_inr((float)($s['price_per_carat_inr'] ?? 0)) ?></td>
                                        <td class="text-end font-monospace fw-bold text-success"><?= format_inr((float)$s['net_amount']) ?></td>
                                        <td><?= e($s['due_date']) ?></td>
                                        <td>
                                            <?php if (($s['payment_status'] ?? '') === 'paid'): ?>
                                                <span class="badge bg-success-subtle text-success">જમા થયેલ</span>
                                            <?php elseif (($s['payment_status'] ?? '') === 'partial'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis">અંશત: પ્રાપ્ત</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger">બાકી</span>
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
    </div>

    <!-- Finance & Payments Tab -->
    <div class="tab-pane fade <?= $activeTab === 'finance' ? 'show active' : '' ?>" id="tab-finance" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('money-bill-transfer', 'text-success me-2') ?>નાણાકીય ચુકવણી & જમા વ્યવહારો (Finance Ledger / Cash & Bank)</h6>
                <a href="<?= BASE_URL ?>/modules/finance/transactions.php" class="btn btn-sm btn-outline-primary">
                    <?= svg_icon('plus', 'me-1') ?>નવો વ્યવહાર
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>વાઉચર નં / તારીખ</th>
                                <th>ખાતું (Account)</th>
                                <th>પ્રકાર (Type)</th>
                                <th>કેટેગરી</th>
                                <th class="text-end">રકમ (Amount ₹)</th>
                                <th>પેમેન્ટ મોડ / રેફરન્સ</th>
                                <th>વિગત / નોંધ</th>
                                <th>યુઝર</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($partyTransactions)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">આ પાર્ટી સાથે કોઈ સીધો બેંક કે રોકડ વ્યવહાર હજુ થયો નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($partyTransactions as $tx): ?>
                                    <tr>
                                        <td>
                                            <strong class="font-mono text-dark">#V-<?= str_pad((string)$tx['id'], 5, '0', STR_PAD_LEFT) ?></strong>
                                            <div class="small text-muted"><?= svg_icon('calendar', 'me-1') ?><?= format_date($tx['transaction_date']) ?></div>
                                        </td>
                                        <td>
                                            <strong class="text-dark"><?= e($tx['account_name']) ?></strong>
                                            <span class="badge <?= $tx['account_type'] === 'cash' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' ?> border ms-1">
                                                <?= strtoupper($tx['account_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($tx['type'] === 'credit'): ?>
                                                <span class="badge bg-success text-white"><?= svg_icon('arrow-down-left', 'me-1') ?>આવક (જમા)</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger text-white"><?= svg_icon('arrow-up-right', 'me-1') ?>જાવક (ચૂકવણી)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= e($tx['category'] ?? 'General') ?></span></td>
                                        <td class="text-end font-mono">
                                            <strong class="<?= $tx['type'] === 'credit' ? 'text-success' : 'text-danger' ?> fs-6">
                                                <?= $tx['type'] === 'credit' ? '+' : '-' ?> <?= format_inr($tx['amount']) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <div class="text-uppercase small fw-bold"><?= e($tx['payment_mode']) ?></div>
                                            <small class="text-muted font-mono"><?= e($tx['reference_no'] ?? '-') ?></small>
                                        </td>
                                        <td><small class="text-muted"><?= e($tx['notes'] ?? '-') ?></small></td>
                                        <td><small class="text-muted"><?= e($tx['user_name'] ?? 'Admin') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Jangad Tab -->
    <div class="tab-pane fade <?= $activeTab === 'jangad' ? 'show active' : '' ?>" id="tab-jangad" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('file-invoice', 'text-warning me-2') ?>જાંગડ વાઉચર હિસ્ટ્રી (Jangad Vouchers)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>જાંગડ નં</th>
                                <th>ઇશ્યુ તારીખ</th>
                                <th>કેટેગરી</th>
                                <th class="text-end">આઇટમ્સ</th>
                                <th class="text-end">કુલ કેરેટ</th>
                                <th>પરત તારીખ</th>
                                <th>સ્થિતિ</th>
                                <th class="text-center">એક્શન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jangads)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">આ પાર્ટીને કોઈ જાંગડ આપવામાં આવેલ નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($jangads as $j): ?>
                                    <tr>
                                        <td><strong class="font-monospace text-dark"><?= e($j['jangad_no']) ?></strong></td>
                                        <td><?= e($j['issue_date']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($j['category']) ?></span></td>
                                        <td class="text-end font-monospace"><?= (int)$j['item_count'] ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)($j['total_weight'] ?? $j['total_issued_weight'] ?? 0), 3) ?></td>
                                        <td><?= e($j['expected_return_date']) ?></td>
                                        <td>
                                            <?php
                                            $jStatus = [
                                                'issued' => ['ચાલુ (Issued)', 'bg-warning-subtle text-warning-emphasis'],
                                                'partially_returned' => ['અંશત: જમા', 'bg-info-subtle text-info'],
                                                'completed' => ['પૂર્ણ (Completed)', 'bg-success-subtle text-success'],
                                                'cancelled' => ['રદ (Cancelled)', 'bg-secondary-subtle text-secondary']
                                            ];
                                            $b = $jStatus[$j['status']] ?? [$j['status'], 'bg-light text-dark'];
                                            ?>
                                            <span class="badge <?= $b[1] ?>"><?= $b[0] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>/modules/jangad/view.php?id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary py-0">
                                                જોવો
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
    </div>

    <?php if (in_array($party['party_type'], ['broker', 'both'])): ?>
    <!-- Brokerage Tab -->
    <div class="tab-pane fade <?= $activeTab === 'brokerage' ? 'show active' : '' ?>" id="tab-brokerage" role="tabpanel">
        <div class="card-erp">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><?= svg_icon('percent', 'text-warning me-2') ?>દલાલી કમિશન વિગત (Brokerage Ledger)</h6>
                <a href="<?= BASE_URL ?>/modules/finance/brokerage.php?broker_id=<?= $partyId ?>" class="btn btn-sm btn-outline-primary">
                    <?= svg_icon('wallet', 'me-1') ?>દલાલી રજિસ્ટર
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>રેફરન્સ બિલ નં</th>
                                <th>પ્રકાર</th>
                                <th>તારીખ</th>
                                <th>વેપારી / ગ્રાહક</th>
                                <th class="text-end">સોદા રકમ</th>
                                <th class="text-end">દલાલી %</th>
                                <th class="text-end">દલાલી રકમ (₹)</th>
                                <th>સ્થિતિ</th>
                                <th class="text-end">એક્શન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $bAll = array_merge(
                                array_map(fn($r) => array_merge($r, ['_type' => 'રફ ખરીદી', '_purchase_type' => 'rough']), $brokerageRough),
                                array_map(fn($p) => array_merge($p, ['_type' => 'પોલિશ ખરીદી', '_purchase_type' => 'polish']), $brokeragePolish),
                                array_map(fn($rs) => array_merge($rs, ['_type' => 'રફ વેચાણ', '_purchase_type' => 'rough_sale']), $brokerageRoughSale),
                                array_map(fn($ps) => array_merge($ps, ['_type' => 'પોલિશ વેચાણ', '_purchase_type' => 'polish_sale']), $brokeragePolishSale)
                            );
                            ?>
                            <?php if (empty($bAll)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">આ દલાલ સાથે કોઈ કમિશન સોદો નોંધાયેલ નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bAll as $b): 
                                    $isPaid = ($b['status'] === 'paid');
                                ?>
                                    <tr>
                                        <td><strong class="font-monospace text-dark"><?= e($b['purchase_no']) ?></strong></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($b['_type']) ?></span></td>
                                        <td><?= format_date($b['purchase_date']) ?></td>
                                        <td><?= e($b['party_name']) ?></td>
                                        <td class="text-end font-monospace"><?= format_inr((float)$b['payment_amount']) ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)$b['brokerage_percent'], 2) ?>%</td>
                                        <td class="text-end font-monospace fw-bold text-danger"><?= format_inr((float)$b['brokerage_amount']) ?></td>
                                        <td>
                                            <?php if ($isPaid): ?>
                                                <span class="badge bg-success-subtle text-success border">
                                                    <?= svg_icon('check', 'me-1') ?>ચૂકવાઈ ગઈ
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border">
                                                    <?= svg_icon('clock', 'me-1') ?>બાકી
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$isPaid): ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger px-2.5 py-1" data-bs-toggle="modal" data-bs-target="#partyBrkModal<?= $b['_purchase_type'] . $b['id'] ?>">
                                                    <?= svg_icon('check', 'me-1') ?>દલાલી ચૂકવો
                                                </button>

                                                <!-- Brokerage Settle Modal inside Party View -->
                                                <div class="modal fade" id="partyBrkModal<?= $b['_purchase_type'] . $b['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered text-start">
                                                        <div class="modal-content">
                                                            <form action="<?= BASE_URL ?>/modules/finance/brokerage.php" method="POST">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="settle_brokerage">
                                                                <input type="hidden" name="purchase_type" value="<?= e($b['_purchase_type']) ?>">
                                                                <input type="hidden" name="purchase_id" value="<?= $b['id'] ?>">
                                                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                                                                <div class="modal-header bg-danger text-white">
                                                                    <h6 class="modal-title fw-bold text-white"><?= svg_icon('percent', 'me-1.5') ?>દલાલી ચુકવણી: <?= e($party['party_name']) ?></h6>
                                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="p-2.5 bg-light rounded-3 mb-3 border">
                                                                        <div>સંદર્ભ બિલ: <strong><?= e($b['purchase_no']) ?></strong> (<?= e($b['_type']) ?>)</div>
                                                                        <div>વેપારી/પાર્ટી: <strong><?= e($b['party_name']) ?></strong></div>
                                                                        <div class="mt-1">દલાલી રકમ: <strong class="text-danger fs-5 font-mono"><?= format_inr($b['brokerage_amount']) ?></strong></div>
                                                                    </div>

                                                                    <div class="mb-3">
                                                                        <label class="form-label small fw-bold text-muted">ચૂકવ્યા તારીખ (Brokerage Chukvya Date) *</label>
                                                                        <input type="date" name="brokerage_chukvya_date" class="form-control font-mono" value="<?= date('Y-m-d') ?>" required>
                                                                    </div>

                                                                    <div class="mb-3">
                                                                        <label class="form-label small fw-bold text-dark">
                                                                            <?= svg_icon('building-columns', 'text-primary me-1') ?>Bank / Cash ખાતું પસંદ કરો *
                                                                        </label>
                                                                        <select name="bank_account_id" class="form-select no-search fw-semibold" required>
                                                                            <option value="">-- ખાતું પસંદ કરો --</option>
                                                                            <?php foreach ($bankAccounts as $acc): ?>
                                                                                <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (Bal: <?= format_inr($acc['current_balance']) ?>)</option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <small class="text-muted">જે ખાતામાંથી દલાલીની રકમ આપવાની હોય તે પસંદ કરો.</small>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                                    <button type="submit" class="btn btn-danger btn-sm fw-bold">
                                                                        <?= svg_icon('check', 'me-1') ?>દલાલી ચુકવણી કન્ફર્મ કરો
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">Completed</span>
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
    </div>
    <?php endif; ?>
</div>

<!-- Block Confirmation Modal -->
<div class="modal fade" id="viewBlockModal" tabindex="-1" aria-labelledby="viewBlockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= BASE_URL ?>/modules/parties/toggle_block.php" class="modal-content border-danger shadow-lg">
            <?= csrf_field() ?>
            <input type="hidden" name="party_id" value="<?= $partyId ?>">
            <input type="hidden" name="action" value="block">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="viewBlockModalLabel">
                    <?= svg_icon('ban', 'me-2') ?>પાર્ટી/દલાલ બ્લોક કરો
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-dark fw-semibold mb-2">
                    શું તમે ખરેખર <span class="text-danger fw-bold fs-6">'<?= e($party['party_name']) ?>'</span> ને બ્લોક કરવા માંગો છો?
                </p>
                <div class="alert alert-warning small mb-3">
                    <?= svg_icon('triangle-exclamation', 'me-1') ?>
                    <strong>ચેતવણી:</strong> બ્લોક કર્યા પછી સિસ્ટમમાં આ પાર્ટી/દલાલના નામ પર કોઈપણ નવી ખરીદી, વેચાણ કે જાંગડ બનશે નહીં.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">બ્લોક કરવાનું કારણ (Block Reason)</label>
                    <input type="text" name="reason" class="form-control" placeholder="દા.ત. પેમેન્ટ મોડું / વિવાદ / માલિકી નિર્ણય" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">રદ કરો</button>
                <button type="submit" class="btn btn-danger fw-bold px-4">હા, બ્લોક કરો</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const triggerTabList = document.querySelectorAll('#partyTab button[data-bs-toggle="tab"]');
    triggerTabList.forEach(triggerEl => {
        triggerEl.addEventListener('shown.bs.tab', event => {
            const targetId = event.target.getAttribute('data-bs-target').replace('#tab-', '');
            const url = new URL(window.location.href);
            url.searchParams.set('tab', targetId);
            window.history.replaceState({}, '', url);
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
