<?php
/**
 * View & Print Jangad Voucher (અસલ જાંગડ પહોંચ - Kapan-Wise Diamond Assignment & Return)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$pdo = getDbConnection();
ensure_jangad_sales_columns();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT jv.*, p.party_name, p.phone as party_phone, p.address as party_address, p.city as party_city, p.pan_gst,
                              b.party_name as broker_name, u.full_name as issuer_name
                       FROM jangad_vouchers jv
                       JOIN parties p ON jv.party_id = p.id
                       LEFT JOIN parties b ON jv.broker_id = b.id
                       LEFT JOIN users u ON jv.created_by = u.id
                       WHERE jv.id = ?");
$stmt->execute([$id]);
$jangad = $stmt->fetch();

if (!$jangad) {
    set_flash('error', 'Jangad વાઉચર મળ્યું નથી.');
    header('Location: ' . BASE_URL . '/modules/jangad/index.php');
    exit;
}

// Fetch Kapan-wise diamond assignments & returns
$kapanAssignments = get_jangad_kapan_assignments($id);

// Fetch Polish Stock / Sales Memo Items with resolved Kapan & Sale Invoice
$legacyItems = [];
if (empty($kapanAssignments)) {
    $itemStmt = $pdo->prepare("
        SELECT ji.*, ps.packet_no, ps.shape, ps.color, ps.clarity, ps.cut, ps.gia_number,
               COALESCE(ji.kapan_id, ps.kapan_id) as resolved_kapan_id,
               k.kapan_code, k.kapan_name,
               psale.invoice_no as linked_invoice_no
        FROM jangad_items ji
        LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
        LEFT JOIN kapans k ON COALESCE(ji.kapan_id, ps.kapan_id) = k.id
        LEFT JOIN polish_sales psale ON ji.polish_sale_id = psale.id
        WHERE ji.jangad_id = ?
        ORDER BY ji.id ASC
    ");
    $itemStmt->execute([$id]);
    $legacyItems = $itemStmt->fetchAll();
}

$soldPcsTot = (int)($jangad['sold_pcs'] ?? 0);
$soldWtTot  = (float)($jangad['sold_weight'] ?? 0);
$retPcsTot  = (int)($jangad['returned_pcs'] ?? 0);
$retWtTot   = (float)($jangad['returned_weight'] ?? 0);
$totalTops  = (float)($jangad['total_tops_weight'] ?? 0.000);
$pendPcs    = max(0, (int)$jangad['total_pcs'] - $soldPcsTot - $retPcsTot);
$pendWt     = max(0.0, round((float)$jangad['total_weight'] - $soldWtTot - $retWtTot - $totalTops, 3));
$isSalesJangad = in_array($jangad['category'], ['memo_party', 'memo_broker', 'lab_gia'], true);

$catTitles = [
    'outsource_galaxy'  => 'Galaxy Scanning (ગેલેક્સી સ્કેનિંગ)',
    'outsource_ls'      => 'Laser Sawing - LS (લેસર સોઇંગ)',
    'outsource_factory' => 'Polish Factory (કારખાનું પોલિશિંગ)',
    'memo_broker'       => 'Broker Memo (બ્રોકર મેમો)',
    'memo_party'        => 'Party Memo (પાર્ટી સેલિંગ મેમો)',
    'lab_gia'           => 'GIA / IGI Lab (લેબ સર્ટિફિકેશન)'
];
$catName = $catTitles[$jangad['category']] ?? strtoupper(str_replace('_', ' ', $jangad['category']));
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jangad Voucher - <?= e($jangad['jangad_no']) ?> | Kasyap Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/diamond.css">
    <style>
        body { 
            background: #F4F7FB; 
            padding: 24px 0; 
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .voucher-box {
            max-width: 960px;
            margin: auto;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .kapan-print-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 20px;
            background: #ffffff;
            overflow: hidden;
        }
        .kapan-print-header {
            background: #f1f5f9;
            padding: 10px 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .table-voucher {
            font-size: 0.88rem;
            margin-bottom: 0;
        }
        .table-voucher th {
            background-color: #f8fafc;
            color: #334155;
            font-weight: 600;
            padding: 8px 12px;
        }
        .table-voucher td {
            padding: 8px 12px;
        }
        .badge-sub {
            display: inline-block;
            font-size: 0.75rem;
            padding: 3px 7px;
            border-radius: 4px;
            margin: 2px 3px 2px 0;
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
            font-family: monospace;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .no-print { display: none !important; }
            .voucher-box { 
                border: 1.5px solid #000000; 
                box-shadow: none; 
                max-width: 100%; 
                width: 100%; 
                border-radius: 0;
                padding: 16px; 
            }
            .kapan-print-card { border: 1px solid #64748b; margin-bottom: 14px; page-break-inside: avoid; }
            .kapan-print-header { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; }
            .table-voucher th { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; }
            .badge-sub { border: 1px solid #94a3b8; background: #f8fafc !important; color: #000 !important; }
        }
    </style>
</head>
<body>

<div class="container-fluid px-3">
    <!-- Action Bar (Hidden on Print) -->
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4" style="max-width: 960px; margin: auto;">
        <a href="<?= BASE_URL ?>/modules/jangad/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <?= svg_icon('arrow-left', 'me-1', 13) ?>યાદી પર પાછા
        </a>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $jangad['party_id'] ?>&tab=jangad" class="btn btn-outline-secondary btn-sm rounded-pill px-3" title="પાર્ટી ખાતાવહી">
                <?= svg_icon('receipt', 'me-1', 13) ?>પાર્ટી લેજર
            </a>
            <a href="<?= BASE_URL ?>/modules/jangad/edit.php?id=<?= $jangad['id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                <?= svg_icon('pen-to-square', 'me-1', 13) ?>એડિટ
            </a>
            <?php if ($jangad['status'] !== 'completed'): ?>
                <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $jangad['id'] ?>" class="btn btn-success btn-sm rounded-pill px-3 fw-bold">
                    <?= svg_icon('rotate-left', 'me-1', 13) ?><?= empty($kapanAssignments) ? 'જમા / વેચાણ કન્વર્ટ' : 'માલ જમા (Return)' ?>
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold">
                <?= svg_icon('print', 'me-1', 13) ?>પ્રિન્ટ વાઉચર
            </button>
        </div>
    </div>

    <!-- Voucher Main Box -->
    <div class="voucher-box">
        <!-- Top Letterhead -->
        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-primary" style="letter-spacing: 0.5px;">KASYAP GEMS</h3>
                <div class="small fw-semibold text-dark">ડાયમંડ મેન્યુફેક્ચરિંગ, ઇમ્પોર્ટ & એક્સપોર્ટ</div>
                <div class="small text-muted">સુરત ડાયમંડ બુર્સ (SDB) / મુંબઈ ભારત ડાયમંડ બુર્સ (BDB)</div>
                <div class="small text-muted font-mono">GST: 24AAECK9876Q1Z5 &bull; ફોન: +91 98250 12345</div>
            </div>
            <div class="text-end">
                <span class="finora-pill finora-pill-blue text-uppercase mb-2 px-3 py-1">જાંગડ પહોંચ (JANGAD MEMO)</span>
                <div class="fs-5 fw-bold text-dark font-mono"><?= e($jangad['jangad_no']) ?></div>
                <div class="small text-muted">ઇસ્યુ તારીખ: <strong class="text-dark font-mono"><?= format_date($jangad['issue_date']) ?></strong></div>
                <?php if ($jangad['expected_return_date']): ?>
                    <div class="small text-danger">પરત મુદત: <strong class="font-mono"><?= format_date($jangad['expected_return_date']) ?></strong></div>
                <?php endif; ?>
                <div class="mt-1">
                    <?php if ($jangad['status'] === 'completed'): ?>
                        <span class="finora-pill finora-pill-green">સંપૂર્ણ જમા</span>
                    <?php elseif ($jangad['status'] === 'partially_returned'): ?>
                        <span class="finora-pill finora-pill-amber">અંશતઃ પરત</span>
                    <?php else: ?>
                        <span class="finora-pill finora-pill-rose">બાકી માલ</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Party & Category Details -->
        <div class="row g-3 mb-4">
            <div class="col-7">
                <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">માલ લેનાર પાર્ટી / કારીગર (Issued To):</div>
                <h5 class="fw-bold text-dark mb-1">
                    <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $jangad['party_id'] ?>&tab=jangad" class="text-decoration-none text-dark">
                        <?= e($jangad['party_name']) ?>
                    </a>
                </h5>
                <div class="small text-muted"><?= e($jangad['party_address'] ?? 'Surat / Mumbai') ?></div>
                <div class="small text-muted font-mono">સંપર્ક: <?= e($jangad['party_phone'] ?? '-') ?> <?= !empty($jangad['pan_gst']) ? ' | GST/PAN: ' . e($jangad['pan_gst']) : '' ?></div>
            </div>
            <div class="col-5 text-end">
                <div class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">જાંગડ હેતુ / કેટેગરી (Category):</div>
                <div class="fw-bold text-dark"><?= e($catName) ?></div>
                <?php if (!empty($jangad['broker_name'])): ?>
                    <div class="small text-muted mt-1">દલાલ મારફત: <strong class="text-dark"><?= e($jangad['broker_name']) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($jangad['remarks'])): ?>
                    <div class="small text-muted mt-1 fst-italic">નોંધ: <?= e($jangad['remarks']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary Strip (Issued, Sold, Returned, Pending / Tops) -->
        <div class="row g-2 p-2.5 rounded-3 bg-light border mb-4 text-center font-mono" style="font-size: 0.85rem;">
            <div class="col-3 border-end">
                <span class="text-muted d-block small">કુલ ઇસ્યુ</span>
                <strong class="text-dark fs-6"><?= (int)$jangad['total_pcs'] ?> Pcs</strong>
                <span class="text-primary d-block small fw-bold"><?= format_carat($jangad['total_weight']) ?></span>
            </div>
            <?php if (empty($kapanAssignments)): ?>
                <div class="col-3 border-end">
                    <span class="text-muted d-block small">વેચાણ થયેલ (Sold)</span>
                    <strong class="text-dark fs-6"><?= $soldPcsTot ?> Pcs</strong>
                    <span class="text-primary d-block small fw-bold"><?= format_carat($soldWtTot) ?></span>
                </div>
                <div class="col-3 border-end">
                    <span class="text-muted d-block small">કાપનમાં પરત</span>
                    <strong class="text-dark fs-6"><?= $retPcsTot ?> Pcs</strong>
                    <span class="text-success d-block small fw-bold"><?= format_carat($retWtTot) ?></span>
                </div>
                <div class="col-3">
                    <span class="text-muted d-block small">પેન્ડિંગ (બાકી)</span>
                    <strong class="text-dark fs-6"><?= $pendPcs ?> Pcs</strong>
                    <span class="<?= $pendWt > 0 ? 'text-danger' : 'text-success' ?> d-block small fw-bold"><?= format_carat($pendWt) ?></span>
                </div>
            <?php else: ?>
                <div class="col-3 border-end">
                    <span class="text-muted d-block small">પરત આવેલ</span>
                    <strong class="text-dark fs-6"><?= (int)$jangad['returned_pcs'] ?> Sub Pcs</strong>
                    <span class="text-success d-block small fw-bold"><?= format_carat($jangad['returned_weight']) ?></span>
                </div>
                <div class="col-3 border-end">
                    <span class="text-muted d-block small">પેન્ડિંગ વજન</span>
                    <strong class="<?= $pendWt > 0 ? 'text-danger' : 'text-success' ?> fs-6"><?= format_carat($pendWt) ?></strong>
                    <span class="text-muted d-block small"><?= $pendWt > 0 ? 'જમા બાકી' : 'પૂર્ણ' ?></span>
                </div>
                <div class="col-3">
                    <span class="text-muted d-block small">ફાઇનલ Tops રદિયો</span>
                    <strong class="text-dark fs-6"><?= format_carat($totalTops) ?></strong>
                    <span class="text-muted d-block small">રદિયો વજન</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: POLISH SALES MEMO ITEMS OR LEGACY KAPAN ASSIGNMENTS -->
        <div class="mb-4">
            <?php if (empty($kapanAssignments) && !empty($legacyItems)): ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <?= svg_icon('gem', 'text-primary', 16) ?>
                        <span>પોલિશ હીરા વિગત (<?= count($legacyItems) ?>)</span>
                    </h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-voucher align-middle">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>પોલિશ પેકેટ / મૂળ કાપન</th>
                                <th class="text-center" style="width: 80px;">ઇસ્યુ નંગ</th>
                                <th class="text-end" style="width: 95px;">ઇસ્યુ વજન</th>
                                <th class="text-end" style="width: 95px;">ભાવ / Ct</th>
                                <th class="text-end" style="width: 115px;">વેચાણ (Sold)</th>
                                <th class="text-end" style="width: 115px;">કાપન પરત (Ret)</th>
                                <th class="text-end" style="width: 95px;">બાકી વજન</th>
                                <th class="text-center" style="width: 85px;">સ્થિતિ</th>
                                <th class="no-print text-end" style="width: 145px;">એક્શન</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $idx = 1; $totMemoValue = 0.0; foreach ($legacyItems as $it):
                                $issWt     = (float)$it['issued_weight'];
                                $itSoldPcs = (int)($it['sold_pcs'] ?? 0);
                                $itSoldWt  = (float)($it['sold_weight'] ?? 0);
                                $itRetPcs  = (int)($it['returned_pcs'] ?? 0);
                                $itRetWt   = (float)($it['returned_weight'] ?? 0);
                                $itPendWt  = max(0.0, round($issWt - $itSoldWt - $itRetWt, 3));
                                $itPendPcs = max(0, (int)$it['issued_pcs'] - $itSoldPcs - $itRetPcs);
                                $askRate   = (float)($it['charges'] ?? 0);
                                $totMemoValue += ($issWt * $askRate);
                                $kapanCode = trim((string)($it['kapan_code'] ?? ''));
                                $kapanId   = (int)($it['resolved_kapan_id'] ?? 0);
                            ?>
                                <tr>
                                    <td><?= $idx++ ?></td>
                                    <td>
                                        <strong class="text-dark"><?= e($it['description']) ?></strong>
                                        <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                            <?php if ($kapanId > 0 && $kapanCode !== ''): ?>
                                                <a href="<?= BASE_URL ?>/modules/kapan/view.php?id=<?= $kapanId ?>" class="badge bg-secondary-subtle text-dark border text-decoration-none font-mono small no-print-link">
                                                    કાપન: <?= e($kapanCode) ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($it['gia_number']) && strpos((string)$it['description'], (string)$it['gia_number']) === false): ?>
                                                <span class="badge bg-info-subtle text-info border font-mono small">GIA: <?= e($it['gia_number']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center font-mono"><?= (int)$it['issued_pcs'] ?> Pcs</td>
                                    <td class="text-end font-mono text-dark fw-bold"><?= format_carat($issWt) ?></td>
                                    <td class="text-end font-mono"><?= $askRate > 0 ? format_inr($askRate) : '—' ?></td>
                                    <td class="text-end font-mono text-primary">
                                        <?php if ($itSoldPcs > 0 || $itSoldWt > 0): ?>
                                            <strong><?= format_carat($itSoldWt) ?></strong>
                                            <small class="d-block text-muted"><?= $itSoldPcs ?> Pcs<?= !empty($it['linked_invoice_no']) ? ' • ' . e($it['linked_invoice_no']) : '' ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono text-success">
                                        <?php if ($itRetPcs > 0 || $itRetWt > 0): ?>
                                            <strong><?= format_carat($itRetWt) ?></strong>
                                            <small class="d-block text-muted"><?= $itRetPcs ?> Pcs<?= $kapanCode !== '' ? ' → ' . e($kapanCode) : '' ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono fw-bold <?= $itPendWt > 0 ? 'text-danger' : 'text-muted' ?>"><?= format_carat($itPendWt) ?></td>
                                    <td class="text-center">
                                        <?php if ($it['status'] === 'returned' || $itPendWt <= 0.0005 || $itPendPcs <= 0): ?>
                                            <span class="finora-pill finora-pill-green">પૂર્ણ</span>
                                        <?php elseif ($it['status'] === 'partially_returned'): ?>
                                            <span class="finora-pill finora-pill-amber">અંશતઃ</span>
                                        <?php else: ?>
                                            <span class="finora-pill finora-pill-rose">બાકી</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print text-end text-nowrap">
                                        <?php if ($itPendWt > 0.0005 && $itPendPcs > 0 && !empty($it['polish_stock_id'])): ?>
                                            <a href="<?= BASE_URL ?>/modules/polish_sales/create.php?jangad_id=<?= $id ?>&jangad_item_id=<?= (int)$it['id'] ?>" class="btn btn-sm btn-primary rounded-pill py-1 px-2.5" style="font-size: 0.74rem;" title="વેચાણ બિલ બનાવો">
                                                <?= svg_icon('cart-shopping', 'me-1', 12) ?>વેચાણ બિલ
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/jangad/return.php?id=<?= $id ?>" class="btn btn-sm btn-outline-success rounded-pill py-1 px-2" title="પરત જમા">
                                                <?= svg_icon('rotate-left', '', 12) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($totMemoValue > 0): ?>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="4" class="text-end">કુલ અંદાજિત મેમો મૂલ્ય (Estimated Memo Value):</td>
                                    <td colspan="6" class="font-mono text-success"><?= format_inr($totMemoValue) ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php elseif (empty($kapanAssignments)): ?>
                <div class="alert alert-light border text-center py-3 text-muted small">
                    કોઈ ડાયમંડ ફાળવણી વિગત ઉપલબ્ધ નથી.
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <?= svg_icon('boxes-stacked', 'text-primary', 16) ?>
                        <span>કાપન વાઇઝ ફાળવણી અને રિટર્ન (<?= count($kapanAssignments) ?>)</span>
                    </h6>
                </div>
                <?php foreach ($kapanAssignments as $kIndex => $kapan): ?>
                    <div class="kapan-print-card">
                        <div class="kapan-print-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="finora-pill finora-pill-blue font-mono">#<?= $kIndex + 1 ?></span>
                                <strong class="text-dark"><?= e($kapan['kapan_name']) ?></strong>
                                <span class="text-muted font-mono small">(<?= e($kapan['kapan_code']) ?>)</span>
                            </div>
                            <div class="font-mono small d-flex gap-3">
                                <span>ફાળવેલ: <strong class="text-primary"><?= (int)$kapan['assigned_pcs'] ?> Pcs / <?= format_carat($kapan['assigned_weight']) ?></strong></span>
                                <span>પરત: <strong class="text-success"><?= format_carat($kapan['returned_weight']) ?></strong></span>
                                <span>બાકી: <strong class="<?= (float)$kapan['pending_weight'] > 0 ? 'text-danger' : 'text-success' ?>"><?= format_carat($kapan['pending_weight']) ?></strong></span>
                                <?php if ((float)$kapan['tops_weight'] > 0): ?>
                                    <span>Tops: <strong class="text-dark"><?= format_carat($kapan['tops_weight']) ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-voucher align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">Assign No</th>
                                        <th style="width: 20%;">Diamond / Barcode</th>
                                        <th class="text-end" style="width: 15%;">Assigned Wt</th>
                                        <th style="width: 25%;">Returned Sub-Diamonds (Splits)</th>
                                        <th class="text-end" style="width: 12%;">Received Wt</th>
                                        <th class="text-end" style="width: 10%;">Pending Wt</th>
                                        <th class="text-center" style="width: 8%;">Status / Tops</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($kapan['assignments'])): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-2 text-muted small">આ કાપનમાં કોઈ ડાયમંડ ફાળવેલ નથી.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($kapan['assignments'] as $assign): ?>
                                            <tr>
                                                <td class="font-mono fw-bold text-center">
                                                    #<?= e($assign['assign_number']) ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark font-mono small"><?= e($assign['packet_no']) ?></div>
                                                    <div class="text-muted font-mono" style="font-size: 0.72rem;"><?= e($assign['barcode']) ?></div>
                                                </td>
                                                <td class="text-end font-mono fw-bold text-primary">
                                                    <?= format_carat($assign['assigned_weight']) ?>
                                                </td>
                                                <td>
                                                    <?php if (empty($assign['returns'])): ?>
                                                        <span class="text-muted small fst-italic">જમા બાકી (Pending)</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($assign['returns'] as $ret): ?>
                                                                <span class="badge-sub" title="<?= format_date($ret['received_at']) ?> - <?= e($ret['notes'] ?? '') ?>">
                                                                    <strong><?= e($ret['sub_number']) ?></strong>: <?= format_carat($ret['received_weight']) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end font-mono fw-bold text-success">
                                                    <?= format_carat($assign['returned_weight']) ?>
                                                </td>
                                                <td class="text-end font-mono fw-bold <?= (float)$assign['pending_weight'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                                    <?= format_carat($assign['pending_weight']) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($assign['status'] === 'completed'): ?>
                                                        <span class="badge bg-success-subtle text-success border small font-mono">
                                                            પૂર્ણ <?= (float)$assign['tops_weight'] > 0 ? '(Tops: ' . format_carat($assign['tops_weight']) . ')' : '' ?>
                                                        </span>
                                                    <?php elseif ($assign['status'] === 'partially_returned'): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border small">અંશતઃ</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger-subtle text-danger border small">બાકી</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Jangad Standard Legal Terms -->
        <div class="p-3 bg-light rounded border mb-4 small text-muted">
            <strong class="text-dark d-block mb-1">શરતો & નિયમો (Sales Jangad / Approval Memo Terms):</strong>
            <ol class="mb-0 ps-3">
                <li>ઉપર દર્શાવેલ તૈયાર પોલિશ હીરા માત્ર પસંદગી / ચકાસણી / વેચાણ જોવા માટે (જવાબ-સવાલ / જાંગડ પર) મોકલેલ છે. આ કોઈ પાકું વેચાણ બિલ નથી.</li>
                <li>માલની મૂળ માલિકી Kasyap Gems ની રહેશે અને કંપનીની માંગણી મુજબ અથવા નિયત મુદતે તુરંત પરત કરવાનો રહેશે.</li>
                <li>જે હીરા પસંદ આવે તેનું પાકું વેચાણ ઇન્વોઇસ બનાવ્યા બાદ જ વેચાણ માન્ય ગણાશે.</li>
                <li>માલ પરત જમા ન થાય અથવા પાકું બિલ ન બને ત્યાં સુધી માલની સંપૂર્ણ સુરક્ષા અને જવાબદારી માલ લેનાર પાર્ટીની રહેશે.</li>
            </ol>
        </div>

        <!-- Signatures Block -->
        <div class="d-flex justify-content-between align-items-end pt-3">
            <div class="text-center" style="width: 220px;">
                <div class="border-top border-dark pt-2 small fw-bold">માલ લેનારની સહી (Receiver Sign)</div>
                <div class="text-muted small" style="font-size: 0.75rem;"><?= e($jangad['party_name']) ?></div>
            </div>
            <div class="text-center" style="width: 220px;">
                <div class="small text-muted mb-1"><?= e($jangad['issuer_name'] ?? 'Authorized Staff') ?></div>
                <div class="border-top border-dark pt-2 small fw-bold">Kasyap Gems વતી સહી (Issuer Sign)</div>
                <div class="text-muted small" style="font-size: 0.75rem;">Authorized Signatory</div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
