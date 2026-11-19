<?php
/**
 * Party-wise Outstanding Jangad Stock (સેક્શન ૬.૪ & ૭)
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'Party વાઇઝ બાકી માલ (Jangad Outstanding)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
ensure_jangad_sales_columns();

$stmt = $pdo->query("SELECT p.id as party_id, p.party_name, p.party_type, p.city, p.phone,
                            COUNT(jv.id) as pending_vouchers,
                            SUM(GREATEST(0, jv.total_pcs - COALESCE(jv.sold_pcs, 0) - jv.returned_pcs)) as pending_pcs,
                            SUM(GREATEST(0, jv.total_weight - COALESCE(jv.sold_weight, 0) - jv.returned_weight - COALESCE(jv.total_tops_weight, 0))) as pending_weight
                     FROM jangad_vouchers jv
                     JOIN parties p ON jv.party_id = p.id
                     WHERE jv.status IN ('issued', 'partially_returned')
                     GROUP BY p.id, p.party_name, p.party_type, p.city, p.phone
                     HAVING pending_weight > 0.0005
                     ORDER BY pending_weight DESC");
$partyOutstanding = $stmt->fetchAll();

$totPendingWeight = 0.000;
$totPendingPcs = 0;
foreach ($partyOutstanding as $po) {
    $totPendingWeight += (float)$po['pending_weight'];
    $totPendingPcs += (int)$po['pending_pcs'];
}

$activeJangadCount = (int)$pdo->query("SELECT COUNT(*) FROM jangad_vouchers WHERE status IN ('issued', 'partially_returned')")->fetchColumn();
$outstandingCount = count($partyOutstanding);
?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Party વાઇઝ બાકી Sales Jangad માલ (Outstanding Report)',
    'કયા ગ્રાહક / દલાલ પાસે કેટલો તૈયાર પોલિશ માલ વેચાણ જોવા માટે બાકી છે તે વિગતવાર હિસાબ',
    'clock-rotate-left',
    '
    <a href="' . BASE_URL . '/modules/reports/export.php?type=jangad_outstanding" class="btn btn-outline-success px-3 shadow-sm fw-semibold">
        ' . svg_icon('file-excel', 'me-1') . 'Excel માં Export
    </a>
    <a href="' . BASE_URL . '/modules/jangad/create.php" class="btn btn-primary px-3 shadow-sm">
        ' . svg_icon('plus', 'me-1') . 'નવો Sales Jangad ઇસ્યુ કરો
    </a>
    <button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm">
        ' . svg_icon('print', 'me-1') . 'પ્રિન્ટ રિપોર્ટ
    </button>
    ',
    'rose'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ બાકી માલ (Pending Carats)', format_carat($totPendingWeight), 'પાર્ટીઓ પાસે બાકી', 'Jangad રિટર્ન પેન્ડિંગ', 'hero', null, '', 'clock-rotate-left') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ બાકી નંગ (Pending Pieces)', number_format($totPendingPcs) . ' Pcs', 'હીરાના નંગ', 'ચકાસણી હેઠળ', 'white', null, 'pill-coral', 'gem') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('સક્રિય પાર્ટીઓ (Active Parties)', (string)count($partyOutstanding) . ' પાર્ટીઓ', 'માલ ધરાવનાર', 'પેન્ડિંગ વાઉચર્સ: ' . $activeJangadCount, 'white', null, 'pill-mint', 'users') ?>
    </div>
</div>

<!-- Table -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>પાર્ટીનું નામ / વેન્ડર</th>
                        <th>પ્રકાર (Party Type)</th>
                        <th>શહેર (City)</th>
                        <th>સંપર્ક (Phone)</th>
                        <th>પેન્ડિંગ વાઉચર્સ</th>
                        <th>બાકી નંગ (Pcs)</th>
                        <th class="text-end">કુલ બાકી વજન (Carat)</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($partyOutstanding)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">કોઈ પાર્ટી પાસે માલ બાકી નથી. બધા જાંગડ જમા છે.</td></tr>
                    <?php else: ?>
                        <?php foreach ($partyOutstanding as $po): ?>
                            <tr>
                                <td>
                                    <strong class="fs-6 text-dark"><?= e($po['party_name']) ?></strong>
                                </td>
                                <td><span class="badge bg-light text-dark border text-uppercase"><?= e($po['party_type']) ?></span></td>
                                <td><?= e($po['city']) ?></td>
                                <td><?= e($po['phone'] ?? '-') ?></td>
                                <td><span class="badge bg-primary-subtle text-primary border"><?= (int)$po['pending_vouchers'] ?> વાઉચર્સ</span></td>
                                <td><strong><?= (int)$po['pending_pcs'] ?> Pcs</strong></td>
                                <td class="text-end">
                                    <strong class="fs-6 text-danger font-mono"><?= format_carat($po['pending_weight']) ?></strong>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $po['party_id'] ?>&tab=jangad" class="btn btn-sm btn-outline-primary" title="પાર્ટી ખાતાવહી (Jangad History) જુઓ">
                                            <?= svg_icon('receipt', 'me-1') ?>લેજર
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/jangad/return.php" class="btn btn-sm btn-outline-success" title="માલ જમા કરો">
                                            <?= svg_icon('rotate-left', 'me-1') ?>જમા
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
