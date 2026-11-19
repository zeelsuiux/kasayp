<?php
/**
 * View Rough Purchase Details
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Rough ખરીદી વિગત (Rough Purchase Details)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT rp.*, p.party_name, p.phone as party_phone, p.city as party_city, 
                              b.party_name as broker_name, ba.account_name
                       FROM rough_purchases rp
                       JOIN parties p ON rp.party_id = p.id
                       LEFT JOIN parties b ON rp.broker_id = b.id
                       LEFT JOIN bank_accounts ba ON rp.bank_account_id = ba.id
                       WHERE rp.id = ?");
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    set_flash('error', 'ખરીદી રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/rough_purchase/index.php');
    exit;
}

$assorts = $pdo->prepare("SELECT * FROM assort_tasks WHERE rough_purchase_id = ? ORDER BY id DESC");
$assorts->execute([$id]);
$assortTasks = $assorts->fetchAll();

// Check if this purchase was merged into another, or merged other purchases
$mergedIntoPurchase = null;
if (!empty($purchase['merged_into_id'])) {
    $mStmt = $pdo->prepare("SELECT id, purchase_no, purchase_date, weight FROM rough_purchases WHERE id = ?");
    $mStmt->execute([(int)$purchase['merged_into_id']]);
    $mergedIntoPurchase = $mStmt->fetch();
}

$mergedInwardStmt = $pdo->prepare("SELECT rp.*, p.party_name FROM rough_purchases rp JOIN parties p ON rp.party_id = p.id WHERE rp.merged_into_id = ? ORDER BY rp.id ASC");
$mergedInwardStmt->execute([$id]);
$mergedInwardLots = $mergedInwardStmt->fetchAll();

// Fetch any rough sales made directly from this lot or its assort parts
$rSalesStmt = $pdo->prepare("
    SELECT rs.*, p.party_name, b.party_name as broker_name, ap.part_name
    FROM rough_sales rs
    JOIN parties p ON rs.party_id = p.id
    LEFT JOIN parties b ON rs.broker_id = b.id
    LEFT JOIN assort_parts ap ON rs.assort_part_id = ap.id
    WHERE rs.rough_purchase_id = ?
    ORDER BY rs.sale_date DESC, rs.id DESC
");
$rSalesStmt->execute([$id]);
$roughSalesFromLot = $rSalesStmt->fetchAll();
$actionButtons = '';
$actionButtons .= '<a href="' . BASE_URL . '/modules/rough_purchase/edit.php?id=' . $purchase['id'] . '" class="btn btn-outline-primary px-3 shadow-sm me-2">' . svg_icon('pen-to-square', 'me-1') . 'ખરીદી એડિટ કરો</a>';
if ($purchase['status'] === 'in_stock' && (float)$purchase['available_weight'] > 0) {
    $actionButtons .= '<a href="' . BASE_URL . '/modules/assort/create.php?rough_id=' . $purchase['id'] . '" class="btn btn-primary px-3 shadow-sm me-2">' . svg_icon('layer-group', 'me-1') . 'આ લોટનો Assort Task બનાવો</a>';
}
$actionButtons .= '<a href="' . BASE_URL . '/modules/rough_purchase/index.php" class="btn btn-outline-secondary px-3">' . svg_icon('arrow-left', 'me-1') . 'ખરીદી યાદી પર પાછા</a>';
?>

<?= render_page_header(
    'ખરીદી વિગત: ' . $purchase['purchase_no'],
    'પાર્ટી: ' . $purchase['party_name'] . ' • ખરીદી તારીખ: ' . format_date($purchase['purchase_date']),
    'shapes',
    $actionButtons,
    'primary'
) ?>

<?php if ($mergedIntoPurchase): ?>
    <div class="alert alert-warning py-3 px-4 mb-4 border d-flex align-items-center justify-content-between rounded-3 shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-3">🔀</span>
            <div>
                <strong class="d-block text-dark">આ ખરીદી મર્જ થઈ ગયેલ છે (Merged Purchase)</strong>
                <span class="small text-muted">આ ખરીદીનો સમગ્ર સ્ટોક અને હિસાબ ખરીદી <strong>#<?= e($mergedIntoPurchase['purchase_no']) ?></strong> માં સમાવી લેવામાં આવ્યો છે.</span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $mergedIntoPurchase['id'] ?>" class="btn btn-sm btn-dark fw-bold px-3">
            માસ્ટર ખરીદી #<?= e($mergedIntoPurchase['purchase_no']) ?> જુઓ &rarr;
        </a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <?php if (!empty($mergedInwardLots)): ?>
            <div class="erp-form-card mb-4 border border-primary-subtle shadow-sm">
                <div class="erp-card-header bg-primary-subtle py-2.5 px-3 d-flex align-items-center justify-content-between">
                    <h6 class="header-title mb-0 text-primary fw-bold d-flex align-items-center gap-2">
                        <?= svg_icon('code-merge') ?>આ ખરીદીમાં મર્જ થયેલા લોટ્સ (Merged Inward Lots)
                    </h6>
                    <span class="badge bg-primary text-white font-mono"><?= count($mergedInwardLots) ?> Lots Merged</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-diamond mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">મૂળ ખરીદી નં.</th>
                                <th>પાર્ટી</th>
                                <th>તારીખ</th>
                                <th class="text-end">નંગ</th>
                                <th class="text-end">મૂળ વજન</th>
                                <th class="text-end">ભાવ/Ct</th>
                                <th class="text-end pe-3">નેટ રકમ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mergedInwardLots as $mLot): ?>
                                <tr>
                                    <td class="ps-3 font-mono fw-bold">
                                        <a href="<?= BASE_URL ?>/modules/rough_purchase/view.php?id=<?= $mLot['id'] ?>" class="text-primary text-decoration-none">#<?= e($mLot['purchase_no']) ?></a>
                                    </td>
                                    <td><?= e($mLot['party_name']) ?></td>
                                    <td><?= format_date($mLot['purchase_date']) ?></td>
                                    <td class="text-end font-mono"><?= (int)$mLot['pcs'] ?></td>
                                    <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$mLot['weight']) ?></td>
                                    <td class="text-end font-mono">₹<?= number_format((float)$mLot['price_per_carat_inr'], 2) ?></td>
                                    <td class="text-end font-mono fw-bold pe-3">₹<?= number_format((float)$mLot['net_payment'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon"><?= svg_icon('circle-info') ?></span>
                    ખરીદી અને હિસાબ માહિતી (Section 6.1 Formula)
                </h6>
                <span class="badge bg-light text-dark border"><?= e(strtoupper($purchase['status'])) ?></span>
            </div>
            <div class="erp-card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Pcs (નંગ)</small>
                        <strong class="fs-6"><?= (int)$purchase['pcs'] ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">વજન (Carat)</small>
                        <strong class="fs-6 text-primary"><?= format_carat($purchase['weight']) ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">AVG SIZE (સરેરાશ)</small>
                        <strong class="fs-6 text-dark font-mono"><?= (int)$purchase['pcs'] > 0 ? format_carat((float)$purchase['weight'] / (int)$purchase['pcs']) . ' ct/pc' : '—' ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">હાજર વજન (Available)</small>
                        <strong class="fs-6 text-success"><?= format_carat($purchase['available_weight']) ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">DESTINATION (ડેસ્ટિનેશન)</small>
                        <span class="badge bg-white text-dark border fs-6"><?= e($purchase['destination'] ?? 'Surat') ?></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">SAFE (સેફ / લોકેશન)</small>
                        <span class="badge bg-light text-dark border font-mono fs-6"><?= e($purchase['safe'] ?? 'OFC') ?></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">DABBA (ડબ્બો / બોક્સ)</small>
                        <span class="badge bg-primary-subtle text-primary border font-mono fs-6"><?= e($purchase['dabba'] ?: '—') ?></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">TAG (ટેગ)</small>
                        <div class="mt-1"><?= render_tag_badge($purchase['tag'] ?? 'Rough') ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">PROCESS TAG (પ્રોસેસ ટેગ)</small>
                        <div class="mt-1"><?= render_process_tag_badge($purchase['process_tag'] ?? 'Assort') ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">સ્થિતિ (Status)</small>
                        <span class="badge bg-primary-subtle text-primary border"><?= e($purchase['status']) ?></span>
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">ખરીદી દર મોડ (Payment Currency)</small>
                        <?php if (is_stand_dollar($purchase)): ?>
                            <span class="badge bg-danger-subtle text-danger border"><span class="stand-dollar-dot me-1"></span>Stand Dollar (દર બાકી)</span>
                        <?php elseif (($purchase['payment_currency'] ?? '') === 'USD' || (float)$purchase['dollar_rate'] > 0): ?>
                            <span class="badge bg-success-subtle text-success border"><?= svg_icon('dollar-sign', 'me-1') ?>Dollar Deal ($)</span>
                        <?php else: ?>
                            <span class="badge bg-primary-subtle text-primary border">Indian Currency (₹)</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Dollar / Carat</small>
                        <strong><?= (float)$purchase['dollar_per_carat'] > 0 ? '$' . number_format((float)$purchase['dollar_per_carat'], 2) : '<span class="text-muted">- (Stand)</span>' ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Dollar Rate</small>
                        <strong><?= (float)$purchase['dollar_rate'] > 0 ? '₹ ' . number_format((float)$purchase['dollar_rate'], 2) : '<span class="text-muted">- (Stand)</span>' ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Price / Carat (INR)</small>
                        <strong class="text-dark"><?= format_inr($purchase['price_per_carat_inr']) ?> <?= (float)$purchase['dollar_rate'] <= 0 ? '<small class="text-muted">(Stand Rate)</small>' : '' ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">કાચી રકમ (Raw Value)</small>
                        <strong class="text-dark"><?= format_inr($purchase['payment_amount']) ?></strong>
                    </div>

                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">- AC % (બાદ)</small>
                        <strong class="text-danger">- <?= format_inr($purchase['ac_amount'] ?? 0) ?> <span class="badge bg-danger-subtle text-danger font-mono ms-1"><?= number_format((float)($purchase['ac_percent'] ?? 0), 2) ?>%</span></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Sub Total (Raw - AC)</small>
                        <strong class="text-primary font-mono"><?= format_inr((float)$purchase['payment_amount'] - (float)($purchase['ac_amount'] ?? 0)) ?></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">+ દલાલી રકમ (Sub Total પર)</small>
                        <strong class="text-warning-emphasis">+ <?= format_inr($purchase['brokerage_amount']) ?> <span class="badge bg-warning-subtle text-dark font-mono ms-1"><?= number_format((float)$purchase['brokerage_percent'], 2) ?>%</span></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">+ GST ખર્ચ (Sub Total પર)</small>
                        <strong class="text-primary">+ <?= format_inr($purchase['gst_amount'] ?? $purchase['ac_gst_amount']) ?> <span class="badge bg-primary-subtle text-primary font-mono ms-1"><?= number_format((float)($purchase['gst_percent'] ?? 0), 2) ?>%</span></strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted d-block">Net Payment (કુલ રકમ)</small>
                        <strong class="text-success fs-6"><?= format_inr($purchase['net_payment']) ?></strong>
                    </div>
                </div>

                <?php if (!empty($purchase['product_description'])): ?>
                    <div class="mt-3 p-2 bg-light rounded small">
                        <strong>વર્ણન:</strong> <?= e($purchase['product_description']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon icon-gold"><?= svg_icon('layer-group') ?></span>
                    આ ખરીદીના Assort Tasks (ભાગ પાડ્યાનો ઇતિહાસ)
                </h6>
                
            </div>
            <div class="erp-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ટાસ્ક નં</th>
                                <th>તારીખ</th>
                                <th>ઇનપુટ વજન</th>
                                <th>આઉટપુટ વજન</th>
                                <th>સ્થિતિ</th>
                                <th class="text-end">વિગત</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($assortTasks)): ?>
                                <tr><td colspan="6" class="text-center py-3 text-muted">હજુ સુધી આ ખરીદી પર Assort ટાસ્ક થયો નથી.</td></tr>
                            <?php else: ?>
                                <?php foreach ($assortTasks as $at): ?>
                                    <tr>
                                        <td><strong><?= e($at['task_no']) ?></strong></td>
                                        <td><?= format_date($at['assort_date']) ?></td>
                                        <td>
                                            <?= format_carat($at['total_input_weight']) ?>
                                            <div class="small text-muted font-mono"><?= (int)$at['total_input_pcs'] ?> Pcs</div>
                                        </td>
                                        <td>
                                            <?= format_carat($at['total_output_weight']) ?>
                                            <div class="small text-muted font-mono"><?= (int)$at['total_output_pcs'] ?> Pcs</div>
                                        </td>
                                        <td><span class="badge bg-success-subtle text-success"><?= e($at['status']) ?></span></td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/modules/assort/view.php?id=<?= $at['id'] ?>" class="btn btn-sm btn-outline-primary">જુઓ &rarr;</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Rough Sales from this Lot Card -->
        <div class="erp-form-card mb-4 border border-warning-subtle">
            <div class="erp-card-header bg-warning-subtle d-flex align-items-center justify-content-between py-2.5 px-3">
                <h6 class="header-title mb-0 text-dark fw-bold d-flex align-items-center gap-2">
                    <?= svg_icon('hand-holding-dollar', 'text-warning-emphasis') ?>
                    <span>આ ખરીદીમાંથી થયેલ રફ વેચાણ (Rough Sales from this Lot)</span>
                </h6>
                <span class="badge bg-warning text-dark font-mono"><?= count($roughSalesFromLot) ?> વેચાણ સોદા</span>
            </div>
            <div class="erp-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3">ઇન્વોઇસ નં.</th>
                                <th>વેચાણ તારીખ</th>
                                <th>ગ્રાહક પાર્ટી</th>
                                <th>સોર્સ / પાર્ટ</th>
                                <th class="text-end">નંગ</th>
                                <th class="text-end">વજન (Ct)</th>
                                <th class="text-end">ભાવ / Ct</th>
                                <th class="text-end">નેટ રકમ</th>
                                <th class="text-end pe-3">વિગત</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($roughSalesFromLot)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-3 text-muted">આ ખરીદી લોટમાંથી હજુ સુધી કોઈ રફ વેચાણ થયું નથી.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($roughSalesFromLot as $rs): ?>
                                    <tr>
                                        <td class="ps-3 font-mono fw-bold">
                                            <a href="<?= BASE_URL ?>/modules/rough_sales/view.php?id=<?= $rs['id'] ?>" class="text-primary text-decoration-none">
                                                #<?= e($rs['invoice_no']) ?>
                                            </a>
                                        </td>
                                        <td class="font-mono text-nowrap"><?= format_date($rs['sale_date']) ?></td>
                                        <td class="fw-semibold text-dark"><?= e($rs['party_name']) ?></td>
                                        <td>
                                            <?php if ($rs['source_type'] === 'assort_part' && !empty($rs['part_name'])): ?>
                                                <span class="badge bg-warning-subtle text-dark border font-mono" style="font-size: 0.72rem;">Assort: <?= e($rs['part_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">ડાયરેક્ટ રફ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-mono"><?= (int)$rs['pcs'] ?></td>
                                        <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$rs['weight']) ?></td>
                                        <td class="text-end font-mono">
                                            <?php if (is_stand_dollar($rs)): ?>
                                                <span class="text-danger fw-bold">$<?= number_format((float)$rs['dollar_per_carat'], 2) ?></span>
                                            <?php else: ?>
                                                <?= format_inr((float)$rs['price_per_carat_inr']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-mono fw-bold text-success"><?= format_inr((float)$rs['net_amount']) ?></td>
                                        <td class="text-end pe-3">
                                            <a href="<?= BASE_URL ?>/modules/rough_sales/view.php?id=<?= $rs['id'] ?>" class="btn btn-xs btn-outline-primary py-1 px-2">
                                                <?= svg_icon('eye', 'me-1', 11) ?>હિસ્ટ્રી જુઓ &rarr;
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

    <div class="col-12 col-lg-4">
        <!-- Party Info & Quick Ledger Card -->
        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon"><?= svg_icon('user') ?></span>
                    પાર્ટી & ખાતાવહી (Party Ledger)
                </h6>
            </div>
            <div class="erp-card-body">
                <div class="fw-bold fs-6 text-dark"><?= e($purchase['party_name']) ?></div>
                <div class="small text-muted mb-3"><?= svg_icon('location-dot', 'me-1 text-danger') ?><?= e($purchase['city'] ?? 'Surat') ?></div>
                <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $purchase['party_id'] ?>&tab=rough" class="btn btn-outline-primary btn-sm w-100 py-1.5 fw-semibold shadow-sm">
                    <?= svg_icon('receipt', 'me-1') ?>પાર્ટીનું Rough Ledger જુઓ &rarr;
                </a>
            </div>
        </div>

        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon icon-rose"><?= svg_icon('calendar-check') ?></span>
                    ચુકવણી શેડ્યુલ (Finance)
                </h6>
            </div>
            <div class="erp-card-body">
                <div class="mb-3">
                    <small class="text-muted d-block">Due Days & Date</small>
                    <div class="fs-6 fw-bold"><?= format_date($purchase['due_date']) ?> <span class="badge bg-light text-dark border"><?= (int)$purchase['due_days'] ?> Days</span></div>
                    <div class="mt-1"><?= get_due_badge($purchase['due_date'], $purchase['party_payment_status']) ?></div>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block">પાર્ટી પેમેન્ટ સ્થિતિ</small>
                    <strong class="text-capitalize"><?= e($purchase['party_payment_status']) ?></strong>
                    <?php if ($purchase['chukvya_date']): ?>
                        <div class="small text-muted">ચૂકવ્યા તારીખ: <?= format_date($purchase['chukvya_date']) ?></div>
                    <?php endif; ?>
                </div>

                <hr>

                <div class="mb-3">
                    <small class="text-muted d-block">દલાલી ચુકવણી (Brokerage)</small>
                    <strong><?= format_inr($purchase['brokerage_amount']) ?></strong> 
                    (<?php if (!empty($purchase['broker_id'])): ?>
                        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $purchase['broker_id'] ?>&tab=brokerage" class="text-primary text-decoration-none fw-semibold" title="દલાલ લેજર જુઓ"><?= e($purchase['broker_name']) ?></a>
                    <?php else: ?>
                        <?= e($purchase['broker_name'] ?? 'No Broker') ?>
                    <?php endif; ?>)
                    <div class="mt-1">
                        <span class="badge <?= $purchase['brokerage_payment_status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= $purchase['brokerage_payment_status'] === 'paid' ? 'દલાલી ચૂકવાઈ (Paid)' : 'દલાલી બાકી (Pending)' ?>
                        </span>
                    </div>
                    <?php if ($purchase['brokerage_chukvya_date']): ?>
                        <div class="small text-muted mt-1">Brokerage ચૂકવ્યા તારીખ: <?= format_date($purchase['brokerage_chukvya_date']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($purchase['account_name'])): ?>
                    <div class="p-2 bg-light rounded small">
                        <?= svg_icon('building-columns', 'text-primary me-1') ?>
                        ખાતું: <strong><?= e($purchase['account_name']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
